<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\UserError;
use App\Core\Uuid;
use App\Models\Employees;
use App\Models\Ppe;
use App\Models\PpeItems;
use App\Models\Sequences;
use App\Models\Settings;

/**
 * EPP (Etapa 14): qué le corresponde a cada empleado (matriz del puesto + extras), su estado (al día, por vencer,
 * vencido, nunca entregado) y las entregas firmadas (en pantalla o planilla en papel escaneada), inmutables.
 * Solo personal propio (decisión del usuario): los empleados de contratistas no participan.
 */
final class PpeService
{
    public const MODULE = 'epp';
    public const CATEGORIES = ['cabeza' => 'Cabeza', 'ojos_cara' => 'Ojos y cara', 'auditiva' => 'Protección auditiva', 'respiratoria' => 'Respiratoria',
        'manos' => 'Manos', 'pies' => 'Pies', 'ropa' => 'Ropa de trabajo', 'caidas' => 'Caídas (arnés)', 'otros' => 'Otros'];
    public const REASONS = ['inicial' => 'Entrega inicial', 'vencimiento' => 'Reposición por vencimiento', 'rotura' => 'Rotura o desgaste',
        'perdida' => 'Pérdida', 'talle' => 'Cambio de talle', 'otro' => 'Otro'];
    /** Tipo de talle → columna del empleado. */
    public const SIZE_TYPES = ['ropa' => ['label' => 'Ropa', 'column' => 'size_clothing'], 'calzado' => ['label' => 'Calzado', 'column' => 'size_shoes'],
        'guantes' => ['label' => 'Guantes', 'column' => 'size_gloves']];
    public const STATES = [
        'vencido'    => ['label' => 'Vencido', 'class' => 'danger'],
        'nunca'      => ['label' => 'Nunca entregado', 'class' => 'danger'],
        'por_vencer' => ['label' => 'Por vencer', 'class' => 'warning'],
        'al_dia'     => ['label' => 'Al día', 'class' => 'success'],
        'opcional'   => ['label' => 'Según tarea', 'class' => 'secondary'],
    ];
    private const PAPER_TYPES = ImageProcessor::TYPES + ['application/pdf' => 'pdf'];
    private const PAPER_MAX = 15 * 1024 * 1024;
    /** Hasta cuánto atrás se puede cargar una entrega (planillas atrasadas, celular sin señal). */
    private const MAX_BACKDATE_DAYS = 60;

    public static function today(): string
    {
        return fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d');
    }

    public static function warnDays(): int
    {
        return max(1, min(90, (int) Settings::get('epp.aviso_dias', '15')));
    }

    /** Catálogo y matriz: quien tiene "editar" con alcance "toda la empresa". */
    public static function canManage(): bool
    {
        return UserAuth::can(self::MODULE, 'editar') && UserAuth::scope(self::MODULE) === 'todo';
    }

    /** Solo personal propio, activo o no, dentro del alcance por sector. */
    public static function canSeeEmployee(array $e): bool
    {
        if ($e['contractor_id'] !== null || !UserAuth::can(self::MODULE, 'ver')) {
            return false;
        }
        return match (UserAuth::scope(self::MODULE)) {
            'todo'     => true,
            'sectores' => $e['sector_id'] !== null && in_array((int) $e['sector_id'], SectorScope::sectorIds(self::MODULE) ?? [], true),
            default    => false,
        };
    }

    /** Empleados propios activos del alcance del usuario. */
    public static function employees(?string $search = null, ?array $sectorIds = null): array
    {
        $rows = Employees::list($search, false, ['contractor_id' => null], 5000);
        return array_values(array_filter($rows, fn ($e) => self::canSeeEmployee($e) && ($sectorIds === null || in_array((int) $e['sector_id'], $sectorIds, true))));
    }

    /** Empleados propios para tareas del sistema (cron), sin depender de la sesión web. */
    public static function employeesForSystem(): array
    {
        return array_values(array_filter(Employees::list(null, false, ['contractor_id' => null], 5000), fn($e) => (int)$e['is_active'] === 1));
    }

