<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\Config;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class HomeController
{
    public function index(Request $request): Response
    {
        $db = ['status' => 'pendiente', 'detail' => 'Sin configurar (se completa con el instalador, Etapa 1)'];
        if (Config::get('db.master.database')) {
            try {
                $version = DB::master()->query('SELECT VERSION()')->fetchColumn();
                $db = ['status' => 'ok', 'detail' => 'Conectada · ' . $version];
            } catch (\Throwable $e) {
                $db = ['status' => 'error', 'detail' => 'No se pudo conectar a la base maestra'];
            }
        }

        return Response::html(View::render('home', [
            'title'      => 'Estado del sistema',
            'phpVersion' => PHP_VERSION,
            'env'        => Config::get('app.env'),
            'debug'      => Config::isDebug(),
            'nowUtc'     => gmdate('Y-m-d H:i:s'),
            'db'         => $db,
        ]));
    }
}
