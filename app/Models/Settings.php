<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Configuración clave/valor de la empresa (tabla settings). */
final class Settings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $stmt = DB::tenant()->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return $value === null ? $default : $value === '1';
    }

    public static function set(string $key, ?string $value): void
    {
        DB::tenant()->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = UTC_TIMESTAMP()')->execute([$key, $value]);
    }
}
