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
use App\Models\WorkPermits;
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

    /** autorizar | rechazar | iniciar | cerrar | recibir | cancelar */
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
            default     => 'Paso desconocido.',
        };
        $done = ['autorizar' => 'Permiso autorizado.', 'rechazar' => 'Permiso rechazado: se avisó al solicitante.', 'iniciar' => 'Trabajo iniciado.',
            'cerrar' => 'Permiso cerrado.', 'recibir' => 'Área recibida.', 'cancelar' => 'Permiso cancelado.'];
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

    private function detail(array $p): array
    {
        return [
            'p'          => $p,
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
