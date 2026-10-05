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
