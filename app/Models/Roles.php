<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Roles de la empresa activa (base de la empresa). */
final class Roles
{
    public static function all(): array
    {
        return DB::tenant()->query('SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.role_id = r.id) AS users_count
            FROM roles r ORDER BY r.is_system DESC, r.name')->fetchAll();
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM roles WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $stmt = DB::tenant()->prepare('SELECT * FROM roles WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    public static function slugExists(string $slug): bool
    {
        return self::findBySlug($slug) !== null;
    }

    /** @return string uuid */
    public static function create(string $slug, string $name, ?string $description, array $permissions): string
    {
        $uuid = Uuid::v4();
        DB::tenant()->prepare('INSERT INTO roles (uuid, slug, name, description, permissions, is_system, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$uuid, $slug, $name, $description, json_encode($permissions)]);
        return $uuid;
    }

    /** Solo roles que no son del sistema. */
    public static function update(int $id, string $name, ?string $description, array $permissions): void
    {
        DB::tenant()->prepare('UPDATE roles SET name = ?, description = ?, permissions = ?, updated_at = UTC_TIMESTAMP()
            WHERE id = ? AND is_system = 0')
            ->execute([$name, $description, json_encode($permissions), $id]);
    }

    public static function delete(int $id): void
    {
        DB::tenant()->prepare('DELETE FROM roles WHERE id = ? AND is_system = 0')->execute([$id]);
    }
}
