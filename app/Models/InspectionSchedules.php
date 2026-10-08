<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Inspecciones programadas (las genera el cron a partir de los programas). */
final class InspectionSchedules
{
    private const SELECT = 'SELECT s.*, p.uuid AS program_uuid, p.name AS program_name, p.frequency, p.assignee_type, p.assignee_user_id,
            p.assignee_role, p.action_responsible_user_id, p.template_id, t.name AS template_name, t.uuid AS template_uuid,
            eq.code AS equipment_code, eq.name AS equipment_name, eq.uuid AS equipment_uuid, se.name AS sector_name, se.uuid AS sector_uuid,
            i.uuid AS inspection_uuid, i.number AS inspection_number, i.result AS inspection_result, iu.name AS inspector_name
        FROM inspection_schedule s
        JOIN inspection_programs p ON p.id = s.program_id
        JOIN inspection_templates t ON t.id = p.template_id
        LEFT JOIN equipment eq ON eq.id = s.equipment_id
        LEFT JOIN sectors se ON se.id = s.sector_id
        LEFT JOIN inspections i ON i.id = s.inspection_id
        LEFT JOIN users iu ON iu.id = i.inspector_user_id';

    /** Crea la programada si no existe (único por programa + objetivo + período). @return bool true si la creó */
    public static function ensure(int $programId, ?int $equipmentId, ?int $sectorId, string $periodKey, string $dueFrom, string $dueOn): bool
    {
        $target = $equipmentId !== null ? 'e:' . $equipmentId : 's:' . (int) $sectorId;
        $stmt = DB::tenant()->prepare('INSERT IGNORE INTO inspection_schedule (uuid, program_id, target_key, equipment_id, sector_id, period_key,
            due_from, due_on, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pendiente\', UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $stmt->execute([Uuid::v4(), $programId, $target, $equipmentId, $sectorId, $periodKey, $dueFrom, $dueOn]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @param array $f status, due_from_max (Y-m-d: ya habilitadas), due_on_lt (vencidas), due_on_from/due_on_to, program_id,
     *                 sector_ids, equipment_id, template_id, mine (['user_id', 'role', 'sector_ids'])
     * @param ?array $scope null = todo; ['sector_ids' => list<int>, 'mine' => array] ; ['mine' => array]
     */
    public static function search(array $f, ?array $scope, int $limit = 200): array
    {
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.due_on, se.name, eq.code LIMIT ' . max(1, $limit));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function count(array $f, ?array $scope): int
    {
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare('SELECT COUNT(*) FROM inspection_schedule s JOIN inspection_programs p ON p.id = s.program_id WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private static function where(array $f, ?array $scope): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        $mineSql = function (array $m) use (&$params): string {
            $ids = $m['sector_ids'] ?: [0];
            array_push($params, (int) $m['user_id'], (string) $m['role']);
            return "((p.assignee_type = 'user' AND p.assignee_user_id = ?) OR (p.assignee_type = 'role' AND p.assignee_role = ?)
                OR (p.assignee_type = 'sector_supervisors' AND s.sector_id IN (" . implode(',', array_map('intval', $ids)) . ')))';
        };
        if ($scope !== null) {
            $parts = [];
            if (isset($scope['sector_ids'])) {
                $parts[] = 's.sector_id IN (' . implode(',', array_map('intval', $scope['sector_ids'] ?: [0])) . ')';
            }
            $parts[] = $mineSql($scope['mine']);
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        if (!empty($f['mine'])) {
            $where[] = $mineSql($f['mine']);
        }
        foreach (['id', 'status', 'program_id', 'equipment_id'] as $col) {
            if (isset($f[$col]) && $f[$col] !== '') {
                $values = (array) $f[$col];
                $where[] = "s.{$col} IN (" . implode(',', array_fill(0, count($values), '?')) . ')';
                array_push($params, ...$values);
            }
        }
        if (!empty($f['template_id'])) {
            $where[] = 'p.template_id = ?';
            $params[] = (int) $f['template_id'];
        }
        if (!empty($f['sector_ids'])) {
            $where[] = 's.sector_id IN (' . implode(',', array_map('intval', $f['sector_ids'])) . ')';
        }
        foreach (['due_from_max' => 's.due_from <= ?', 'due_on_lt' => 's.due_on < ?', 'due_on_from' => 's.due_on >= ?', 'due_on_to' => 's.due_on <= ?'] as $k => $sql) {
            if (!empty($f[$k])) {
                $where[] = $sql;
                $params[] = $f[$k];
            }
        }
        return [$where, $params];
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE s.uuid = ?');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE s.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Programada pendiente que corresponde a una inspección recién hecha: misma plantilla y objetivo, ya habilitada
     * (due_from <= fecha). Si hay varias, la más vieja (se pone al día lo vencido primero).
     */
    public static function matchFor(int $templateId, ?int $equipmentId, ?int $sectorId, string $localDate): ?array
    {
        if ($equipmentId === null && $sectorId === null) {
            return null;
        }
        $stmt = DB::tenant()->prepare(self::SELECT . " WHERE p.template_id = ? AND s.status = 'pendiente' AND s.due_from <= ?
            AND " . ($equipmentId !== null ? 's.equipment_id = ?' : 's.equipment_id IS NULL AND s.sector_id = ?') . ' ORDER BY s.due_on, s.id LIMIT 1');
        $stmt->execute([$templateId, $localDate, $equipmentId ?? $sectorId]);
        return $stmt->fetch() ?: null;
    }

    public static function markDone(int $id, int $inspectionId, string $localDate, bool $onTime): void
    {
        DB::tenant()->prepare("UPDATE inspection_schedule SET status = 'hecha', inspection_id = ?, done_on = ?, on_time = ?, updated_at = UTC_TIMESTAMP()
            WHERE id = ? AND status = 'pendiente'")->execute([$inspectionId, $localDate, $onTime ? 1 : 0, $id]);
    }

    public static function skip(int $id, int $userId, string $reason): void
    {
        DB::tenant()->prepare("UPDATE inspection_schedule SET status = 'omitida', skip_reason = ?, skipped_by = ?, updated_at = UTC_TIMESTAMP()
            WHERE id = ? AND status = 'pendiente'")->execute([$reason, $userId, $id]);
    }

    /**
     * Cumplimiento de un período (solo lo que ya venció o se hizo: lo futuro no cuenta).
     * @param string $group program | sector | equipment | inspector
     * @return list<array{k, label, total, on_time, late, missed, skipped}>
     */
    public static function compliance(string $from, string $to, string $today, string $group, ?array $scope, array $f = []): array
    {
        [$where, $params] = self::where($f + ['due_on_from' => $from, 'due_on_to' => min($to, $today)], $scope);
        $cols = [
            'program'   => ['s.program_id', 'MAX(p.name)'],
            'sector'    => ['s.sector_id', 'MAX(se.name)'],
            'equipment' => ['s.equipment_id', "MAX(CONCAT(eq.code, ' · ', eq.name))"],
            'inspector' => ['i.inspector_user_id', 'MAX(iu.name)'],
        ][$group];
        $stmt = DB::tenant()->prepare("SELECT {$cols[0]} AS k, {$cols[1]} AS label, COUNT(*) AS total,
                SUM(s.status = 'hecha' AND s.on_time = 1) AS on_time, SUM(s.status = 'hecha' AND s.on_time = 0) AS late,
                SUM(s.status = 'pendiente' AND s.due_on < ?) AS missed, SUM(s.status = 'omitida') AS skipped,
                SUM(s.status = 'pendiente' AND s.due_on >= ?) AS open_today
            FROM inspection_schedule s JOIN inspection_programs p ON p.id = s.program_id
            LEFT JOIN sectors se ON se.id = s.sector_id LEFT JOIN equipment eq ON eq.id = s.equipment_id
            LEFT JOIN inspections i ON i.id = s.inspection_id LEFT JOIN users iu ON iu.id = i.inspector_user_id
            WHERE " . implode(' AND ', $where) . ($group === 'inspector' ? " AND s.status = 'hecha'" : '') . "
            GROUP BY {$cols[0]} ORDER BY label");
        $stmt->execute([$today, $today, ...$params]);
        return $stmt->fetchAll();
    }
}
