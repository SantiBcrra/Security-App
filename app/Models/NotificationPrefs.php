<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Canales que cada usuario quiere recibir para avisos no críticos (por defecto, todos). */
final class NotificationPrefs
{
    /** @return array<string,bool> canal => habilitado */
    public static function forUser(int $userId): array
    {
        $stmt = DB::tenant()->prepare('SELECT channel, enabled FROM notification_prefs WHERE user_id = ?');
        $stmt->execute([$userId]);
        return array_map(fn ($v) => (int) $v === 1, array_column($stmt->fetchAll(), 'enabled', 'channel'));
    }

    public static function set(int $userId, string $channel, bool $enabled): void
    {
        DB::tenant()->prepare('INSERT INTO notification_prefs (user_id, channel, enabled, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = UTC_TIMESTAMP()')->execute([$userId, $channel, $enabled ? 1 : 0]);
    }
}
