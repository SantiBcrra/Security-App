<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Token CSRF por sesión (mismo enfoque que MetalERP core/Csrf.php).
 * Vistas:  <?= csrf_field() ?>
 * AJAX:    header X-CSRF-Token con csrf_token()
 * La verificación la hace el middleware VerifyCsrf en todo POST web.
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . e(self::token()) . '">';
    }

    public static function check(mixed $sent): bool
    {
        $expected = $_SESSION[self::KEY] ?? '';
        return is_string($sent) && $sent !== '' && is_string($expected) && $expected !== ''
            && hash_equals($expected, $sent);
    }

    /** Nuevo token (al loguearse, para no arrastrar el de la sesión anónima). */
    public static function rotate(): void
    {
        unset($_SESSION[self::KEY]);
        self::token();
    }
}
