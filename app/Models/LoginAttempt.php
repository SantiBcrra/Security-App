<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

/** Intentos de login (base maestra), para bloquear fuerza bruta. */
final class LoginAttempt
{
    public static function record(string $scope, string $email, string $ip, bool $success): void
    {
        DB::master()->prepare('INSERT INTO login_attempts (scope, email, ip, success, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$scope, mb_strtolower(trim($email)), $ip, $success ? 1 : 0]);
    }

    /** Fallos recientes para ese email desde esa IP. */
    public static function recentFailures(string $scope, string $email, string $ip, int $minutes): int
    {
        $stmt = DB::master()->prepare('SELECT COUNT(*) FROM login_attempts
            WHERE scope = ? AND email = ? AND ip = ? AND success = 0
              AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE');
        $stmt->execute([$scope, mb_strtolower(trim($email)), $ip, $minutes]);
        return (int) $stmt->fetchColumn();
    }

    /** Fallos recientes desde una IP (cualquier email): frena a quien prueba muchas cuentas. */
    public static function recentFailuresFromIp(string $ip, int $minutes): int
    {
        $stmt = DB::master()->prepare('SELECT COUNT(*) FROM login_attempts
            WHERE ip = ? AND success = 0 AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE');
        $stmt->execute([$ip, $minutes]);
        return (int) $stmt->fetchColumn();
    }
}
