<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Core\Flash;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/** "Actualizar base de datos": se usa después de cada deploy. */
final class MigrationsController
{
    public function index(Request $request): Response
    {
        return Response::html(View::render('admin/migrations', [
            'title'  => 'Base de datos',
            'status' => Migrator::statusAll(),
        ], 'layouts/admin'));
    }

    public function run(Request $request): Response
    {
        $total = 0;
        $failed = false;
        foreach (Migrator::runAll() as $result) {
            $total += count($result['applied']);
            if ($result['error'] !== null) {
                $failed = true;
                $err = $result['error'];
                Flash::add('danger', "{$result['label']}: falló {$err['migration']} — {$err['message']}");
            }
        }
        if ($total > 0) {
            Flash::add('success', "Se aplicaron {$total} migración(es).");
        } elseif (!$failed) {
            Flash::add('info', 'La base de datos ya estaba al día.');
        }
        return Response::redirect('/admin/migraciones');
    }
}
