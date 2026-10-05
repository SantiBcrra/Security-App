<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Avisos en la app (campanita). */
final class Notifications
{
    public static function create(int $userId, string $event, string $title, ?string $body, ?string $url, bool $critical, ?int $alertId): void
    {
        DB::tenant()->prepare('INSERT INTO notifications (uuid, user_id, event, title, body, url, is_critical, alert_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([Uuid::v4(), $userId, $event, mb_substr($title, 0, 191), $body, $url, $critical ? 1 : 0, $alertId]);
    }

    public static function unreadCount(int $userId): int
    {
        $stmt = DB::tenant()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    public static function forUser(int $userId, int $limit = 50, bool $onlyUnread = false): array
    {
        $stmt = DB::tenant()->prepare('SELECT n.*, a.uuid AS alert_uuid, a.acked_at AS alert_acked_at, a.acked_name AS alert_acked_name
            FROM notifications n LEFT JOIN alerts a ON a.id = n.alert_id
            WHERE n.user_id = ?' . ($onlyUnread ? ' AND n.read_at IS NULL' : '') . ' ORDER BY n.id DESC LIMIT ' . max(1, $limit));
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function markRead(int $userId, ?string $uuid = null): void
    {
        $sql = 'UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL';
        $params = [$userId];
        if ($uuid !== null) {
            $sql .= ' AND uuid = ?';
            $params[] = $uuid;
        }
        DB::tenant()->prepare($sql)->execute($params);
    }

    public static function findForUser(int $userId, string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM notifications WHERE user_id = ? AND uuid = ?');
        $stmt->execute([$userId, $uuid]);
        return $stmt->fetch() ?: null;
    }
}
