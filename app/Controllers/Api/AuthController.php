<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Services\ApiAuth;
use App\Services\UserAuth;

/** Login de la app móvil: JWT de acceso (15 min) + refresh token rotativo por dispositivo. */
final class AuthController
{
    private const ERRORS = [
        'invalid_credentials' => [401, 'Empresa, usuario o contraseña incorrectos.'],
        'locked'              => [429, 'Demasiados intentos fallidos. Esperá unos minutos.'],
        'totp_required'       => [401, 'Ingresá el código de verificación en dos pasos.'],
        'totp_invalid'        => [401, 'Código de verificación incorrecto.'],
        'device_invalid'      => [422, 'Identificador de dispositivo inválido (tiene que ser un UUID).'],
        'invalid_refresh'     => [401, 'La sesión venció o fue cerrada. Volvé a ingresar.'],
    ];

    public function login(Request $request): Response
    {
        $result = ApiAuth::login(
            (string) $request->input('empresa', ''),
            (string) $request->input('usuario', ''),
            (string) $request->input('password', ''),
            (string) $request->input('device_uuid', ''),
            $request->input('device_name') !== null ? (string) $request->input('device_name') : null,
            $request->input('totp') !== null ? (string) $request->input('totp') : null,
            $request->ip()
        );
        return self::respond($result);
    }

    public function refresh(Request $request): Response
    {
        return self::respond(ApiAuth::refresh((string) $request->input('refresh_token', ''), $request->ip()));
    }

    public function logout(Request $request): Response
    {
        ApiAuth::logout();
        return Response::json(['logged_out' => true]);
    }

    public function me(Request $request): Response
    {
        $user = UserAuth::user();
        $tenant = Tenant::current();
        return Response::json([
            'usuario' => [
                'uuid' => $user['uuid'], 'nombre' => $user['name'], 'email' => $user['email'], 'dni' => $user['dni'],
                'rol' => ['slug' => $user['role_slug'], 'nombre' => $user['role_name']],
            ],
            'permisos' => UserAuth::permissions(),
            'empresa'  => ['uuid' => $tenant['uuid'], 'nombre' => $tenant['name'], 'slug' => $tenant['slug'], 'zona_horaria' => $tenant['timezone']],
            'dispositivo' => ApiAuth::device()['uuid'] ?? null,
            'consentimiento' => ['version' => \App\Services\Consent::VERSION, 'aceptado' => \App\Services\Consent::accepted((int) $user['id'])],
        ]);
    }

    private static function respond(array $result): Response
    {
        if (isset($result['error'])) {
            [$status, $message] = self::ERRORS[$result['error']] ?? [401, 'No autorizado.'];
            return Response::jsonError($message, $status, $result['error']);
        }
        return Response::json($result);
    }
}
