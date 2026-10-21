<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\UserError;
use App\Core\View;
use App\Services\Audit;
use App\Services\Logbook;
use App\Services\ShiftPlanner;
use App\Services\UserAuth;

/** Libro de guardia en el panel: el libro del día por predio, alta rápida (garita con PC), relevo, búsqueda, parte diario. */
final class LogbookController
{
    private function today(): string
    {
        return ShiftPlanner::localDay(gmdate('Y-m-d H:i:s'));
    }

    /** Predio elegido: el de la URL, el último usado (sesión) o el único. */
    private function site(Request $request): ?array
    {
        $uuid = (string) $request->input('predio', '') ?: (string) (Session::get('libro_predio') ?? '');
        $site = Logbook::site($uuid) ?? Logbook::site(null);
        if ($site) Session::put('libro_predio', $site['uuid']);
        return $site;
    }

    private function back(?array $site, ?string $day = null): Response
    {
        return Response::redirect('/panel/libro' . ($site ? '?predio=' . $site['uuid'] . ($day ? '&fecha=' . $day : '') : ''));
    }

    public function index(Request $request): Response
    {
        $site = $this->site($request);
        $today = $this->today();
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('fecha', '')) ? (string) $request->input('fecha') : $today;
        $me = (int) UserAuth::user()['id'];
        return Response::html(View::render('panel/logbook/index', [
            'title' => 'Libro de guardia', 'site' => $site, 'sites' => Logbook::sites(), 'day' => $day, 'today' => $today,
            'entries' => $site ? Logbook::day((int) $site['id'], $day) : [], 'inside' => $site ? Logbook::inside((int) $site['id']) : [],
            'pending' => $site ? Logbook::pendingClose((int) $site['id'], $me) : null, 'summary' => $site ? Logbook::summary((int) $site['id'], $me) : null,
            'verify' => $site ? Logbook::verify((int) $site['id']) : null, 'canCreate' => UserAuth::can(Logbook::MODULE, 'crear'),
            'canExport' => UserAuth::can(Logbook::MODULE, 'exportar'),
        ], 'layouts/app'));
    }

    /** Alta de novedad, persona o vehículo (con foto opcional). */
    public function store(Request $request): Response
    {
        $site = $this->site($request);
        try {
            $r = Logbook::create($request->post + ['site_uuid' => $site['uuid'] ?? '']);
            $photo = $request->files['photo'] ?? null;
            if (is_array($photo) && ($photo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $meta = \App\Services\ImageProcessor::store(Tenant::current()['uuid'], $photo['tmp_name'], $photo['name'] ?? null, true, (int) UserAuth::user()['id'], 'logbook');
                Logbook::addAttachment((int) $r['entry']['id'], $meta);
            }
            Flash::add('success', 'Anotado (folio ' . $r['entry']['folio'] . ').');
        } catch (UserError | \DomainException $e) {
            Flash::add('danger', $e->getMessage());
            Flash::withInput(array_diff_key($request->post, ['csrf_token' => 1]));
        }
        return $this->back($site);
    }

    public function exit(Request $request, string $uuid): Response
    {
        try {
            $r = Logbook::exit($uuid, $request->post);
            Flash::add('success', 'Salida anotada (folio ' . $r['entry']['folio'] . ').');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return $this->back($this->site($request));
    }

    public function correct(Request $request, string $uuid): Response
    {
        try {
            $r = Logbook::correct($uuid, $request->post);
            Flash::add('success', 'Corrección anotada (folio ' . $r['entry']['folio'] . '). La anotación original no cambia.');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return $this->back($this->site($request));
    }

    public function close(Request $request): Response
    {
        $site = $this->site($request);
        try {
            $r = Logbook::close($request->post + ['site_uuid' => $site['uuid'] ?? '']);
            Flash::add('success', 'Turno cerrado y firmado (folio ' . $r['entry']['folio'] . ').');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return $this->back($site);
    }

    public function receive(Request $request, string $uuid): Response
    {
        try {
            $r = Logbook::receive($uuid, $request->post);
            Flash::add('success', 'Recibiste el puesto (folio ' . $r['entry']['folio'] . ').');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return $this->back($this->site($request));
    }

    /** Sugerencias para autocompletar por DNI o patente. */
    public function lookup(Request $request): Response
    {
        return Response::json(['items' => Logbook::lookup($request->input('tipo') === 'vehiculo' ? 'vehiculo' : 'persona', (string) $request->input('q', ''))]);
    }

    private function filters(Request $request): array
    {
        $date = fn (string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input($k, '')) ? (string) $request->input($k) : '';
        $site = ($u = (string) $request->input('predio', '')) !== '' ? Logbook::site($u) : null;
        return ['q' => trim((string) $request->input('q', '')), 'kind' => (string) $request->input('tipo', ''), 'from' => $date('desde'), 'to' => $date('hasta'),
            'site_id' => $site['id'] ?? null, 'predio' => $site['uuid'] ?? ''];
    }

    public function search(Request $request): Response
    {
        $f = $this->filters($request);
        $searched = $f['q'] !== '' || $f['kind'] !== '' || $f['from'] !== '' || $f['to'] !== '' || $f['site_id'];
        return Response::html(View::render('panel/logbook/search', [
            'title' => 'Buscar en el libro', 'filters' => $f, 'rows' => $searched ? Logbook::search($f) : [], 'searched' => $searched, 'sites' => Logbook::sites(),
            'canExport' => UserAuth::can(Logbook::MODULE, 'exportar'),
            'canPurge' => UserAuth::can(Logbook::MODULE, 'editar') && UserAuth::scope(Logbook::MODULE) === 'todo',
        ], 'layouts/app'));
    }

    /** Parte diario imprimible con folios y verificación de la cadena. */
    public function printable(Request $request): Response
    {
        $site = $this->site($request);
        if ($site === null) return Response::redirect('/panel/libro');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('fecha', '')) ? (string) $request->input('fecha') : $this->today();
        $entries = Logbook::day((int) $site['id'], $day);
        $last = $entries ? (int) end($entries)['folio'] : null;
        Audit::tenant('logbook.print', 'logbook', null, null, ['predio' => $site['name'], 'dia' => $day]);
        return Response::html(View::render('panel/logbook/print', ['site' => $site, 'day' => $day, 'entries' => $entries,
            'verify' => Logbook::verify((int) $site['id'], $last)], null));
    }

    public function export(Request $request): Response
    {
        $f = $this->filters($request);
        $rows = Logbook::search($f, 20000);
        Audit::tenant('logbook.export', 'logbook', null, null, array_diff_key($f, ['site_id' => 1]) + ['filas' => count($rows)]);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Predio', 'Folio', 'Fecha y hora', 'Tipo', 'Categoría', 'Importante', 'Nombre', 'DNI', 'Empresa', 'Visita a', 'Motivo', 'Patente', 'Vehículo',
            'Conductor', 'Carga / remito', 'Texto', 'Salida', 'Guardia', 'Referencia (folio)'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [$r['site_name'], $r['folio'], fecha($r['occurred_at'], 'd/m/Y H:i'), Logbook::KINDS[$r['kind']] ?? $r['kind'],
                Logbook::CATEGORIES[$r['category'] ?? ''] ?? '', $r['important'] ? 'Sí' : '', $r['person_name'], $r['person_doc'], $r['company'], $r['visiting'],
                $r['reason'], $r['plate'], Logbook::VEHICLE_TYPES[$r['vehicle_type'] ?? ''] ?? '', $r['driver'], $r['load_note'], $r['text'],
                $r['exited_at'] ? fecha($r['exited_at'], 'd/m/Y H:i') : '', $r['user_name'], $r['ref_folio']], ';');
        }
        rewind($out);
        return new Response((string) stream_get_contents($out), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="libro-de-guardia-' . date('Y-m-d') . '.csv"']);
    }

    public function purge(Request $request): Response
    {
        try {
            if ($request->input('confirm') !== 'BORRAR') throw new UserError('Escribí BORRAR para confirmar.');
            $n = Logbook::purge((string) $request->input('before', ''));
            Flash::add('success', "Se borraron los datos personales de {$n} anotación(es). Las anotaciones (hora, tipo, folio) quedan.");
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return Response::redirect('/panel/libro/buscar');
    }

    /** Foto o firma de una anotación (nunca se sirven directo). */
    public function file(Request $request, string $uuid, string $what): Response
    {
        $tenant = Tenant::current()['uuid'];
        if ($what === 'firma') {
            $e = Logbook::find($uuid);
            $path = $e && $e['signature_path'] ? TenantFiles::path($tenant, $e['signature_path']) : null;
        } else {
            $q = \App\Core\DB::tenant()->prepare('SELECT * FROM logbook_attachments WHERE uuid = ?');
            $q->execute([$uuid]);
            $a = $q->fetch();
            $path = $a ? TenantFiles::path($tenant, $what === 'mini' && $a['thumb_path'] ? $a['thumb_path'] : $a['path']) : null;
        }
        return $path ? TenantFiles::response($path, 3600) : new Response('', 404);
    }
}
