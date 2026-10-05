<?php
declare(strict_types=1);

namespace App\Core;

final class App
{
    private static ?string $baseUrl = null;

    /** Prepara el entorno: constantes, config, errores, storage. Lo usan web y tests. */
    public static function boot(string $basePath): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', $basePath);
        }
        // La base y PHP trabajan en UTC; la zona de cada empresa se aplica al mostrar.
        date_default_timezone_set('UTC');
        mb_internal_encoding('UTF-8');

        Config::load(BASE_PATH . '/config');
        Storage::ensure();
        ErrorHandler::register();
    }

    public static function run(): void
    {
        $request = Request::fromGlobals();
        ErrorHandler::setRequest($request);

        if (!$request->isApi()) {
            Session::start();
        }

        $router = new Router();
        (require BASE_PATH . '/app/routes.php')($router);
        $router->dispatch($request)->send();

        // Respaldo del cron: solo en páginas (no API ni el propio cron) y si el servidor puede
        // cerrar la respuesta antes de seguir trabajando (PHP-FPM).
        if (self::isInstalled() && !$request->isApi() && !str_starts_with($request->path, '/cron')) {
            try {
                \App\Services\Notify\CronRunner::lazy();
            } catch (\Throwable) {
            }
        }
    }

    /** El instalador crea este archivo al terminar; sin él, todo redirige a /install. */
    public static function isInstalled(): bool
    {
        return is_file(Storage::path('installed.lock'));
    }

    /**
     * Prefijo de URL donde está instalada la app ('' en un subdominio, '/securityapp' en
     * una subcarpeta). Si el docroot es la raíz del proyecto, el .htaccess reescribe a
     * public/ y SCRIPT_NAME trae '/public': se lo saca para que las URLs no lo muestren.
     */
    public static function baseUrl(): string
    {
        if (self::$baseUrl === null && PHP_SAPI === 'cli') {
            self::$baseUrl = ''; // tests y scripts: no hay URL de instalación
        }
        if (self::$baseUrl === null) {
            $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
            $dir = rtrim($dir, '/.');
            if (str_ends_with($dir, '/public')) {
                $withoutPublic = substr($dir, 0, -strlen('/public'));
                $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
                if (!str_starts_with($uri, $dir . '/') && $uri !== $dir) {
                    $dir = $withoutPublic;
                }
            }
            self::$baseUrl = $dir;
        }
        return self::$baseUrl;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }
}
