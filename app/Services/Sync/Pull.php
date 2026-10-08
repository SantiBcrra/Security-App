<?php
declare(strict_types=1);

namespace App\Services\Sync;

use App\Core\DB;
use App\Core\Tenant;
use App\Models\Observations;
use App\Models\Patrols;
use App\Models\Sectors;
use App\Models\Settings;
use App\Services\ActionService;
use App\Services\ActionWorkflow;
use App\Services\ObservationService;
use App\Services\ObservationWorkflow;
use App\Services\UserAuth;

/**
 * Pull de la sincronización offline: lo que cambió desde el cursor, por entidad.
 * Cursor por entidad = (updated_at, id) del último registro enviado, codificado en base64url.
 * Las bajas (inactivos / borrados) viajan en "deleted". El cliente hace upsert por uuid:
 * recibir algo dos veces no duplica (hay una ventana de 5 s de solapamiento para cambios recientes).
 */
final class Pull
{
    public const ENTITIES = ['catalog_items', 'sites', 'sectors', 'equipment', 'employees', 'observations', 'patrol_points', 'patrol_routes', 'patrol_rounds', 'patrol_scans', 'actions', 'inspection_templates', 'inspection_schedule', 'incidents'];
    private const OVERLAP_SECONDS = 5;
    private const OBS_HISTORY_DAYS = 180;

    public static function run(?string $cursor, int $limit): array
    {
        $limit = max(10, min(1000, $limit));
        $positions = self::decode($cursor);
        $changes = [];
        $deleted = [];
        $hasMore = false;
        $newPositions = [];
        $sectorLabels = null;

        foreach (self::ENTITIES as $entity) {
            [$ts, $id] = $positions[$entity] ?? ['1970-01-01 00:00:00', 0];
            [$sql, $params] = self::query($entity, $ts, (int) $id, $limit + 1);
            $stmt = DB::tenant()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $more = count($rows) > $limit;
            $rows = array_slice($rows, 0, $limit);
            $hasMore = $hasMore || $more;

            foreach ($rows as $row) {
                $gone = (isset($row['is_active']) && (int) $row['is_active'] !== 1) || !empty($row['deleted_at'])
                    // Acciones: el alcance se decide en PHP; si dejó de verla (ej. la reasignaron) le llega como baja.
                    || ($entity === 'actions' && !ActionService::canView($row))
                    // Checklists: solo a quien puede hacer inspecciones. Programadas: solo las pendientes a su cargo.
                    || ($entity === 'inspection_templates' && (!UserAuth::can('inspecciones', 'crear') || $row['version_uuid'] === null))
                    || ($entity === 'inspection_schedule' && ($row['status'] !== 'pendiente' || !self::scheduleIsMine($row)))
                    // Incidentes: al celular solo llegan los que reportó el usuario (sin datos de salud).
                    || ($entity === 'incidents' && (int) $row['reported_by'] !== (int) (UserAuth::user()['id'] ?? 0));
                if ($gone) {
                    $deleted[$entity][] = $row['uuid'];
                } else {
                    if ($entity === 'sectors') {
                        $sectorLabels ??= Sectors::labelMap();
                    }
                    $changes[$entity][] = self::shape($entity, $row, $sectorLabels);
                }
            }
            $newPositions[$entity] = self::nextPosition($rows, $more, $ts, (int) $id);
        }

        $user = UserAuth::user();
        return [
            'changes'     => (object) $changes,
            'deleted'     => (object) $deleted,
            'cursor'      => self::encode($newPositions),
            'has_more'    => $hasMore,
            'server_time' => gmdate('Y-m-d\TH:i:s\Z'),
            'meta'        => [
                'empresa'           => ['nombre' => Tenant::current()['name'], 'zona_horaria' => Tenant::timezone()],
                'anonimo_habilitado'=> Settings::bool('observaciones.anonimo_habilitado'),
                'permisos'          => UserAuth::permissions(),
                'usuario'           => $user ? ['uuid' => $user['uuid'], 'nombre' => $user['name'], 'rol' => $user['role_name']] : null,
            ],
        ];
    }

