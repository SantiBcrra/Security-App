<?php
declare(strict_types=1);

namespace App\Services\Notify;

use App\Models\NotificationQueue;

/** Procesa la cola de envíos con reintento exponencial y tope de tiempo (para no pasar el max_execution_time). */
final class QueueRunner
{
    /** Espera antes de cada reintento: 1, 5, 15 y 60 minutos. Al 5° fallo queda "failed". */
    public const BACKOFF = [60, 300, 900, 3600];
    public const MAX_ATTEMPTS = 5;

    /** @return array{sent:int, failed:int, retry:int} */
    public static function run(int $timeBudgetSeconds = 20, int $limit = 50, array $onlyIds = []): array
    {
        $deadline = microtime(true) + $timeBudgetSeconds;
        $stats = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        foreach (NotificationQueue::due($limit, $onlyIds) as $row) {
            if (microtime(true) > $deadline) {
                break;
            }
            try {
                Channels::send($row);
                NotificationQueue::markSent((int) $row['id']);
                $stats['sent']++;
            } catch (\Throwable $e) {
                $attempts = (int) $row['attempts'] + 1;
                $retry = $attempts >= self::MAX_ATTEMPTS ? null : self::BACKOFF[min($attempts - 1, count(self::BACKOFF) - 1)];
                NotificationQueue::markFailed((int) $row['id'], $attempts, $e->getMessage(), $retry);
                $stats[$retry === null ? 'failed' : 'retry']++;
            }
        }
        return $stats;
    }
}
