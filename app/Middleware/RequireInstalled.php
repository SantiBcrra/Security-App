<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;

/** Sin instalar, todo (salvo el instalador y el diagnóstico) manda a /install. */
final class RequireInstalled
{
    private const ALLOWED = ['/install', '/_diag'];

    public function __invoke(Request $request, callable $next): Response
    {
        if (App::isInstalled()) {
            return $next($request);
        }
        foreach (self::ALLOWED as $prefix) {
            if ($request->path === $prefix || str_starts_with($request->path, $prefix . '/')) {
                return $next($request);
            }
        }
        return $request->wantsJson()
            ? Response::jsonError('El sistema no está instalado.', 503, 'not_installed')
            : Response::redirect('/install');
    }
}
