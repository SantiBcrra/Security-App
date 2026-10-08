<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\Http;
use App\Models\PlatformSettings;

/**
 * Envío por canal externo de una fila de la cola. Lanza \RuntimeException si falla (se reintenta).
 * - email: MailTransport (smtp o archivo)
 * - push: API HTTP de Expo (tokens de la app, Etapa 8)
 * - whatsapp: Meta Cloud API con plantilla aprobada
 */
final class Channels
{
    public const LABELS = ['app' => 'En la app', 'email' => 'Email', 'push' => 'Push (celular)', 'whatsapp' => 'WhatsApp'];

    /** Reemplazable en tests: fn(array $row): void. @var ?callable */
    public static $fakeExternal = null;

    public static function send(array $row): void
    {
        if ($row['channel'] === 'email') {
            MailTransport::send($row['to_address'], (string) $row['subject'], (string) $row['body_text'], $row['body_html']);
            return;
        }
        if (self::$fakeExternal !== null) {
            (self::$fakeExternal)($row);
            return;
        }
        match ($row['channel']) {
            'push'     => self::push($row),
            'whatsapp' => self::whatsapp($row),
            default    => throw new \RuntimeException('Canal desconocido: ' . $row['channel']),
        };
    }

    public static function enabled(string $channel): bool
    {
        return match ($channel) {
            'app', 'email' => true,
            'push'         => PlatformSettings::get('push.enabled', '1') === '1',
            'whatsapp'     => PlatformSettings::get('whatsapp.enabled') === '1' && PlatformSettings::get('whatsapp.phone_id'),
            default        => false,
        };
    }

    private static function push(array $row): void
    {
        $payload = json_decode((string) $row['payload'], true) ?: [];
        if (str_starts_with($row['to_address'], 'web:')) {
            $sub = \App\Models\PushSubscriptions::find((int) substr($row['to_address'], 4));
            if ($sub === null) {
                return; // la suscripción ya no existe: no hay a quién mandarle
            }
            WebPush::send($sub, [
                'title' => $row['subject'], 'body' => mb_strimwidth((string) $row['body_text'], 0, 180, '…'),
                'url' => $payload['app_url'] ?? null, 'critical' => (bool) $row['is_critical'], 'tag' => $payload['observation'] ?? $payload['action'] ?? null,
            ], (bool) $row['is_critical']);
            return;
        }
        $headers = ($token = PlatformSettings::secret('push.expo_token')) ? ['Authorization: Bearer ' . $token] : [];
        $res = Http::postJson('https://exp.host/--/api/v2/push/send', [[
            'to' => $row['to_address'], 'title' => $row['subject'], 'body' => mb_strimwidth((string) $row['body_text'], 0, 180, '…'),
            'data' => $payload, 'sound' => 'default', 'priority' => $row['is_critical'] ? 'high' : 'default',
            'channelId' => $row['is_critical'] ? 'alertas' : 'default',
        ]], $headers);
        $status = $res['json']['data'][0]['status'] ?? null;
        if ($res['error'] || $res['status'] >= 400 || $status !== 'ok') {
            throw new \RuntimeException('Expo: ' . ($res['error'] ?: ($res['json']['data'][0]['message'] ?? substr($res['body'], 0, 200))));
        }
    }

    private static function whatsapp(array $row): void
    {
        $token = PlatformSettings::secret('whatsapp.token');
        $phoneId = PlatformSettings::get('whatsapp.phone_id');
        $template = PlatformSettings::get('whatsapp.template') ?: 'alerta_seguridad';
        if (!$token || !$phoneId) {
            throw new \RuntimeException('WhatsApp no está configurado.');
        }
        // Mensajes iniciados por la empresa: Meta exige una plantilla aprobada con 2 parámetros (título, detalle).
        $res = Http::postJson("https://graph.facebook.com/v20.0/{$phoneId}/messages", [
            'messaging_product' => 'whatsapp', 'to' => $row['to_address'], 'type' => 'template',
            'template' => ['name' => $template, 'language' => ['code' => PlatformSettings::get('whatsapp.language') ?: 'es_AR'],
                'components' => [['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => mb_substr((string) $row['subject'], 0, 60)],
                    ['type' => 'text', 'text' => mb_substr(str_replace("\n", ' ', (string) $row['body_text']), 0, 900)],
                ]]]],
        ], ['Authorization: Bearer ' . $token], 6);
        if ($res['error'] || $res['status'] >= 400) {
            throw new \RuntimeException('WhatsApp: ' . ($res['error'] ?: ($res['json']['error']['message'] ?? substr($res['body'], 0, 200))));
        }
    }
}
