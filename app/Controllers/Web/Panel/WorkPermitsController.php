<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\View;
use App\Models\Contractors;
use App\Models\Employees;
use App\Models\Equipment;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\WorkPermits;
use App\Services\Audit;
use App\Services\UserAuth;
use App\Services\WorkPermitControls;
use App\Services\WorkPermitService;

/** Permisos de trabajo (Etapa 13): activos ahora, solicitar, autorizar, iniciar, cerrar. */
final class WorkPermitsController
{
    /** Tablero: activos ahora (por planta), para autorizar y recientes. Se refresca solo. */
    public function index(Request $request): Response
    {
        $scope = WorkPermitService::scope();
        $active = WorkPermits::search(['status' => WorkPermits::ACTIVE], $scope, 300);
        $bySite = [];
        foreach ($active as $p) {
            $bySite[$p['site_name'] ?? 'Sin planta'][] = $p;
        }
        ksort($bySite);
        return Response::html(View::render('panel/permits/index', [
            'title'   => 'Permisos de trabajo',
            'bySite'  => $bySite,
            'pending' => WorkPermits::search(['status' => 'solicitado'], $scope, 100),
            'recent'  => WorkPermits::search(['status' => ['cerrado', 'vencido', 'rechazado', 'cancelado']], $scope, 30),
        ], 'layouts/app'));
    }

    /** Historial con filtros (estado, tipo, planta, sector, fechas, texto). */
    public function list(Request $request): Response
    {
        [$f, $form] = $this->filters($request);
        $scope = WorkPermitService::scope();
        return Response::html(View::render('panel/permits/list', [
            'title'   => 'Permisos de trabajo · historial',
            'rows'    => WorkPermits::search($f, $scope, 300),
            'total'   => WorkPermits::count($f, $scope),
            'form'    => $form,
            'sites'   => Sites::options(),
            'sectors' => Sectors::options(),
            'canExport' => UserAuth::can(WorkPermitService::MODULE, 'exportar'),
        ], 'layouts/app'));
    }

