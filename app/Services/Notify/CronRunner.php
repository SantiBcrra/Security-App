<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Storage;
use App\Core\Tenant;
use App\Models\PlatformSettings;
use App\Models\Tenants;

/**
 * Tareas programadas sin workers: lo dispara /cron/run?key=… (cron del hosting o cron-job.org)
 * o el "lazy cron". Por cada empresa: cola de envíos, escalamientos, vencidas y resúmenes.
 * Lock por empresa con GET_LOCK: dos corridas nunca se pisan.
 */
final class CronRunner
{
    public static function key(): string
    {
        return substr(hash_hmac('sha256', 'cron-url', \App\Core\Crypto::key('cron')), 0, 32);
    }

    public static function run(int $timeBudgetSeconds = 25): array
    {
        $deadline = microtime(true) + $timeBudgetSeconds;
        $summary = [];
        foreach (Tenants::all() as $tenant) {
            if ($tenant['status'] === 'suspended' || microtime(true) > $deadline) {
                continue;
            }
            try {
                Tenant::activate($tenant);
                $lock = 'secapp_cron_' . $tenant['db_name'];
                if ((int) DB::tenant()->query('SELECT GET_LOCK(' . DB::tenant()->quote($lock) . ', 0)')->fetchColumn() !== 1) {
                    $summary[$tenant['slug']] = 'en curso (otra corrida)';
                    continue;
                }
                try {
                    $remaining = max(2, (int) ($deadline - microtime(true)));
                    $summary[$tenant['slug']] = [
                        'escalated' => Escalations::run(),
                        'actions'   => ActionReminders::run(),
                        'inspections' => InspectionReminders::run(),
                        'digests'   => Digests::run(),
                        'queue'     => QueueRunner::run($remaining, 100),
                    ];
                } finally {
                    DB::tenant()->query('SELECT RELEASE_LOCK(' . DB::tenant()->quote($lock) . ')');
                }
            } catch (\Throwable $e) {
                Logger::error('Cron: falló una empresa', ['tenant' => $tenant['slug'], 'error' => $e->getMessage()]);
                $summary[$tenant['slug']] = 'error: ' . $e->getMessage();
            } finally {
                Tenant::deactivate();
            }
        }
        PlatformSettings::set('cron.last_run', gmdate('Y-m-d H:i:s'));
        PlatformSettings::set('cron.last_summary', json_encode($summary, JSON_UNESCAPED_UNICODE));
        @file_put_contents(Storage::path('cache/cron-last.txt'), (string) time());
        return $summary;
    }

    /**
     * Respaldo cuando no hay cron configurado: si el servidor puede cerrar la respuesta antes de
     * seguir (PHP-FPM), corre un lote corto cada 5 minutos como máximo.
     */
    public static function lazy(): void
    {
        if (!function_exists('fastcgi_finish_request') || PlatformSettings::get('cron.lazy', '1') !== '1') {
            return;
        }
        $file = Storage::path('cache/cron-last.txt');
        if ((int) @file_get_contents($file) > time() - 300) {
            return;
        }
        $fp = @fopen(Storage::path('cache/cron.lock'), 'c');
        if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
            return;
        }
        @file_put_contents($file, (string) time());
        fastcgi_finish_request();
        try {
            self::run(10);
        } catch (\Throwable $e) {
            Logger::error('Lazy cron falló', ['error' => $e->getMessage()]);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
