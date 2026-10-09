<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\View;
use App\Models\Employees;
use App\Models\Positions;
use App\Models\Ppe;
use App\Models\PpeItems;
use App\Models\Sectors;
use App\Models\Settings;
use App\Services\Audit;
use App\Services\IndustryTemplates;
use App\Services\PpeService;
use App\Services\UserAuth;
use App\Services\WorkPermitService;

/** EPP (Etapa 14): empleados y su estado, entregas con firma, constancia SRT 299/11, catálogo y matriz por puesto. */
final class PpeController
{
    /** Empleados propios con el estado de su EPP. */
    public function index(Request $request): Response
    {
        $q = trim((string) $request->input('q', ''));
        $sector = Sectors::findByUuid((string) $request->input('sector', ''));
        $state = (string) $request->input('estado', '');
        $employees = PpeService::employees($q !== '' ? $q : null, $sector ? Sectors::withDescendants([(int) $sector['id']]) : null);
        $summaries = PpeService::summaries($employees);
        $compliance = PpeService::compliance($employees);
        if (isset(PpeService::STATES[$state])) {
            $employees = array_values(array_filter($employees, fn ($e) => $summaries[(int) $e['id']]['overall'] === $state));
        }
        return Response::html(View::render('panel/ppe/index', [
            'title'     => 'EPP',
            'employees' => $employees,
            'summaries' => $summaries,
            'sectors'   => Sectors::options(),
            'form'      => ['q' => $q, 'sector' => $sector['uuid'] ?? '', 'estado' => $state],
            'noMatrix'  => !Ppe::matrix(),
            'compliance'=> $compliance,
        ], 'layouts/app'));
    }

