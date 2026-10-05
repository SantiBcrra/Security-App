<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Services\Impersonation;

/**
 * Mientras el super-admin está "dentro" de una empresa, es SOLO LECTURA (mismo criterio que el
 * "ver como" de MetalERP): bloquea toda escritura para no operar en nombre de la empresa.
 */
final class ReadOnlyImpersonation
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (!Impersonation::active() || in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        if ($request->wantsJson()) {
            return Response::jsonError('Modo soporte: solo lectura.', 403, 'read_only');
        }
        Flash::add('warning', 'Estás en modo soporte (solo lectura): las acciones están deshabilitadas.');
        return Response::redirect('/panel');
    }
}
