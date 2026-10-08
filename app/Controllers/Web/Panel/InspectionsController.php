<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\View;
use App\Models\Equipment;
use App\Models\InspectionSchedules;
use App\Models\InspectionTemplates;
use App\Models\Inspections;
use App\Models\Sectors;
use App\Models\Users;
use App\Services\ActionService;
use App\Services\InspectionPlanner;
use App\Services\InspectionService;
use App\Services\UserAuth;

/** Inspecciones y checklists (Etapa 11). */
final class InspectionsController
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        [$filters, $form] = $this->filters($request);
        $scope = InspectionService::scope();
        $page = max(1, (int) $request->input('pagina', 1));
        return Response::html(View::render('panel/inspections/index', [
            'title'     => 'Inspecciones',
            'rows'      => Inspections::search($filters, $scope, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total'     => Inspections::count($filters, $scope),
            'byResult'  => Inspections::countByResult($filters, $scope),
            'form'      => $form,
            'page'      => $page,
            'perPage'   => self::PER_PAGE,
            'templates' => InspectionTemplates::all(),
            'sectors'   => Sectors::options(),
        ], 'layouts/app'));
    }

    /** Sin plantilla: elegir checklist (y equipo). Con plantilla: el formulario del checklist. */
    public function create(Request $request): Response
    {
        $template = InspectionTemplates::findByUuid((string) $request->input('plantilla', ''));
        $equipment = Equipment::findByUuid((string) $request->input('equipo', ''));
        if ($template === null || !$template['is_active'] || ($template['scope'] === 'equipo' && $equipment === null)) {
            return Response::html(View::render('panel/inspections/choose', [
                'title'     => 'Nueva inspección',
                'templates' => InspectionTemplates::all(true),
                'template'  => $template,
                'equipment' => $template && $template['scope'] === 'equipo'
                    ? Equipment::list(null, false, ['type_id' => (int) $template['equipment_type_id']], 1000) : [],
            ], 'layouts/app'));
        }
        [$old, $errors] = Flash::pullInput();
        $schedule = InspectionSchedules::findByUuid((string) $request->input('programada', $old['schedule'] ?? ''));
        return Response::html(View::render('panel/inspections/form', [
            'title'     => $template['name'],
            'template'  => $template,
            'schedule'  => $schedule && $schedule['status'] === 'pendiente' ? $schedule : null,
            'preSector' => (string) $request->input('sector', ''),
            'version'   => InspectionTemplates::version((int) $template['current_version_id']),
            'equipment' => $equipment,
            'sectors'   => Sectors::options(),
            'old'       => $old,
            'errors'    => $errors,
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $in = $request->post;
        $result = InspectionService::create($in, $this->uploadedPhotos($request));
        if ($result['inspection'] === null) {
            unset($in['csrf_token']);
            Flash::add('danger', 'Revisá el checklist: ' . count($result['errors']) . ' dato(s) por completar.');
            Flash::withInput($in, $result['errors']);
            return Response::redirect('/panel/inspecciones/nueva?' . http_build_query(array_filter(['plantilla' => $in['template'] ?? '', 'equipo' => $in['equipment'] ?? '',
                'programada' => $in['schedule'] ?? ''])));
        }
        $i = $result['inspection'];
        foreach ($result['photo_errors'] ?? [] as $err) {
            Flash::add('warning', 'No se guardó una foto: ' . $err);
        }
        $msg = 'Inspección ' . Inspections::format((int) $i['number']) . ' registrada: ' . InspectionService::RESULTS[$i['result']]['label'] . '.';
        if ((int) $i['items_fail'] > 0) {
            $msg .= ' Se crearon ' . $i['items_fail'] . ' acción(es) correctiva(s).';
        }
        Flash::add($i['result'] === 'no_conforme_critico' ? 'danger' : ($i['result'] === 'conforme' ? 'success' : 'warning'), $msg);
        return Response::redirect('/panel/inspecciones/' . $i['uuid']);
    }

    public function show(Request $request, string $uuid): Response
    {
        $i = $this->find($uuid);
        if ($i === null) {
            return self::notFound();
        }
        return Response::html(View::render('panel/inspections/show', $this->detail($i) + ['title' => Inspections::format((int) $i['number'])], 'layouts/app'));
    }

    public function printable(Request $request, string $uuid): Response
    {
        $i = $this->find($uuid);
        return $i === null ? self::notFound() : Response::html(View::render('panel/inspections/print', $this->detail($i), null));
    }

    public function annul(Request $request, string $uuid): Response
    {
        $i = $this->find($uuid);
        if ($i === null) {
            return self::notFound();
        }
        $error = InspectionService::annul($i, (string) $request->input('reason', ''));
        Flash::add($error ? 'danger' : 'success', $error ?? 'Inspección anulada. Las acciones que generó siguen abiertas: cancelalas si corresponde.');
        return Response::redirect('/panel/inspecciones/' . $uuid);
    }

    public function photo(Request $request, string $uuid, string $attachment): Response
    {
        $i = $this->find($uuid);
        $att = $i ? Inspections::attachment((int) $i['id'], $attachment) : null;
        if ($att === null) {
            return new Response('', 404);
        }
        $path = $request->input('t') === '1' && $att['thumb_path'] ? $att['thumb_path'] : $att['path'];
        $file = TenantFiles::path(Tenant::current()['uuid'], $path);
        return $file ? TenantFiles::response($file, 3600) : new Response('', 404);
    }

    /** Inspecciones programadas: de hoy, vencidas, próximas, hechas y omitidas. */
    public function scheduled(Request $request): Response
    {
        $today = ActionService::today();
        $tab = (string) $request->input('ver', 'hoy');
        $mine = $request->input('mias') === '1';
        $base = $mine ? ['mine' => InspectionPlanner::mine()] : [];
        $tabs = [
            'hoy'      => ['Para hacer hoy', ['status' => 'pendiente', 'due_from_max' => $today, 'due_on_from' => $today]],
            'vencidas' => ['Vencidas', ['status' => 'pendiente', 'due_on_lt' => $today]],
            'proximas' => ['Próximas', ['status' => 'pendiente', 'due_on_from' => (new \DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d')]],
            'hechas'   => ['Hechas (30 días)', ['status' => 'hecha', 'due_on_from' => (new \DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d')]],
            'omitidas' => ['Omitidas (30 días)', ['status' => 'omitida', 'due_on_from' => (new \DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d')]],
        ];
        $tab = isset($tabs[$tab]) ? $tab : 'hoy';
        $scope = InspectionPlanner::scope();
        return Response::html(View::render('panel/inspections/scheduled', [
            'title'  => 'Inspecciones programadas',
            'tabs'   => array_map(fn ($t) => [$t[0], InspectionSchedules::count($t[1] + $base, $scope)], $tabs),
            'tab'    => $tab,
            'mine'   => $mine,
            'rows'   => InspectionSchedules::search($tabs[$tab][1] + $base, $scope, 300),
            'today'  => $today,
        ], 'layouts/app'));
    }

    public function skip(Request $request, string $uuid): Response
    {
        $s = InspectionSchedules::findByUuid($uuid);
        if ($s === null || InspectionSchedules::count(['id' => (int) $s['id']], InspectionPlanner::scope()) === 0) {
            return self::notFound(); // no existe o está fuera de su alcance
        }
        $error = InspectionPlanner::skip($s, (string) $request->input('reason', ''));
        Flash::add($error ? 'danger' : 'success', $error ?? 'Inspección omitida. Figura aparte en el cumplimiento.');
        return Response::redirect('/panel/inspecciones/programadas?ver=' . ($s['due_on'] < ActionService::today() ? 'vencidas' : 'hoy'));
    }

    /** Cumplimiento: hechas a tiempo / programadas (sin contar las omitidas), por mes. */
    public function compliance(Request $request): Response
    {
        $today = ActionService::today();
        $month = (string) $request->input('mes', substr($today, 0, 7));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = substr($today, 0, 7);
        }
        $from = $month . '-01';
        $to = (new \DateTimeImmutable($from))->format('Y-m-t');
        $group = in_array($request->input('por'), ['program', 'sector', 'equipment', 'inspector'], true) ? (string) $request->input('por') : 'program';
        $scope = InspectionPlanner::scope();
        $rows = InspectionSchedules::compliance($from, $to, $today, $group, $scope);
        $totals = InspectionSchedules::compliance($from, $to, $today, 'program', $scope);
        if ($request->input('csv') === '1' && UserAuth::can(InspectionService::MODULE, 'exportar')) {
            return new Response(self::complianceCsv($rows, $group), 200, [
                'Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="cumplimiento-inspecciones-' . $month . '.csv"',
            ]);
        }
        return Response::html(View::render('panel/inspections/compliance', [
            'title'  => 'Cumplimiento de inspecciones',
            'rows'   => $rows,
            'total'  => self::sum($totals),
            'month'  => $month,
            'group'  => $group,
            'months' => array_map(fn ($i) => (new \DateTimeImmutable($today))->modify("first day of -{$i} month")->format('Y-m'), range(0, 11)),
        ], 'layouts/app'));
    }

    /** CSV de inspecciones con los filtros de la lista. */
    public function export(Request $request): Response
    {
        [$filters] = $this->filters($request);
        $rows = Inspections::search($filters, InspectionService::scope(), 5000);
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Número', 'Fecha', 'Checklist', 'Versión', 'Equipo', 'Sector', 'Inspector', 'Resultado', 'Cumplimiento %', 'No cumplen', 'Críticos', 'Estado'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [Inspections::format((int) $r['number']), fecha($r['done_at_device'], 'd/m/Y H:i'), $r['template_name'], $r['template_version'],
                $r['equipment_code'] ? $r['equipment_code'] . ' · ' . $r['equipment_name'] : '', $r['sector_name'] ?? '', $r['inspector_name'] ?? '',
                InspectionService::RESULTS[$r['result']]['label'] ?? $r['result'], $r['score'] ?? '', $r['items_fail'], $r['items_critical_fail'], $r['status']], ';');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        \App\Services\Audit::tenant('inspection.export', 'inspection', null, null, ['filas' => count($rows)]);
        return new Response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="inspecciones-' . fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d') . '.csv"']);
    }

    /** % de cumplimiento: a tiempo / (programadas que ya vencieron - omitidas). */
    public static function percent(array $r): ?int
    {
        $base = (int) $r['on_time'] + (int) $r['late'] + (int) $r['missed'];
        return $base > 0 ? (int) round(100 * (int) $r['on_time'] / $base) : null;
    }

    private static function sum(array $rows): array
    {
        $t = ['total' => 0, 'on_time' => 0, 'late' => 0, 'missed' => 0, 'skipped' => 0, 'open_today' => 0];
        foreach ($rows as $r) {
            foreach ($t as $k => $_) {
                $t[$k] += (int) $r[$k];
            }
        }
        return $t;
    }

    private static function complianceCsv(array $rows, string $group): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [['program' => 'Programa', 'sector' => 'Sector', 'equipment' => 'Equipo', 'inspector' => 'Inspector'][$group],
            'Programadas', 'A tiempo', 'Tarde', 'No hechas', 'Omitidas', 'Cumplimiento %'], ';');
        foreach ($rows as $r) {
            fputcsv($out, [$r['label'] ?? 'Sin dato', $r['total'], $r['on_time'], $r['late'], $r['missed'], $r['skipped'], self::percent($r) ?? ''], ';');
        }
        rewind($out);
        return (string) stream_get_contents($out);
    }

    /** Ficha de campo de un equipo (adonde lleva su QR): checklists, últimas inspecciones, reportar. */
    public function equipment(Request $request, string $uuid): Response
    {
        $eq = Equipment::findByUuid($uuid);
        if ($eq === null) {
            return self::notFound();
        }
        $latest = UserAuth::can(InspectionService::MODULE, 'ver')
            ? array_values(array_filter(Inspections::latestForEquipment((int) $eq['id'], 10), [InspectionService::class, 'canView'])) : [];
        return Response::html(View::render('panel/inspections/equipment', [
            'title'     => $eq['code'] . ' · ' . $eq['name'],
            'eq'        => $eq,
            'type'      => $eq['type_id'] ? \App\Models\CatalogItems::findById((int) $eq['type_id']) : null,
            'sector'    => $eq['sector_id'] ? (Sectors::labelMap()[(int) $eq['sector_id']]['label'] ?? null) : null,
            'templates' => InspectionTemplates::forEquipmentType($eq['type_id'] ? (int) $eq['type_id'] : null),
            'latest'    => array_slice($latest, 0, 5),
        ], 'layouts/app'));
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function detail(array $i): array
    {
        $answers = Inspections::answers((int) $i['id']);
        $photos = [];
        foreach (Inspections::attachments((int) $i['id']) as $p) {
            $photos[$p['item_key'] ?? ''][] = $p;
        }
        $version = InspectionTemplates::version((int) $i['template_version_id']);
        $units = [];
        foreach (\App\Services\InspectionStructure::items($version['structure']) as ['item' => $item]) {
            $units[$item['key']] = $item['unit'] ?? null;
        }
        return [
            'i'        => $i,
            'answers'  => $answers,
            'photos'   => $photos,
            'units'    => $units,
            'events'   => Inspections::events((int) $i['id']),
            'hashOk'   => hash('sha256', $i['original_data']) === $i['original_hash'],
        ];
    }

    private function find(string $uuid): ?array
    {
        $i = Inspections::findByUuid($uuid);
        return $i !== null && InspectionService::canView($i) ? $i : null;
    }

    /** $_FILES['photos'][item_key][] → [item_key => [fotos]] */
    private function uploadedPhotos(Request $request): array
    {
        $files = $request->files['photos'] ?? null;
        if (!is_array($files) || !isset($files['tmp_name']) || !is_array($files['tmp_name'])) {
            return [];
        }
        $out = [];
        foreach ($files['tmp_name'] as $key => $list) {
            foreach ((array) $list as $n => $tmp) {
                if (($files['error'][$key][$n] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
                    $out[(string) $key][] = ['tmp' => $tmp, 'name' => $files['name'][$key][$n] ?? null, 'upload' => true];
                }
            }
        }
        return array_map(fn ($l) => array_slice($l, 0, 5), $out);
    }

    private function filters(Request $request): array
    {
        $form = [];
        foreach (['q', 'plantilla', 'resultado', 'sector', 'desde', 'hasta', 'mias', 'anuladas'] as $k) {
            $form[$k] = (string) $request->input($k, '');
        }
        $f = ['q' => $form['q'], 'status' => $form['anuladas'] === '1' ? 'anulada' : 'completa'];
        if ($form['plantilla'] !== '' && ($t = InspectionTemplates::findByUuid($form['plantilla'])) !== null) {
            $f['template_id'] = (int) $t['id'];
        }
        if (isset(InspectionService::RESULTS[$form['resultado']])) {
            $f['result'] = $form['resultado'];
        }
        if ($form['sector'] !== '' && ($s = Sectors::findByUuid($form['sector'])) !== null) {
            $f['sector_ids'] = Sectors::withDescendants([(int) $s['id']]);
        }
        if ($form['mias'] === '1' && UserAuth::user()) {
            $f['inspector_user_id'] = (int) UserAuth::user()['id'];
        }
        $tz = new \DateTimeZone(Tenant::timezone() ?? 'UTC');
        foreach (['desde' => 'from', 'hasta' => 'to'] as $k => $col) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $form[$k], $tz);
            if ($d) {
                $f[$col] = ($k === 'hasta' ? $d->modify('+1 day') : $d)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        return [$f, $form];
    }

    private static function notFound(): Response
    {
        return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
    }
}
