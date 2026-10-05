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

/** Inicio del área de la empresa. Acá se montan los módulos desde la Etapa 3. */
final class HomeController
{
    public function index(Request $request): Response
    {
        $tenant = Tenant::current();
        return Response::html(View::render('app/home', [
            'title'   => 'Inicio',
            'tenant'  => $tenant,
            'dbName'  => (string) DB::tenant()->query('SELECT DATABASE()')->fetchColumn(),
            'events'  => TenantAudit::latest(DB::tenant(), 10),
        ], 'layouts/app'));
    }

    public static function logoUrl(array $tenant): ?string
    {
        return $tenant['logo_path'] ? SignedUrl::make('/archivos/logo/' . $tenant['uuid'], 600) : null;
    }
}
