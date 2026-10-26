<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Super-admins de la plataforma (base maestra). */
final class PlatformAdmin
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = DB::master()->prepare('SELECT * FROM platform_admins WHERE email = ? LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email))]);
        return $stmt->fetch() ?: null;
    }

    public static function findActive(int $id): ?array
    {
        $stmt = DB::master()->prepare('SELECT id, uuid, name, email FROM platform_admins WHERE id = ? AND is_active = 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Crea el admin o, si el email ya existe (reintento del instalador), actualiza nombre y contraseña. */
    public static function upsert(string $name, string $email, string $password): void
    {
        $email = mb_strtolower(trim($email));
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (self::findByEmail($email)) {
            DB::master()->prepare('UPDATE platform_admins SET name = ?, password_hash = ?, is_active = 1, updated_at = UTC_TIMESTAMP() WHERE email = ?')
                ->execute([trim($name), $hash, $email]);
            return;
        }
        DB::master()->prepare('INSERT INTO platform_admins (uuid, name, email, password_hash, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([Uuid::v4(), trim($name), $email, $hash]);
    }

    public static function all(): array
    {
        return DB::master()->query('SELECT id, uuid, name, email, is_active, last_login_at, created_at FROM platform_admins ORDER BY is_active DESC, name')->fetchAll();
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::master()->prepare('SELECT * FROM platform_admins WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function emailTaken(string $email, ?int $exceptId = null): bool
    {
        $stmt = DB::master()->prepare('SELECT 1 FROM platform_admins WHERE email = ? AND id <> ? LIMIT 1');
        $stmt->execute([mb_strtolower(trim($email)), $exceptId ?? 0]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return string uuid */
    public static function create(string $name, string $email, string $password): string
    {
        $uuid = Uuid::v4();
        DB::master()->prepare('INSERT INTO platform_admins (uuid, name, email, password_hash, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$uuid, trim($name), mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT)]);
        return $uuid;
    }

    /** Nombre y email; la contraseña solo si viene (null = no se cambia). */
    public static function update(int $id, string $name, string $email, ?string $password): void
    {
        DB::master()->prepare('UPDATE platform_admins SET name = ?, email = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
            ->execute([trim($name), mb_strtolower(trim($email)), $id]);
        if ($password !== null) {
            DB::master()->prepare('UPDATE platform_admins SET password_hash = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        }
    }

    public static function setActive(int $id, bool $active): void
    {
        DB::master()->prepare('UPDATE platform_admins SET is_active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }

    public static function delete(int $id): void
    {
        DB::master()->prepare('DELETE FROM platform_admins WHERE id = ?')->execute([$id]);
    }

    public static function countActive(?int $exceptId = null): int
    {
        $stmt = DB::master()->prepare('SELECT COUNT(*) FROM platform_admins WHERE is_active = 1 AND id <> ?');
        $stmt->execute([$exceptId ?? 0]);
        return (int) $stmt->fetchColumn();
    }

    public static function touchLogin(int $id): void
    {
        DB::master()->prepare('UPDATE platform_admins SET last_login_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$id]);
    }

    public static function rehashIfNeeded(int $id, string $hash, string $password): void
    {
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            DB::master()->prepare('UPDATE platform_admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        }
    }
}
