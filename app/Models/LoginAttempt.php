<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Intentos de login para bloquear fuerza bruta. Misma tabla en la base maestra (super-admins)
 * y en la base de cada empresa (sus usuarios): se pasa la conexión que corresponde.
 */
final class LoginAttempt
{
    public static function record(PDO $db, string $scope, string $identifier, string $ip, bool $success): void
    {
        $db->prepare('INSERT INTO login_attempts (scope, email, ip, success, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$scope, mb_strtolower(trim($identifier)), $ip, $success ? 1 : 0]);
    }

    /** Fallos recientes para ese identificador desde esa IP. */
    public static function recentFailures(PDO $db, string $scope, string $identifier, string $ip, int $minutes): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts
            WHERE scope = ? AND email = ? AND ip = ? AND success = 0
              AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE');
        $stmt->execute([$scope, mb_strtolower(trim($identifier)), $ip, $minutes]);
        return (int) $stmt->fetchColumn();
    }

    /** Fallos recientes desde una IP (cualquier identificador): frena a quien prueba muchas cuentas. */
    public static function recentFailuresFromIp(PDO $db, string $ip, int $minutes): int
    {
        $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts
            WHERE ip = ? AND success = 0 AND created_at > UTC_TIMESTAMP() - INTERVAL ? MINUTE');
        $stmt->execute([$ip, $minutes]);
        return (int) $stmt->fetchColumn();
    }
}
