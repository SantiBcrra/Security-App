<?php
declare(strict_types=1);

namespace App\Core;

/** Log a archivo diario en storage/logs. Nunca rompe la página si no puede escribir. */
final class Logger
{
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('WARNING', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    private static function write(string $level, string $message, array $context): void
    {
        $line = sprintf(
            "[%s] %s: %s%s\n",
            gmdate('Y-m-d H:i:s'),
            $level,
            $message,
            $context ? ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );
        $file = Storage::path('logs/app-' . gmdate('Y-m-d') . '.log');
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === false) {
            error_log(trim($line));
        }
    }
}
