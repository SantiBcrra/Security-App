<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Observaciones (actos / condiciones inseguras) de la empresa activa. */
final class Observations
{
    private const SELECT = 'SELECT o.*,
            ca.name AS category_name, rt.name AS risk_name,
            sv.name AS severity_name, sv.color AS severity_color, sv.level AS severity_level,
            si.name AS site_name, se.name AS sector_name, eq.code AS equipment_code, eq.name AS equipment_name,
            ru.name AS reporter_name, au.name AS assigned_name
        FROM observations o
        LEFT JOIN catalog_items ca ON ca.id = o.category_id
        LEFT JOIN catalog_items rt ON rt.id = o.risk_type_id
        LEFT JOIN catalog_items sv ON sv.id = o.severity_id
        LEFT JOIN sites si ON si.id = o.site_id
        LEFT JOIN sectors se ON se.id = o.sector_id
        LEFT JOIN equipment eq ON eq.id = o.equipment_id
        LEFT JOIN users ru ON ru.id = o.reporter_user_id
        LEFT JOIN users au ON au.id = o.assigned_user_id';

    /**
     * @param array $filters status, severity_id, category_id, risk_type_id, sector_ids (list), from/to (UTC),
     *                       imminent, q, assigned_user_id, reporter_user_id
     * @param ?array $scope null = todo; ['sector_ids' => list<int>, 'user_id' => int] o ['own' => user_id]
     */
    public static function search(array $filters, ?array $scope, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::where($filters, $scope);
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY o.created_at_device DESC, o.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function count(array $filters, ?array $scope): int
    {
        [$where, $params] = self::where($filters, $scope);
        $stmt = DB::tenant()->prepare('SELECT COUNT(*) FROM observations o WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Cantidades por estado (con el mismo alcance y filtros, salvo el de estado). */
    public static function countByStatus(array $filters, ?array $scope): array
    {
        unset($filters['status']);
        [$where, $params] = self::where($filters, $scope);
        $stmt = DB::tenant()->prepare('SELECT o.status, COUNT(*) AS n FROM observations o WHERE ' . implode(' AND ', $where) . ' GROUP BY o.status');
        $stmt->execute($params);
        return array_map('intval', array_column($stmt->fetchAll(), 'n', 'status'));
    }

    private static function where(array $f, ?array $scope): array
    {
        $where = ['o.deleted_at IS NULL'];
        $params = [];
        if ($scope !== null) {
            if (isset($scope['own'])) {
                $where[] = 'o.reporter_user_id = ?';
                $params[] = $scope['own'];
            } else {
                $ids = $scope['sector_ids'] ?: [0];
                $where[] = '(o.sector_id IN (' . implode(',', array_map('intval', $ids)) . ') OR o.reporter_user_id = ?)';
                $params[] = $scope['user_id'];
            }
        }
        foreach (['status', 'severity_id', 'category_id', 'risk_type_id', 'assigned_user_id', 'reporter_user_id'] as $col) {
            if (isset($f[$col]) && $f[$col] !== '' && $f[$col] !== null) {
                if (is_array($f[$col])) {
                    $where[] = "o.{$col} IN (" . implode(',', array_fill(0, count($f[$col]), '?')) . ')';
                    array_push($params, ...$f[$col]);
                } else {
                    $where[] = "o.{$col} = ?";
                    $params[] = $f[$col];
                }
            }
        }
        if (!empty($f['sector_ids'])) {
            $where[] = 'o.sector_id IN (' . implode(',', array_map('intval', $f['sector_ids'])) . ')';
        }
        if (!empty($f['from'])) {
            $where[] = 'o.created_at_device >= ?';
            $params[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'o.created_at_device < ?';
            $params[] = $f['to'];
        }
        if (!empty($f['imminent'])) {
            $where[] = 'o.imminent_risk = 1';
        }
        if (!empty($f['with_gps'])) {
            $where[] = 'o.lat IS NOT NULL';
        }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $num = (int) preg_replace('/\D/', '', $q);
            $where[] = '(o.description LIKE ? OR o.location_text LIKE ?' . ($num > 0 ? ' OR o.number = ?' : '') . ')';
            array_push($params, "%{$q}%", "%{$q}%");
            if ($num > 0) {
                $params[] = $num;
            }
        }
        return [$where, $params];
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE o.uuid = ? AND o.deleted_at IS NULL');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id, bool $lock = false): ?array
    {
        $stmt = DB::tenant()->prepare(($lock ? 'SELECT * FROM observations o' : self::SELECT) . ' WHERE o.id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function create(array $data): int
    {
        $data += ['uuid' => Uuid::v4()];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO observations (`' . implode('`, `', $cols) . '`, received_at, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    /** Solo columnas vigentes (estado, clasificación, asignación). original_data y original_hash NUNCA. */
    public static function update(int $id, array $data): void
    {
        unset($data['original_data'], $data['original_hash'], $data['description'], $data['number'], $data['uuid']);
        if (!$data) {
            return;
        }
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare("UPDATE observations SET {$sets}, updated_at = UTC_TIMESTAMP() WHERE id = ?")
            ->execute([...array_values($data), $id]);
    }

    public static function addPeople(int $observationId, array $employeeIds): void
    {
        $stmt = DB::tenant()->prepare('INSERT IGNORE INTO observation_people (observation_id, employee_id) VALUES (?, ?)');
        foreach (array_unique($employeeIds) as $id) {
            $stmt->execute([$observationId, $id]);
        }
    }

    public static function people(int $observationId): array
    {
        $stmt = DB::tenant()->prepare("SELECT e.uuid, CONCAT(e.last_name, ', ', e.first_name) AS name, e.dni
            FROM observation_people p JOIN employees e ON e.id = p.employee_id WHERE p.observation_id = ? ORDER BY e.last_name");
        $stmt->execute([$observationId]);
        return $stmt->fetchAll();
    }

    public static function format(int $number): string
    {
        return 'OBS-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
