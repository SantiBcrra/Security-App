<?php
declare(strict_types=1);

namespace App\Services\Sync;

use App\Core\DB;
use App\Core\Tenant;
use App\Models\Sectors;
use App\Models\Settings;
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
    public const ENTITIES = ['catalog_items', 'sites', 'sectors', 'equipment', 'employees', 'observations', 'patrol_points', 'patrol_routes', 'patrol_rounds', 'patrol_scans'];
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
                $gone = (isset($row['is_active']) && (int) $row['is_active'] !== 1) || !empty($row['deleted_at']);
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
            case 'patrol_points':
                return ["SELECT t.* FROM patrol_points t WHERE {$after}{$order}", $params];
            case 'patrol_routes':
                return ["SELECT t.* FROM patrol_routes t WHERE {$after}{$order}", $params];
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
            'patrol_points' => ['uuid' => $r['uuid'], 'name' => $r['name'], 'code' => $r['code'], 'description' => $r['description'], 'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'radius_m' => (int) $r['radius_m'], 'critical' => (bool) $r['is_critical']],
            'patrol_routes' => ['uuid' => $r['uuid'], 'name' => $r['name'], 'description' => $r['description'], 'frequency' => $r['frequency'], 'expected_minutes' => $r['expected_minutes']],
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
