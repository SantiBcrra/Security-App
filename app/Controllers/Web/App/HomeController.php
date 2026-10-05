<?php
declare(strict_types=1);

namespace App\Controllers\Web\App;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\SignedUrl;
use App\Core\Tenant;
use App\Core\View;
use App\Models\TenantAudit;
use App\Services\UserAuth;

/** Inicio del área de la empresa. Acá se montan los módulos desde la Etapa 3. */
final class HomeController
{
    public function index(Request $request): Response
    {
        $tenant = Tenant::current();
        // Datos técnicos y auditoría: solo para quien administra usuarios (o soporte).
        $isManager = UserAuth::can('usuarios', 'ver');
        return Response::html(View::render('app/home', [
            'title'   => 'Inicio',
            'tenant'  => $tenant,
            'dbName'  => UserAuth::user() === null ? (string) DB::tenant()->query('SELECT DATABASE()')->fetchColumn() : null,
            'events'  => $isManager ? TenantAudit::latest(DB::tenant(), 10) : null,
        ], 'layouts/app'));
    }

    public static function logoUrl(array $tenant): ?string
    {
        return $tenant['logo_path'] ? SignedUrl::make('/archivos/logo/' . $tenant['uuid'], 600) : null;
    }
}
