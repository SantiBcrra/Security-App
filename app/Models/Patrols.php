<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\UserError;
use App\Core\Uuid;
use App\Services\UserAuth;

/** Puntos, rutas y rondas de vigilancia de la empresa activa. */
final class Patrols
{
    public static function points(bool $all = false): array
    {
        $where = $all ? '' : ' WHERE p.is_active = 1 AND p.deleted_at IS NULL';
        $stmt = DB::tenant()->query('SELECT p.*, si.name AS site_name, se.name AS sector_name FROM patrol_points p
            LEFT JOIN sites si ON si.id = p.site_id LEFT JOIN sectors se ON se.id = p.sector_id' . $where . ' ORDER BY p.code');
        return $stmt->fetchAll();
    }

    public static function point(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT p.*, si.name AS site_name, se.name AS sector_name FROM patrol_points p
            LEFT JOIN sites si ON si.id = p.site_id LEFT JOIN sectors se ON se.id = p.sector_id WHERE p.uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    /** Modos de orden de una ruta. En secuencial y azar una marca fuera de orden se registra, no se rechaza. */
    public const ORDER_MODES = [
        'libre' => 'Libre: cualquier orden, solo importa pasar por todos',
        'secuencial' => 'Secuencial: en el orden de la ruta',
        'azar' => 'Al azar: la app arma un orden distinto en cada ronda',
    ];

    /** Precisión mínima del GPS para fijar la ubicación de un punto desde el celular (metros). */
    public const LOCATE_MAX_ACCURACY_M = 30;

    /** Actualiza las coordenadas de un punto desde Android, con alcance por sector y auditoría del controlador. */
    public static function locatePoint(string $uuid, float $lat, float $lng): array
    {
        $point = self::point($uuid);
        if (!$point || (int)$point['is_active'] !== 1 || $point['deleted_at'] !== null) throw new UserError('El punto no existe o está desactivado.');
        if (!UserAuth::can('rondas', 'editar')) throw new UserError('No tenés permiso para ubicar puntos de ronda.');
        if (UserAuth::scope('rondas') !== 'todo' && ($point['sector_id'] === null || !in_array((int)$point['sector_id'], \App\Services\SectorScope::sectorIds('rondas') ?? [], true))) {
            throw new UserError('El punto está fuera de tus sectores.');
        }
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) throw new UserError('Coordenadas inválidas.');
        DB::tenant()->prepare('UPDATE patrol_points SET lat=?, lng=?, updated_at=UTC_TIMESTAMP() WHERE id=?')->execute([$lat, $lng, (int)$point['id']]);
        return self::point($uuid) ?? $point;
    }

    /** Marcas fuera del radio o con GPS simulado, para el reporte de supervisión. */
    public static function outsideScans(?string $userUuid = null, ?string $pointUuid = null, ?string $from = null, ?string $to = null): array
    {
        $where = ["s.location_status IN ('lejos','simulada')"]; $params = [];
        if ($userUuid) { $where[] = 'u.uuid = ?'; $params[] = $userUuid; }
        if ($pointUuid) { $where[] = 'p.uuid = ?'; $params[] = $pointUuid; }
        // Las fechas del filtro son días locales de la empresa; en la base todo está en UTC.
        $tz = new \DateTimeZone(\App\Core\Tenant::timezone() ?: 'America/Argentina/Buenos_Aires');
        $utc = fn (string $day) => (new \DateTimeImmutable($day . ' 00:00:00', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        if ($from && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $from)) { $where[] = 's.scanned_at_device >= ?'; $params[] = $utc($from); }
        if ($to && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $to)) { $where[] = 's.scanned_at_device < ?'; $params[] = $utc((new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d')); }
        $scope = UserAuth::scope('rondas');
        if ($scope === 'propios') { $where[] = 's.user_id = ?'; $params[] = (int)UserAuth::user()['id']; }
        elseif ($scope === 'sectores') { $ids = \App\Services\SectorScope::sectorIds('rondas') ?? [0]; $where[] = 'p.sector_id IN (' . implode(',', array_map('intval',$ids)) . ')'; }
        $q = DB::tenant()->prepare('SELECT s.uuid, s.scanned_at_device, s.location_status, s.distance_m, s.accuracy_m, s.lat, s.lng,
                u.uuid AS user_uuid, u.name AS user_name, p.uuid AS point_uuid, p.code AS point_code, p.name AS point_name,
                r.uuid AS round_uuid FROM patrol_scans s JOIN users u ON u.id=s.user_id JOIN patrol_points p ON p.id=s.point_id JOIN patrol_rounds r ON r.id=s.round_id
                WHERE ' . implode(' AND ', $where) . ' ORDER BY s.scanned_at_device DESC LIMIT 500');
        $q->execute($params); return $q->fetchAll();
    }

    /**
     * "Eliminar" un punto: deja de verse y de bajar a los celulares (baja por la sync), y se saca de las rutas que lo
     * tenían. No se borra: las rondas viejas conservan sus marcas. @return int rutas de las que se sacó
     */
    public static function deletePoint(array $point): int
    {
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $routes = $db->prepare('SELECT DISTINCT route_id FROM patrol_route_points WHERE point_id = ?');
            $routes->execute([(int) $point['id']]);
            $routeIds = array_map('intval', $routes->fetchAll(\PDO::FETCH_COLUMN));
            $db->prepare('UPDATE patrol_points SET is_active = 0, deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([(int) $point['id']]);
            $db->prepare('DELETE FROM patrol_route_points WHERE point_id = ?')->execute([(int) $point['id']]);
            if ($routeIds) $db->exec('UPDATE patrol_routes SET updated_at = UTC_TIMESTAMP() WHERE id IN (' . implode(',', $routeIds) . ')'); // la ruta baja de nuevo al celular
            $db->commit();
            return count($routeIds);
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function createPoint(array $data): int
    {
        $data += ['uuid' => Uuid::v4(), 'is_active' => 1, 'is_critical' => 0, 'radius_m' => 50];
        DB::tenant()->prepare('INSERT INTO patrol_points (uuid,name,code,description,site_id,sector_id,lat,lng,radius_m,is_critical,is_active,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([
                $data['uuid'], $data['name'], $data['code'], $data['description'] ?: null, $data['site_id'] ?: null,
                $data['sector_id'] ?: null, $data['lat'], $data['lng'], $data['radius_m'], $data['is_critical'] ? 1 : 0, $data['is_active'],
            ]);
        return (int) DB::tenant()->lastInsertId();
    }

    /**
     * Ids internos de puntos activos a partir de sus UUID, en el mismo orden (los inválidos se descartan).
     * @return list<int>
     */
    public static function pointIdsByUuid(array $uuids): array
    {
        $uuids = array_values(array_unique(array_filter(array_map('strval', $uuids), [Uuid::class, 'isValid'])));
        if (!$uuids) return [];
        $stmt = DB::tenant()->prepare('SELECT uuid, id FROM patrol_points WHERE is_active = 1 AND deleted_at IS NULL AND uuid IN (' . implode(',', array_fill(0, count($uuids), '?')) . ')');
        $stmt->execute($uuids);
        $map = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        return array_values(array_map('intval', array_filter(array_map(fn ($u) => $map[$u] ?? null, $uuids))));
    }

    public static function isAssigned(int $routeId, int $userId): bool
    {
        $s = DB::tenant()->prepare('SELECT 1 FROM patrol_route_assignments WHERE route_id=? AND user_id=? AND is_active=1 LIMIT 1');
        $s->execute([$routeId, $userId]);
        return (bool) $s->fetchColumn();
    }

    /** @return list<string> UUID de los puntos de la ruta, en el orden de recorrido. */
    public static function routePointUuids(int $routeId): array
    {
        $s = DB::tenant()->prepare('SELECT p.uuid FROM patrol_route_points rp JOIN patrol_points p ON p.id=rp.point_id WHERE rp.route_id=? ORDER BY rp.sort_order, rp.id');
        $s->execute([$routeId]);
        return $s->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Puntos de la ruta que la ronda no registró. */
    public static function missingPoints(int $roundId, int $routeId): int
    {
        $s = DB::tenant()->prepare('SELECT COUNT(*) FROM patrol_route_points rp
            LEFT JOIN patrol_scans sc ON sc.round_id=? AND sc.point_id=rp.point_id WHERE rp.route_id=? AND sc.id IS NULL');
        $s->execute([$roundId, $routeId]);
        return (int) $s->fetchColumn();
    }

    /**
     * Detalle de una ronda para el panel: escaneos y, si es de ruta, cada punto de la ruta con su
     * escaneo (o salteado). Los escaneos fuera de la ruta se listan aparte.
     */
    public static function roundDetail(string $uuid): ?array
    {
        $round = self::round($uuid);
        if (!$round) return null;
        $round['route_points'] = [];
        if ($round['route_id'] !== null) {
            $s = DB::tenant()->prepare('SELECT p.uuid, p.code, p.name, p.is_critical, p.radius_m, rp.sort_order, p.lat AS point_lat, p.lng AS point_lng,
                    sc.scanned_at_device, sc.method, sc.out_of_order, sc.distance_m, sc.within_radius, sc.location_status, sc.accuracy_m, sc.lat, sc.lng
                FROM patrol_route_points rp JOIN patrol_points p ON p.id=rp.point_id
                LEFT JOIN patrol_scans sc ON sc.round_id=? AND sc.point_id=rp.point_id
                WHERE rp.route_id=? ORDER BY rp.sort_order, rp.id');
            $s->execute([(int) $round['id'], (int) $round['route_id']]);
            $round['route_points'] = $s->fetchAll();
            $inRoute = array_column($round['route_points'], 'uuid');
            $round['extra_scans'] = array_values(array_filter($round['scans'], fn ($sc) => !in_array($sc['point_uuid'], $inRoute, true)));
        } else {
            $round['extra_scans'] = $round['scans'];
        }
        return $round;
    }

    public static function routes(): array
    {
        return DB::tenant()->query('SELECT r.*,
            (SELECT COUNT(*) FROM patrol_route_points rp WHERE rp.route_id = r.id) AS point_count
            FROM patrol_routes r WHERE r.deleted_at IS NULL ORDER BY r.is_active DESC, r.name')->fetchAll();
    }

    public static function assignments(int $routeId): array
    {
        $s = DB::tenant()->prepare('SELECT u.uuid, u.name FROM patrol_route_assignments a JOIN users u ON u.id=a.user_id WHERE a.route_id=? AND a.is_active=1 ORDER BY u.name');
        $s->execute([$routeId]);
        return $s->fetchAll();
    }

    public static function route(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM patrol_routes WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        $route = $stmt->fetch() ?: null;
        if ($route) {
            $q = DB::tenant()->prepare('SELECT p.*, rp.sort_order FROM patrol_route_points rp JOIN patrol_points p ON p.id = rp.point_id WHERE rp.route_id = ? ORDER BY rp.sort_order');
            $q->execute([(int) $route['id']]);
            $route['points'] = $q->fetchAll();
        }
        return $route;
    }

    /** Edición: datos, puntos en el nuevo orden y guardias. Toca updated_at para que la ruta vuelva a bajar a los celulares. */
    public static function updateRoute(int $id, array $data): void
    {
        $db = DB::tenant();
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE patrol_routes SET name = ?, description = ?, expected_minutes = ?, order_mode = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([$data['name'], $data['description'] ?: null, $data['expected_minutes'] ?: null,
                    isset(self::ORDER_MODES[$data['order_mode'] ?? '']) ? $data['order_mode'] : 'libre', $id]);
            $db->prepare('DELETE FROM patrol_route_points WHERE route_id = ?')->execute([$id]);
            foreach ((array) ($data['point_ids'] ?? []) as $i => $pointId) {
                $db->prepare('INSERT INTO patrol_route_points (route_id,point_id,sort_order) VALUES (?,?,?)')->execute([$id, (int) $pointId, $i + 1]);
            }
            $keep = array_map('intval', (array) ($data['user_ids'] ?? []));
            $db->prepare('UPDATE patrol_route_assignments SET is_active = 0, updated_at = UTC_TIMESTAMP() WHERE route_id = ?' . ($keep ? ' AND user_id NOT IN (' . implode(',', $keep) . ')' : ''))->execute([$id]);
            foreach ($keep as $userId) {
                $db->prepare('INSERT INTO patrol_route_assignments (route_id,user_id,created_at,updated_at) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE is_active = 1, updated_at = UTC_TIMESTAMP()')->execute([$id, $userId]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /** Puntos de una ruta en orden, con coordenadas (detalle y mapa). */
    public static function routePoints(int $routeId): array
    {
        $s = DB::tenant()->prepare('SELECT p.*, rp.sort_order FROM patrol_route_points rp JOIN patrol_points p ON p.id = rp.point_id
            WHERE rp.route_id = ? ORDER BY rp.sort_order, rp.id');
        $s->execute([$routeId]);
        return $s->fetchAll();
    }

    public static function createRoute(array $data): int
    {
        DB::tenant()->beginTransaction();
        try {
            $uuid = $data['uuid'] ?? Uuid::v4();
            DB::tenant()->prepare('INSERT INTO patrol_routes (uuid,name,description,frequency,expected_minutes,order_mode,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
                ->execute([$uuid, $data['name'], $data['description'] ?: null, $data['frequency'] ?? 'manual', $data['expected_minutes'] ?: null,
                    isset(self::ORDER_MODES[$data['order_mode'] ?? '']) ? $data['order_mode'] : 'libre']);
            $id = (int) DB::tenant()->lastInsertId();
            foreach ((array) ($data['point_ids'] ?? []) as $i => $pointId) {
                DB::tenant()->prepare('INSERT INTO patrol_route_points (route_id,point_id,sort_order) VALUES (?,?,?)')->execute([$id, (int) $pointId, $i + 1]);
            }
            foreach ((array) ($data['user_ids'] ?? []) as $userId) {
                DB::tenant()->prepare('INSERT IGNORE INTO patrol_route_assignments (route_id,user_id,created_at,updated_at) VALUES (?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')->execute([$id, (int) $userId]);
            }
            DB::tenant()->commit();
            return $id;
        } catch (\Throwable $e) {
            DB::tenant()->rollBack();
            throw $e;
        }
    }

    /** @param ?string $startedAt hora del celular (ISO 8601): la ronda pudo empezar sin señal y llegar después */
    /** Orden que le toca a una ronda: el de la ruta (secuencial), el que armó el celular o uno al azar (azar); libre = null. */
    private static function roundOrder(int $routeId, string $mode, ?array $fromDevice): ?string
    {
        if ($mode === 'libre') return null;
        $route = self::routePointUuids($routeId);
        if ($mode === 'azar') {
            $device = array_values(array_map('strval', (array) $fromDevice));
            $sorted = $device; sort($sorted); $want = $route; sort($want);
            if ($sorted === $want) return json_encode($device); // permutación válida de los puntos de la ruta
            shuffle($route);
        }
        return json_encode($route);
    }

    /** @param ?array $pointOrder orden armado por el celular (rutas "al azar") */
    public static function start(?string $routeUuid, ?float $lat, ?float $lng, ?string $roundUuid = null, ?string $startedAt = null, ?array $pointOrder = null): array
    {
        $routeId = null;
        if ($routeUuid !== null && $routeUuid !== '') {
            $r = self::route($routeUuid);
            if (!$r || (int) $r['is_active'] !== 1 || $r['deleted_at'] !== null) throw new UserError('La ruta no existe o está desactivada.');
            $userId = (int) UserAuth::user()['id'];
            if (UserAuth::scope('rondas') !== 'todo' && !self::isAssigned((int) $r['id'], $userId)) throw new UserError('Esta ruta no está asignada a tu usuario.');
            $routeId = (int) $r['id'];
        }
        if ($roundUuid !== null && $roundUuid !== '' && !Uuid::isValid($roundUuid)) throw new UserError('Identificador de ronda inválido.');
        $uuid = $roundUuid ?: Uuid::v4();
        if ($existing = self::round($uuid)) { // reenvío del celular: misma ronda, no se duplica
            if ((int) $existing['user_id'] !== (int) UserAuth::user()['id']) throw new UserError('La ronda no está disponible.');
            return $existing;
        }
        DB::tenant()->prepare('INSERT INTO patrol_rounds (uuid,route_id,point_order,user_id,status,started_at,started_lat,started_lng,created_at,updated_at) VALUES (?,?,?,?,\'en_curso\',?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
            ->execute([$uuid, $routeId, $routeId !== null ? self::roundOrder($routeId, $r['order_mode'] ?? 'libre', $pointOrder) : null,
                (int) UserAuth::user()['id'], self::deviceTime($startedAt), $lat, $lng]);
        return self::round($uuid) ?? [];
    }

    public static function round(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT r.*, u.name AS user_name, pr.name AS route_name, pr.expected_minutes FROM patrol_rounds r JOIN users u ON u.id=r.user_id LEFT JOIN patrol_routes pr ON pr.id=r.route_id WHERE r.uuid=? LIMIT 1');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch() ?: null;
        if ($row) {
            $q = DB::tenant()->prepare('SELECT s.*, p.uuid AS point_uuid, p.name AS point_name, p.code AS point_code FROM patrol_scans s JOIN patrol_points p ON p.id=s.point_id WHERE s.round_id=? ORDER BY s.scanned_at_device');
            $q->execute([(int) $row['id']]);
            $row['scans'] = $q->fetchAll();
        }
        return $row;
    }

    /** Rondas recientes; con $userId solo las de ese usuario (alcance "propios"). */
    /**
     * Rondas realizadas con filtros (panel → Rondas realizadas). $f: from/to (UTC 'Y-m-d H:i:s', [from, to)), user_id, route_id
     * (0 = rondas libres), status (completa | incompleta | en_curso | con_problemas = marcas fuera de lugar o alertas).
     * @return array{rows: list<array>, total: int}
     */
    public static function searchRounds(array $f, int $limit = 200): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($f['from'])) { $where[] = 'r.started_at >= ?'; $params[] = $f['from']; }
        if (!empty($f['to'])) { $where[] = 'r.started_at < ?'; $params[] = $f['to']; }
        if (!empty($f['user_id'])) { $where[] = 'r.user_id = ?'; $params[] = (int) $f['user_id']; }
        if (isset($f['route_id']) && $f['route_id'] !== null) {
            if ((int) $f['route_id'] === 0) $where[] = 'r.route_id IS NULL';
            else { $where[] = 'r.route_id = ?'; $params[] = (int) $f['route_id']; }
        }
        if (in_array($f['status'] ?? '', ['completa', 'incompleta', 'en_curso'], true)) { $where[] = 'r.status = ?'; $params[] = $f['status']; }
        if (($f['status'] ?? '') === 'con_problemas') {
            $where[] = "(EXISTS (SELECT 1 FROM patrol_scans s WHERE s.round_id = r.id AND s.location_status IN ('lejos','simulada'))
                OR EXISTS (SELECT 1 FROM patrol_round_alerts a WHERE a.round_id = r.id) OR EXISTS (SELECT 1 FROM panic_alerts pa WHERE pa.round_id = r.id))";
        }
        $w = implode(' AND ', $where);
        $count = DB::tenant()->prepare("SELECT COUNT(*) FROM patrol_rounds r WHERE {$w}");
        $count->execute($params);
        $stmt = DB::tenant()->prepare("SELECT r.*, u.name AS user_name, pr.name AS route_name, pr.uuid AS route_uuid, pr.expected_minutes,
            (SELECT COUNT(*) FROM patrol_scans s WHERE s.round_id = r.id) AS scans_count,
            (SELECT COUNT(*) FROM patrol_scans s WHERE s.round_id = r.id AND s.location_status IN ('lejos','simulada')) AS outside_count,
            (SELECT COUNT(*) FROM patrol_scans s WHERE s.round_id = r.id AND s.location_status IN ('impreciso','sin_gps')) AS doubtful_count,
            (SELECT COUNT(*) FROM patrol_scans s WHERE s.round_id = r.id AND s.out_of_order = 1) AS out_of_order_count,
            (SELECT COUNT(*) FROM patrol_round_alerts a WHERE a.round_id = r.id) + (SELECT COUNT(*) FROM panic_alerts pa WHERE pa.round_id = r.id) AS alerts_count,
            (SELECT COUNT(*) FROM patrol_route_points rp WHERE rp.route_id = r.route_id) AS route_points
            FROM patrol_rounds r JOIN users u ON u.id = r.user_id LEFT JOIN patrol_routes pr ON pr.id = r.route_id
            WHERE {$w} ORDER BY r.started_at DESC LIMIT " . max(1, min(1000, $limit)));
        $stmt->execute($params);
        return ['rows' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    public static function rounds(int $limit = 100, ?int $userId = null): array
    {
        $stmt = DB::tenant()->prepare('SELECT r.*, u.name AS user_name, pr.name AS route_name,
            (SELECT COUNT(*) FROM patrol_scans s WHERE s.round_id=r.id) AS scans_count,
            (SELECT COUNT(*) FROM patrol_scans s WHERE s.round_id=r.id AND s.location_status IN (\'lejos\',\'simulada\')) AS outside_count,
            (SELECT COUNT(*) FROM patrol_scans s WHERE s.round_id=r.id AND s.location_status IN (\'impreciso\',\'sin_gps\')) AS doubtful_count,
            (SELECT COUNT(*) FROM patrol_route_points rp WHERE rp.route_id=r.route_id) AS route_points
            FROM patrol_rounds r JOIN users u ON u.id=r.user_id LEFT JOIN patrol_routes pr ON pr.id=r.route_id'
            . ($userId !== null ? ' WHERE r.user_id = ?' : '') . ' ORDER BY r.started_at DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute($userId !== null ? [$userId] : []);
        return $stmt->fetchAll();
    }

    public static function scan(array $data): array
    {
        $point = self::point((string) ($data['point_uuid'] ?? ''));
        if (!$point || (int) $point['is_active'] !== 1 || $point['deleted_at'] !== null) throw new UserError('El QR no corresponde a un punto activo.');
        $round = self::round((string) ($data['round_uuid'] ?? ''));
        if (!$round || (int) $round['user_id'] !== (int) UserAuth::user()['id']) throw new UserError('La ronda no está disponible.');
        $at = self::deviceTime($data['scanned_at_device'] ?? null);
        // Sin señal el escaneo puede llegar después del fin de la ronda (reintento): vale si se hizo antes de terminarla.
        $late = $round['status'] !== 'en_curso';
        if ($late && ($round['finished_at'] === null || $at > $round['finished_at'])) throw new UserError('La ronda no está disponible.');
        $lat = isset($data['lat']) && $data['lat'] !== '' ? (float) $data['lat'] : null;
        $lng = isset($data['lng']) && $data['lng'] !== '' ? (float) $data['lng'] : null;
        $distance = ($lat !== null && $lng !== null) ? min(self::distance($lat, $lng, (float) $point['lat'], (float) $point['lng']), 999999999.0) : null;
        $accuracyIn = isset($data['accuracy_m']) && is_numeric($data['accuracy_m']) ? (float) $data['accuracy_m'] : null;
        $mock = !empty($data['mock']) && $lat !== null;
        $status = self::locationStatus($distance, $accuracyIn, (int) $point['radius_m'], $mock);
        $inside = $status === 'ok';
        $exists = DB::tenant()->prepare('SELECT uuid FROM patrol_scans WHERE round_id=? AND point_id=? LIMIT 1');
        $exists->execute([(int) $round['id'], (int) $point['id']]);
        if ($old = $exists->fetchColumn()) return ['uuid' => $old, 'duplicate' => true, 'within_radius' => $inside, 'distance_m' => $distance];
        $uuid = (string) ($data['uuid'] ?? Uuid::v4());
        if (!Uuid::isValid($uuid)) throw new UserError('Identificador de escaneo inválido.');
        $accuracy = isset($data['accuracy_m']) && is_numeric($data['accuracy_m']) ? min(max((float) $data['accuracy_m'], 0.0), 999999999.0) : null;
        $method = in_array($data['method'] ?? null, ['qr', 'nfc'], true) ? $data['method'] : null; // cómo se marcó el punto
        $outOfOrder = self::outOfOrder($round, $point['uuid'], $at);
        DB::tenant()->prepare('INSERT INTO patrol_scans (uuid,round_id,point_id,method,out_of_order,user_id,scanned_at_device,received_at,lat,lng,accuracy_m,distance_m,within_radius,location_status,is_mock,note,created_at,updated_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(),?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
            ->execute([$uuid, (int) $round['id'], (int) $point['id'], $method, $outOfOrder, (int) UserAuth::user()['id'], $at, $lat, $lng, $accuracy, $distance, $inside ? 1 : 0, $status, $mock ? 1 : 0, $data['note'] ?? null]);
        if (in_array($status, ['lejos', 'simulada'], true)) {
            self::warnOffSite($round, $point, $status, $distance, $at);
        }
        if ($late && $round['route_id'] !== null) {
            $status = self::missingPoints((int) $round['id'], (int) $round['route_id']) > 0 ? 'incompleta' : 'completa';
            DB::tenant()->prepare('UPDATE patrol_rounds SET status = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$status, (int) $round['id']]);
        }
        return ['uuid' => $uuid, 'point_uuid' => $point['uuid'], 'within_radius' => $inside, 'distance_m' => $distance, 'duplicate' => false];
    }

    public const LOCATION_STATES = [
        'ok'        => ['label' => 'En el lugar', 'class' => 'success'],
        'lejos'     => ['label' => 'Lejos del punto', 'class' => 'danger'],
        'simulada'  => ['label' => 'Ubicación simulada (GPS falso)', 'class' => 'danger'],
        'impreciso' => ['label' => 'GPS impreciso', 'class' => 'warning'],
        'sin_gps'   => ['label' => 'Sin GPS', 'class' => 'warning'],
    ];

    /**
     * Dónde estaba el celular respecto del punto. "impreciso" = fuera del radio, pero con el margen de error del GPS
     * podría estar adentro (no se acusa a nadie por un GPS malo); "lejos" = fuera aun descontando ese margen.
     */
    public static function locationStatus(?float $distance, ?float $accuracy, int $radius, bool $mock): string
    {
        return match (true) {
            $mock => 'simulada',
            $distance === null => 'sin_gps',
            $distance <= $radius => 'ok',
            $accuracy !== null && $distance - $accuracy <= $radius => 'impreciso',
            default => 'lejos',
        };
    }

    /**
     * Rondas con orden (secuencial / azar): la marca es fuera de orden si no es el primer punto del orden que faltaba marcar
     * a esa hora (se cuenta con la hora del celular, así no importa en qué orden llegan sin señal). Libre o punto fuera de la
     * ruta = null.
     */
    private static function outOfOrder(array $round, string $pointUuid, string $at): ?int
    {
        $order = json_decode((string) ($round['point_order'] ?? ''), true);
        if (!is_array($order) || !in_array($pointUuid, $order, true)) return null;
        $q = DB::tenant()->prepare('SELECT p.uuid FROM patrol_scans s JOIN patrol_points p ON p.id = s.point_id WHERE s.round_id = ? AND s.scanned_at_device <= ?');
        $q->execute([(int) $round['id'], $at]);
        $done = $q->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($order as $uuid) {
            if (!in_array($uuid, $done, true)) return $uuid === $pointUuid ? 0 : 1;
        }
        return 0;
    }

    /** Aviso a SyH y supervisores: una vez por ronda y por tipo (lejos / simulada), no por cada punto. */
    private static function warnOffSite(array $round, array $point, string $status, ?float $distance, string $at): void
    {
        if (!\App\Models\NotificationMarks::claim("guard_offsite:{$round['id']}:{$status}")) {
            return;
        }
        \App\Services\Notify\Notifier::dispatch('guard.off_site', $round + ['_kind' => $status, 'point_name' => $point['name'],
            'point_code' => $point['code'], 'distance_m' => $distance, 'scanned_at' => $at]);
    }

    public static function finish(string $uuid, ?string $finishedAt = null): array
    {
        $round = self::round($uuid);
        if (!$round || (int) $round['user_id'] !== (int) UserAuth::user()['id']) throw new UserError('La ronda no existe.');
        $status = $round['route_id'] !== null && self::missingPoints((int) $round['id'], (int) $round['route_id']) > 0 ? 'incompleta' : 'completa';
        $at = max(self::deviceTime($finishedAt), (string) $round['started_at']); // nunca antes de empezar
        DB::tenant()->prepare("UPDATE patrol_rounds SET status=?, finished_at=?, updated_at=UTC_TIMESTAMP() WHERE id=? AND status='en_curso'")->execute([$status, $at, (int) $round['id']]);
        return self::round($uuid) ?? $round;
    }

    /** Hora del dispositivo (ISO 8601) en UTC; sin dato usa la del servidor. No acepta fechas absurdas. */
    public static function deviceTime(mixed $value): string
    {
        if ($value === null || $value === '') return gmdate('Y-m-d H:i:s');
        try {
            $t = new \DateTimeImmutable((string) $value);
        } catch (\Exception) {
            throw new UserError('Hora del escaneo inválida.');
        }
        $ts = $t->getTimestamp();
        if ($ts > time() + 600 || $ts < time() - 30 * 86400) throw new UserError('Hora del escaneo fuera de rango.');
        return gmdate('Y-m-d H:i:s', $ts);
    }

    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;
        return 6371000 * 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));
    }
}
