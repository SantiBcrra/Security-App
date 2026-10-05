<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Equipment;
use App\Services\UserAuth;

/** QR de equipos: /q/{uuid} (lo que lee el celular) y la hoja de etiquetas para imprimir. */
final class QrController
{
    /** El QR apunta acá: con sesión abre la ficha; sin sesión pide login y vuelve. */
    public function resolve(Request $request, string $uuid): Response
    {
        $target = '/panel/datos/equipos/' . rawurlencode($uuid);
        if (Session::get(UserAuth::USER_KEY) === null) {
            Session::put('intended', $target);
            return Response::redirect('/login');
        }
        return Response::redirect($target);
    }

    public function labels(Request $request): Response
    {
        $uuids = array_filter(explode(',', (string) $request->input('equipos', '')));
        $rows = $uuids
            ? array_values(array_filter(array_map(fn ($u) => Equipment::findByUuid($u), $uuids)))
            : Equipment::list(null, false, [], 1000);
        $items = array_map(fn ($r) => ['code' => $r['code'], 'name' => $r['name'], 'url' => absolute_url('/q/' . $r['uuid'])], $rows);
        return Response::html(View::render('panel/master/labels', ['title' => 'Etiquetas QR', 'items' => $items], null));
    }

    public function patrolPoint(Request $request, string $uuid): Response
    {
        return Response::redirect('/movil#/ronda/punto/' . rawurlencode($uuid));
    }
}