    public function export(Request $request): Response
    {
        $employees = PpeService::employees();
        $c = PpeService::compliance($employees);
        $deliveriesMode = (string)$request->input('tipo','') === 'entregas';
        $lines = [$deliveriesMode ? "Entrega;Fecha;Empleado;DNI;Sector;Motivo;Elementos" : "Empleado;DNI;Sector;Puesto;Estado;Vencidos;Por vencer;Nunca entregados"];
        foreach ($employees as $e) {
            if ($deliveriesMode) { foreach (Ppe::deliveries((int)$e['id'], false) as $d) $lines[] = implode(';', array_map(fn($v) => '"'.str_replace('"','""',(string)$v).'"', [Ppe::format((int)$d['number']), fecha($d['delivered_at'],'d/m/Y H:i'), $e['name'], $e['dni'], $e['sector_name']??'', PpeService::REASONS[$d['reason']]??$d['reason'], implode(', ', array_map(fn($i)=>$i['item_name'].' x'.$i['quantity'], $d['items']??[]))])); continue; }
            $s = $c['summaries'][(int) $e['id']];
            $lines[] = implode(';', array_map(fn($v) => '"' . str_replace('"', '""', (string)$v) . '"', [$e['name'], $e['dni'], $e['sector_name'] ?? '', $e['position_name'] ?? '', $s['overall'], $s['counts']['vencido'], $s['counts']['por_vencer'], $s['counts']['nunca']]));
        }
        return new Response("\xEF\xBB\xBF" . implode("\r\n", $lines), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="epp-estado.csv"']);
    }

    public function batch(Request $request): Response
    {
        $employees = PpeService::employees();
        $c = PpeService::compliance($employees);
        $employees = array_values(array_filter($employees, fn($e) => in_array($c['summaries'][(int)$e['id']]['overall'], ['nunca','vencido','por_vencer'], true)));
        return Response::html(View::render('panel/ppe/batch', ['title' => 'Entrega de EPP por lote', 'employees' => $employees, 'summaries' => $c['summaries']], 'layouts/app'));
    }

    public function sectorCertificates(Request $request): Response
    {
        $sector = Sectors::findByUuid((string)$request->input('sector', ''));
        if (!$sector) return self::notFound();
        $employees = PpeService::employees(null, Sectors::withDescendants([(int)$sector['id']]));
        return Response::html(View::render('panel/ppe/certificates-sector', ['title'=>'Constancias · '.$sector['name'], 'sector'=>$sector, 'employees'=>$employees], null));
    }

    // ── empleado ─────────────────────────────────────────────────────

    public function employee(Request $request, string $uuid): Response
    {
        $e = $this->employeeOr404($uuid);
        if ($e === null) {
            return self::notFound();
        }
        return Response::html(View::render('panel/ppe/employee', [
            'title'      => 'EPP · ' . $e['name'],
            'e'          => $e,
            'status'     => PpeService::status($e),
            'deliveries' => Ppe::deliveries((int) $e['id']),
            'extras'     => Ppe::extras((int) $e['id']),
            'items'      => PpeItems::options(),
        ], 'layouts/app'));
    }

    public function sizes(Request $request, string $uuid): Response
    {
        $e = $this->employeeOr404($uuid);
        if ($e === null || !UserAuth::can(PpeService::MODULE, 'crear')) {
            return self::notFound();
        }
        $data = [];
        foreach (PpeService::SIZE_TYPES as $key => $t) {
            $data[$t['column']] = mb_substr(trim((string) $request->input($key, '')), 0, 10) ?: null;
        }
        Employees::update((int) $e['id'], $data);
        Flash::add('success', 'Talles guardados.');
        return Response::redirect('/panel/epp/empleado/' . $uuid);
    }

    /** Extra para este empleado (o sacarlo): solo quien administra el catálogo. */
    public function extra(Request $request, string $uuid): Response
    {
        $e = $this->employeeOr404($uuid);
        $item = PpeItems::findByUuid((string) $request->input('item', ''));
        if ($e === null || !PpeService::canManage()) {
            return self::notFound();
        }
        if ($item === null) {
            Flash::add('danger', 'Elegí el elemento.');
            return Response::redirect('/panel/epp/empleado/' . $uuid);
        }
        if ($request->input('remove')) {
            Ppe::setExtra((int) $e['id'], (int) $item['id'], null);
            Audit::tenant('ppe.extra_remove', 'employee', $e['uuid'], ['extra' => $item['name']], null);
            Flash::add('success', $item['name'] . ': ya no es un extra de ' . $e['name'] . '.');
            return Response::redirect('/panel/epp/empleado/' . $uuid);
        }
        $reason = trim((string) $request->input('reason', ''));
        $life = trim((string) $request->input('life_days', ''));
        $qty = (int) $request->input('quantity', 1);
        if (mb_strlen($reason) < 3 || $qty < 1 || $qty > 50 || ($life !== '' && (!ctype_digit($life) || (int) $life < 1 || (int) $life > 3650))) {
            Flash::add('danger', 'Revisá el extra: motivo, cantidad (1 a 50) y vida útil en días (opcional).');
            return Response::redirect('/panel/epp/empleado/' . $uuid);
        }
        Ppe::setExtra((int) $e['id'], (int) $item['id'], ['quantity' => $qty, 'life_days' => $life !== '' ? (int) $life : null, 'reason' => mb_substr($reason, 0, 191)]);
        Audit::tenant('ppe.extra', 'employee', $e['uuid'], null, ['extra' => $item['name'], 'motivo' => $reason]);
        Flash::add('success', $item['name'] . ' agregado como extra.');
        return Response::redirect('/panel/epp/empleado/' . $uuid);
    }

    // ── entregar ─────────────────────────────────────────────────────

    public function deliverForm(Request $request, string $uuid): Response
    {
        $e = $this->employeeOr404($uuid);
        if ($e === null) {
            return self::notFound();
        }
        [$old, $errors] = Flash::pullInput();
        return Response::html(View::render('panel/ppe/deliver', [
            'title'   => 'Entregar EPP · ' . $e['name'],
            'e'       => $e,
            'status'  => PpeService::status($e),
            'catalog' => PpeItems::list(null, false, [], 1000),
            'old'     => $old,
            'errors'  => $errors,
        ], 'layouts/app'));
    }

    public function deliver(Request $request, string $uuid): Response
    {
        $e = $this->employeeOr404($uuid);
        if ($e === null) {
            return self::notFound();
        }
        $in = $request->post;
        $in['items'] = array_values(array_filter((array) ($in['items'] ?? []), fn ($r) => is_array($r) && !empty($r['selected'])));
        $file = $request->files['paper'] ?? null;
        $paper = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK ? ['tmp' => $file['tmp_name'], 'name' => $file['name'], 'upload' => true] : null;
        $r = PpeService::deliver($e, $in, $paper, ['ip' => $request->ip(), 'user_agent' => $request->header('user-agent')]);
        if ($r['delivery'] === null) {
            unset($in['csrf_token'], $in['signature']);
            Flash::add('danger', 'Revisá la entrega: ' . implode(' ', array_slice($r['errors'], 0, 4)));
            Flash::withInput($request->post + ['_items' => 1], $r['errors']);
            return Response::redirect('/panel/epp/empleado/' . $uuid . '/entregar');
        }
        Flash::add('success', Ppe::format((int) $r['delivery']['number']) . ' registrada: ' . count($in['items']) . ' elemento(s) entregados a ' . $e['name'] . '.');
        return Response::redirect('/panel/epp/empleado/' . $uuid);
    }

    public function void(Request $request, string $uuid): Response
    {
        $d = Ppe::findDelivery($uuid);
        if ($d === null) {
            return self::notFound();
        }
        $error = PpeService::void($d, (string) $request->input('reason', ''));
        Flash::add($error ? 'danger' : 'success', $error ?? Ppe::format((int) $d['number']) . ' anulada.');
        return Response::redirect('/panel/epp/empleado/' . $d['employee_uuid']);
    }

    /** Firma (PNG) o planilla en papel (foto/PDF) de una entrega. */
    public function signature(Request $request, string $uuid): Response
    {
        $d = Ppe::findDelivery($uuid);
        $e = $d ? Employees::findById((int) $d['employee_id']) : null;
        if ($e === null || !PpeService::canSeeEmployee($e)) {
            return new Response('', 404);
        }
        $file = TenantFiles::path(Tenant::current()['uuid'], $d['signature_path']);
        return $file ? TenantFiles::response($file, 3600) : new Response('', 404);
    }

    /** Constancia de entrega (Res. SRT 299/11), una por empleado, con la firma de cada entrega. */
    public function certificate(Request $request, string $uuid): Response
    {
        $e = $this->employeeOr404($uuid);
        if ($e === null) {
            return self::notFound();
        }
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('desde', '')) ? WorkPermitService::parseTime($request->input('desde') . ' 00:00') : null;
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('hasta', ''))
            ? WorkPermitService::parseTime((new \DateTimeImmutable((string) $request->input('hasta')))->modify('+1 day')->format('Y-m-d') . ' 00:00') : null;
        Audit::tenant('ppe.certificate', 'employee', $e['uuid'], null, ['desde' => $request->input('desde'), 'hasta' => $request->input('hasta')]);
        return Response::html(View::render('panel/ppe/certificate', [
            'e'          => $e,
            'deliveries' => array_reverse(Ppe::deliveries((int) $e['id'], false, $from, $to)),
            'required'   => PpeService::requirements($e),
            'tenant'     => Tenant::current(),
            'company'    => array_map(fn ($k) => Settings::get($k) ?? '', SettingsController::COMPANY),
        ], null));
    }

    // ── catálogo ─────────────────────────────────────────────────────

    public function catalog(Request $request): Response
    {
        $edit = ($request->input('editar') ?? '') !== '' ? PpeItems::findByUuid((string) $request->input('editar')) : null;
        [$old, $errors] = Flash::pullInput();
        return Response::html(View::render('panel/ppe/catalog', [
            'title'  => 'EPP · catálogo',
            'items'  => PpeItems::list(null, true, [], 1000),
            'edit'   => $edit,
            'old'    => $old ?: ($edit ?? []),
            'errors' => $errors,
            'canManage' => PpeService::canManage(),
            'industries' => IndustryTemplates::available(),
        ], 'layouts/app'));
    }

    public function saveItem(Request $request): Response
    {
        if (!PpeService::canManage()) {
            return self::notFound();
        }
        $current = ($request->input('uuid') ?? '') !== '' ? PpeItems::findByUuid((string) $request->input('uuid')) : null;
        [$data, $errors] = PpeService::validateItem($request->post, $current);
        if ($data === null) {
            Flash::add('danger', 'Revisá el elemento: ' . implode(' ', $errors));
            Flash::withInput($request->post, $errors);
            return Response::redirect('/panel/epp/catalogo' . ($current ? '?editar=' . $current['uuid'] : ''));
        }
        if ($current) {
            PpeItems::update((int) $current['id'], $data);
            Audit::tenant('ppe.item_update', 'ppe_item', $current['uuid'], array_intersect_key($current, $data), $data);
        } else {
            $id = PpeItems::create($data);
            Audit::tenant('ppe.item_create', 'ppe_item', PpeItems::findById($id)['uuid'], null, $data);
        }
        Flash::add('success', $data['name'] . ' guardado.');
        return Response::redirect('/panel/epp/catalogo');
    }

    public function toggleItem(Request $request, string $uuid): Response
    {
        $item = PpeItems::findByUuid($uuid);
        if ($item === null || !PpeService::canManage()) {
            return self::notFound();
        }
        PpeItems::setActive((int) $item['id'], !(int) $item['is_active']);
        Audit::tenant('ppe.item_toggle', 'ppe_item', $uuid, ['activo' => (bool) $item['is_active']], ['activo' => !(int) $item['is_active']]);
        Flash::add('success', $item['name'] . ((int) $item['is_active'] ? ' dado de baja: ya no se entrega ni cuenta como faltante.' : ' activado.'));
        return Response::redirect('/panel/epp/catalogo');
    }

    public function applyTemplate(Request $request): Response
    {
        if (!PpeService::canManage()) {
            return self::notFound();
        }
        try {
            $r = IndustryTemplates::applyPpe((string) $request->input('industry', ''));
            Flash::add('success', $r['created'] ? "Se agregaron {$r['created']} elemento(s) y celdas de la matriz." : 'El EPP del rubro ya estaba cargado.');
        } catch (\DomainException $ex) {
            Flash::add('danger', $ex->getMessage());
        }
        return Response::redirect('/panel/epp/catalogo');
    }

    // ── matriz ───────────────────────────────────────────────────────

    public function matrix(Request $request): Response
    {
        $cells = [];
        foreach (Ppe::matrix() as $m) {
            $cells[(int) $m['position_id']][(int) $m['item_id']] = $m;
        }
        return Response::html(View::render('panel/ppe/matrix', [
            'title'     => 'EPP · matriz por puesto',
            'positions' => Positions::list(null, false, [], 500),
            'items'     => PpeItems::list(null, false, [], 1000),
            'cells'     => $cells,
            'canManage' => PpeService::canManage(),
        ], 'layouts/app'));
    }

    public function saveCell(Request $request): Response
    {
        $position = Positions::findByUuid((string) $request->input('position', ''));
        $item = PpeItems::findByUuid((string) $request->input('item', ''));
        if (!PpeService::canManage() || $position === null || $item === null) {
            return self::notFound();
        }
        [$data, $error] = PpeService::validateCell($request->post);
        if ($error !== null) {
            Flash::add('danger', $error);
        } else {
            Ppe::setMatrix((int) $position['id'], (int) $item['id'], $data);
            Audit::tenant('ppe.matrix', 'position', $position['uuid'], null, ['elemento' => $item['name'], 'celda' => $data]);
            Flash::add('success', $position['name'] . ' · ' . $item['name'] . ($data ? ' guardado.' : ' quitado.'));
        }
        return Response::redirect('/panel/epp/matriz#p-' . $position['uuid']);
    }

    /** Copiar la matriz de un puesto a otro (agrega o actualiza; no saca lo que el destino ya tenía). */
    public function copyMatrix(Request $request): Response
    {
        $from = Positions::findByUuid((string) $request->input('from', ''));
        $to = Positions::findByUuid((string) $request->input('to', ''));
        if (!PpeService::canManage() || $from === null || $to === null || $from['id'] === $to['id']) {
            Flash::add('danger', 'Elegí dos puestos distintos.');
            return Response::redirect('/panel/epp/matriz');
        }
        $rows = Ppe::matrix((int) $from['id']);
        foreach ($rows as $m) {
            Ppe::setMatrix((int) $to['id'], (int) $m['item_id'], ['quantity' => (int) $m['quantity'], 'life_days' => $m['life_days'] !== null ? (int) $m['life_days'] : null,
                'mandatory' => (bool) $m['mandatory'], 'notes' => $m['notes']]);
        }
        Audit::tenant('ppe.matrix_copy', 'position', $to['uuid'], null, ['desde' => $from['name'], 'celdas' => count($rows)]);
        Flash::add('success', count($rows) . ' elemento(s) copiados de ' . $from['name'] . ' a ' . $to['name'] . '.');
        return Response::redirect('/panel/epp/matriz#p-' . $to['uuid']);
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function employeeOr404(string $uuid): ?array
    {
        $e = Employees::findByUuid($uuid);
        return $e !== null && PpeService::canSeeEmployee($e) ? $e : null;
    }

    private static function notFound(): Response
    {
        return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
    }
}