    /** ¿La programada está a cargo del usuario actual? (usuario, rol o supervisor del sector) */
    private static function scheduleIsMine(array $row): bool
    {
        static $mine = null, $forUser = null;
        $user = UserAuth::user();
        if ($forUser !== ($user['id'] ?? null)) {
            $mine = \App\Services\InspectionPlanner::mine();
            $forUser = $user['id'] ?? null;
        }
        return match ($row['assignee_type']) {
            'user'  => (int) $row['assignee_user_id'] === $mine['user_id'],
            'role'  => $row['assignee_role'] === $mine['role'],
            default => $row['sector_id'] !== null && in_array((int) $row['sector_id'], $mine['sector_ids'], true),
        };
    }

    /** Siguiente cursor: exacto si el último cambio es "viejo"; con solapamiento si es muy reciente. */
    private static function nextPosition(array $rows, bool $more, string $ts, int $id): array
    {
        if (!$rows) {
            return [$ts, $id];
        }
        $last = end($rows);
        if ($more) {
            return [$last['updated_at'], (int) $last['id']];
        }
        $recent = gmdate('Y-m-d H:i:s', time() - self::OVERLAP_SECONDS);
        return $last['updated_at'] < $recent ? [$last['updated_at'], (int) $last['id']] : [$recent, 0];
    }

