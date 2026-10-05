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
use App\Models\Users;
use App\Services\AdminAuth;
use App\Services\Impersonation;
use App\Services\UserAuth;

/**
 * Área de empresa (/panel): resuelve la empresa de la sesión y conecta DB::tenant() a SU base.
 * Dos formas de estar adentro:
 *  - Usuario de la empresa logueado (se recarga en cada request desde la base de la empresa:
 *    si lo desactivan, vence o le cambian el rol, se nota en el próximo click).
 *  - Super-admin en modo soporte (impersonación, solo lectura).
 */
final class RequireTenant
{
    public function __invoke(Request $request, callable $next): Response
    {
        $uuid = Session::get(Impersonation::TENANT_KEY);
        $tenant = is_string($uuid) ? Tenants::findByUuid($uuid) : null;
        $userUuid = Session::get(UserAuth::USER_KEY);

        if ($tenant !== null && is_string($userUuid)) {
            return $this->asUser($request, $next, $tenant, $userUuid);
        }
        if ($tenant !== null && Impersonation::active() && AdminAuth::user() !== null) {
            try {
                Tenant::activate($tenant);
            } catch (TenantSuspended $e) {
                Impersonation::clear();
                return $this->deny($request, $e->getMessage());
            }
            return $next($request);
        }

        Impersonation::clear();
        Session::forget(UserAuth::USER_KEY);
        return $this->deny($request, 'Iniciá sesión para continuar.');
    }

    private function asUser(Request $request, callable $next, array $tenant, string $userUuid): Response
    {
        try {
            Tenant::activate($tenant);
        } catch (TenantSuspended) {
            UserAuth::logout();
            return $this->deny($request, 'La empresa no está disponible. Consultá con el administrador.');
        }
        $user = Users::findByUuid($userUuid);
        if ($user === null || !Users::canSignIn($user)) {
            UserAuth::logout();
            return $this->deny($request, 'Tu acceso ya no está habilitado. Consultá con el administrador.');
        }
        UserAuth::setCurrent($user);
        return $next($request);
    }

    private function deny(Request $request, string $message): Response
    {
        if ($request->wantsJson()) {
            return Response::jsonError($message, 401, 'unauthenticated');
        }
        Flash::add('warning', $message);
        return Response::redirect(AdminAuth::user() !== null && Session::get(UserAuth::USER_KEY) === null ? '/admin/empresas' : '/login');
    }
}
