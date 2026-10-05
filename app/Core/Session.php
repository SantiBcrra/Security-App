<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Sesión PHP con carpeta propia en storage/sessions (mismo criterio que MetalERP:
 * el /tmp compartido del hosting barría las sesiones con el GC de otros sitios).
 */
final class Session
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $lifetime = (int) Config::get('session.lifetime', 43200);
        $dir = Storage::path('sessions');
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
            // Con carpeta propia nadie más limpia: GC en el 1% de los requests.
            ini_set('session.gc_probability', '1');
            ini_set('session.gc_divisor', '100');
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        session_name((string) Config::get('session.name', 'secapp_sid'));
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => App::baseUrl() . '/',
            'secure'   => App::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Llamar al loguearse / cambiar privilegios para evitar fijación de sesión. */
    public static function regenerate(): void
    {
        session_regenerate_id(true);
    }
}
