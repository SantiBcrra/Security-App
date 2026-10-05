<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Registro de empresas (base maestra). */
final class Tenants
{
    /** Campos editables desde el panel. La conexión a la base no se edita por formulario. */
    public const EDITABLE = ['name', 'legal_name', 'cuit', 'timezone', 'plan'];

    public static function all(): array
    {
        return DB::master()->query('SELECT * FROM tenants ORDER BY name')->fetchAll();
    }

    public static function findByUuid(string $uuid): ?array
    {
        $stmt = DB::master()->prepare('SELECT * FROM tenants WHERE uuid = ? LIMIT 1');
        $stmt->execute([$uuid]);
        return $stmt->fetch() ?: null;
    }

    public static function findBySlug(string $slug): ?array
    {
        $stmt = DB::master()->prepare('SELECT * FROM tenants WHERE slug = ? LIMIT 1');
        $stmt->execute([mb_strtolower(trim($slug))]);
        return $stmt->fetch() ?: null;
    }

    public static function slugExists(string $slug): bool
    {
        $stmt = DB::master()->prepare('SELECT 1 FROM tenants WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        return (bool) $stmt->fetchColumn();
    }

    public static function databaseInUse(string $host, int $port, string $name): bool
    {
        $stmt = DB::master()->prepare('SELECT 1 FROM tenants WHERE db_host = ? AND db_port = ? AND db_name = ? LIMIT 1');
        $stmt->execute([$host, $port, $name]);
        return (bool) $stmt->fetchColumn();
    }

    public static function create(array $data): void
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO tenants (`' . implode('`, `', $cols) . '`, created_at, updated_at) VALUES ('
            . implode(', ', array_fill(0, count($cols), '?')) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())';
        DB::master()->prepare($sql)->execute(array_values($data));
    }

    /** @param array<string,mixed> $data solo columnas de EDITABLE, logo_path y estado */
    public static function update(string $uuid, array $data): void
    {
        $allowed = array_merge(self::EDITABLE, ['logo_path', 'status', 'status_reason', 'suspended_at', 'settings']);
        $data = array_intersect_key($data, array_flip($allowed));
        if (!$data) {
            return;
        }
        $sets = implode(', ', array_map(fn ($c) => "`{$c}` = ?", array_keys($data)));
        DB::master()->prepare("UPDATE tenants SET {$sets}, updated_at = UTC_TIMESTAMP() WHERE uuid = ?")
            ->execute([...array_values($data), $uuid]);
    }
}
