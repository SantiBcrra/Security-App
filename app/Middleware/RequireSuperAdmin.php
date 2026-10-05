<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Services\AdminAuth;

final class RequireSuperAdmin
{
    public function __invoke(Request $request, callable $next): Response
    {
        if (AdminAuth::user() !== null) {
            return $next($request);
        }
        return $request->wantsJson()
            ? Response::jsonError('Sesión vencida.', 401, 'unauthenticated')
            : Response::redirect('/admin/login');
    }
}
