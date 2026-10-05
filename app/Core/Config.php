<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Configuración: config/config.php (defaults versionados) + config/config.local.php
 * (credenciales del entorno, fuera de git, lo genera el instalador).
 */
final class Config
{
    private static array $items = [];

    public static function load(string $configDir): void
    {
        $items = require $configDir . '/config.php';
        $local = $configDir . '/config.local.php';
        if (is_file($local)) {
            $items = array_replace_recursive($items, require $local);
        }
        self::$items = $items;
    }

    /** Config::get('db.master.host') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $ref = &self::$items;
        foreach (explode('.', $key) as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref = $value;
    }

    public static function isDebug(): bool
    {
        return (bool) self::get('app.debug', false);
    }
}
