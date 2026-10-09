<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\Http;
use App\Models\PlatformSettings;

/**
 * Firebase Cloud Messaging (API HTTP v1) en PHP puro, para la app Android: notificaciones aunque la app esté cerrada.
 * El super-admin pega en /admin/configuracion el google-services.json (datos públicos con los que la app se conecta a
 * Firebase) y la cuenta de servicio (privada, cifrada). Token OAuth = JWT RS256 firmado con openssl, cacheado ~55 min.
 * Se mandan mensajes "data" de alta prioridad: la app arma la notificación (canal crítico o normal) aunque esté cerrada.
 */
final class Fcm
{
    /** Reemplazable en tests: fn(string $url, string $body, array $headers): array{status:int, body:string, error:?string} */
    public static $transport = null;

    public static function configured(): bool
    {
        return self::serviceAccount() !== null && PlatformSettings::get('push.fcm_google_services') !== null;
    }

    /** @return ?array{client_email:string, private_key:string, project_id:string} */
    public static function serviceAccount(): ?array
    {
        $json = PlatformSettings::secret('push.fcm_service_account');
        $sa = $json ? json_decode($json, true) : null;
        return is_array($sa) && !empty($sa['client_email']) && !empty($sa['private_key']) && !empty($sa['project_id']) ? $sa : null;
    }

    /**
     * Datos para que la app inicialice Firebase (no son secretos: van en cualquier app Android).
     * @return ?array{project_id:string, application_id:string, api_key:string, sender_id:string}
     */
    public static function clientConfig(string $package): ?array
    {
        $gs = json_decode((string) PlatformSettings::get('push.fcm_google_services'), true);
        if (!is_array($gs) || !self::configured()) {
            return null;
        }
        foreach ((array) ($gs['client'] ?? []) as $client) {
            if (($client['client_info']['android_client_info']['package_name'] ?? null) === $package) {
                return ['project_id' => (string) ($gs['project_info']['project_id'] ?? ''), 'sender_id' => (string) ($gs['project_info']['project_number'] ?? ''),
                    'application_id' => (string) ($client['client_info']['mobilesdk_app_id'] ?? ''),
                    'api_key' => (string) ($client['api_key'][0]['current_key'] ?? '')];
            }
        }
        return null;
    }

    /** Valida lo que pega el super-admin. @return ?string error */
    public static function validateConfig(string $googleServices, string $serviceAccount): ?string
    {
        if ($googleServices !== '') {
            $gs = json_decode($googleServices, true);
            if (!is_array($gs) || empty($gs['project_info']['project_id']) || empty($gs['client'])) {
                return 'El google-services.json no es válido (bajalo de Firebase → Configuración del proyecto → Tus apps → Android).';
            }
            $packages = array_map(fn ($c) => $c['client_info']['android_client_info']['package_name'] ?? '', (array) $gs['client']);
            if (!in_array('ar.com.securityapp.campo', $packages, true)) {
                return 'El google-services.json no incluye la app ar.com.securityapp.campo: agregala en Firebase y volvé a bajarlo.';
            }
        }
        if ($serviceAccount !== '') {
            $sa = json_decode($serviceAccount, true);
            if (!is_array($sa) || ($sa['type'] ?? '') !== 'service_account' || empty($sa['private_key']) || !openssl_pkey_get_private((string) $sa['private_key'])) {
                return 'La cuenta de servicio no es válida (Firebase → Configuración del proyecto → Cuentas de servicio → Generar nueva clave privada).';
            }
        }
        return null;
    }

    /**
     * @param array $data valores string (title, body, url, event, critical…)
     * @throws FcmTokenGone si el token ya no existe (app desinstalada): hay que borrarlo
     * @throws \RuntimeException otros errores (se reintenta por la cola)
     */
    public static function send(string $token, array $data, bool $critical): void
    {
        $sa = self::serviceAccount() ?? throw new \RuntimeException('FCM sin configurar.');
        $message = ['message' => [
            'token' => $token,
            'data' => array_map('strval', $data),
            'android' => ['priority' => $critical ? 'HIGH' : 'NORMAL', 'ttl' => $critical ? '3600s' : '86400s'],
        ]];
        $res = self::http('https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send',
            json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ['Content-Type: application/json', 'Authorization: Bearer ' . self::accessToken($sa)]);
        if ($res['error'] === null && $res['status'] >= 200 && $res['status'] < 300) {
            return;
        }
        $json = json_decode($res['body'], true);
        $code = $json['error']['details'][0]['errorCode'] ?? $json['error']['status'] ?? '';
        if (in_array($code, ['UNREGISTERED', 'NOT_FOUND'], true) || ($res['status'] === 400 && $code === 'INVALID_ARGUMENT')) {
            throw new FcmTokenGone('FCM: token inválido (' . $code . ').');
        }
        if ($res['status'] === 401) {
            PlatformSettings::setSecret('push.fcm_token_cache', null); // token OAuth vencido o revocado: se pide otro
        }
        throw new \RuntimeException('FCM: ' . ($res['error'] ?: ($json['error']['message'] ?? substr($res['body'], 0, 200))));
    }

    /** Token OAuth de la cuenta de servicio (cacheado cifrado en la maestra hasta 5 min antes de vencer). */
    private static function accessToken(array $sa): string
    {
        $cached = json_decode((string) PlatformSettings::secret('push.fcm_token_cache'), true);
        if (is_array($cached) && ($cached['exp'] ?? 0) > time() + 300 && ($cached['sub'] ?? '') === $sa['client_email']) {
            return (string) $cached['token'];
        }
        $now = time();
        $jwt = self::jwt($sa, $now);
        $res = self::http('https://oauth2.googleapis.com/token', http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
            ['Content-Type: application/x-www-form-urlencoded']);
        $json = json_decode($res['body'], true);
        if (empty($json['access_token'])) {
            throw new \RuntimeException('FCM: no se pudo obtener el token OAuth (' . ($json['error_description'] ?? $json['error'] ?? $res['error'] ?? $res['status']) . ').');
        }
        PlatformSettings::setSecret('push.fcm_token_cache', json_encode(['token' => $json['access_token'], 'exp' => $now + (int) ($json['expires_in'] ?? 3600),
            'sub' => $sa['client_email']]));
        return (string) $json['access_token'];
    }

    /** JWT RS256 para el intercambio OAuth de Google. */
    public static function jwt(array $sa, int $now): string
    {
        $b64 = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = $b64(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
        $key = openssl_pkey_get_private((string) $sa['private_key']);
        if ($key === false || !openssl_sign($header . '.' . $claims, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('FCM: la clave privada de la cuenta de servicio no sirve.');
        }
        return $header . '.' . $claims . '.' . $b64($signature);
    }

    private static function http(string $url, string $body, array $headers): array
    {
        return self::$transport !== null ? (self::$transport)($url, $body, $headers) : Http::post($url, $body, $headers, 8);
    }
}

