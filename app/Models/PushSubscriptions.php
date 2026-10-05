<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Suscripciones Web Push de los navegadores/PWA instaladas. */
final class PushSubscriptions
{
    public static function save(int $userId, ?string $deviceUuid, string $endpoint, string $p256dh, string $auth, ?string $ua): void
    {
        DB::tenant()->prepare('INSERT INTO push_subscriptions (user_id, device_uuid, endpoint, endpoint_hash, p256dh, auth, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), device_uuid = VALUES(device_uuid), p256dh = VALUES(p256dh),
                auth = VALUES(auth), user_agent = VALUES(user_agent), failed_count = 0')
            ->execute([$userId, $deviceUuid, $endpoint, hash('sha256', $endpoint), $p256dh, $auth, $ua ? mb_substr($ua, 0, 255) : null]);
    }

    public static function forUser(int $userId): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM push_subscriptions WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM push_subscriptions WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function deleteByEndpoint(int $userId, string $endpoint): void
    {
        DB::tenant()->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?')->execute([$userId, hash('sha256', $endpoint)]);
    }

    public static function delete(int $id): void
    {
        DB::tenant()->prepare('DELETE FROM push_subscriptions WHERE id = ?')->execute([$id]);
    }

    public static function touch(int $id): void
    {
        DB::tenant()->prepare('UPDATE push_subscriptions SET last_used_at = UTC_TIMESTAMP(), failed_count = 0 WHERE id = ?')->execute([$id]);
    }
}
