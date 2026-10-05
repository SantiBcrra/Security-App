<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class DashboardController
{
    public function index(Request $request): Response
    {
        $status = Migrator::statusAll();
        return Response::html(View::render('admin/dashboard', [
            'title'      => 'Tablero',
            'pending'    => array_sum(array_map(fn ($s) => count($s['pending']), $status)),
            'phpVersion' => PHP_VERSION,
            'dbVersion'  => (string) DB::master()->query('SELECT VERSION()')->fetchColumn(),
            'env'        => Config::get('app.env'),
            'debug'      => Config::isDebug(),
            'nowUtc'     => gmdate('Y-m-d H:i:s'),
        ], 'layouts/admin'));
    }
}
