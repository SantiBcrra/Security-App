<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\ApiAuth;

/** API: Bearer JWT → activa la empresa del token, el usuario y el dispositivo. */
final class RequireApiUser
{
    private const MESSAGES = [
        'missing_token'      => 'Falta el token de acceso.',
        'invalid_token'      => 'Token inválido.',
        'token_expired'      => 'El token venció: renovalo con el refresh token.',
        'tenant_unavailable' => 'La empresa no está disponible.',
        'session_revoked'    => 'La sesión de este dispositivo ya no es válida. Volvé a ingresar.',
    ];

    public function __invoke(Request $request, callable $next): Response
    {
        $error = ApiAuth::authenticate($request->bearerToken());
        if ($error !== null) {
            return Response::jsonError(self::MESSAGES[$error] ?? 'No autorizado.', 401, $error);
        }
        return $next($request);
    }
}
