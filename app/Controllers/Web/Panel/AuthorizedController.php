<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\UserError;
use App\Core\View;
use App\Services\LogbookAuthorized;

/** Libro de guardia → Lista de autorizados para ingresar (personas y vehículos, por predio, con vigencia y horario). */
final class AuthorizedController
{
    public function index(Request $request): Response
    {
        $site = !empty($request->input('predio')) ? \App\Models\Sites::findByUuid((string) $request->input('predio')) : null;
        $f = ['q' => trim((string) $request->input('q', '')), 'kind' => (string) $request->input('tipo', ''), 'estado' => (string) $request->input('estado', 'vigentes'),
            'predio' => $site['uuid'] ?? ''];
        return Response::html(View::render('panel/logbook/authorized', [
            'title' => 'Lista de autorizados', 'rows' => LogbookAuthorized::list(['q' => $f['q'], 'kind' => $f['kind'], 'estado' => $f['estado'], 'site_id' => $site['id'] ?? null]),
            'filters' => $f, 'sites' => \App\Models\Sites::list(), 'canManage' => LogbookAuthorized::canManage(),
        ], 'layouts/app'));
    }

    public function form(Request $request, ?string $uuid = null): Response
    {
        $row = $uuid ? LogbookAuthorized::find($uuid) : null;
        if ($uuid && !$row) return new Response('', 404);
        $old = Session::get('authorized_old');
        Session::forget('authorized_old');
        return Response::html(View::render('panel/logbook/authorized_form', [
            'title' => $row ? 'Editar autorizado' : 'Nuevo autorizado', 'row' => $row, 'old' => $old, 'sites' => \App\Models\Sites::list(),
            'kind' => (string) $request->input('tipo', 'persona'),
        ], 'layouts/app'));
    }

    public function store(Request $request, ?string $uuid = null): Response
    {
        $row = $uuid ? LogbookAuthorized::find($uuid) : null;
        if ($uuid && !$row) return new Response('', 404);
        [$data, $errors] = LogbookAuthorized::validate($request->post);
        if ($errors) {
            foreach ($errors as $e) Flash::add('danger', $e);
            Session::put('authorized_old', $request->post);
            return Response::redirect('/panel/libro/autorizados/' . ($uuid ?? 'nuevo'));
        }
        LogbookAuthorized::save($row, $data);
        Flash::add('success', ($row ? 'Actualizado: ' : 'Agregado a la lista: ') . LogbookAuthorized::label($data + ['kind' => $data['kind']]) . '. Llega a los celulares en la próxima sincronización.');
        return Response::redirect('/panel/libro/autorizados');
    }

    public function toggle(Request $request, string $uuid): Response
    {
        if ($row = LogbookAuthorized::find($uuid)) {
            LogbookAuthorized::setActive($row, (int) $row['is_active'] !== 1);
            Flash::add('success', (int) $row['is_active'] === 1 ? 'Suspendido: deja de estar autorizado (no se borra).' : 'Autorización reactivada.');
        }
        return Response::redirect('/panel/libro/autorizados');
    }

    public function delete(Request $request, string $uuid): Response
    {
        if ($row = LogbookAuthorized::find($uuid)) {
            LogbookAuthorized::delete($row);
            Flash::add('success', 'Quitado de la lista: ' . LogbookAuthorized::label($row) . '.');
        }
        return Response::redirect('/panel/libro/autorizados');
    }

    public function import(Request $request): Response
    {
        $file = $request->files['archivo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::add('danger', 'Elegí el archivo Excel (.xlsx) o CSV.');
            return Response::redirect('/panel/libro/autorizados');
        }
        try {
            $r = LogbookAuthorized::import($file['tmp_name'], (string) ($file['name'] ?? 'lista.csv'));
            Flash::add($r['errors'] ? 'warning' : 'success', "Importación: {$r['created']} agregado(s), {$r['updated']} actualizado(s)"
                . ($r['errors'] ? ', ' . count($r['errors']) . ' fila(s) con error: ' . implode(' ', array_slice($r['errors'], 0, 8)) . (count($r['errors']) > 8 ? ' …' : '') : '.'));
        } catch (UserError | \DomainException $e) {
            Flash::add('danger', $e->getMessage());
        }
        return Response::redirect('/panel/libro/autorizados');
    }

    /** Planilla de ejemplo para completar en Excel (CSV con ";" que Excel abre directo). */
    public function template(Request $request): Response
    {
        $csv = "\u{FEFF}tipo;nombre;dni;empresa;patente;predio;desde;hasta;dias;hora_desde;hora_hasta;motivo;autorizo\r\n"
            . "persona;Juan Pérez;30111222;Gas SA;;;01/11/2026;30/11/2026;lun a vie;08:00;17:00;Mantenimiento de calderas;Jefe de planta\r\n"
            . "vehiculo;;;Transportes Sur;AB123CD;;;;;;;Retiro de chatarra;Compras\r\n";
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="lista-de-autorizados.csv"']);
    }
}
