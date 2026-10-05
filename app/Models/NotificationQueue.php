<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Cola de envíos y registro de cada envío (email, push, whatsapp). */
final class NotificationQueue
{
    public static function add(array $data): int
    {
        $data += ['uuid' => Uuid::v4(), 'status' => 'pending'];
        $cols = array_keys($data);
        DB::tenant()->prepare('INSERT INTO notification_queue (`' . implode('`, `', $cols) . '`, next_attempt_at, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute(array_values($data));
        return (int) DB::tenant()->lastInsertId();
    }

    /** Pendientes cuyo próximo intento ya llegó (las críticas primero). */
    public static function due(int $limit, array $onlyIds = []): array
    {
        $sql = "SELECT * FROM notification_queue WHERE status = 'pending' AND next_attempt_at <= UTC_TIMESTAMP()";
        if ($onlyIds) {
            $sql .= ' AND id IN (' . implode(',', array_map('intval', $onlyIds)) . ')';
        }
        return DB::tenant()->query($sql . ' ORDER BY is_critical DESC, id LIMIT ' . max(1, $limit))->fetchAll();
    }

    public static function markSent(int $id): void
    {
        DB::tenant()->prepare("UPDATE notification_queue SET status = 'sent', attempts = attempts + 1, sent_at = UTC_TIMESTAMP(),
            last_error = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$id]);
    }

    public static function markFailed(int $id, int $attempts, string $error, ?int $retryInSeconds): void
    {
        DB::tenant()->prepare('UPDATE notification_queue SET status = ?, attempts = ?, last_error = ?,
            next_attempt_at = UTC_TIMESTAMP() + INTERVAL ? SECOND, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([$retryInSeconds === null ? 'failed' : 'pending', $attempts, mb_substr($error, 0, 500), $retryInSeconds ?? 0, $id]);
    }

    public static function findById(int $id): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM notification_queue WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function recent(int $limit = 50): array
    {
        return DB::tenant()->query('SELECT q.*, u.name AS user_name FROM notification_queue q LEFT JOIN users u ON u.id = q.user_id
            ORDER BY q.id DESC LIMIT ' . max(1, $limit))->fetchAll();
    }

    public static function stats(): array
    {
        return array_map('intval', array_column(DB::tenant()->query('SELECT status, COUNT(*) n FROM notification_queue GROUP BY status')->fetchAll(), 'n', 'status'));
    }
}
