<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\UserAuth;

/** Permiso por ruta: new RequirePermission('usuarios', 'ver'). Va después de RequireTenant. */
final class RequirePermission
{
    public function __construct(private readonly string $module, private readonly string $action)
    {
    }

    public function __invoke(Request $request, callable $next): Response
    {
        if (UserAuth::can($this->module, $this->action)) {
            return $next($request);
        }
        if ($request->wantsJson()) {
            return Response::jsonError('No tenés permiso para esta acción.', 403, 'forbidden');
        }
        return Response::html(View::render('errors/403', ['title' => 'Sin permiso'], 'layouts/app'), 403);
    }
}
