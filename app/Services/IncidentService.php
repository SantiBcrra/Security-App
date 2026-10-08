<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\Uuid;
use App\Models\CatalogItems;
use App\Models\Employees;
use App\Models\Equipment;
use App\Models\Incidents;
use App\Models\Sectors;
use App\Models\Sequences;
use App\Resources\Resource;
use App\Services\Notify\Notifier;

/**
 * Incidentes y accidentes: alta con el reporte original congelado (hash), personas involucradas,
 * lesión y seguimiento de la baja (datos de salud: solo con incidentes.datos_salud), correcciones como
 * eventos, adjuntos y cierre (las investigaciones llegan en la entrega 2).
 */
final class IncidentService
{
    public const MODULE = 'incidentes';

    /** label, accident (tiene lesionados), serious (aviso crítico), investigation (obligatoria para cerrar) */
    public const TYPES = [
        'accidente_con_baja'     => ['label' => 'Accidente con baja', 'accident' => true, 'serious' => true, 'investigation' => true, 'class' => 'danger'],
        'in_itinere'             => ['label' => 'Accidente in itinere', 'accident' => true, 'serious' => true, 'investigation' => true, 'class' => 'danger'],
        'accidente_sin_baja'     => ['label' => 'Accidente sin baja', 'accident' => true, 'serious' => false, 'investigation' => true, 'class' => 'warning'],
        'enfermedad_profesional' => ['label' => 'Enfermedad profesional', 'accident' => true, 'serious' => false, 'investigation' => true, 'class' => 'dark'],
        'incidente'              => ['label' => 'Incidente (daño material)', 'accident' => false, 'serious' => false, 'investigation' => false, 'class' => 'info'],
        'casi_accidente'         => ['label' => 'Casi-accidente', 'accident' => false, 'serious' => false, 'investigation' => false, 'class' => 'secondary'],
    ];

    public const STATES = [
        'reportado'        => ['label' => 'Reportado', 'class' => 'secondary'],
        'en_investigacion' => ['label' => 'En investigación', 'class' => 'info'],
        'investigado'      => ['label' => 'Investigado', 'class' => 'primary'],
        'cerrado'          => ['label' => 'Cerrado', 'class' => 'success'],
        'anulado'          => ['label' => 'Anulado', 'class' => 'dark'],
    ];

    /** acción => desde, a, permiso y si pide motivo */
    public const TRANSITIONS = [
        'cerrar'  => ['from' => ['reportado', 'en_investigacion', 'investigado'], 'to' => 'cerrado', 'perm' => 'cerrar', 'comment' => true, 'label' => 'Cerrar'],
        'anular'  => ['from' => ['reportado', 'en_investigacion'], 'to' => 'anulado', 'perm' => 'cerrar', 'comment' => true, 'label' => 'Anular'],
        'reabrir' => ['from' => ['cerrado'], 'to' => 'reportado', 'perm' => 'cerrar', 'comment' => true, 'label' => 'Reabrir'],
    ];

