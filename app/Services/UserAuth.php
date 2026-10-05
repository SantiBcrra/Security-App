<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Session;
use App\Core\Tenant;
use App\Core\TenantSuspended;
use App\Core\Totp;
use App\Models\LoginAttempt;
use App\Models\Tenants;
use App\Models\Users;
use App\Policies\Permissions;

/**
 * Login web de los usuarios de una empresa. Como en el ERP: se elige la empresa, se conecta a
 * SU base y ahí se buscan usuario y rol. La maestra no conoce a los usuarios.
 */
final class UserAuth
{
    public const MAX_FAILURES = 5;
    public const MAX_FAILURES_IP = 20;
    public const WINDOW_MINUTES = 15;
    public const USER_KEY = 'user_uuid';
    private const PENDING_2FA_KEY = 'pending_2fa';
    private const SCOPE = 'user';

    private static ?array $user = null;
    private static array $permissions = [];

    /**
     * Valida empresa + credenciales. No inicia sesión: eso lo hace complete() (o el paso 2FA).
     * @return array{status:'ok'|'invalid'|'locked'|'totp', tenant?:array, user?:array}
     */
    public static function check(string $tenantSlug, string $identifier, string $password, string $ip): array
    {
        $tenant = Tenants::findBySlug($tenantSlug);
        try {
            if ($tenant === null) {
                throw new TenantSuspended('');
            }
            Tenant::activate($tenant);
        } catch (TenantSuspended) {
            password_verify($password, password_hash('x', PASSWORD_DEFAULT)); // mismo tiempo de respuesta
            return ['status' => 'invalid'];
        }

        $db = DB::tenant();
        if (LoginAttempt::recentFailures($db, self::SCOPE, $identifier, $ip, self::WINDOW_MINUTES) >= self::MAX_FAILURES
            || LoginAttempt::recentFailuresFromIp($db, $ip, self::WINDOW_MINUTES) >= self::MAX_FAILURES_IP) {
            return ['status' => 'locked'];
        }

        $user = Users::findByLogin($identifier);
        $hash = $user['password_hash'] ?? null ?: password_hash(random_bytes(16), PASSWORD_DEFAULT);
        $valid = password_verify($password, $hash) && $user !== null && Users::canSignIn($user);
        LoginAttempt::record($db, self::SCOPE, $identifier, $ip, $valid);
        if (!$valid) {
            return ['status' => 'invalid'];
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            Users::update((int) $user['id'], ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }
        return ['status' => (int) $user['totp_enabled'] === 1 ? 'totp' : 'ok', 'tenant' => $tenant, 'user' => $user];
    }

    /** Login web completo (con paso 2FA si el usuario lo tiene activado). */
    public static function attempt(string $tenantSlug, string $identifier, string $password, string $ip): string
    {
        $result = self::check($tenantSlug, $identifier, $password, $ip);
        if ($result['status'] === 'totp') {
            Session::put(self::PENDING_2FA_KEY, [
                'tenant' => $result['tenant']['uuid'], 'user' => $result['user']['uuid'], 'until' => time() + 300,
            ]);
        } elseif ($result['status'] === 'ok') {
            self::complete($result['tenant'], $result['user']);
        }
        return $result['status'];
    }

    public static function hasPending2fa(): bool
    {
        $pending = Session::get(self::PENDING_2FA_KEY);
        return is_array($pending) && $pending['until'] > time();
    }

    /** @return 'ok'|'invalid'|'expired' */
    public static function verifyPending2fa(string $code, string $ip): string
    {
        $pending = Session::get(self::PENDING_2FA_KEY);
        if (!self::hasPending2fa()) {
            Session::forget(self::PENDING_2FA_KEY);
            return 'expired';
        }
        $tenant = Tenants::findByUuid($pending['tenant']);
        try {
            Tenant::activate($tenant ?? throw new TenantSuspended(''));
        } catch (TenantSuspended) {
            Session::forget(self::PENDING_2FA_KEY);
            return 'expired';
        }
        $user = Users::findByUuid($pending['user']);
        if ($user === null || !Users::canSignIn($user)) {
            Session::forget(self::PENDING_2FA_KEY);
            return 'expired';
        }
        if (LoginAttempt::recentFailures(DB::tenant(), self::SCOPE, $user['uuid'] . ':2fa', $ip, self::WINDOW_MINUTES) >= self::MAX_FAILURES) {
            Session::forget(self::PENDING_2FA_KEY);
            return 'expired';
        }
        $step = self::verifyTotp($user, $code);
        LoginAttempt::record(DB::tenant(), self::SCOPE, $user['uuid'] . ':2fa', $ip, $step !== null);
        if ($step === null) {
            return 'invalid';
        }
        Session::forget(self::PENDING_2FA_KEY);
        self::complete($tenant, $user);
        return 'ok';
    }

    /** Verifica un código TOTP del usuario y lo marca como usado. @return ?int paso usado */
    public static function verifyTotp(array $user, string $code): ?int
    {
        if (empty($user['totp_secret_enc'])) {
            return null;
        }
        $last = $user['totp_last_step'] !== null ? (int) $user['totp_last_step'] : null;
        $step = Totp::verify(Crypto::decrypt($user['totp_secret_enc']), $code, $last);
        if ($step !== null) {
            Users::update((int) $user['id'], ['totp_last_step' => $step]);
        }
        return $step;
    }

    private static function complete(array $tenant, array $user): void
    {
        Session::regenerate();
        Csrf::rotate();
        Impersonation::clear();
        Session::put(Impersonation::TENANT_KEY, $tenant['uuid']);
        Session::put(self::USER_KEY, $user['uuid']);
        Tenant::activate($tenant);
        Users::touchLogin((int) $user['id']);
        self::setCurrent($user);
        Audit::tenant('user.login', 'user', $user['uuid']);
    }

    /** Usuario del request (lo carga RequireTenant en web o RequireApiUser en la API). */
    public static function setCurrent(?array $user): void
    {
        self::$user = $user;
        self::$permissions = $user ? Permissions::decode($user['role_permissions'] ?? null) : [];
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    public static function permissions(): array
    {
        return self::$permissions;
    }

    /** En modo soporte (super-admin dentro de la empresa) se puede ver todo, nada más. */
    public static function can(string $module, string $action): bool
    {
        if (self::$user === null) {
            return Impersonation::active() && $action === 'ver' && isset(Permissions::MODULES[$module]);
        }
        return Permissions::can(self::$permissions, $module, $action);
    }

    public static function scope(string $module): ?string
    {
        if (self::$user === null) {
            return Impersonation::active() ? 'todo' : null;
        }
        return Permissions::scope(self::$permissions, $module);
    }

    public static function isAdmin(): bool
    {
        return (self::$user['role_slug'] ?? null) === Permissions::ADMIN_ROLE;
    }

    public static function logout(): void
    {
        if (self::$user !== null && Tenant::current() !== null) {
            Audit::tenant('user.logout', 'user', self::$user['uuid']);
        }
        Session::forget(self::USER_KEY);
        Session::forget(Impersonation::TENANT_KEY);
        Session::forget(self::PENDING_2FA_KEY);
        Session::regenerate();
        Csrf::rotate();
        self::setCurrent(null);
    }
}
