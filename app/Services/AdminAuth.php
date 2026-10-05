<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Csrf;
use App\Core\Session;
use App\Models\LoginAttempt;
use App\Models\PlatformAdmin;

/** Login del super-admin de la plataforma (separado de los usuarios de empresas, Etapa 3). */
final class AdminAuth
{
    public const MAX_FAILURES = 5;
    public const MAX_FAILURES_IP = 20;
    public const WINDOW_MINUTES = 15;
    private const SESSION_KEY = 'platform_admin_id';
    private const SCOPE = 'platform';

    private static ?array $user = null;

    /** @return 'ok'|'invalid'|'locked' */
    public static function attempt(string $email, string $password, string $ip): string
    {
        if (LoginAttempt::recentFailures(self::SCOPE, $email, $ip, self::WINDOW_MINUTES) >= self::MAX_FAILURES
            || LoginAttempt::recentFailuresFromIp($ip, self::WINDOW_MINUTES) >= self::MAX_FAILURES_IP) {
            return 'locked';
        }

        $admin = PlatformAdmin::findByEmail($email);
        // Si el email no existe igual se verifica contra un hash para no revelar por tiempo de respuesta.
        $hash = $admin['password_hash'] ?? password_hash(random_bytes(16), PASSWORD_DEFAULT);
        $valid = password_verify($password, $hash) && $admin !== null && (int) $admin['is_active'] === 1;

        LoginAttempt::record(self::SCOPE, $email, $ip, $valid);
        if (!$valid) {
            return 'invalid';
        }

        PlatformAdmin::rehashIfNeeded((int) $admin['id'], $admin['password_hash'], $password);
        PlatformAdmin::touchLogin((int) $admin['id']);
        Session::regenerate();
        Csrf::rotate();
        Session::put(self::SESSION_KEY, (int) $admin['id']);
        self::$user = null;
        return 'ok';
    }

    public static function user(): ?array
    {
        if (self::$user === null) {
            $id = Session::get(self::SESSION_KEY);
            self::$user = $id ? PlatformAdmin::findActive((int) $id) : null;
        }
        return self::$user;
    }

    public static function logout(): void
    {
        Session::forget(self::SESSION_KEY);
        Session::regenerate();
        Csrf::rotate();
        self::$user = null;
    }
}
