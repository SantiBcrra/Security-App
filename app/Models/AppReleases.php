<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Core\Uuid;

/** Versiones de la app Android (base maestra: son de la plataforma, no de una empresa). */
final class AppReleases
{
    public const ANDROID = 'android';

    public static function latest(string $platform = self::ANDROID): ?array
    {
        $stmt = DB::master()->prepare('SELECT * FROM app_releases WHERE platform = ? AND is_active = 1 ORDER BY version_code DESC LIMIT 1');
        $stmt->execute([$platform]);
        return $stmt->fetch() ?: null;
    }

    public static function all(string $platform = self::ANDROID): array
    {
        $stmt = DB::master()->prepare('SELECT r.*, a.name AS uploaded_by_name FROM app_releases r LEFT JOIN platform_admins a ON a.id = r.uploaded_by
            WHERE r.platform = ? ORDER BY r.version_code DESC');
        $stmt->execute([$platform]);
        return $stmt->fetchAll();
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::master()->prepare('SELECT * FROM app_releases WHERE uuid = ?');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function maxCode(string $platform = self::ANDROID): int
    {
        $stmt = DB::master()->prepare('SELECT COALESCE(MAX(version_code), 0) FROM app_releases WHERE platform = ?');
        $stmt->execute([$platform]);
        return (int) $stmt->fetchColumn();
    }

    public static function create(array $r): string
    {
        $uuid = Uuid::v4();
        DB::master()->prepare('INSERT INTO app_releases (uuid, platform, version_code, version_name, min_version_code, notes, path, sha256, size_bytes, uploaded_by,
            is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())')
            ->execute([$uuid, $r['platform'], $r['version_code'], $r['version_name'], $r['min_version_code'], $r['notes'], $r['path'], $r['sha256'],
                $r['size_bytes'], $r['uploaded_by']]);
        return $uuid;
    }

    public static function setActive(int $id, bool $active): void
    {
        DB::master()->prepare('UPDATE app_releases SET is_active = ? WHERE id = ?')->execute([$active ? 1 : 0, $id]);
    }
}
