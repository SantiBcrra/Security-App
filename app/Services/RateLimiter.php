<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Límite de pedidos por minuto en MySQL (sin Redis). Ventanas fijas de 1 minuto. */
final class RateLimiter
{
    /** @return bool true si todavía está dentro del límite */
    public static function hit(string $bucket, int $perMinute): bool
    {
        $db = DB::tenant();
        $window = gmdate('Y-m-d H:i:00');
        $db->prepare('INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1')
            ->execute([mb_substr($bucket, 0, 80), $window]);
        $stmt = $db->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $stmt->execute([mb_substr($bucket, 0, 80), $window]);
        if (random_int(1, 100) === 1) {
            $db->exec('DELETE FROM rate_limits WHERE window_start < UTC_TIMESTAMP() - INTERVAL 10 MINUTE');
        }
        return (int) $stmt->fetchColumn() <= $perMinute;
    }
}