    public function export(Request $request): Response
    {
        [$f] = $this->filters($request);
        $rows = WorkPermits::search($f, WorkPermitService::scope(), 5000);
        Audit::tenant('permit.export', 'work_permit', null, null, ['filas' => count($rows)]);
        return new Response(WorkPermitService::csv($rows), 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="permisos-' . fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d') . '.csv"',
        ]);
    }

    public function create(Request $request): Response
    {
        [$old, $errors] = Flash::pullInput();
        $equipment = Equipment::findByUuid((string) $request->input('equipo', ''));
        $now = time();
        return Response::html(View::render('panel/permits/form', [
            'title'       => 'Solicitar permiso de trabajo',
            'old'         => $old + ['types' => [], 'sector' => '', 'equipment' => $equipment['uuid'] ?? '', 'location_text' => '', 'task' => '', 'contractor' => '',
                'valid_from' => fecha(gmdate('Y-m-d H:i:s', $now), 'Y-m-d\TH:i'), 'valid_until' => fecha(gmdate('Y-m-d H:i:s', $now + 4 * 3600), 'Y-m-d\TH:i'),
                'workers' => [], 'checklists' => []],
            'errors'      => $errors,
            'templates'   => WorkPermitService::checklistTemplates(),
            'sectors'     => Sectors::options(),
            'equipment'   => array_map(fn ($e) => $e['code'] . ' · ' . $e['name'], array_column(Equipment::list(null, false, [], 3000), null, 'uuid')),
            'contractors' => Contractors::options(),
            'employees'   => Employees::options(),
            'maxHours'    => WorkPermitService::maxHours(),
            'fireWatch'   => (int) \App\Models\Settings::get('permisos.vigia_minutos', '30'),
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        $in = $request->post;
        $in['workers'] = array_values((array) ($in['workers'] ?? []));
        $result = WorkPermitService::request($in, $this->meta($request));
        if ($result['permit'] === null) {
            unset($in['csrf_token'], $in['signature']);
            Flash::add('danger', 'Revisá la solicitud: ' . implode(' ', array_slice($result['errors'], 0, 4)) . (count($result['errors']) > 4 ? ' …' : ''));
            Flash::withInput($in, $result['errors']);
            return Response::redirect('/panel/permisos/nuevo');
        }
        $p = $result['permit'];
        Flash::add((int) $p['critical_fails'] > 0 ? 'warning' : 'success', WorkPermits::format((int) $p['number']) . ' solicitado. '
            . ((int) $p['critical_fails'] > 0 ? 'Ojo: hay ítems críticos sin cumplir, así no se va a poder autorizar.' : 'Se avisó a quienes autorizan.'));
        if (($n = count(WorkPermits::conflicts($p))) > 0) {
            Flash::add('warning', "Hay {$n} permiso(s) más en el mismo lugar y horario: revisalos en el detalle.");
        }
        return Response::redirect('/panel/permisos/' . $p['uuid']);
    }

    public function show(Request $request, string $uuid): Response
    {
        $p = $this->find($uuid);
        if ($p === null) {
            return self::notFound();
        }
        return Response::html(View::render('panel/permits/show', $this->detail($p) + ['title' => WorkPermits::format((int) $p['number'])], 'layouts/app'));
    }

    /** autorizar | rechazar | iniciar | cerrar | recibir | cancelar | medir | bloquear | desbloquear | suspender | reanudar | extender */
    public function step(Request $request, string $uuid, string $step): Response
    {
        $p = $this->find($uuid);
        if ($p === null) {
            return self::notFound();
        }
        $sig = (string) $request->input('signature', '');
        $reason = (string) $request->input('comment', '');
        $meta = $this->meta($request);
        $error = match ($step) {
            'autorizar' => WorkPermitService::approve($p, $sig, $meta, $reason),
            'rechazar'  => WorkPermitService::reject($p, $reason),
            'iniciar'   => WorkPermitService::start($p, (array) $request->input('worker_signatures', []), $meta),
            'cerrar'    => WorkPermitService::close($p, $sig, $reason, $meta),
            'recibir'   => WorkPermitService::receive($p, $sig, $meta),
            'cancelar'  => WorkPermitService::cancel($p, $reason),
            'medir'     => $this->measure($p, $request),
            'bloquear'  => WorkPermitControls::isolate($p, $request->post),
            'desbloquear' => WorkPermitControls::release($p, (string) $request->input('isolation', ''), (string) $request->input('removed_by', '')),
            'suspender' => WorkPermitControls::suspend($p, $reason),
            'reanudar'  => WorkPermitControls::resume($p, $reason),
            'extender'  => WorkPermitControls::extend($p, (string) $request->input('until', ''), $sig, $meta),
            default     => 'Paso desconocido.',
        };
        if ($step === 'medir' && is_array($error)) { // la medición devuelve su propio resultado
            Flash::add($error[0], $error[1]);
            return Response::redirect('/panel/permisos/' . $uuid);
        }
        $done = ['autorizar' => 'Permiso autorizado.', 'rechazar' => 'Permiso rechazado: se avisó al solicitante.', 'iniciar' => 'Trabajo iniciado.',
            'cerrar' => 'Permiso cerrado.', 'recibir' => 'Área recibida.', 'cancelar' => 'Permiso cancelado.', 'bloquear' => 'Bloqueo registrado.',
            'desbloquear' => 'Bloqueo retirado.', 'suspender' => 'Trabajo suspendido: se avisó.', 'reanudar' => 'Trabajo reanudado.', 'extender' => 'Permiso extendido.'];
        Flash::add($error ? 'danger' : 'success', $error ?? ($done[$step] ?? 'Listo.'));
        return Response::redirect('/panel/permisos/' . $uuid);
    }

    public function signature(Request $request, string $uuid, string $sig): Response
    {
        $p = $this->find($uuid);
        $s = $p ? WorkPermits::signature((int) $p['id'], $sig) : null;
        $file = $s ? TenantFiles::path(Tenant::current()['uuid'], $s['path']) : null;
        return $file ? TenantFiles::response($file, 3600) : new Response('', 404);
    }

    public function printable(Request $request, string $uuid): Response
    {
        $p = $this->find($uuid);
        return $p === null ? self::notFound() : Response::html(View::render('panel/permits/print', $this->detail($p) + [
            'verifyUrl' => absolute_url('/panel/permisos/' . $p['uuid'] . '/verificar'),
        ], null));
    }

    /** Adonde lleva el QR del permiso colgado en el lugar: ¿está vigente? Cualquier usuario de la empresa. */
    public function verify(Request $request, string $uuid): Response
    {
        $p = WorkPermits::findByUuid($uuid);
        if ($p === null) {
            return self::notFound();
        }
        return Response::html(View::render('panel/permits/verify', [
            'title'   => 'Verificar ' . WorkPermits::format((int) $p['number']),
            'p'       => $p,
            'workers' => WorkPermits::workers((int) $p['id']),
            'valid'   => $p['status'] === 'en_ejecucion' && strtotime($p['ends_at']) > time(),
        ], 'layouts/app'));
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @return array{0: string, 1: string} [tipo de aviso, mensaje] */
    private function measure(array $p, Request $request): array
    {
        $r = WorkPermitControls::measure($p, $request->post);
        if (isset($r['error'])) {
            return ['danger', $r['error']];
        }
        if ($r['out']) {
            return ['danger', 'FUERA DE RANGO: ' . implode('; ', $r['out']) . '.' . ($r['suspended'] ? ' El permiso quedó SUSPENDIDO: evacuar y ventilar.' : ' No ingresar.')
                . ' Se avisó a SyH y a quienes autorizan.'];
        }
        return ['success', 'Medición en rango registrada.'];
    }

    /** @return array{0: array, 1: array} [filtros del modelo, valores del formulario] */
    private function filters(Request $request): array
    {
        $form = [];
        foreach (['estado', 'tipo', 'planta', 'sector', 'desde', 'hasta', 'q', 'mios'] as $k) {
            $form[$k] = trim((string) $request->input($k, ''));
        }
        $f = ['q' => $form['q']];
        if (isset(WorkPermitService::STATES[$form['estado']])) {
            $f['status'] = $form['estado'];
        } elseif ($form['estado'] === 'activos') {
            $f['status'] = WorkPermits::ACTIVE;
        }
        if (isset(WorkPermitService::TYPES[$form['tipo']])) {
            $f['type'] = $form['tipo'];
        }
        if ($form['planta'] !== '' && ($s = Sites::findByUuid($form['planta'])) !== null) {
            $f['site_id'] = (int) $s['id'];
        }
        if ($form['sector'] !== '' && ($s = Sectors::findByUuid($form['sector'])) !== null) {
            $f['sector_ids'] = Sectors::withDescendants([(int) $s['id']]);
        }
        foreach (['desde' => 'from', 'hasta' => 'to'] as $k => $col) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $form[$k])) {
                $day = $k === 'hasta' ? (new \DateTimeImmutable($form[$k]))->modify('+1 day')->format('Y-m-d') : $form[$k];
                $f[$col] = WorkPermitService::parseTime($day . ' 00:00');
            }
        }
        if ($form['mios'] === '1') {
            $f['requested_by'] = (int) (UserAuth::user()['id'] ?? 0);
        }
        return [$f, $form];
    }

    private function detail(array $p): array
    {
        $active = in_array($p['status'], ['solicitado', 'aprobado', 'en_ejecucion', 'suspendido'], true);
        return [
            'p'          => $p,
            'measurements' => WorkPermits::measurements((int) $p['id']),
            'isolations' => WorkPermits::isolations((int) $p['id']),
            'conflicts'  => $active ? array_values(array_filter(WorkPermits::conflicts($p), [WorkPermitService::class, 'canView'])) : [],
            'gasLimits'  => WorkPermitControls::gasLimits(),
            'workers'    => WorkPermits::workers((int) $p['id']),
            'checklists' => WorkPermits::checklists((int) $p['id']),
            'signatures' => WorkPermits::signatures((int) $p['id']),
            'events'     => WorkPermits::events((int) $p['id']),
            'hashOk'     => hash('sha256', $p['original_data']) === $p['original_hash'],
        ];
    }

    private function meta(Request $request): array
    {
        return ['ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'user_agent' => $request->header('user-agent')];
    }

    private function find(string $uuid): ?array
    {
        $p = WorkPermits::findByUuid($uuid);
        return $p !== null && WorkPermitService::canView($p) ? $p : null;
    }

    private static function notFound(): Response
    {
        return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
    }
}
