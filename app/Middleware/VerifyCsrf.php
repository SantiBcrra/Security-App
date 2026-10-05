<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;

/**
 * CSRF en todo POST/PUT/DELETE web. La API (/api) no usa cookies de sesión sino JWT, así que
 * queda afuera. Igual que en MetalERP: AJAX recibe 403 JSON; navegación normal vuelve por GET
 * a la página anterior con un aviso (sin re-POST). El request NUNCA se ejecuta sin token válido.
 */
final class VerifyCsrf
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true) || $request->isApi()) {
            return $next($request);
        }
        $sent = $request->post['csrf_token'] ?? $request->header('x-csrf-token');
        if (Csrf::check($sent)) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::jsonError('La sesión se actualizó. Recargá la página y reintentá.', 403, 'csrf');
        }
        Flash::add('warning', 'La sesión se actualizó. Volvé a intentarlo.');
        return Response::redirect(self::sameOriginReferer() ?? '/');
    }

    private static function sameOriginReferer(): ?string
    {
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($referer !== '' && $host !== '' && parse_url($referer, PHP_URL_HOST) === parse_url('//' . $host, PHP_URL_HOST)) {
            return $referer;
        }
        return null;
    }
}
