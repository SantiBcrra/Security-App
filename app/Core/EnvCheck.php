<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Requisitos mínimos que el instalador exige antes de avanzar.
 * (public/install/check.php tiene el diagnóstico completo y standalone.)
 */
final class EnvCheck
{
    public const MIN_PHP = '8.2.0';
    public const EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'curl', 'gd', 'fileinfo', 'zip', 'json'];

    /** @return list<array{name:string, ok:bool, detail:string}> */
    public static function requirements(): array
    {
        $checks = [[
            'name'   => 'PHP ' . self::MIN_PHP . ' o superior',
            'ok'     => version_compare(PHP_VERSION, self::MIN_PHP, '>='),
            'detail' => PHP_VERSION,
        ]];
        foreach (self::EXTENSIONS as $ext) {
            $checks[] = ['name' => "Extensión {$ext}", 'ok' => extension_loaded($ext), 'detail' => extension_loaded($ext) ? 'cargada' : 'falta'];
        }
        foreach (['storage', 'storage/logs', 'storage/sessions', 'config'] as $dir) {
            $ok = self::writable(BASE_PATH . '/' . $dir);
            $checks[] = ['name' => "Escritura en {$dir}/", 'ok' => $ok, 'detail' => $ok ? 'ok' : 'sin permiso de escritura'];
        }
        return $checks;
    }

    public static function passes(): bool
    {
        foreach (self::requirements() as $check) {
            if (!$check['ok']) {
                return false;
            }
        }
        return true;
    }

    private static function writable(string $dir): bool
    {
        $probe = $dir . '/.write-test-' . bin2hex(random_bytes(4));
        return is_dir($dir) && @file_put_contents($probe, 'ok') === 2 && @unlink($probe);
    }
}