    /**
     * Lo que le corresponde: matriz de su puesto + extras (el extra pisa al puesto para el mismo elemento).
     * @return array<int, array> item_id => {item, quantity, life_days (efectiva), mandatory, source puesto|extra, notes}
     */
    public static function requirements(array $e, ?array $matrix = null, ?array $items = null): array
    {
        $items ??= PpeItems::byId();
        $out = [];
        $rows = $matrix !== null ? ($matrix[(int) $e['position_id']] ?? []) : ($e['position_id'] ? Ppe::matrix((int) $e['position_id']) : []);
        foreach ($rows as $m) {
            $item = $items[(int) $m['item_id']] ?? null;
            if ($item === null || !(int) $item['is_active']) {
                continue;
            }
            $out[(int) $m['item_id']] = ['item' => $item, 'quantity' => (int) $m['quantity'], 'mandatory' => (bool) $m['mandatory'], 'source' => 'puesto',
                'life_days' => $m['life_days'] !== null ? (int) $m['life_days'] : ($item['life_days'] !== null ? (int) $item['life_days'] : null), 'notes' => $m['notes']];
        }
        foreach (Ppe::extras((int) $e['id']) as $x) {
            $item = $items[(int) $x['item_id']] ?? null;
            if ($item === null || !(int) $item['is_active']) {
                continue;
            }
            $out[(int) $x['item_id']] = ['item' => $item, 'quantity' => (int) $x['quantity'], 'mandatory' => true, 'source' => 'extra',
                'life_days' => $x['life_days'] !== null ? (int) $x['life_days'] : ($item['life_days'] !== null ? (int) $item['life_days'] : null), 'notes' => $x['reason']];
        }
        uasort($out, fn ($a, $b) => [$a['item']['category'], $a['item']['name']] <=> [$b['item']['category'], $b['item']['name']]);
        return $out;
    }

    /** Estado de un elemento según su última entrega. */
    public static function stateOf(array $req, ?array $last, ?string $today = null): string
    {
        $today ??= self::today();
        if ($last === null) {
            return $req['mandatory'] ? 'nunca' : 'opcional';
        }
        if ($last['next_due_on'] === null) {
            return 'al_dia';
        }
        if ($last['next_due_on'] < $today) {
            return $req['mandatory'] ? 'vencido' : 'opcional';
        }
        $warn = (new \DateTimeImmutable($today))->modify('+' . self::warnDays() . ' days')->format('Y-m-d');
        return $last['next_due_on'] <= $warn && $req['mandatory'] ? 'por_vencer' : 'al_dia';
    }

    /**
     * Estado completo de un empleado.
     * @return array{rows: list<array>, counts: array<string,int>, overall: string}
     */
    public static function status(array $e, ?array $last = null): array
    {
        $last ??= Ppe::lastDeliveries([(int) $e['id']])[(int) $e['id']] ?? [];
        $rows = [];
        $counts = array_fill_keys(array_keys(self::STATES), 0);
        foreach (self::requirements($e) as $itemId => $req) {
            $l = $last[$itemId] ?? null;
            $state = self::stateOf($req, $l);
            $counts[$state]++;
            $rows[] = $req + ['item_id' => $itemId, 'last' => $l, 'state' => $state];
        }
        return ['rows' => $rows, 'counts' => $counts, 'overall' => self::overall($counts)];
    }

    /** Resumen de muchos empleados con pocas consultas (para el listado). @return array<int, array{counts, overall}> */
    public static function summaries(array $employees): array
    {
        $items = PpeItems::byId();
        $matrix = [];
        foreach (Ppe::matrix() as $m) {
            $matrix[(int) $m['position_id']][] = $m;
        }
        $last = Ppe::lastDeliveries(array_map(fn ($e) => (int) $e['id'], $employees));
        $today = self::today();
        $out = [];
        foreach ($employees as $e) {
            $counts = array_fill_keys(array_keys(self::STATES), 0);
            foreach (self::requirements($e, $matrix, $items) as $itemId => $req) {
                $counts[self::stateOf($req, $last[(int) $e['id']][$itemId] ?? null, $today)]++;
            }
            $out[(int) $e['id']] = ['counts' => $counts, 'overall' => self::overall($counts)];
        }
        return $out;
    }

    /** Tablero de cumplimiento agrupado por sector. */
    public static function compliance(array $employees): array
    {
        $summaries = self::summaries($employees);
        $totals = ['empleados' => count($employees), 'al_dia' => 0, 'por_vencer' => 0, 'vencido' => 0, 'nunca' => 0];
        $sectors = [];
        foreach ($employees as $e) {
            $s = $summaries[(int) $e['id']] ?? ['overall' => 'nunca', 'counts' => []];
            $state = $s['overall'];
            if (isset($totals[$state])) $totals[$state]++;
            $key = (string) ($e['sector_id'] ?? 0);
            if (!isset($sectors[$key])) $sectors[$key] = ['name' => $e['sector_name'] ?? 'Sin sector', 'empleados' => 0, 'al_dia' => 0, 'por_vencer' => 0, 'vencido' => 0, 'nunca' => 0];
            $sectors[$key]['empleados']++;
            if (isset($sectors[$key][$state])) $sectors[$key][$state]++;
        }
        foreach ($sectors as &$s) $s['porcentaje'] = $s['empleados'] ? round($s['al_dia'] * 100 / $s['empleados'], 1) : 0;
        return ['totals' => $totals, 'sectors' => $sectors, 'summaries' => $summaries];
    }

