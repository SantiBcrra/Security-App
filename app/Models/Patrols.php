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

    public static function createRoute(array $data): int
    {
        DB::tenant()->beginTransaction();
        try {
            $uuid = $data['uuid'] ?? Uuid::v4();
            DB::tenant()->prepare('INSERT INTO patrol_routes (uuid,name,description,frequency,expected_minutes,is_active,created_at,updated_at) VALUES (?,?,?,?,?,1,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
                ->execute([$uuid, $data['name'], $data['description'] ?: null, $data['frequency'] ?: 'manual', $data['expected_minutes'] ?: null]);
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

    public static function start(?string $routeUuid, ?float $lat, ?float $lng, ?string $roundUuid = null): array
    {
        $routeId = null;
        if ($routeUuid !== null && $routeUuid !== '') {
            $r = self::route($routeUuid);
            if (!$r) throw new UserError('Ruta inexistente.');
            $routeId = (int) $r['id'];
        }
        if ($roundUuid !== null && $roundUuid !== '' && !Uuid::isValid($roundUuid)) throw new UserError('Identificador de ronda inválido.');
        $uuid = $roundUuid ?: Uuid::v4();
        if ($existing = self::round($uuid)) { // reenvío del celular: misma ronda, no se duplica
            if ((int) $existing['user_id'] !== (int) UserAuth::user()['id']) throw new UserError('La ronda no está disponible.');
            return $existing;
        }
        DB::tenant()->prepare('INSERT INTO patrol_rounds (uuid,route_id,user_id,status,started_at,started_lat,started_lng,created_at,updated_at) VALUES (?,?,?,"en_curso",UTC_TIMESTAMP(),?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
            ->execute([$uuid, $routeId, (int) UserAuth::user()['id'], $lat, $lng]);
        return self::round($uuid) ?? [];
    }

    public static function round(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT r.*, u.name AS user_name, pr.name AS route_name FROM patrol_rounds r JOIN users u ON u.id=r.user_id LEFT JOIN patrol_routes pr ON pr.id=r.route_id WHERE r.uuid=? LIMIT 1');
        $stmt->execute([$uuid]);
        $row = $stmt->fetch() ?: null;
        if ($row) {
            $q = DB::tenant()->prepare('SELECT s.*, p.uuid AS point_uuid, p.name AS point_name, p.code AS point_code FROM patrol_scans s JOIN patrol_points p ON p.id=s.point_id WHERE s.round_id=? ORDER BY s.scanned_at_device');
            $q->execute([(int) $row['id']]);
            $row['scans'] = $q->fetchAll();
        }
        return $row;
    }

    public static function rounds(int $limit = 100): array
    {
        $stmt = DB::tenant()->prepare('SELECT r.*, u.name AS user_name, pr.name AS route_name,
            (SELECT COUNT(*) FROM patrol_scans s WHERE s.round_id=r.id) AS scans_count
            FROM patrol_rounds r JOIN users u ON u.id=r.user_id LEFT JOIN patrol_routes pr ON pr.id=r.route_id ORDER BY r.started_at DESC LIMIT ' . max(1, min(500, $limit)));
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function scan(array $data): array
    {
        $point = self::point((string) ($data['point_uuid'] ?? ''));
        if (!$point || (int) $point['is_active'] !== 1 || $point['deleted_at'] !== null) throw new UserError('El QR no corresponde a un punto activo.');
        $round = self::round((string) ($data['round_uuid'] ?? ''));
        if (!$round || $round['status'] !== 'en_curso' || (int) $round['user_id'] !== (int) UserAuth::user()['id']) throw new UserError('La ronda no está disponible.');
        $lat = isset($data['lat']) && $data['lat'] !== '' ? (float) $data['lat'] : null;
        $lng = isset($data['lng']) && $data['lng'] !== '' ? (float) $data['lng'] : null;
        $distance = ($lat !== null && $lng !== null) ? self::distance($lat, $lng, (float) $point['lat'], (float) $point['lng']) : null;
        $inside = $distance !== null && $distance <= (int) $point['radius_m'];
        $exists = DB::tenant()->prepare('SELECT uuid FROM patrol_scans WHERE round_id=? AND point_id=? LIMIT 1');
        $exists->execute([(int) $round['id'], (int) $point['id']]);
        if ($old = $exists->fetchColumn()) return ['uuid' => $old, 'duplicate' => true, 'within_radius' => $inside, 'distance_m' => $distance];
        $uuid = (string) ($data['uuid'] ?? Uuid::v4());
        if (!Uuid::isValid($uuid)) throw new UserError('Identificador de escaneo inválido.');
        $at = self::deviceTime($data['scanned_at_device'] ?? null);
        $accuracy = isset($data['accuracy_m']) && is_numeric($data['accuracy_m']) ? (float) $data['accuracy_m'] : null;
        DB::tenant()->prepare('INSERT INTO patrol_scans (uuid,round_id,point_id,user_id,scanned_at_device,received_at,lat,lng,accuracy_m,distance_m,within_radius,note,created_at,updated_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())')
            ->execute([$uuid, (int) $round['id'], (int) $point['id'], (int) UserAuth::user()['id'], $at, $lat, $lng, $accuracy, $distance, $inside ? 1 : 0, $data['note'] ?? null]);
        return ['uuid' => $uuid, 'point_uuid' => $point['uuid'], 'within_radius' => $inside, 'distance_m' => $distance, 'duplicate' => false];
    }

    public static function finish(string $uuid): array
    {
        $round = self::round($uuid);
        if (!$round || (int) $round['user_id'] !== (int) UserAuth::user()['id']) throw new UserError('La ronda no existe.');
        DB::tenant()->prepare('UPDATE patrol_rounds SET status="completa", finished_at=UTC_TIMESTAMP(), updated_at=UTC_TIMESTAMP() WHERE id=? AND status="en_curso"')->execute([(int) $round['id']]);
        return self::round($uuid) ?? $round;
    }

    /** Hora del dispositivo (ISO 8601) en UTC; sin dato usa la del servidor. No acepta fechas absurdas. */
    private static function deviceTime(mixed $value): string
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
