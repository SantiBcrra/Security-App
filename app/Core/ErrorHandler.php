<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Manejo global de errores: todo se loguea en storage/logs con un id corto.
 * En debug se muestra la traza; en producción, una pantalla genérica con el id.
 */
final class ErrorHandler
{
    private static ?Request $request = null;

    public static function register(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false; // suprimido con @
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        set_exception_handler([self::class, 'handle']);

        register_shutdown_function(static function (): void {
            $error = error_get_last();
            if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::handle(new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']));
            }
        });
    }

    public static function setRequest(Request $request): void
    {
        self::$request = $request;
    }

    public static function handle(\Throwable $e): void
    {
        $id = bin2hex(random_bytes(4));
        Logger::error(get_class($e) . ': ' . $e->getMessage(), [
            'id'    => $id,
            'file'  => $e->getFile() . ':' . $e->getLine(),
            'path'  => self::$request?->path,
            'trace' => explode("\n", $e->getTraceAsString()),
        ]);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        self::render($e, $id)->send();
        exit(1);
    }

    private static function render(\Throwable $e, string $id): Response
    {
        $debug = Config::isDebug();
        if (self::$request?->wantsJson()) {
            $message = $debug ? $e->getMessage() : "Error interno (ref. {$id})";
            return Response::jsonError($message, 500, 'server_error');
        }
        try {
            $html = View::render('errors/500', [
                'errorId' => $id,
                'debug'   => $debug,
                'error'   => $debug ? $e : null,
            ]);
        } catch (\Throwable) {
            $html = '<h1>Error interno</h1><p>Referencia: ' . htmlspecialchars($id) . '</p>';
        }
        return Response::html($html, 500);
    }
}