    public const ROLES = ['lesionado' => 'Lesionado', 'involucrado' => 'Involucrado', 'testigo' => 'Testigo'];
    public const ATTENTION = ['primeros_auxilios' => 'Primeros auxilios en planta', 'art' => 'Prestador de la ART', 'guardia' => 'Guardia / emergencia',
        'hospital' => 'Internación', 'ninguna' => 'No requirió'];
    public const FOLLOW_UP = ['en_tratamiento' => 'En tratamiento', 'alta' => 'Con alta médica', 'reingreso' => 'Reingresó a su puesto',
        'secuelas' => 'Con secuelas', 'reubicado' => 'Reubicado'];

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type]['label'] ?? $type;
    }

    public static function canSeeHealth(): bool
    {
        return UserAuth::can(self::MODULE, 'datos_salud');
    }

    public static function scope(): ?array
    {
        $user = UserAuth::user();
        return match (UserAuth::scope(self::MODULE)) {
            'propios'  => ['own' => (int) ($user['id'] ?? 0)],
            'sectores' => ['sector_ids' => SectorScope::sectorIds(self::MODULE) ?? [], 'user_id' => (int) ($user['id'] ?? 0)],
            null       => ['own' => 0],
            default    => null,
        };
    }

    public static function canView(array $i): bool
    {
        $scope = self::scope();
        if ($scope === null) {
            return true;
        }
        if (isset($scope['own'])) {
            return $scope['own'] > 0 && (int) $i['reported_by'] === $scope['own'];
        }
        return in_array((int) $i['sector_id'], $scope['sector_ids'], true) || (int) $i['reported_by'] === $scope['user_id'];
    }

    /** Hoy en la zona de la empresa. */
    public static function today(): string
    {
        return fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d');
    }

    /**
     * Días perdidos: días corridos desde el inicio de la baja hasta el alta (sin contar el día del alta).
     * Sin alta: hasta hoy inclusive, provisorios.
     * @return array{days:int, provisional:bool}
     */
    public static function lostDays(array $p, ?string $today = null): array
    {
        if (!(int) $p['lost_time'] || !$p['leave_start']) {
            return ['days' => 0, 'provisional' => false];
        }
        $start = new \DateTimeImmutable($p['leave_start']);
        $end = $p['discharge_date'] ? new \DateTimeImmutable($p['discharge_date'])
            : (new \DateTimeImmutable($today ?? self::today()))->modify('+1 day');
        return ['days' => max(0, (int) $start->diff($end)->format('%r%a')), 'provisional' => !$p['discharge_date']];
    }

    // ── alta ────────────────────────────────────────────────────────

    /**
     * Valida un reporte (web y app de campo). Personas: [{employee (uuid) | external_name + external_dni + external_company,
     * role, injury_description}]. @return array{0: array, 1: list<array>, 2: array<string,string>}
     */
    public static function validate(array $in): array
    {
        $errors = [];
        $type = (string) ($in['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            $errors['type'] = 'Elegí qué pasó (tipo).';
        }
        $occurred = self::parseTime($in['occurred_at'] ?? null);
        if ($occurred === null) {
            $errors['occurred_at'] = 'Fecha y hora inválidas (no puede ser futura ni de hace más de un año).';
        }
        $sector = ($in['sector'] ?? '') !== '' ? Sectors::findByUuid((string) $in['sector']) : null;
        $equipment = ($in['equipment'] ?? '') !== '' ? Equipment::findByUuid((string) $in['equipment']) : null;
        if (($in['equipment'] ?? '') !== '' && $equipment === null) {
            $errors['equipment'] = 'Equipo inválido.';
        }
        if ($sector === null && $equipment && $equipment['sector_id']) {
            $sector = Sectors::findById((int) $equipment['sector_id']);
        }
        if ($sector === null && $type !== 'in_itinere') {
            $errors['sector'] = 'Indicá el sector donde pasó.';
        }
        $description = trim((string) ($in['description'] ?? ''));
        if (mb_strlen($description) < 10) {
            $errors['description'] = 'Contá qué pasó (mínimo 10 caracteres).';
        }
        $severity = ($in['potential_severity'] ?? '') !== '' ? CatalogItems::findByUuid((string) $in['potential_severity']) : null;
        if ($severity !== null && $severity['catalog'] !== 'severidad') {
            $severity = null;
        }
        $people = self::parsePeople((array) ($in['people'] ?? []));
        if (isset(self::TYPES[$type]) && self::TYPES[$type]['accident'] && !array_filter($people, fn ($p) => $p['role'] === 'lesionado')) {
            $errors['people'] = 'Indicá quién se lastimó (lesionado).';
        }
        $data = [
            'type' => $type, 'occurred_at' => $occurred,
            'site_id' => $sector ? (int) $sector['site_id'] : ($equipment && $equipment['site_id'] ? (int) $equipment['site_id'] : null),
            'sector_id' => $sector ? (int) $sector['id'] : null, 'equipment_id' => $equipment ? (int) $equipment['id'] : null,
            'location_text' => mb_substr(trim((string) ($in['location_text'] ?? '')), 0, 191) ?: null,
            'lat' => is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, 'lng' => is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null,
            'description' => $description, 'immediate_actions' => trim((string) ($in['immediate_actions'] ?? '')) ?: null,
            'potential_severity_id' => $severity ? (int) $severity['id'] : null,
            '_sector' => $sector, '_equipment' => $equipment, '_severity' => $severity,
        ];
        return [$data, $people, $errors];
    }

    /** Personas del formulario (filas vacías se ignoran). @return list<array> */
    private static function parsePeople(array $rows): array
    {
        $people = [];
        foreach ($rows as $p) {
            $role = isset(self::ROLES[$p['role'] ?? '']) ? $p['role'] : 'lesionado';
            $emp = ($p['employee'] ?? '') !== '' ? Employees::findByUuid((string) $p['employee']) : null;
            $name = trim((string) ($p['external_name'] ?? ''));
            if ($emp === null && $name === '') {
                continue; // fila vacía
            }
            $people[] = [
                'role' => $role, 'employee_id' => $emp ? (int) $emp['id'] : null,
                'external_name' => $emp ? null : mb_substr($name, 0, 160),
                'external_dni' => $emp ? null : (preg_replace('/\D/', '', (string) ($p['external_dni'] ?? '')) ?: null),
                'external_company' => $emp ? null : (mb_substr(trim((string) ($p['external_company'] ?? '')), 0, 160) ?: null),
                'injury_description' => $role === 'lesionado' ? (trim((string) ($p['injury_description'] ?? '')) ?: null) : null,
                'statement' => $role === 'testigo' ? (trim((string) ($p['statement'] ?? '')) ?: null) : null,
                '_label' => $emp ? $emp['last_name'] . ', ' . $emp['first_name'] : $name,
            ];
        }
        return $people;
    }

    /**
     * Alta. @param array $files fotos y documentos [['tmp','name','upload']]
     * @return array{incident: ?array, errors: array<string,string>, duplicate?: bool, file_errors?: list<string>}
     */
    public static function create(array $in, array $files = []): array
    {
        if (!UserAuth::can(self::MODULE, 'crear')) {
            return ['incident' => null, 'errors' => ['_' => 'Tu rol no puede reportar incidentes.']];
        }
        $uuid = strtolower((string) ($in['uuid'] ?? ''));
        if ($uuid !== '') {
            if (!Uuid::isValid($uuid)) {
                return ['incident' => null, 'errors' => ['_' => 'Identificador inválido.']];
            }
            if (($existing = Incidents::findByUuid($uuid)) !== null) {
                return ['incident' => $existing, 'errors' => [], 'duplicate' => true]; // reenvío del celular
            }
        }
        [$data, $people, $errors] = self::validate($in);
        if ($errors) {
            return ['incident' => null, 'errors' => $errors];
        }
        $user = UserAuth::user();
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $number = Sequences::next('incidents');
            $original = [
                'numero' => Incidents::format($number), 'tipo' => self::typeLabel($data['type']), 'fecha_hecho' => $data['occurred_at'],
                'sector' => $data['_sector'] ? (Sectors::labelMap()[(int) $data['_sector']['id']]['label'] ?? $data['_sector']['name']) : null,
                'equipo' => $data['_equipment'] ? $data['_equipment']['code'] . ' · ' . $data['_equipment']['name'] : null,
                'lugar' => $data['location_text'], 'gps' => $data['lat'] !== null ? ['lat' => $data['lat'], 'lng' => $data['lng']] : null,
                'descripcion' => $data['description'], 'acciones_inmediatas' => $data['immediate_actions'],
                'gravedad_potencial' => $data['_severity']['name'] ?? null, 'reportado_por' => $user['name'] ?? null,
                'personas' => array_map(fn ($p) => ['nombre' => $p['_label'], 'rol' => $p['role'], 'lesion' => $p['injury_description'], 'declaracion' => $p['statement']], $people),
            ];
            $json = json_encode($original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $id = Incidents::create(array_filter(array_diff_key($data, ['_sector' => 1, '_equipment' => 1, '_severity' => 1]), fn ($v) => $v !== null) + array_filter([
                'uuid' => $uuid ?: null, 'number' => $number, 'reported_by' => $user['id'] ?? null, 'status' => 'reportado',
                'original_data' => $json, 'original_hash' => hash('sha256', $json),
            ], fn ($v) => $v !== null));
            // Accidente con baja: la baja arranca, por defecto, al día siguiente del hecho (se corrige en el seguimiento).
            $leaveStart = $data['type'] === 'accidente_con_baja'
                ? (new \DateTimeImmutable(fecha($data['occurred_at'], 'Y-m-d')))->modify('+1 day')->format('Y-m-d') : null;
            foreach ($people as $p) {
                unset($p['_label']);
                if ($p['role'] === 'lesionado' && $leaveStart !== null) {
                    $p += ['lost_time' => 1, 'leave_start' => $leaveStart, 'follow_up_status' => 'en_tratamiento'];
                }
                Incidents::addPerson($id, array_filter($p, fn ($v) => $v !== null));
            }
            Incidents::addEvent($id, 'created', ['to' => 'reportado', 'data' => ['tipo' => self::typeLabel($data['type'])]] + self::actor());
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        $incident = Incidents::findById($id);
        $fileErrors = $files ? self::storeFiles($incident, $files, false, false) : [];
        Audit::tenant('incident.create', 'incident', $incident['uuid'], null, ['numero' => Incidents::format($number), 'tipo' => $data['type']]);
        Notifier::dispatch(self::TYPES[$data['type']]['serious'] ? 'incident.serious' : 'incident.reported', $incident);
        return ['incident' => Incidents::findById($id), 'errors' => [], 'file_errors' => $fileErrors];
    }

    // ── cambios ─────────────────────────────────────────────────────

    /** Corrección de clasificación (tipo, sector, equipo, gravedad potencial, hora): evento con antes/después. */
    public static function correct(array $i, array $in): ?string
    {
        if (!UserAuth::can(self::MODULE, 'editar')) {
            return 'No tenés permiso para corregir incidentes.';
        }
        $comment = trim((string) ($in['comment'] ?? ''));
        if (mb_strlen($comment) < 5) {
            return 'Explicá el motivo de la corrección (mínimo 5 caracteres).';
        }
        $new = [];
        if (isset(self::TYPES[$in['type'] ?? ''])) {
            $new['type'] = $in['type'];
        }
        if (($in['sector'] ?? '') !== '' && ($s = Sectors::findByUuid((string) $in['sector']))) {
            $new['sector_id'] = (int) $s['id'];
            $new['site_id'] = (int) $s['site_id'];
        }
        if (array_key_exists('equipment', $in)) {
            $eq = $in['equipment'] !== '' ? Equipment::findByUuid((string) $in['equipment']) : null;
            $new['equipment_id'] = $eq ? (int) $eq['id'] : null;
        }
        if (array_key_exists('potential_severity', $in)) {
            $sv = $in['potential_severity'] !== '' ? CatalogItems::findByUuid((string) $in['potential_severity']) : null;
            $new['potential_severity_id'] = $sv && $sv['catalog'] === 'severidad' ? (int) $sv['id'] : null;
        }
        if (($in['occurred_at'] ?? '') !== '') {
            $t = self::parseTime($in['occurred_at']);
            if ($t === null) {
                return 'Fecha y hora inválidas.';
            }
            $new['occurred_at'] = $t;
        }
        $labels = ['type' => 'Tipo', 'sector_id' => 'Sector', 'equipment_id' => 'Equipo', 'potential_severity_id' => 'Gravedad potencial', 'occurred_at' => 'Fecha del hecho'];
        $show = fn (string $col, $v) => $v === null ? null : match ($col) {
            'type' => self::typeLabel((string) $v),
            'sector_id' => Sectors::labelMap()[(int) $v]['label'] ?? null,
            'equipment_id' => ($e = Equipment::findById((int) $v)) ? $e['code'] . ' · ' . $e['name'] : null,
            'potential_severity_id' => CatalogItems::findById((int) $v)['name'] ?? null,
            'occurred_at' => fecha((string) $v, 'd/m/Y H:i'),
            default => (string) $v,
        };
        $changes = $before = $after = [];
        foreach ($labels as $col => $label) {
            if (array_key_exists($col, $new) && (string) ($i[$col] ?? '') !== (string) ($new[$col] ?? '')) {
                $changes[$col] = $new[$col];
                $before[$label] = $show($col, $i[$col]);
                $after[$label] = $show($col, $new[$col]);
            }
        }
        if (isset($changes['sector_id'])) {
            $changes['site_id'] = $new['site_id'];
        }
        if (!$changes) {
            return 'No hay cambios para corregir.';
        }
        Incidents::update((int) $i['id'], $changes);
        Incidents::addEvent((int) $i['id'], 'correction', ['comment' => $comment, 'data' => ['antes' => $before, 'despues' => $after]] + self::actor());
        Audit::tenant('incident.correct', 'incident', $i['uuid'], $before, $after);
        return null;
    }

    /** Agregar una persona (testigo, otro lesionado) después del reporte. */
    public static function addPerson(array $i, array $in): ?string
    {
        if (!UserAuth::can(self::MODULE, 'editar')) {
            return 'No tenés permiso para editar incidentes.';
        }
        $people = self::parsePeople([$in]);
        if (!$people) {
            return 'Elegí el empleado o escribí el nombre de la persona.';
        }
        $p = $people[0];
        $label = $p['_label'];
        unset($p['_label']);
        Incidents::addPerson((int) $i['id'], array_filter($p, fn ($v) => $v !== null));
        Incidents::addEvent((int) $i['id'], 'person', ['data' => ['persona' => $label, 'rol' => self::ROLES[$p['role']]]] + self::actor());
        Incidents::update((int) $i['id'], []);
        return null;
    }

    /**
     * Lesión, baja, alta y seguimiento de una persona. Los campos de salud exigen incidentes.datos_salud.
     * Cada cambio queda como evento (marcado como de salud) con antes/después.
     */
    public static function updatePerson(array $i, array $p, array $in): ?string
    {
        if (!UserAuth::can(self::MODULE, 'editar') || !self::canSeeHealth()) {
            return 'Para cargar la lesión y el seguimiento hace falta el permiso de datos de salud.';
        }
        $cat = function (string $key, string $catalog) use ($in): ?int {
            $item = ($in[$key] ?? '') !== '' ? CatalogItems::findByUuid((string) $in[$key]) : null;
            return $item && $item['catalog'] === $catalog ? (int) $item['id'] : null;
        };
        $date = fn (string $key) => ($in[$key] ?? '') !== '' ? Resource::parseDate((string) $in[$key]) : null;
        $data = [
            'role'               => isset(self::ROLES[$in['role'] ?? '']) ? $in['role'] : $p['role'],
            'injury_type_id'     => $cat('injury_type', 'lesion'),
            'body_part_id'       => $cat('body_part', 'parte_cuerpo'),
            'accident_form_id'   => $cat('accident_form', 'forma_accidente'),
            'injury_agent'       => mb_substr(trim((string) ($in['injury_agent'] ?? '')), 0, 191) ?: null,
            'injury_description' => trim((string) ($in['injury_description'] ?? '')) ?: null,
            'medical_attention'  => isset(self::ATTENTION[$in['medical_attention'] ?? '']) ? $in['medical_attention'] : null,
            'lost_time'          => !empty($in['lost_time']) ? 1 : 0,
            'leave_start'        => $date('leave_start'),
            'discharge_date'     => $date('discharge_date'),
            'return_date'        => $date('return_date'),
            'follow_up_status'   => isset(self::FOLLOW_UP[$in['follow_up_status'] ?? '']) ? $in['follow_up_status'] : null,
            'art_case_number'    => mb_substr(trim((string) ($in['art_case_number'] ?? '')), 0, 40) ?: null,
            'statement'          => trim((string) ($in['statement'] ?? '')) ?: null,
        ];
        if ($data['lost_time'] && $data['leave_start'] === null) {
            return 'Con baja: indicá desde cuándo.';
        }
        if (!$data['lost_time']) {
            $data['leave_start'] = $data['discharge_date'] = null;
        }
        if ($data['discharge_date'] !== null && $data['discharge_date'] < (string) $data['leave_start']) {
            return 'El alta no puede ser anterior al inicio de la baja.';
        }
        if ($data['return_date'] !== null && $data['discharge_date'] !== null && $data['return_date'] < $data['discharge_date']) {
            return 'El reingreso no puede ser anterior al alta.';
        }
        if ($data['discharge_date'] !== null && $data['discharge_date'] > self::today()) {
            return 'El alta no puede ser una fecha futura.';
        }
        $labels = ['role' => 'Rol', 'injury_type_id' => 'Lesión', 'body_part_id' => 'Parte del cuerpo', 'accident_form_id' => 'Forma del accidente',
            'injury_agent' => 'Agente material', 'injury_description' => 'Descripción de la lesión', 'medical_attention' => 'Atención',
            'lost_time' => 'Con baja', 'leave_start' => 'Inicio de la baja', 'discharge_date' => 'Alta médica', 'return_date' => 'Reingreso',
            'follow_up_status' => 'Seguimiento', 'art_case_number' => 'N° de siniestro ART', 'statement' => 'Declaración'];
        $show = fn (string $col, $v) => $v === null || $v === '' ? null : match ($col) {
            'role' => self::ROLES[$v] ?? $v, 'medical_attention' => self::ATTENTION[$v] ?? $v, 'follow_up_status' => self::FOLLOW_UP[$v] ?? $v,
            'lost_time' => (int) $v ? 'Sí' : 'No', 'injury_type_id', 'body_part_id', 'accident_form_id' => CatalogItems::findById((int) $v)['name'] ?? null,
            'leave_start', 'discharge_date', 'return_date' => date('d/m/Y', strtotime((string) $v)),
            default => (string) $v,
        };
        $changes = $before = $after = [];
        foreach ($labels as $col => $label) {
            if ((string) ($p[$col] ?? '') !== (string) ($data[$col] ?? '')) {
                $changes[$col] = $data[$col];
                $before[$label] = $show($col, $p[$col]);
                $after[$label] = $show($col, $data[$col]);
            }
        }
        if (!$changes) {
            return 'No hay cambios.';
        }
        Incidents::updatePerson((int) $p['id'], $changes);
        $name = $p['employee_id'] ? $p['last_name'] . ', ' . $p['first_name'] : $p['external_name'];
        $healthOnly = array_diff_key($changes, ['role' => 1, 'statement' => 1]) !== [];
        Incidents::addEvent((int) $i['id'], 'follow_up', ['health' => $healthOnly, 'data' => ['persona' => $name, 'antes' => $before, 'despues' => $after]] + self::actor());
        Incidents::update((int) $i['id'], []);
        Audit::tenant('incident.person_update', 'incident', $i['uuid'], ['persona' => $name] + $before, $after);
        return null;
    }

    public static function transition(array $i, string $key, array $input): ?string
    {
        $t = self::TRANSITIONS[$key] ?? null;
        if ($t === null) {
            return 'Acción desconocida.';
        }
        if (!UserAuth::can(self::MODULE, $t['perm'])) {
            return 'No tenés permiso para esta acción.';
        }
        $comment = trim((string) ($input['comment'] ?? ''));
        if ($t['comment'] && mb_strlen($comment) < 5) {
            return 'Escribí el motivo (mínimo 5 caracteres).';
        }
        if ($key === 'cerrar' && self::TYPES[$i['type']]['investigation'] && $i['status'] !== 'investigado') {
            return 'En un ' . mb_strtolower(self::typeLabel($i['type'])) . ' hay que terminar la investigación antes de cerrar.';
        }
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $locked = Incidents::findById((int) $i['id'], true);
            if (!in_array($locked['status'], $t['from'], true)) {
                $db->rollBack();
                return 'El incidente ya está "' . self::STATES[$locked['status']]['label'] . '". Recargá la página.';
            }
            Incidents::update((int) $i['id'], ['status' => $t['to'], 'closed_at' => $t['to'] === 'cerrado' ? gmdate('Y-m-d H:i:s') : null]);
            Incidents::addEvent((int) $i['id'], 'status', ['from' => $locked['status'], 'to' => $t['to'], 'comment' => $comment ?: null] + self::actor());
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
        Audit::tenant('incident.' . $key, 'incident', $i['uuid'], ['estado' => $i['status']], ['estado' => $t['to']]);
        if ($key === 'cerrar') {
            Notifier::dispatch('incident.closed', Incidents::findById((int) $i['id']), ['comment' => $comment]);
        }
        return null;
    }

    public static function comment(array $i, string $comment): ?string
    {
        $comment = trim($comment);
        if (mb_strlen($comment) < 2) {
            return 'El comentario está vacío.';
        }
        Incidents::addEvent((int) $i['id'], 'comment', ['comment' => mb_substr($comment, 0, 5000)] + self::actor());
        return null;
    }

    /**
     * Fotos (ImageProcessor: original intacto + miniatura) o PDF. $health = documento médico (solo con permiso).
     * @return list<string> errores por archivo
     */
    public static function storeFiles(array $i, array $files, bool $health, bool $logEvent = true): array
    {
        if ($health && !self::canSeeHealth()) {
            return ['Los documentos médicos requieren el permiso de datos de salud.'];
        }
        $errors = [];
        $saved = 0;
        $tenant = Tenant::current()['uuid'];
        $me = UserAuth::user()['id'] ?? null;
        foreach (array_slice($files, 0, 10) as $f) {
            try {
                $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp']) ?: '';
                if (isset(ImageProcessor::TYPES[$mime])) {
                    $meta = ImageProcessor::store($tenant, $f['tmp'], $f['name'] ?? null, $f['upload'] ?? true, $me, 'incidents');
                } else {
                    $rel = TenantFiles::storeFile($tenant, $f['tmp'], 'incidents/' . gmdate('Y') . '/' . gmdate('m'), ImageProcessor::TYPES + ['application/pdf' => 'pdf'],
                        ImageProcessor::MAX_BYTES, $f['upload'] ?? true);
                    $full = TenantFiles::path($tenant, $rel);
                    $meta = ['path' => $rel, 'original_name' => isset($f['name']) ? mb_substr(basename((string) $f['name']), 0, 191) : null, 'mime' => $mime,
                        'size_bytes' => (int) filesize($full), 'sha256' => hash_file('sha256', $full), 'uploaded_by' => $me];
                }
                Incidents::addAttachment((int) $i['id'], $health, $meta);
                $saved++;
            } catch (\DomainException $e) {
                $errors[] = ($f['name'] ?? 'Archivo') . ': ' . $e->getMessage();
            }
        }
        if ($saved && $logEvent) {
            Incidents::addEvent((int) $i['id'], 'attachment', ['health' => $health, 'data' => ['archivos' => $saved, 'medico' => $health]] + self::actor());
        }
        return $errors;
    }

    /** "Y-m-d\TH:i" local (formulario) o ISO 8601 con zona (celular) → UTC. Máx. 1 año atrás, no futuro. */
    private static function parseTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            $v = (string) $value;
            $t = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v) ? new \DateTimeImmutable($v) : new \DateTimeImmutable($v, new \DateTimeZone(Tenant::timezone() ?? 'UTC'));
        } catch (\Exception) {
            return null;
        }
        $ts = $t->getTimestamp();
        return ($ts > time() + 600 || $ts < time() - 366 * 86400) ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    private static function actor(): array
    {
        $user = UserAuth::user();
        if ($user !== null) {
            return ['user_id' => (int) $user['id'], 'actor_name' => $user['name']];
        }
        $admin = AdminAuth::user();
        return ['user_id' => null, 'actor_name' => $admin ? $admin['name'] . ' (soporte)' : 'Sistema'];
    }
}
