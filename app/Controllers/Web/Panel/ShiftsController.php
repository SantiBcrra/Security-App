<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\UserError;
use App\Core\View;
use App\Models\Users;
use App\Services\Audit;
use App\Services\ShiftAbsences;
use App\Services\ShiftPlanner;
use App\Services\ShiftReport;
use App\Services\ShiftService;
use App\Services\UserAuth;

/** Rondas → Turnos: hoy, programación (fija / rotativa), ausencias y días sin servicio, horas trabajadas y detalle. */
final class ShiftsController
{
    private function onlyUser(): ?int
    {
        return UserAuth::scope('rondas') === 'propios' ? (int) UserAuth::user()['id'] : null;
    }

    private function localToday(): string
    {
        return ShiftPlanner::localDay(gmdate('Y-m-d H:i:s'));
    }

    private function date(Request $request, string $key, string $default): string
    {
        $v = (string) $request->input($key, '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : $default;
    }

    /** Usuarios activos que pueden ser guardias (con el módulo rondas). */
    private function guards(): array
    {
        return array_values(array_filter(Users::all(), fn ($u) => (int) $u['is_active'] === 1
            && \App\Policies\Permissions::can(\App\Policies\Permissions::decode($u['role_permissions']), 'rondas', 'ver')));
    }

    public function today(Request $request): Response
    {
        $today = $this->localToday();
        $day = $this->date($request, 'fecha', $today);
        ShiftPlanner::generate(); // por si el cron todavía no corrió
        \App\Services\PostCoverage::generate();
        return Response::html(View::render('panel/shifts/today', [
            'title' => 'Turnos de guardias', 'day' => $day, 'today' => $today, 'slots' => ShiftService::day($day, $this->onlyUser()),
            'posts' => $this->onlyUser() === null ? \App\Services\PostCoverage::day($day) : [],
            'pending' => $this->onlyUser() === null ? ShiftAbsences::list('solicitada') : [],
            'hasShifts' => (bool) ShiftPlanner::all(), 'canEdit' => ShiftAbsences::canManage(),
        ], 'layouts/app'));
    }

    public function show(Request $request, string $uuid): Response
    {
        $slot = ShiftService::find($uuid);
        if ($slot === null || ($this->onlyUser() !== null && (int) $slot['user_id'] !== $this->onlyUser())) return new Response('', 404);
        return Response::html(View::render('panel/shifts/show', [
            'title' => 'Turno de ' . $slot['user_name'], 'slot' => $slot, 'exits' => ShiftService::exits((int) $slot['id']),
            'positions' => ShiftService::positions((int) $slot['id']), 'measure' => ShiftReport::measure($slot),
            'replaces' => $slot['replaces_slot_id'] ? ShiftService::findById((int) $slot['replaces_slot_id']) : null,
            'absence' => $slot['absence_id'] ? ShiftAbsences::findById((int) $slot['absence_id']) : null,
        ], 'layouts/app'));
    }

    /** Franja de un puesto: quién la cubrió (tramos y relevos), huecos y motivos. */
    public function post(Request $request, string $uuid): Response
    {
        $c = \App\Services\PostCoverage::find($uuid);
        if ($c === null || $this->onlyUser() !== null) return new Response('', 404);
        return Response::html(View::render('panel/shifts/post', [
            'title' => 'Puesto ' . ($c['shift_name'] ?: $c['site_name']), 'c' => \App\Services\PostCoverage::detail($c),
            'report' => \App\Services\ShiftEndReport::build($c), 'canSend' => UserAuth::can('rondas', 'editar'),
        ], 'layouts/app'));
    }

    /** Reporte de fin de turno de una franja: enviarlo ahora (para probar el email o reenviarlo a RRHH). */
    public function postReport(Request $request, string $uuid): Response
    {
        $c = \App\Services\PostCoverage::find($uuid);
        if ($c === null) return new Response('', 404);
        $n = \App\Services\ShiftEndReport::sendNow($c);
        Flash::add($n > 0 ? 'success' : 'warning', $n > 0
            ? "Reporte enviado por email a {$n} destino(s). Revisá la bandeja (y spam). El estado de cada envío está en la cola de envíos."
            : 'No salió ningún email: la regla "Reporte de fin de turno (RRHH)" no tiene destinatarios con email, o está desactivada. Revisala en Configuración → Notificaciones y alertas.');
        return Response::redirect('/panel/rondas/turnos/puesto/' . $uuid);
    }

    /** Reporte de cobertura de los puestos (horas a cubrir vs. cubiertas, huecos). */
    public function coverage(Request $request): Response
    {
        [$f, $rows] = $this->coverageFilters($request);
        if ($request->input('exportar') === '1' && UserAuth::can('rondas', 'exportar')) {
            Audit::tenant('shifts.coverage_export', 'guard_shift', null, null, ['desde' => $f['desde'], 'hasta' => $f['hasta']]);
            $out = "\u{FEFF}Puesto;Predio;Franja;Horas a cubrir;Horas cubiertas;% cubierto;Huecos justificados (h);Huecos sin justificar (h);Guardias\r\n";
            $h = fn (int $sec) => number_format($sec / 3600, 2, ',', '');
            foreach ($rows as $p) foreach ($p['rows'] as $c) {
                $j = array_sum(array_map(fn ($g) => $g['justified'] ? $g['seconds'] : 0, $c['gaps']));
                $u = array_sum(array_map(fn ($g) => $g['justified'] ? 0 : $g['seconds'], $c['gaps']));
                $names = implode(', ', array_unique(array_map(fn ($sg) => $sg['user_name'], $c['segments'])));
                $out .= implode(';', array_map(fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"', [$p['name'] ?: $p['site_name'], $p['site_name'],
                    fecha($c['start_at'], 'd/m/Y H:i') . ' a ' . fecha($c['end_at'], 'd/m/Y H:i'), $h($c['elapsed']), $h($c['covered']),
                    $c['percent'] ?? '', $h($j), $h($u), $names])) . "\r\n";
            }
            return new Response($out, 200, ['Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"cobertura-{$f['desde']}-a-{$f['hasta']}.csv\""]);
        }
        return Response::html(View::render('panel/shifts/coverage', [
            'title' => 'Cobertura de puestos', 'filters' => $f, 'posts' => $rows, 'sites' => \App\Models\Sites::list(null, true),
            'canExport' => UserAuth::can('rondas', 'exportar'),
        ], 'layouts/app'));
    }

    private function coverageFilters(Request $request): array
    {
        $today = $this->localToday();
        $f = ['desde' => $this->date($request, 'desde', date('Y-m-d', strtotime($today . ' -6 days'))), 'hasta' => $this->date($request, 'hasta', $today),
            'predio' => (string) $request->input('predio', '')];
        if ($f['hasta'] < $f['desde']) $f['hasta'] = $f['desde'];
        $site = $f['predio'] !== '' ? \App\Models\Sites::findByUuid($f['predio']) : null;
        return [$f, \App\Services\PostCoverage::report($f['desde'], $f['hasta'], $site ? (int) $site['id'] : null)];
    }

    // ── un turno: alta suelta, edición y baja ────────────────────

    public function slotForm(Request $request, ?string $uuid = null): Response
    {
        $slot = $uuid ? ShiftService::find($uuid) : null;
        if ($uuid && !$slot) return new Response('', 404);
        $old = Session::get('slot_old');
        Session::forget('slot_old');
        return Response::html(View::render('panel/shifts/slot_form', [
            'title' => $slot ? 'Editar turno' : 'Nuevo turno', 'slot' => $slot, 'old' => $old, 'guards' => $this->guards(),
            'sites' => \App\Models\Sites::list(), 'defaultGrace' => ShiftPlanner::defaultGrace(),
            'day' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('fecha', '')) ? (string) $request->input('fecha') : $this->localToday(),
        ], 'layouts/app'));
    }

    public function slotStore(Request $request, ?string $uuid = null): Response
    {
        $slot = $uuid ? ShiftService::find($uuid) : null;
        if ($uuid && !$slot) return new Response('', 404);
        try {
            $saved = ShiftService::adminSave($slot, $request->post);
            Flash::add('success', $slot ? 'Turno actualizado.' : 'Turno cargado.');
            return Response::redirect('/panel/rondas/turno/' . $saved['uuid']);
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
            Session::put('slot_old', $request->post);
            return Response::redirect($slot ? '/panel/rondas/turno/' . $slot['uuid'] . '/editar' : '/panel/rondas/turno/nuevo');
        }
    }

    public function slotDelete(Request $request, string $uuid): Response
    {
        $slot = ShiftService::find($uuid);
        if (!$slot) return new Response('', 404);
        try {
            ShiftService::adminCancel($slot, (string) $request->input('note', ''));
            Flash::add('success', 'Turno eliminado (anulado): no cuenta en las horas ni genera avisos. Queda el registro con el motivo.');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return Response::redirect('/panel/rondas/turno/' . $uuid);
    }

    public function scheduleDelete(Request $request, string $uuid): Response
    {
        if ($shift = ShiftPlanner::find($uuid)) {
            ShiftPlanner::delete($shift);
            Flash::add('success', 'Programación eliminada: se anularon sus turnos futuros. Los turnos ya hechos quedan.');
        }
        return Response::redirect('/panel/rondas/turnos/programacion');
    }

    // ── programación ─────────────────────────────────────────────

    public function schedules(Request $request): Response
    {
        return Response::html(View::render('panel/shifts/schedules', [
            'title' => 'Programación de turnos', 'shifts' => ShiftPlanner::all(), 'canEdit' => ShiftAbsences::canManage(), 'sites' => \App\Models\Sites::list(),
        ], 'layouts/app'));
    }

    public function form(Request $request, ?string $uuid = null): Response
    {
        $shift = $uuid ? ShiftPlanner::find($uuid) : null;
        if ($uuid && !$shift) return new Response('', 404);
        $userUuids = [];
        foreach ($shift ? ShiftPlanner::userIds($shift) : [] as $id) {
            if ($u = Users::findById($id)) $userUuids[] = $u['uuid'];
        }
        $old = Session::get('shift_old');
        Session::forget('shift_old');
        return Response::html(View::render('panel/shifts/form', [
            'title' => $shift ? 'Editar turno' : 'Nuevo turno', 'shift' => $shift, 'userUuids' => $userUuids, 'old' => $old,
            'sites' => \App\Models\Sites::list(),
            'guards' => array_values(array_filter($this->guards(), fn ($u) => (int) ($u['no_login'] ?? 0) !== 1)), 'defaultGrace' => ShiftPlanner::defaultGrace(),
            'employees' => \App\Services\PostGuards::list(), 'employeeUuids' => array_values(\App\Services\PostGuards::employeeUuids($shift ? ShiftPlanner::userIds($shift) : [])),
            'devices' => array_values(array_filter(Users::accounts(), fn ($u) => (int) $u['is_active'] === 1)),
        ], 'layouts/app'));
    }

    /** Guardias del celular compartido = empleados con PIN (sin usuario). */
    public function guardsPage(Request $request): Response
    {
        $q = trim((string) $request->input('q', ''));
        return Response::html(View::render('panel/shifts/guards', [
            'title' => 'Guardias y PIN', 'q' => $q, 'rows' => \App\Services\PostGuards::list($q),
        ], 'layouts/app'));
    }

    public function guardPin(Request $request, string $uuid): Response
    {
        $emp = \App\Models\Employees::findByUuid($uuid);
        $back = '/panel/rondas/turnos/guardias' . ($request->input('q') ? '?q=' . rawurlencode((string) $request->input('q')) : '');
        if ($emp === null || $emp['deleted_at'] !== null) return Response::redirect($back);
        $remove = (string) $request->input('quitar', '') === '1';
        try {
            \App\Services\PostGuards::setPin($emp, $remove ? null : trim((string) $request->input('pin', '')));
        } catch (UserError $e) {
            Flash::add('danger', $emp['first_name'] . ' ' . $emp['last_name'] . ': ' . $e->getMessage());
            return Response::redirect($back);
        }
        Flash::add('success', $remove ? 'PIN quitado.' : 'PIN guardado para ' . $emp['first_name'] . ' ' . $emp['last_name'] . '. Llega al celular del puesto en la próxima sincronización.');
        return Response::redirect($back);
    }

    public function store(Request $request, ?string $uuid = null): Response
    {
        $shift = $uuid ? ShiftPlanner::find($uuid) : null;
        if ($uuid && !$shift) return new Response('', 404);
        [$data, $errors] = ShiftPlanner::validate($request->post);
        if ($errors) {
            foreach ($errors as $e) Flash::add('danger', $e);
            Session::put('shift_old', $request->post);
            return Response::redirect('/panel/rondas/turnos/programacion/' . ($uuid ?? 'nuevo'));
        }
        ShiftPlanner::save($shift, $data);
        Flash::add('success', $shift ? 'Turno actualizado: los turnos futuros se recalcularon.' : 'Turno creado: ya se generaron los próximos días.');
        return Response::redirect('/panel/rondas/turnos/programacion');
    }

    public function toggle(Request $request, string $uuid): Response
    {
        if ($shift = ShiftPlanner::find($uuid)) {
            ShiftPlanner::setActive($shift, (int) $shift['is_active'] !== 1);
            Flash::add('success', (int) $shift['is_active'] === 1 ? 'Turno pausado: no se generan más días.' : 'Turno activado.');
        }
        return Response::redirect('/panel/rondas/turnos/programacion');
    }

    // ── predios (centro y radio para el control de salida) ───────

    public function site(Request $request, string $uuid): Response
    {
        $site = \App\Models\Sites::findByUuid($uuid);
        if ($site === null) return new Response('', 404);
        return Response::html(View::render('panel/shifts/site', ['title' => 'Área del predio', 'site' => $site,
            'points' => \App\Models\Patrols::points(), 'tolerance' => ShiftService::toleranceM()], 'layouts/app'));
    }

    public function saveSite(Request $request, string $uuid): Response
    {
        $site = \App\Models\Sites::findByUuid($uuid);
        if ($site === null) return new Response('', 404);
        $lat = (float) str_replace(',', '.', (string) $request->input('lat', ''));
        $lng = (float) str_replace(',', '.', (string) $request->input('lng', ''));
        $radius = max(20, min(5000, (int) $request->input('radius', 200)));
        if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) {
            Flash::add('danger', 'Tocá el mapa para marcar el centro del predio.');
            return Response::redirect('/panel/rondas/turnos/predio/' . $uuid);
        }
        \App\Models\Sites::update((int) $site['id'], ['lat' => $lat, 'lng' => $lng, 'geofence_radius_m' => $radius]);
        Audit::tenant('site.geofence', 'site', $uuid, ['lat' => $site['lat'], 'lng' => $site['lng'], 'radio' => $site['geofence_radius_m']],
            ['lat' => $lat, 'lng' => $lng, 'radio' => $radius]);
        Flash::add('success', 'Área del predio guardada: los celulares la reciben en la próxima sincronización.');
        return Response::redirect('/panel/rondas/turnos/programacion');
    }

    // ── ausencias y días sin servicio ────────────────────────────

    public function absences(Request $request): Response
    {
        $only = $this->onlyUser();
        return Response::html(View::render('panel/shifts/absences', [
            'title' => 'Ausencias de guardias', 'pending' => $only === null ? ShiftAbsences::list('solicitada') : ShiftAbsences::list('solicitada', $only),
            'current' => ShiftAbsences::list('vigentes', $only), 'history' => ShiftAbsences::list(null, $only, 60),
            'closures' => ShiftAbsences::closuresList(), 'guards' => $this->guards(), 'canEdit' => ShiftAbsences::canManage(),
            'sites' => \App\Models\Sites::list(), 'today' => $this->localToday(),
            'editing' => ShiftAbsences::canManage() && ($e = ShiftAbsences::find((string) $request->input('editar', ''))) !== null
                && in_array($e['status'], ['solicitada', 'aprobada'], true) ? $e : null,
        ], 'layouts/app'));
    }

    private function back(): Response
    {
        return Response::redirect('/panel/rondas/turnos/ausencias');
    }

    public function storeAbsence(Request $request): Response
    {
        try {
            ShiftAbsences::createApproved($request->post);
            Flash::add('success', 'Ausencia cargada: sus turnos quedaron justificados.');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return $this->back();
    }

    public function decide(Request $request, string $uuid): Response
    {
        $a = ShiftAbsences::find($uuid);
        if ($a === null) return new Response('', 404);
        $approve = $request->input('decision') === 'aprobar';
        try {
            ShiftAbsences::decide($a, $approve, (string) $request->input('note', ''), (string) $request->input('replacement', '') ?: null);
            Flash::add('success', $approve ? 'Ausencia aprobada: se le avisó al guardia.' : 'Ausencia rechazada: se le avisó al guardia.');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return $this->back();
    }

    public function updateAbsence(Request $request, string $uuid): Response
    {
        $a = ShiftAbsences::find($uuid);
        if ($a === null) return new Response('', 404);
        try {
            ShiftAbsences::update($a, $request->post);
            Flash::add('success', $a['status'] === 'aprobada' ? 'Ausencia actualizada: los turnos futuros se rearmaron con las fechas nuevas.' : 'Ausencia actualizada.');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
            return Response::redirect('/panel/rondas/turnos/ausencias?editar=' . $uuid . '#cargar');
        }
        return $this->back();
    }

    public function cancelAbsence(Request $request, string $uuid): Response
    {
        $a = ShiftAbsences::find($uuid);
        if ($a === null) return new Response('', 404);
        try {
            ShiftAbsences::cancel($a);
            Flash::add('success', 'Ausencia cancelada: los turnos futuros vuelven a estar programados.');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return $this->back();
    }

    public function storeClosure(Request $request): Response
    {
        $site = $request->input('site') ? \App\Models\Sites::findByUuid((string) $request->input('site')) : null;
        try {
            ShiftAbsences::addClosure($site ? (int) $site['id'] : null, (string) $request->input('day', ''), (string) $request->input('reason', ''));
            Flash::add('success', 'Día sin servicio cargado: ese día no hay turnos ni avisos.');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return $this->back();
    }

    public function deleteClosure(Request $request, string $uuid): Response
    {
        try {
            ShiftAbsences::removeClosure($uuid);
            Flash::add('success', 'Día sin servicio quitado: los turnos de ese día se vuelven a generar.');
        } catch (UserError $e) {
            Flash::add('danger', $e->getMessage());
        }
        return $this->back();
    }

    // ── horas trabajadas ─────────────────────────────────────────

    private function hoursFilters(Request $request): array
    {
        $today = $this->localToday();
        $f = ['desde' => $this->date($request, 'desde', date('Y-m-01', strtotime($today))), 'hasta' => $this->date($request, 'hasta', $today),
            'guardia' => (string) $request->input('guardia', ''), 'predio' => (string) $request->input('predio', '')];
        if ($f['hasta'] < $f['desde']) $f['hasta'] = $f['desde'];
        $guard = $f['guardia'] !== '' ? Users::findByUuid($f['guardia']) : null;
        $site = $f['predio'] !== '' ? \App\Models\Sites::findByUuid($f['predio']) : null;
        $report = ShiftReport::summary($f['desde'], $f['hasta'], $this->onlyUser() ?? ($guard ? (int) $guard['id'] : null), $site ? (int) $site['id'] : null);
        return [$f, $report];
    }

    public function hours(Request $request): Response
    {
        [$f, $report] = $this->hoursFilters($request);
        return Response::html(View::render('panel/shifts/hours', [
            'title' => 'Horas trabajadas', 'filters' => $f, 'r' => $report, 'guards' => $this->onlyUser() === null ? $this->guards() : [],
            'sites' => \App\Models\Sites::list(null, true), 'canExport' => UserAuth::can('rondas', 'exportar'),
        ], 'layouts/app'));
    }

    public function export(Request $request): Response
    {
        [$f, $report] = $this->hoursFilters($request);
        $type = $request->input('tipo') === 'turnos' ? 'turnos' : 'guardias';
        Audit::tenant('shifts.export', 'guard_shift_slot', null, null, ['tipo' => $type, 'desde' => $f['desde'], 'hasta' => $f['hasta']]);
        return new Response(ShiftReport::csv($report, $type), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"horas-{$type}-{$f['desde']}-a-{$f['hasta']}.csv\""]);
    }
}
