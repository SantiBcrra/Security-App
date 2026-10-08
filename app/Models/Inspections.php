<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Inspecciones hechas (evidencia inmutable: solo cambia el estado al anular). */
final class Inspections
{
    private const SELECT = 'SELECT i.*, t.name AS template_name, t.uuid AS template_uuid, v.version AS template_version,
            eq.code AS equipment_code, eq.name AS equipment_name, eq.uuid AS equipment_uuid,
            si.name AS site_name, se.name AS sector_name, u.name AS inspector_name, au.name AS annulled_by_name
        FROM inspections i
        JOIN inspection_templates t ON t.id = i.template_id
        JOIN inspection_template_versions v ON v.id = i.template_version_id
        LEFT JOIN equipment eq ON eq.id = i.equipment_id
        LEFT JOIN sites si ON si.id = i.site_id
        LEFT JOIN sectors se ON se.id = i.sector_id
        LEFT JOIN users u ON u.id = i.inspector_user_id
        LEFT JOIN users au ON au.id = i.annulled_by';

    /**
     * @param array $f template_id, equipment_id, sector_ids, result, inspector_user_id, status, from/to (UTC), q
     * @param ?array $scope null = todo; ['own' => user_id]; ['sector_ids' => list<int>, 'user_id' => int]
     */
    public static function search(array $f, ?array $scope, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY i.done_at_device DESC, i.id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset));
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function count(array $f, ?array $scope): int
    {
        [$where, $params] = self::where($f, $scope);
        $stmt = DB::tenant()->prepare('SELECT COUNT(*) FROM inspections i WHERE ' . implode(' AND ', $where));
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public static function countByResult(array $f, ?array $scope): array
    {
        unset($f['result']);
        [$where, $params] = self::where($f + ['status' => 'completa'], $scope);
        $stmt = DB::tenant()->prepare('SELECT i.result, COUNT(*) AS n FROM inspections i WHERE ' . implode(' AND ', $where) . ' GROUP BY i.result');
        $stmt->execute($params);
        return array_map('intval', array_column($stmt->fetchAll(), 'n', 'result'));
    }

    private static function where(array $f, ?array $scope): array
    {
        $where = ['i.deleted_at IS NULL'];
        $params = [];
        if ($scope !== null) {
            if (isset($scope['own'])) {
                $where[] = 'i.inspector_user_id = ?';
                $params[] = $scope['own'];
            } else {
                $ids = $scope['sector_ids'] ?: [0];
                $where[] = '(i.sector_id IN (' . implode(',', array_map('intval', $ids)) . ') OR i.inspector_user_id = ?)';
                $params[] = $scope['user_id'];
            }
        }
        foreach (['template_id', 'equipment_id', 'result', 'inspector_user_id', 'status'] as $col) {
            if (isset($f[$col]) && $f[$col] !== '' && $f[$col] !== null) {
                $where[] = "i.{$col} = ?";
                $params[] = $f[$col];
            }
        }
        if (!empty($f['sector_ids'])) {
            $where[] = 'i.sector_id IN (' . implode(',', array_map('intval', $f['sector_ids'])) . ')';
        }
        foreach (['from' => '>=', 'to' => '<'] as $k => $op) {
            if (!empty($f[$k])) {
                $where[] = "i.done_at_device {$op} ?";
                $params[] = $f[$k];
            }
        }
        if (($f['q'] ?? '') !== '') {
            $q = trim((string) $f['q']);
            if (preg_match('/^(?:INS-?)?0*(\d+)$/i', $q, $m)) {
                $where[] = 'i.number = ?';
                $params[] = (int) $m[1];
            } else {
                $where[] = '(i.notes LIKE ? OR EXISTS (SELECT 1 FROM equipment e2 WHERE e2.id = i.equipment_id AND (e2.code LIKE ? OR e2.name LIKE ?)))';
                array_push($params, "%{$q}%", "%{$q}%", "%{$q}%");
            }
        }
        return [$where, $params];
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE i.uuid = ? AND i.deleted_at IS NULL');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . ' WHERE i.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Últimas inspecciones completas de un equipo. */
    public static function latestForEquipment(int $equipmentId, int $limit = 5): array
    {
        $stmt = DB::tenant()->prepare(self::SELECT . " WHERE i.equipment_id = ? AND i.status = 'completa' AND i.deleted_at IS NULL
            ORDER BY i.done_at_device DESC, i.id DESC LIMIT " . max(1, $limit));
        $stmt->execute([$equipmentId]);
        return $stmt->fetchAll();
    }

    public static function create(array $data): int
    {
        $data += ['uuid' => Uuid::v4()];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO inspections (`' . implode('`, `', $cols) . '`, received_at, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    /** Solo la anulación cambia una inspección: respuestas y reporte original quedan como llegaron. */
    public static function annul(int $id, int $userId, string $reason): void
    {
        DB::tenant()->prepare("UPDATE inspections SET status = 'anulada', annulled_at = UTC_TIMESTAMP(), annulled_by = ?, annul_reason = ?,
            updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'completa'")->execute([$userId, $reason, $id]);
    }

    public static function addAnswer(int $inspectionId, array $a): int
    {
        DB::tenant()->prepare('INSERT INTO inspection_answers (inspection_id, item_key, section_title, item_text, value, ok, critical, comment, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$inspectionId, $a['key'], $a['section'], mb_substr($a['text'], 0, 255), $a['value'],
            $a['ok'] === null ? null : ($a['ok'] ? 1 : 0), $a['critical'] ? 1 : 0, $a['comment'], $a['sort']]);
        return (int) DB::tenant()->lastInsertId();
    }

    public static function linkAnswerAction(int $answerId, int $actionId): void
    {
        DB::tenant()->prepare('UPDATE inspection_answers SET action_id = ? WHERE id = ? AND action_id IS NULL')->execute([$actionId, $answerId]);
    }

    public static function answers(int $inspectionId): array
    {
        $stmt = DB::tenant()->prepare('SELECT a.*, ac.uuid AS action_uuid, ac.number AS action_number, ac.status AS action_status
            FROM inspection_answers a LEFT JOIN actions ac ON ac.id = a.action_id WHERE a.inspection_id = ? ORDER BY a.sort_order, a.id');
        $stmt->execute([$inspectionId]);
        return $stmt->fetchAll();
    }

    public static function addAttachment(int $inspectionId, ?string $itemKey, array $data): string
    {
        $uuid = Uuid::v4();
        DB::tenant()->prepare('INSERT INTO inspection_attachments (uuid, inspection_id, item_key, path, thumb_path, original_name, mime,
            size_bytes, sha256, width, height, exif, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$uuid, $inspectionId, $itemKey, $data['path'], $data['thumb_path'], $data['original_name'], $data['mime'],
                $data['size_bytes'], $data['sha256'], $data['width'], $data['height'], $data['exif'], $data['uploaded_by']]);
        return $uuid;
    }

    public static function attachments(int $inspectionId): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM inspection_attachments WHERE inspection_id = ? ORDER BY id');
        $stmt->execute([$inspectionId]);
        return $stmt->fetchAll();
    }

    public static function attachment(int $inspectionId, string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM inspection_attachments WHERE inspection_id = ? AND uuid = ?');
        $stmt->execute([$inspectionId, $uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function addEvent(int $inspectionId, string $type, array $f = []): void
    {
        DB::tenant()->prepare('INSERT INTO inspection_events (uuid, inspection_id, type, comment, data, user_id, actor_name, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')->execute([Uuid::v4(), $inspectionId, $type, $f['comment'] ?? null,
            isset($f['data']) ? json_encode($f['data'], JSON_UNESCAPED_UNICODE) : null, $f['user_id'] ?? null, $f['actor_name'] ?? null]);
    }

    public static function events(int $inspectionId): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM inspection_events WHERE inspection_id = ? ORDER BY id');
        $stmt->execute([$inspectionId]);
        return $stmt->fetchAll();
    }

    public static function format(int $number): string
    {
        return 'INS-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
