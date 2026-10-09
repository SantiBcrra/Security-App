<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Dispositivos (sesiones de la app móvil) de los usuarios de la empresa activa. */
final class UserDevices
{
    /** Alta o re-login del mismo dispositivo: un registro por usuario + dispositivo. @return string uuid */
    public static function upsert(int $userId, string $deviceUuid, ?string $name, string $refreshHash, string $expiresAt, ?string $ip): string
    {
        $stmt = DB::tenant()->prepare('SELECT uuid FROM user_devices WHERE user_id = ? AND device_uuid = ?');
        $stmt->execute([$userId, $deviceUuid]);
        $uuid = $stmt->fetchColumn();
        if ($uuid) {
            DB::tenant()->prepare('UPDATE user_devices SET device_name = ?, refresh_token_hash = ?, previous_token_hash = NULL,
                expires_at = ?, last_used_at = UTC_TIMESTAMP(), revoked_at = NULL, revoked_reason = NULL, ip = ? WHERE uuid = ?')
                ->execute([$name, $refreshHash, $expiresAt, $ip, $uuid]);
            return $uuid;
        }
        $uuid = Uuid::v4();
        DB::tenant()->prepare('INSERT INTO user_devices (uuid, user_id, device_uuid, device_name, refresh_token_hash, expires_at,
            last_used_at, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())')
            ->execute([$uuid, $userId, $deviceUuid, $name, $refreshHash, $expiresAt, $ip]);
        return $uuid;
    }

    /** Token de notificaciones del celular (FCM: "fcm:{token}"). Un token pertenece a un solo dispositivo. */
    public static function setPushToken(int $id, ?string $token): void
    {
        $db = DB::tenant();
        if ($token !== null) {
            $db->prepare('UPDATE user_devices SET push_token = NULL WHERE push_token = ? AND id <> ?')->execute([$token, $id]);
        }
        $db->prepare('UPDATE user_devices SET push_token = ? WHERE id = ?')->execute([$token, $id]);
    }

    public static function findByUuid(string $uuid): ?array
    {
        return self::one('uuid', $uuid);
    }

    public static function findByRefreshHash(string $hash): ?array
    {
        return self::one('refresh_token_hash', $hash);
    }

    public static function findByPreviousHash(string $hash): ?array
    {
        return self::one('previous_token_hash', $hash);
    }

    public static function rotate(int $id, string $newHash, string $previousHash, string $expiresAt, ?string $ip): void
    {
        DB::tenant()->prepare('UPDATE user_devices SET refresh_token_hash = ?, previous_token_hash = ?, expires_at = ?,
            last_used_at = UTC_TIMESTAMP(), ip = ? WHERE id = ?')
            ->execute([$newHash, $previousHash, $expiresAt, $ip, $id]);
    }

    public static function revoke(int $id, string $reason): void
    {
        DB::tenant()->prepare('UPDATE user_devices SET revoked_at = UTC_TIMESTAMP(), revoked_reason = ? WHERE id = ? AND revoked_at IS NULL')
            ->execute([$reason, $id]);
    }

    public static function revokeAllForUser(int $userId, string $reason): int
    {
        $stmt = DB::tenant()->prepare('UPDATE user_devices SET revoked_at = UTC_TIMESTAMP(), revoked_reason = ? WHERE user_id = ? AND revoked_at IS NULL');
        $stmt->execute([$reason, $userId]);
        return $stmt->rowCount();
    }

    public static function forUser(int $userId): array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM user_devices WHERE user_id = ? ORDER BY revoked_at IS NULL DESC, last_used_at DESC');
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    }

    public static function isUsable(array $device): bool
    {
        return $device['revoked_at'] === null && strtotime($device['expires_at'] . ' UTC') > time();
    }

    private static function one(string $column, string $value): ?array
    {
        $stmt = DB::tenant()->prepare("SELECT * FROM user_devices WHERE `{$column}` = ? LIMIT 1");
        $stmt->execute([$value]);
        return $stmt->fetch() ?: null;
    }
}
