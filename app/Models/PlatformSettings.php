<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Crypto;
use App\Core\DB;

/** Configuración de la plataforma (base maestra): SMTP, WhatsApp, Expo, estado del cron. */
final class PlatformSettings
{
    private static ?array $cache = null;

    public static function get(string $key, ?string $default = null): ?string
    {
        if (self::$cache === null) {
            try {
                self::$cache = array_column(DB::master()->query('SELECT `key`, `value` FROM platform_settings')->fetchAll(), 'value', 'key');
            } catch (\PDOException) {
                self::$cache = []; // tabla todavía sin migrar
            }
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        DB::master()->prepare('INSERT INTO platform_settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = UTC_TIMESTAMP()')->execute([$key, $value]);
        self::$cache = null;
    }

    /** Secretos (contraseña SMTP, token de WhatsApp): se guardan cifrados. */
    public static function secret(string $key): ?string
    {
        $value = self::get($key);
        return $value ? Crypto::decrypt($value) : null;
    }

    public static function setSecret(string $key, ?string $plain): void
    {
        self::set($key, $plain === null || $plain === '' ? null : Crypto::encrypt($plain));
    }

    public static function forget(): void
    {
        self::$cache = null;
    }
}
