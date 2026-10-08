<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Tenant;
use App\Core\Uuid;
use App\Models\Equipment;
use App\Models\InspectionSchedules;
use App\Models\InspectionTemplates;
use App\Models\Inspections;
use App\Models\Sectors;
use App\Models\Sequences;
use App\Models\Settings;
use App\Models\Users;
use App\Services\Notify\Notifier;

/**
 * Inspecciones: alta con el resultado calculado en el servidor, respuestas inmutables con hash,
 * una acción CAPA por cada ítem que no cumple y aviso inmediato si falla un ítem crítico.
 */
final class InspectionService
{
    public const MODULE = 'inspecciones';
    public const RESULTS = [
        'conforme'            => ['label' => 'Conforme', 'class' => 'success'],
        'con_observaciones'   => ['label' => 'Con observaciones', 'class' => 'warning'],
        'no_conforme_critico' => ['label' => 'No conforme (crítico)', 'class' => 'danger'],
    ];

    /** Alcance para listar/ver. null = todo; propios = las que hizo. */
    public static function scope(): ?array
    {
        $me = (int) (UserAuth::user()['id'] ?? 0);
        return match (UserAuth::scope(self::MODULE)) {
            'todo'     => null,
            'sectores' => ['sector_ids' => SectorScope::sectorIds(self::MODULE) ?? [], 'user_id' => $me],
            'propios'  => ['own' => $me],
            default    => ['own' => 0],
        };
    }

    public static function canView(array $i): bool
    {
        $scope = self::scope();
        if ($scope === null) {
            return true;
        }
        $me = (int) (UserAuth::user()['id'] ?? 0);
        if ($me > 0 && (int) $i['inspector_user_id'] === $me) {
            return true;
        }
        return isset($scope['sector_ids']) && in_array((int) $i['sector_id'], $scope['sector_ids'], true);
    }