    private static function query(string $entity, string $ts, int $id, int $limit): array
    {
        $after = '(t.updated_at > ? OR (t.updated_at = ? AND t.id > ?))';
        $params = [$ts, $ts, $id];
        $order = ' ORDER BY t.updated_at, t.id LIMIT ' . $limit;
        switch ($entity) {
            case 'catalog_items':
                return ["SELECT t.* FROM catalog_items t WHERE {$after}{$order}", $params];
            case 'sites':
                return ["SELECT t.* FROM sites t WHERE {$after}{$order}", $params];
            case 'sectors':
                return ["SELECT t.*, si.uuid AS site_uuid, pa.uuid AS parent_uuid FROM sectors t JOIN sites si ON si.id = t.site_id
                    LEFT JOIN sectors pa ON pa.id = t.parent_id WHERE {$after}{$order}", $params];
            case 'equipment':
                return ["SELECT t.*, ty.uuid AS type_uuid, si.uuid AS site_uuid, se.uuid AS sector_uuid FROM equipment t
                    LEFT JOIN catalog_items ty ON ty.id = t.type_id LEFT JOIN sites si ON si.id = t.site_id
                    LEFT JOIN sectors se ON se.id = t.sector_id WHERE {$after}{$order}", $params];
            case 'employees':
                return ["SELECT t.id, t.uuid, t.last_name, t.first_name, t.is_active, t.deleted_at, t.updated_at, se.uuid AS sector_uuid
                    FROM employees t LEFT JOIN sectors se ON se.id = t.sector_id WHERE {$after}{$order}", $params];
            case 'observations':
                $where = [$after, 't.created_at_device > UTC_TIMESTAMP() - INTERVAL ' . self::OBS_HISTORY_DAYS . ' DAY'];
                $scope = ObservationService::scope();
                if ($scope !== null) {
                    if (isset($scope['own'])) {
                        $where[] = 't.reporter_user_id = ?';
                        $params[] = $scope['own'];
                    } else {
                        $where[] = '(t.sector_id IN (' . implode(',', array_map('intval', $scope['sector_ids'] ?: [0])) . ') OR t.reporter_user_id = ?)';
                        $params[] = $scope['user_id'];
                    }
                }
                return ['SELECT t.*, ca.uuid AS category_uuid, sv.uuid AS severity_uuid, rt.uuid AS risk_uuid, se.uuid AS sector_uuid,
                        eq.uuid AS equipment_uuid, au.name AS assigned_name, ru.uuid AS reporter_uuid
                    FROM observations t LEFT JOIN catalog_items ca ON ca.id = t.category_id LEFT JOIN catalog_items sv ON sv.id = t.severity_id
                    LEFT JOIN catalog_items rt ON rt.id = t.risk_type_id LEFT JOIN sectors se ON se.id = t.sector_id
                    LEFT JOIN equipment eq ON eq.id = t.equipment_id LEFT JOIN users au ON au.id = t.assigned_user_id
                    LEFT JOIN users ru ON ru.id = t.reporter_user_id
                    WHERE ' . implode(' AND ', $where) . $order, $params];
            case 'actions':
                // Las terminadas hace más de 60 días no viajan (el historial completo está en la web).
                return ["SELECT t.*, ru.name AS responsible_name, se.uuid AS sector_uuid, o.uuid AS observation_uuid, o.number AS observation_number
                    FROM actions t JOIN users ru ON ru.id = t.responsible_user_id LEFT JOIN sectors se ON se.id = t.sector_id
                    LEFT JOIN observations o ON t.origin_type = 'observacion' AND o.id = t.origin_id
                    WHERE {$after} AND (t.status IN ('abierta', 'en_curso', 'cerrada') OR t.updated_at > UTC_TIMESTAMP() - INTERVAL 60 DAY){$order}", $params];
            case 'incidents':
                return ["SELECT t.id, t.uuid, t.number, t.type, t.status, t.occurred_at, t.description, t.reported_by, t.updated_at, t.deleted_at,
                        se.uuid AS sector_uuid FROM incidents t LEFT JOIN sectors se ON se.id = t.sector_id
                    WHERE {$after} AND t.occurred_at > UTC_TIMESTAMP() - INTERVAL 180 DAY{$order}", $params];
            case 'inspection_templates':
                return ["SELECT t.*, v.uuid AS version_uuid, v.structure, ty.uuid AS type_uuid FROM inspection_templates t
                    LEFT JOIN inspection_template_versions v ON v.id = t.current_version_id LEFT JOIN catalog_items ty ON ty.id = t.equipment_type_id
                    WHERE {$after}{$order}", $params];
            case 'inspection_schedule':
                return ["SELECT t.*, p.assignee_type, p.assignee_user_id, p.assignee_role, p.name AS program_name, tp.uuid AS template_uuid,
                        eq.uuid AS equipment_uuid, se.uuid AS sector_uuid
                    FROM inspection_schedule t JOIN inspection_programs p ON p.id = t.program_id JOIN inspection_templates tp ON tp.id = p.template_id
                    LEFT JOIN equipment eq ON eq.id = t.equipment_id LEFT JOIN sectors se ON se.id = t.sector_id
                    WHERE {$after}{$order}", $params];
            case 'patrol_points':
                return ["SELECT t.* FROM patrol_points t WHERE {$after}{$order}", $params];
            case 'patrol_routes':
                // Solo las rutas asignadas (o todas con alcance "todo"): las demás viajan como baja,
                // así el celular se entera si le sacan una ruta. Cambiar asignaciones o puntos debe
                // tocar patrol_routes.updated_at.
                $all = UserAuth::scope('rondas') === 'todo' ? 1 : 0;
                return ["SELECT t.id, t.uuid, t.name, t.description, t.frequency, t.expected_minutes, t.updated_at, t.deleted_at,
                        CASE WHEN t.is_active = 1 AND (? = 1 OR EXISTS (SELECT 1 FROM patrol_route_assignments a
                            WHERE a.route_id = t.id AND a.user_id = ? AND a.is_active = 1)) THEN 1 ELSE 0 END AS is_active
                    FROM patrol_routes t WHERE {$after}{$order}", array_merge([$all, (int) (UserAuth::user()['id'] ?? 0)], $params)];
            case 'patrol_rounds':
                $join = 'SELECT t.*, pr.uuid AS route_uuid FROM patrol_rounds t LEFT JOIN patrol_routes pr ON pr.id=t.route_id WHERE ';
                if (UserAuth::scope('rondas') === 'propios') { $params[] = (int) UserAuth::user()['id']; return [$join . "{$after} AND t.user_id=?{$order}", $params]; }
                return [$join . $after . $order, $params];
            case 'patrol_scans':
                $join = 'SELECT t.*, pr.uuid AS round_uuid, pp.uuid AS point_uuid FROM patrol_scans t JOIN patrol_rounds pr ON pr.id=t.round_id JOIN patrol_points pp ON pp.id=t.point_id WHERE ';
                if (UserAuth::scope('rondas') === 'propios') { $params[] = (int) UserAuth::user()['id']; return [$join . "{$after} AND t.user_id=?{$order}", $params]; }
                return [$join . $after . $order, $params];
        }
        throw new \InvalidArgumentException($entity);
    }

    private static function shape(string $entity, array $r, ?array $sectorLabels): array
    {
        return match ($entity) {
            'catalog_items' => ['uuid' => $r['uuid'], 'catalog' => $r['catalog'], 'name' => $r['name'], 'code' => $r['code'],
                'description' => $r['description'], 'color' => $r['color'], 'level' => $r['level'] !== null ? (int) $r['level'] : null, 'sort' => (int) $r['sort_order']],
            'sites' => ['uuid' => $r['uuid'], 'name' => $r['name'], 'code' => $r['code'], 'lat' => $r['lat'], 'lng' => $r['lng'], 'radius_m' => $r['geofence_radius_m']],
            'sectors' => ['uuid' => $r['uuid'], 'site_uuid' => $r['site_uuid'], 'parent_uuid' => $r['parent_uuid'], 'name' => $r['name'],
                'label' => $sectorLabels[(int) $r['id']]['label'] ?? $r['name'], 'depth' => (int) $r['depth']],
            'equipment' => ['uuid' => $r['uuid'], 'code' => $r['code'], 'name' => $r['name'], 'type_uuid' => $r['type_uuid'],
                'site_uuid' => $r['site_uuid'], 'sector_uuid' => $r['sector_uuid'], 'status' => $r['status']],
            'employees' => ['uuid' => $r['uuid'], 'name' => $r['last_name'] . ', ' . $r['first_name'], 'sector_uuid' => $r['sector_uuid']],
            'observations' => [
                'uuid' => $r['uuid'], 'number' => (int) $r['number'], 'status' => $r['status'], 'status_label' => ObservationWorkflow::label($r['status']),
                'category_uuid' => $r['category_uuid'], 'severity_uuid' => $r['severity_uuid'], 'risk_uuid' => $r['risk_uuid'],
                'sector_uuid' => $r['sector_uuid'], 'equipment_uuid' => $r['equipment_uuid'], 'description' => $r['description'],
                'imminent' => (bool) $r['imminent_risk'], 'anonymous' => (bool) $r['is_anonymous'],
                'mine' => $r['reporter_uuid'] !== null && $r['reporter_uuid'] === (UserAuth::user()['uuid'] ?? null),
                'assigned_name' => $r['assigned_name'], 'action_due_on' => $r['action_due_on'],
                'created_at_device' => str_replace(' ', 'T', $r['created_at_device']) . 'Z', 'updated_at' => str_replace(' ', 'T', $r['updated_at']) . 'Z',
            ],
            'actions' => [
                'uuid' => $r['uuid'], 'code' => \App\Models\Actions::format((int) $r['number']), 'title' => $r['title'], 'description' => $r['description'],
                'status' => $r['status'], 'status_label' => ActionWorkflow::label($r['status']), 'priority' => $r['priority'], 'type' => $r['type'],
                'due_on' => $r['due_on'], 'responsible' => $r['responsible_name'], 'mine' => ActionService::isResponsible($r),
                'sector_uuid' => $r['sector_uuid'], 'origin' => ActionWorkflow::ORIGINS[$r['origin_type']] ?? $r['origin_type'],
                'observation_uuid' => $r['observation_uuid'], 'observation_code' => $r['observation_number'] ? Observations::format((int) $r['observation_number']) : null,
                'cycle' => (int) $r['cycle'], 'closure_text' => $r['closure_text'], 'closed_at' => $r['closed_at'] ? str_replace(' ', 'T', $r['closed_at']) . 'Z' : null,
                'evidence' => \App\Models\ActionAttachments::countEvidence((int) $r['id'], (int) $r['cycle']),
                'can_start' => $r['status'] === 'abierta' && ActionService::canDo($r, 'tomar'),
                'can_close' => ActionWorkflow::isOpen($r['status']) && ActionService::canDo($r, 'cerrar'),
                'updated_at' => str_replace(' ', 'T', $r['updated_at']) . 'Z',
            ],
            'incidents' => [
                'uuid' => $r['uuid'], 'code' => \App\Models\Incidents::format((int) $r['number']), 'type' => $r['type'],
                'type_label' => \App\Services\IncidentService::typeLabel($r['type']), 'status' => $r['status'],
                'status_label' => \App\Services\IncidentService::STATES[$r['status']]['label'], 'sector_uuid' => $r['sector_uuid'],
                'occurred_at' => str_replace(' ', 'T', $r['occurred_at']) . 'Z', 'description' => mb_strimwidth((string) $r['description'], 0, 300, '…'),
            ],
            'inspection_templates' => [
                'uuid' => $r['uuid'], 'name' => $r['name'], 'description' => $r['description'], 'scope' => $r['scope'], 'type_uuid' => $r['type_uuid'],
                'version_uuid' => $r['version_uuid'], 'structure' => json_decode((string) $r['structure'], true) ?: ['sections' => []],
            ],
            'inspection_schedule' => [
                'uuid' => $r['uuid'], 'template_uuid' => $r['template_uuid'], 'equipment_uuid' => $r['equipment_uuid'], 'sector_uuid' => $r['sector_uuid'],
                'program' => $r['program_name'], 'due_from' => $r['due_from'], 'due_on' => $r['due_on'],
            ],
            'patrol_points' => ['uuid' => $r['uuid'], 'name' => $r['name'], 'code' => $r['code'], 'description' => $r['description'], 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'radius_m' => (int) $r['radius_m'], 'critical' => (bool) $r['is_critical']],
            'patrol_routes' => ['uuid' => $r['uuid'], 'name' => $r['name'], 'description' => $r['description'], 'frequency' => $r['frequency'],
                'expected_minutes' => $r['expected_minutes'] !== null ? (int) $r['expected_minutes'] : null, 'points' => Patrols::routePointUuids((int) $r['id'])],
            'patrol_rounds' => ['uuid' => $r['uuid'], 'route_uuid' => $r['route_uuid'], 'mine' => (int) $r['user_id'] === (int) (UserAuth::user()['id'] ?? 0), 'status' => $r['status'], 'started_at' => str_replace(' ', 'T', $r['started_at']) . 'Z', 'finished_at' => $r['finished_at'] ? str_replace(' ', 'T', $r['finished_at']) . 'Z' : null],
            'patrol_scans' => ['uuid' => $r['uuid'], 'round_uuid' => $r['round_uuid'], 'point_uuid' => $r['point_uuid'], 'scanned_at_device' => str_replace(' ', 'T', $r['scanned_at_device']) . 'Z', 'lat' => $r['lat'] !== null ? (float) $r['lat'] : null, 'lng' => $r['lng'] !== null ? (float) $r['lng'] : null, 'accuracy_m' => $r['accuracy_m'] !== null ? (float) $r['accuracy_m'] : null, 'distance_m' => $r['distance_m'] !== null ? (float) $r['distance_m'] : null, 'within_radius' => (bool) $r['within_radius'], 'note' => $r['note']],
        };
    }

    public static function encode(array $positions): string
    {
        return rtrim(strtr(base64_encode(json_encode($positions)), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return [];
        }
        $data = json_decode((string) base64_decode(strtr($cursor, '-_', '+/')), true);
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $entity => $pos) {
            if (in_array($entity, self::ENTITIES, true) && is_array($pos) && count($pos) === 2
                && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $pos[0])) {
                $out[$entity] = [(string) $pos[0], (int) $pos[1]];
            }
        }
        return $out;
    }
}