    private static function overall(array $counts): string
    {
        foreach (['vencido', 'nunca', 'por_vencer'] as $s) {
            if ($counts[$s] > 0) {
                return $s;
            }
        }
        return array_sum($counts) - $counts['opcional'] > 0 ? 'al_dia' : 'opcional';
    }

    // ── entregar ───────────────────────────────────────────────────

    /**
     * @param array $in uuid (del celular, opcional), delivered_at (local o ISO; por defecto ahora), reason, notes,
     *   items [{item (uuid), quantity, size, returned_previous}], signature_mode pantalla|papel, signature (data URL) o
     *   paper_reason (+ $paper = ['tmp', 'name', 'upload']), lat, lng
     * @return array{delivery: ?array, errors: array<string,string>, duplicate?: bool}
     */
    public static function deliver(array $e, array $in, ?array $paper = null, array $meta = []): array
    {
        if (!UserAuth::can(self::MODULE, 'crear') || !self::canSeeEmployee($e)) {
            return ['delivery' => null, 'errors' => ['permiso' => 'No podés registrar entregas para este empleado.']];
        }
        $uuid = strtolower(trim((string) ($in['uuid'] ?? '')));
        if ($uuid !== '' && ($existing = Ppe::findDelivery($uuid)) !== null) {
            return ['delivery' => $existing, 'errors' => [], 'duplicate' => true]; // reenvío del celular
        }
        $errors = [];
        if ($uuid !== '' && !Uuid::isValid($uuid)) {
            $errors['uuid'] = 'Identificador inválido.';
        }
        if (!(int) $e['is_active']) {
            $errors['employee'] = 'El empleado está dado de baja.';
        }
        $reason = (string) ($in['reason'] ?? '');
        if (!isset(self::REASONS[$reason])) {
            $errors['reason'] = 'Elegí el motivo de la entrega.';
        }
        $at = time();
        if (($in['delivered_at'] ?? '') !== '') {
            $parsed = WorkPermitService::parseTime($in['delivered_at']);
            if ($parsed === null || strtotime($parsed) > time() + 600 || strtotime($parsed) < time() - self::MAX_BACKDATE_DAYS * 86400) {
                $errors['delivered_at'] = 'Fecha de entrega inválida (hasta ' . self::MAX_BACKDATE_DAYS . ' días atrás).';
            } else {
                $at = strtotime($parsed);
            }
        }
        $catalog = PpeItems::byId();
        $byUuid = array_column($catalog, null, 'uuid');
        $reqs = self::requirements($e, null, $catalog);
        $lines = [];
        foreach ((array) ($in['items'] ?? []) as $i => $row) {
            if (!is_array($row) || ($row['item'] ?? '') === '') {
                continue;
            }
            $item = $byUuid[(string) $row['item']] ?? null;
            if ($item === null || !(int) $item['is_active']) {
                $errors["items.{$i}"] = 'Elemento inexistente o dado de baja.';
                continue;
            }
            if (isset($lines[(int) $item['id']])) {
                $errors["items.{$i}"] = $item['name'] . ': repetido.';
                continue;
            }
            $qty = (int) ($row['quantity'] ?? 1);
            if ($qty < 1 || $qty > 50) {
                $errors["items.{$i}"] = $item['name'] . ': cantidad entre 1 y 50.';
            }
            $size = mb_substr(trim((string) ($row['size'] ?? '')), 0, 10);
            if ($item['size_type'] !== null && $size === '') {
                $errors["items.{$i}"] = $item['name'] . ': falta el talle.';
            }
            $life = $reqs[(int) $item['id']]['life_days'] ?? ($item['life_days'] !== null ? (int) $item['life_days'] : null);
            $lines[(int) $item['id']] = ['item_id' => (int) $item['id'], 'quantity' => $qty, 'size' => $size !== '' ? $size : null, 'item_name' => $item['name'],
                'model' => $item['model'], 'brand' => $item['brand'], 'certified' => (bool) $item['certified'], 'certification' => $item['certification'],
                'life_days' => $life, 'size_type' => $item['size_type'],
                'returned_previous' => isset($row['returned_previous']) && $row['returned_previous'] !== '' ? ((int) (bool) $row['returned_previous']) : null];
        }
        if (!$lines) {
            $errors['items'] = 'Elegí al menos un elemento.';
        }
        $mode = ($in['signature_mode'] ?? 'pantalla') === 'papel' ? 'papel' : 'pantalla';
        if ($mode === 'papel') {
            if ($paper === null) {
                $errors['paper'] = 'Subí la planilla firmada (foto o PDF).';
            }
            if (mb_strlen(trim((string) ($in['paper_reason'] ?? ''))) < 5) {
                $errors['paper_reason'] = 'Contá por qué se firmó en papel (mínimo 5 caracteres).';
            }
        } elseif (trim((string) ($in['signature'] ?? '')) === '') {
            $errors['signature'] = 'Falta la firma del empleado.';
        }
        if ($errors) {
            return ['delivery' => null, 'errors' => $errors];
        }

        try {
            $sig = $mode === 'papel' ? self::storePaper($paper) : Signatures::store((string) $in['signature'], 'ppe') + ['mime' => 'image/png'];
        } catch (UserError | \DomainException $ex) {
            return ['delivery' => null, 'errors' => [$mode === 'papel' ? 'paper' : 'signature' => $ex->getMessage()]];
        }

        $user = UserAuth::user();
        $deliveredAt = gmdate('Y-m-d H:i:s', $at);
        $localDay = fecha($deliveredAt, 'Y-m-d');
        foreach ($lines as &$l) {
            $l['next_due_on'] = $l['life_days'] !== null ? (new \DateTimeImmutable($localDay))->modify('+' . $l['life_days'] . ' days')->format('Y-m-d') : null;
        }
        unset($l);
        $original = [
            'empleado' => ['uuid' => $e['uuid'], 'dni' => $e['dni'], 'nombre' => $e['name'], 'puesto' => $e['position_name']],
            'entregado' => $deliveredAt, 'motivo' => $reason, 'notas' => trim((string) ($in['notes'] ?? '')) ?: null,
            'items' => array_values(array_map(fn ($l) => ['item' => $catalog[$l['item_id']]['uuid']] + array_diff_key($l, ['size_type' => 1, 'item_id' => 1]), $lines)),
            'firma' => ['modo' => $mode, 'sha256' => $sig['sha256']], 'entrego' => $user['name'] ?? null,
        ];
        $json = json_encode($original, JSON_UNESCAPED_UNICODE);
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $id = Ppe::createDelivery([
                'uuid' => $uuid ?: Uuid::v4(), 'number' => Sequences::next('ppe_deliveries'), 'employee_id' => (int) $e['id'], 'position_name' => $e['position_name'],
                'delivered_at' => $deliveredAt, 'delivered_by' => $user['id'] ?? null, 'reason' => $reason, 'notes' => $original['notas'],
                'signature_mode' => $mode, 'signature_path' => $sig['path'], 'signature_sha256' => $sig['sha256'], 'signature_mime' => $sig['mime'],
                'paper_reason' => $mode === 'papel' ? mb_substr(trim((string) $in['paper_reason']), 0, 191) : null,
                'signer_name' => $e['name'], 'signer_dni' => $e['dni'],
                'lat' => is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, 'lng' => is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null,
                'ip' => $meta['ip'] ?? null, 'user_agent' => isset($meta['user_agent']) ? mb_substr((string) $meta['user_agent'], 0, 255) : null,
                'original_data' => $json, 'original_hash' => hash('sha256', $json),
            ]);
            foreach ($lines as $l) {
                Ppe::addDeliveryItem($id, $l);
            }
            $db->commit();
        } catch (\Throwable $ex) {
            $db->rollBack();
            if ($uuid !== '' && ($existing = Ppe::findDelivery($uuid)) !== null) { // llegó dos veces a la vez
                return ['delivery' => $existing, 'errors' => [], 'duplicate' => true];
            }
            throw $ex;
        }
        // Los talles del empleado quedan como los de la última entrega.
        $sizes = [];
        foreach ($lines as $l) {
            if ($l['size'] !== null && $l['size_type'] !== null) {
                $sizes[self::SIZE_TYPES[$l['size_type']]['column']] = $l['size'];
            }
        }
        if ($sizes) {
            Employees::update((int) $e['id'], $sizes);
        }
        $d = Ppe::findDelivery($uuid ?: (string) $db->query('SELECT uuid FROM ppe_deliveries WHERE id = ' . $id)->fetchColumn());
        Audit::tenant('ppe.deliver', 'ppe_delivery', $d['uuid'], null, ['numero' => Ppe::format((int) $d['number']), 'empleado' => $e['dni'],
            'elementos' => count($lines), 'firma' => $mode]);
        return ['delivery' => $d, 'errors' => []];
    }

    /** Un error se corrige anulando (con motivo) y cargando de nuevo: la entrega original no se modifica. */
    public static function void(array $d, string $reason): ?string
    {
        $e = Employees::findById((int) $d['employee_id']);
        if (!UserAuth::can(self::MODULE, 'cerrar') || $e === null || !self::canSeeEmployee($e)) {
            return 'No tenés permiso para anular entregas de EPP.';
        }
        if ($d['voided_at'] !== null) {
            return 'La entrega ya estaba anulada.';
        }
        if (mb_strlen(trim($reason)) < 5) {
            return 'Escribí el motivo de la anulación (mínimo 5 caracteres).';
        }
        Ppe::voidDelivery((int) $d['id'], (int) UserAuth::user()['id'], mb_substr(trim($reason), 0, 255));
        Audit::tenant('ppe.void', 'ppe_delivery', $d['uuid'], ['anulada' => false], ['anulada' => true, 'motivo' => trim($reason)]);
        return null;
    }

    /** Planilla en papel firmada: foto o PDF tal como llegó (evidencia, no se procesa). */
    private static function storePaper(array $file): array
    {
        $rel = TenantFiles::storeFile(Tenant::current()['uuid'], $file['tmp'], 'ppe/' . gmdate('Y') . '/' . gmdate('m'), self::PAPER_TYPES, self::PAPER_MAX, $file['upload'] ?? true);
        $full = TenantFiles::path(Tenant::current()['uuid'], $rel);
        return ['path' => $rel, 'sha256' => hash_file('sha256', $full), 'mime' => (new \finfo(FILEINFO_MIME_TYPE))->file($full) ?: 'application/octet-stream'];
    }

    // ── catálogo y matriz ──────────────────────────────────────────

    /** @return array{0: ?array, 1: array<string,string>} [datos limpios, errores] */
    public static function validateItem(array $in, ?array $current = null): array
    {
        $errors = [];
        $name = trim((string) ($in['name'] ?? ''));
        if (mb_strlen($name) < 3) {
            $errors['name'] = 'Nombre (mínimo 3 caracteres).';
        }
        $category = (string) ($in['category'] ?? '');
        if (!isset(self::CATEGORIES[$category])) {
            $errors['category'] = 'Elegí la categoría.';
        }
        $life = trim((string) ($in['life_days'] ?? ''));
        if ($life !== '' && (!ctype_digit($life) || (int) $life < 1 || (int) $life > 3650)) {
            $errors['life_days'] = 'Vida útil en días, entre 1 y 3650 (vacío = sin vencimiento).';
        }
        $size = (string) ($in['size_type'] ?? '');
        if ($size !== '' && !isset(self::SIZE_TYPES[$size])) {
            $errors['size_type'] = 'Tipo de talle inválido.';
        }
        $certified = !empty($in['certified']);
        $cert = mb_substr(trim((string) ($in['certification'] ?? '')), 0, 120);
        if ($errors) {
            return [null, $errors];
        }
        return [['name' => mb_substr($name, 0, 120), 'category' => $category, 'model' => mb_substr(trim((string) ($in['model'] ?? '')), 0, 120) ?: null,
            'brand' => mb_substr(trim((string) ($in['brand'] ?? '')), 0, 120) ?: null, 'certified' => $certified ? 1 : 0,
            'certification' => $certified ? ($cert ?: null) : null, 'life_days' => $life !== '' ? (int) $life : null, 'size_type' => $size ?: null], []];
    }

    /** Celda de la matriz: datos limpios o null = sacar. @return array{0: ?array, 1: ?string} */
    public static function validateCell(array $in): array
    {
        if (empty($in['active'])) {
            return [null, null];
        }
        $qty = (int) ($in['quantity'] ?? 1);
        if ($qty < 1 || $qty > 50) {
            return [null, 'Cantidad entre 1 y 50.'];
        }
        $life = trim((string) ($in['life_days'] ?? ''));
        if ($life !== '' && (!ctype_digit($life) || (int) $life < 1 || (int) $life > 3650)) {
            return [null, 'Vida útil en días, entre 1 y 3650 (vacío = la del catálogo).'];
        }
        return [['quantity' => $qty, 'life_days' => $life !== '' ? (int) $life : null, 'mandatory' => !empty($in['mandatory']),
            'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 191) ?: null], null];
    }
}
