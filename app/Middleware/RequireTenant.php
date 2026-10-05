<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Core\TenantSuspended;
use App\Models\Tenants;
use App\Services\AdminAuth;
use App\Services\Impersonation;

/**
 * Área de empresa (/panel): resuelve la empresa de la sesión y conecta DB::tenant() a SU base.
 * Etapa 2: la única forma de entrar es la impersonación del super-admin.
 * Etapa 3: también el login de usuarios de la empresa.
 */
final class RequireTenant
{
    public function __invoke(Request $request, callable $next): Response
    {
        $uuid = Session::get(Impersonation::TENANT_KEY);
        $tenant = is_string($uuid) ? Tenants::findByUuid($uuid) : null;

        // Impersonación sin super-admin logueado (sesión vencida o cerrada) → no vale.
        if ($tenant !== null && Impersonation::active() && AdminAuth::user() === null) {
            $tenant = null;
        }
        if ($tenant === null) {
            Impersonation::clear();
            return $this->deny($request, 'Elegí una empresa para continuar.');
        }

        try {
            Tenant::activate($tenant);
        } catch (TenantSuspended $e) {
            Impersonation::clear();
            return $this->deny($request, $e->getMessage());
        }
        return $next($request);
    }

    private function deny(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::jsonError($message, 403, 'tenant');
        }
        Flash::add('warning', $message);
        return Response::redirect(AdminAuth::user() !== null ? '/admin/empresas' : '/');
    }
}