    /**
     * Alta. @param array $in template (uuid), equipment (uuid, opcional), sector (uuid, opcional), done_at (ISO / local),
     *   lat, lng, gps_accuracy, notes, uuid (del celular, opcional), answers [key => [value, comment]]
     * @param array<string, list<array{tmp:string,name:?string,upload:bool}>> $photos fotos por item_key
     * @return array{inspection: ?array, errors: array<string,string>, duplicate?: bool}
     */
    public static function create(array $in, array $photos = [], array $declaredPhotos = []): array
    {
        if (!UserAuth::can(self::MODULE, 'crear')) {
            return ['inspection' => null, 'errors' => ['_' => 'Tu rol no puede hacer inspecciones.']];
        }
        $uuid = strtolower((string) ($in['uuid'] ?? ''));
        if ($uuid !== '') {
            if (!Uuid::isValid($uuid)) {
                return ['inspection' => null, 'errors' => ['_' => 'Identificador inválido.']];
            }
            if (($existing = Inspections::findByUuid($uuid)) !== null) {
                return ['inspection' => $existing, 'errors' => [], 'duplicate' => true]; // reenvío del celular
            }
        }
        $template = InspectionTemplates::findByUuid((string) ($in['template'] ?? ''));
        if ($template === null || !$template['current_version_id']) {
            return ['inspection' => null, 'errors' => ['_' => 'Elegí un checklist.']];
        }
        // La versión: la que trae el celular (si la plantilla cambió mientras estaba sin señal) o la vigente.
        $version = null;
        if (($in['version'] ?? '') !== '') {
            foreach (InspectionTemplates::versions((int) $template['id']) as $v) {
                if ($v['uuid'] === $in['version']) {
                    $version = InspectionTemplates::version((int) $v['id']);
                }
            }
        }
        $version ??= InspectionTemplates::version((int) $template['current_version_id']);

        $errors = [];
        $equipment = ($in['equipment'] ?? '') !== '' ? Equipment::findByUuid((string) $in['equipment']) : null;
        if (($in['equipment'] ?? '') !== '' && $equipment === null) {
            $errors['equipment'] = 'Equipo inválido.';
        }
        if ($template['scope'] === 'equipo' && $equipment === null) {
            $errors['equipment'] = 'Elegí el equipo que inspeccionás.';
        }
        $sector = ($in['sector'] ?? '') !== '' ? Sectors::findByUuid((string) $in['sector']) : null;
        if ($sector === null && $equipment && $equipment['sector_id']) {
            $sector = Sectors::findById((int) $equipment['sector_id']);
        }
        if ($template['scope'] === 'sector' && $sector === null) {
            $errors['sector'] = 'Elegí el sector que inspeccionás.';
        }
        $doneAt = self::deviceTime($in['done_at'] ?? null);
        if ($doneAt === null) {
            $errors['done_at'] = 'Fecha y hora inválidas (no puede ser futura ni de hace más de 30 días).';
        }
        // Fotos: las que llegan en este request (web) o las que la app declara que va a subir por partes.
        $photoCounts = array_map('count', $photos);
        foreach ($declaredPhotos as $key => $n) {
            $photoCounts[(string) $key] = max($photoCounts[(string) $key] ?? 0, (int) $n);
        }
        $eval = InspectionStructure::evaluate($version['structure'], (array) ($in['answers'] ?? []), $photoCounts);
        $errors += $eval['errors'];
        // Programada que cumple esta inspección: la indicada (desde el aviso / la lista) o la que corresponda.
        $schedule = null;
        if (!$errors) {
            $localDate = fecha($doneAt, 'Y-m-d');
            if (($in['schedule'] ?? '') !== '') {
                $schedule = InspectionSchedules::findByUuid((string) $in['schedule']);
                $matches = $schedule && $schedule['status'] === 'pendiente' && (int) $schedule['template_id'] === (int) $template['id']
                    && ($equipment ? (int) $schedule['equipment_id'] === (int) $equipment['id'] : (int) $schedule['sector_id'] === (int) ($sector['id'] ?? 0));
                $schedule = $matches ? $schedule : null;
            }
            $schedule ??= InspectionSchedules::matchFor((int) $template['id'], $equipment ? (int) $equipment['id'] : null,
                $equipment ? null : ($sector ? (int) $sector['id'] : null), $localDate);
        }
        if ($errors) {
            return ['inspection' => null, 'errors' => $errors];
        }

        $user = UserAuth::user();
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $number = Sequences::next('inspections');
            $original = [
                'numero' => Inspections::format($number), 'checklist' => $template['name'], 'version' => (int) $version['version'],
                'equipo' => $equipment ? $equipment['code'] . ' · ' . $equipment['name'] : null,
                'sector' => $sector ? (Sectors::labelMap()[(int) $sector['id']]['label'] ?? $sector['name']) : null,
                'inspector' => $user['name'] ?? null, 'fecha' => $doneAt, 'resultado' => $eval['result'], 'cumplimiento' => $eval['score'],
                'gps' => is_numeric($in['lat'] ?? null) ? ['lat' => (float) $in['lat'], 'lng' => (float) $in['lng']] : null,
                'notas' => trim((string) ($in['notes'] ?? '')) ?: null,
                'respuestas' => array_map(fn ($r) => ['item' => $r['text'], 'respuesta' => $r['value'], 'cumple' => $r['ok'], 'critico' => $r['critical'],
                    'comentario' => $r['comment']], $eval['rows']),
            ];
            $json = json_encode($original, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $id = Inspections::create(array_filter([
                'uuid' => $uuid ?: null, 'number' => $number, 'template_id' => (int) $template['id'], 'template_version_id' => (int) $version['id'],
                'schedule_id' => $schedule ? (int) $schedule['id'] : null,
                'equipment_id' => $equipment ? (int) $equipment['id'] : null,
                'sector_id' => $sector ? (int) $sector['id'] : null,
                'site_id' => $sector ? (int) $sector['site_id'] : ($equipment && $equipment['site_id'] ? (int) $equipment['site_id'] : null),
                'inspector_user_id' => $user['id'] ?? null, 'done_at_device' => $doneAt,
                'lat' => is_numeric($in['lat'] ?? null) ? (float) $in['lat'] : null, 'lng' => is_numeric($in['lng'] ?? null) ? (float) $in['lng'] : null,
                'gps_accuracy_m' => is_numeric($in['gps_accuracy'] ?? null) ? (int) $in['gps_accuracy'] : null,
                'result' => $eval['result'], 'score' => $eval['score'], 'items_ok' => $eval['ok'], 'items_fail' => $eval['fail'],
                'items_critical_fail' => $eval['critical_fail'], 'notes' => $original['notas'], 'status' => 'completa',
                'original_data' => $json, 'original_hash' => hash('sha256', $json),
            ], fn ($v) => $v !== null));
            $answerIds = [];
            foreach ($eval['rows'] as $row) {
                $answerIds[$row['key']] = Inspections::addAnswer($id, $row);
            }
            Inspections::addEvent($id, 'created', ['data' => ['resultado' => $eval['result'], 'cumplimiento' => $eval['score']]] + self::actor());
            if ($schedule) {
                InspectionSchedules::markDone((int) $schedule['id'], $id, $localDate, $localDate <= $schedule['due_on']);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $inspection = Inspections::findById($id);
        $photoErrors = self::storePhotos($inspection, $photos);
        Audit::tenant('inspection.create', 'inspection', $inspection['uuid'], null, ['numero' => Inspections::format($number), 'resultado' => $eval['result']]);
        $program = $schedule ? \App\Models\InspectionPrograms::findById((int) $schedule['program_id']) : self::programFor($template, $equipment, $sector);
        self::createActions($inspection, $eval['rows'], $answerIds, ['action_responsible_id' => $program['action_responsible_user_id'] ?? null]);
        if ($eval['critical_fail'] > 0) {
            Notifier::dispatch('inspection.critical_fail', $inspection);
        }
        return ['inspection' => Inspections::findById($id), 'errors' => [], 'photo_errors' => $photoErrors];
    }

    /** Una acción CAPA por cada ítem que no cumple (crítica si el ítem es crítico). */
    private static function createActions(array $inspection, array $rows, array $answerIds, array $in): void
    {
        $failed = array_filter($rows, fn ($r) => $r['ok'] === false);
        if (!$failed) {
            return;
        }
        $responsible = self::actionResponsible($inspection, $in);
        $today = ActionService::today();
        $days = fn (bool $critical) => max(1, (int) Settings::get($critical ? 'inspecciones.plazo_critico_dias' : 'inspecciones.plazo_dias', $critical ? '1' : '7'));
        $where = trim(($inspection['equipment_code'] ? $inspection['equipment_code'] . ' · ' . $inspection['equipment_name'] : '')
            . ($inspection['sector_name'] ? ' (' . $inspection['sector_name'] . ')' : ''));
        $created = [];
        foreach ($failed as $row) {
            $data = [
                'title'               => mb_substr(($inspection['equipment_code'] ? $inspection['equipment_code'] . ': ' : '') . $row['text'], 0, 191),
                'description'         => 'No cumple en la inspección ' . Inspections::format((int) $inspection['number']) . ' (' . $inspection['template_name'] . ').'
                    . ($where !== '' ? "\nDónde: {$where}" : '') . "\nRespuesta: " . InspectionStructure::valueLabel($row['value'])
                    . ($row['comment'] ? "\nComentario: " . $row['comment'] : ''),
                'type'                => 'correctiva',
                'priority'            => $row['critical'] ? 'critica' : 'media',
                'responsible_user_id' => $responsible,
                'due_on'              => (new \DateTimeImmutable($today))->modify('+' . $days($row['critical']) . ' days')->format('Y-m-d'),
                'sector_id'           => $inspection['sector_id'] !== null ? (int) $inspection['sector_id'] : null,
                'site_id'             => $inspection['site_id'] !== null ? (int) $inspection['site_id'] : null,
            ];
            $action = ActionService::create($data, 'inspeccion', (int) $inspection['id'])['action'];
            Inspections::linkAnswerAction($answerIds[$row['key']], (int) $action['id']);
            $created[] = \App\Models\Actions::format((int) $action['number']);
        }
        Inspections::addEvent((int) $inspection['id'], 'action_created', ['data' => ['acciones' => $created], 'actor_name' => 'Sistema']);
    }

    /** Sin programada: el programa activo de esa plantilla que cubre al equipo / sector (para el responsable de acciones). */
    private static function programFor(array $template, ?array $equipment, ?array $sector): ?array
    {
        foreach (\App\Models\InspectionPrograms::forTemplate((int) $template['id']) as $p) {
            foreach (InspectionPlanner::targets($p) as [$eqId, $secId]) {
                if ($equipment ? $eqId === (int) $equipment['id'] : ($eqId === null && $secId === (int) ($sector['id'] ?? 0))) {
                    return $p;
                }
            }
        }
        return null;
    }

    /**
     * Responsable de las acciones automáticas: el que indique el programa (Etapa 11 entrega 2), si no un
     * supervisor del sector (con un sector asignado igual o por encima), si no quien inspeccionó.
     */
    private static function actionResponsible(array $inspection, array $in): int
    {
        if (!empty($in['action_responsible_id'])) {
            return (int) $in['action_responsible_id'];
        }
        if ($inspection['sector_id'] !== null) {
            $sector = Sectors::findById((int) $inspection['sector_id']);
            $stmt = DB::tenant()->prepare("SELECT u.* FROM user_sectors us JOIN sectors s ON s.id = us.sector_id JOIN users u ON u.id = us.user_id
                WHERE ? LIKE CONCAT(s.path, '%') AND u.is_active = 1 ORDER BY s.depth DESC, u.name");
            $stmt->execute([$sector['path']]);
            foreach ($stmt->fetchAll() as $u) {
                if (Users::canSignIn($u)) {
                    return (int) $u['id'];
                }
            }
        }
        return (int) $inspection['inspector_user_id'];
    }

    /** @return list<string> errores por foto (las válidas se guardan igual) */
    public static function storePhotos(array $inspection, array $photos): array
    {
        $errors = [];
        $tenant = Tenant::current()['uuid'];
        foreach ($photos as $key => $files) {
            foreach ($files as $f) {
                try {
                    $meta = ImageProcessor::store($tenant, $f['tmp'], $f['name'] ?? null, $f['upload'] ?? true, UserAuth::user()['id'] ?? null, 'inspections');
                    Inspections::addAttachment((int) $inspection['id'], Uuid::isValid((string) $key) ? (string) $key : null, $meta);
                } catch (\DomainException $e) {
                    $errors[] = ($f['name'] ?? 'Foto') . ': ' . $e->getMessage();
                }
            }
        }
        return $errors;
    }

    public static function annul(array $inspection, string $reason): ?string
    {
        if (!UserAuth::can(self::MODULE, 'cerrar') || !self::canView($inspection)) {
            return 'No tenés permiso para anular inspecciones.';
        }
        if ($inspection['status'] !== 'completa') {
            return 'La inspección ya está anulada.';
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            return 'Escribí el motivo de la anulación (mínimo 5 caracteres).';
        }
        Inspections::annul((int) $inspection['id'], (int) UserAuth::user()['id'], $reason);
        Inspections::addEvent((int) $inspection['id'], 'annulled', ['comment' => $reason] + self::actor());
        Audit::tenant('inspection.annul', 'inspection', $inspection['uuid'], ['estado' => 'completa'], ['estado' => 'anulada', 'motivo' => $reason]);
        return null;
    }

    /** Fecha y hora del hecho (ISO 8601 del celular o "Y-m-d\TH:i" local del formulario web) en UTC. */
    private static function deviceTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return gmdate('Y-m-d H:i:s');
        }
        try {
            $value = (string) $value;
            $hasZone = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value);
            $t = $hasZone ? new \DateTimeImmutable($value) : new \DateTimeImmutable($value, new \DateTimeZone(Tenant::timezone() ?? 'UTC'));
        } catch (\Exception) {
            return null;
        }
        $ts = $t->getTimestamp();
        return ($ts > time() + 600 || $ts < time() - 30 * 86400) ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    private static function actor(): array
    {
        $user = UserAuth::user();
        return $user ? ['user_id' => (int) $user['id'], 'actor_name' => $user['name']] : ['user_id' => null, 'actor_name' => 'Sistema'];
    }
}
