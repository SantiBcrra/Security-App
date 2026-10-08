<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Acciones correctivas y preventivas (CAPA) de la empresa activa. */
final class Actions
{
    public const OPEN = ['abierta', 'en_curso'];

    private const SELECT = 'SELECT a.*, ru.name AS responsible_name, ru.uuid AS responsible_uuid, cu.name AS created_by_name,
            clu.name AS closed_by_name, vu.name AS verified_by_name, si.name AS site_name, se.name AS sector_name
        FROM actions a
        JOIN users ru ON ru.id = a.responsible_user_id
        LEFT JOIN users cu ON cu.id = a.created_by
        LEFT JOIN users clu ON clu.id = a.closed_by
        LEFT JOIN users vu ON vu.id = a.verified_by
        LEFT JOIN sites si ON si.id = a.site_id
        LEFT JOIN sectors se ON se.id = a.sector_id';

    /**
     * @param array $f status (string|list), overdue (fecha local Y-m-d), responsible_user_id, sector_ids, origin_type,
     *                 origin_id, priority, q
     * @param ?array $scope null = todo; ['own' => user_id] (responsable o creador); ['responsible' => user_id];
     *                      ['sector_ids' => list<int>, 'user_id' => int]
     */
    public static function search(array $f, ?array $scope, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . " ORDER BY FIELD(a.status, 'abierta', 'en_curso', 'cerrada', 'verificada', 'cancelada'), a.due_on, a.id DESC"
            . ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function count(array $f, ?array $scope): int
    {
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare('SELECT COUNT(*) FROM actions a WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Cantidades por estado + vencidas (mismo alcance y filtros, salvo estado). */
    public static function countByStatus(array $f, ?array $scope, string $today): array
    {
        unset($f['status'], $f['overdue']);
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare('SELECT a.status, COUNT(*) AS n, SUM(a.status IN (\'abierta\', \'en_curso\') AND a.due_on < ?) AS late
            FROM actions a WHERE ' . implode(' AND ', $where) . ' GROUP BY a.status');
        $stmt->execute([$today, ...$params]);
        $out = ['vencidas' => 0];
        foreach ($stmt->fetchAll() as $r) {
            $out[$r['status']] = (int) $r['n'];
            $out['vencidas'] += (int) $r['late'];
        }
        return $out;
    }

    private static function where(array $f, ?array $scope): array
    {
        $where = ['a.deleted_at IS NULL'];
        $params = [];
        if ($scope !== null) {
            if (isset($scope['responsible'])) {
                $where[] = 'a.responsible_user_id = ?';
                $params[] = $scope['responsible'];
            } elseif (isset($scope['own'])) {
                $where[] = '(a.responsible_user_id = ? OR a.created_by = ?)';
                array_push($params, $scope['own'], $scope['own']);
            } else {
                $ids = $scope['sector_ids'] ?: [0];
                $where[] = '(a.sector_id IN (' . implode(',', array_map('intval', $ids)) . ') OR a.responsible_user_id = ? OR a.created_by = ?)';
                array_push($params, $scope['user_id'], $scope['user_id']);
            }
        }
        foreach (['status', 'responsible_user_id', 'origin_type', 'origin_id', 'priority'] as $col) {
            if (!isset($f[$col]) || $f[$col] === '' || $f[$col] === []) {
                continue;
            }
            $values = (array) $f[$col];
            $where[] = "a.{$col} IN (" . implode(',', array_fill(0, count($values), '?')) . ')';
            array_push($params, ...$values);
        }
        if (!empty($f['overdue'])) {
            $where[] = "a.status IN ('abierta', 'en_curso') AND a.due_on < ?";
            $params[] = $f['overdue'];
        }
        if (!empty($f['sector_ids'])) {
            $where[] = 'a.sector_id IN (' . implode(',', array_map('intval', $f['sector_ids'])) . ')';
        }
        if (($f['q'] ?? '') !== '') {
            $q = trim((string) $f['q']);
            if (preg_match('/^(?:ACC-?)?0*(\d+)$/i', $q, $m)) {
                $where[] = 'a.number = ?';
                $params[] = (int) $m[1];
            } else {
                $where[] = '(a.title LIKE ? OR a.description LIKE ?)';
                array_push($params, "%{$q}%", "%{$q}%");
            }
        }
        return [$where, $params];
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE a.uuid = ? AND a.deleted_at IS NULL');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id, bool $lock = false): ?array
    {
        $stmt = DB::tenant()->prepare(($lock ? 'SELECT * FROM actions a' : self::SELECT) . ' WHERE a.id = ?' . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Acciones de un origen (ej. todas las de una observación), en orden de alta. */
    public static function forOrigin(string $type, int $id): array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE a.origin_type = ? AND a.origin_id = ? AND a.deleted_at IS NULL ORDER BY a.id');
        $stmt->execute([$type, $id]);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $data += ['uuid' => Uuid::v4()];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO actions (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    /** El número, el origen y quién la creó no cambian nunca. */
    public static function update(int $id, array $data): void
    {
        unset($data['uuid'], $data['number'], $data['origin_type'], $data['origin_id'], $data['created_by'], $data['created_at']);
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::tenant()->prepare('UPDATE actions SET ' . ($sets ? $sets . ', ' : '') . 'updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([...array_values($data), $id]);
    }

    public static function format(int $number): string
    {
        return 'ACC-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
