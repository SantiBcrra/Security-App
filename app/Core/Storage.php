<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Carpeta /storage (logs, sesiones, caché, archivos de empresas).
 * El deploy por FTP no sube /storage: las carpetas y su .htaccess se crean solos acá.
 */
final class Storage
{
    private const DIRS = ['logs', 'sessions', 'cache', 'tenants'];
    private const DENY = "Require all denied\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n";

    public static function root(): string
    {
        return BASE_PATH . '/storage';
    }

    public static function path(string $relative = ''): string
    {
        return self::root() . ($relative !== '' ? '/' . ltrim($relative, '/') : '');
    }

    public static function ensure(): void
    {
        $root = self::root();
        if (is_file($root . '/.htaccess') && is_dir($root . '/sessions')) {
            return;
        }
        foreach (self::DIRS as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                @mkdir($root . '/' . $dir, 0755, true);
            }
        }
        if (!is_file($root . '/.htaccess')) {
            @file_put_contents($root . '/.htaccess', self::DENY);
        }
    }
}
