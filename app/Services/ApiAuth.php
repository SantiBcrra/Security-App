<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Jwt;
use App\Core\Tenant;
use App\Core\TenantSuspended;
use App\Core\Uuid;
use App\Models\Tenants;
use App\Models\UserDevices;
use App\Models\Users;

/**
 * Tokens de la app móvil.
 * - Access token: JWT de 15 min con usuario (sub), empresa (tid) y dispositivo (did).
 * - Refresh token: "{uuid empresa}.{aleatorio}" de 60 días; en la base se guarda solo su SHA-256.
 *   Lleva la empresa adelante porque el dispositivo vive en la base de ESA empresa.
 *   Se rota en cada uso; si llega uno ya rotado (reutilización = posible robo) se revoca el dispositivo.
 */
final class ApiAuth
{
    public const ACCESS_TTL = 900;
    public const REFRESH_TTL = 60 * 86400;

    private static ?array $device = null;

    /** @return array tokens, o ['error' => código] */
    public static function login(string $tenantSlug, string $identifier, string $password, string $deviceUuid, ?string $deviceName, ?string $totp, string $ip): array
    {
        if (!Uuid::isValid($deviceUuid)) {
            return ['error' => 'device_invalid'];
        }
        $result = UserAuth::check($tenantSlug, $identifier, $password, $ip);
        if ($result['status'] === 'locked') {
            return ['error' => 'locked'];
        }
        if ($result['status'] === 'invalid') {
            return ['error' => 'invalid_credentials'];
        }
        $user = $result['user'];
        if ($result['status'] === 'totp') {
            if ($totp === null || $totp === '') {
                return ['error' => 'totp_required'];
            }
            if (UserAuth::verifyTotp($user, $totp) === null) {
                return ['error' => 'totp_invalid'];
            }
        }

        [$refresh, $hash, $expires] = self::newRefresh($result['tenant']['uuid']);
        $deviceRowUuid = UserDevices::upsert((int) $user['id'], $deviceUuid, $deviceName ? mb_substr($deviceName, 0, 120) : null, $hash, $expires, $ip);
        Users::touchLogin((int) $user['id']);
        UserAuth::setCurrent($user);
        Audit::tenant('user.login.app', 'user', $user['uuid'], null, ['dispositivo' => $deviceName]);
        return self::tokens($result['tenant'], $user, $deviceRowUuid, $refresh);
    }

    /** @return array tokens, o ['error' => código] */
    public static function refresh(string $refreshToken, string $ip): array
    {
        [$tenantUuid] = explode('.', $refreshToken, 2) + [''];
        $tenant = Uuid::isValid($tenantUuid) ? Tenants::findByUuid($tenantUuid) : null;
        try {
            Tenant::activate($tenant ?? throw new TenantSuspended(''));
        } catch (TenantSuspended) {
            return ['error' => 'invalid_refresh'];
        }

        $hash = hash('sha256', $refreshToken);
        $device = UserDevices::findByRefreshHash($hash);
        if ($device === null) {
            $reused = UserDevices::findByPreviousHash($hash);
            if ($reused !== null) {
                UserDevices::revoke((int) $reused['id'], 'Reutilización de refresh token');
                Audit::tenant('device.revoke.reuse', 'device', $reused['uuid']);
            }
            return ['error' => 'invalid_refresh'];
        }
        $user = Users::findById((int) $device['user_id']);
        if (!UserDevices::isUsable($device) || $user === null || !Users::canSignIn($user)) {
            return ['error' => 'invalid_refresh'];
        }

        [$refresh, $newHash, $expires] = self::newRefresh($tenant['uuid']);
        UserDevices::rotate((int) $device['id'], $newHash, $hash, $expires, $ip);
        return self::tokens($tenant, $user, $device['uuid'], $refresh);
    }

    /**
     * Valida un access token y deja activos empresa, usuario y dispositivo.
     * @return ?string código de error o null si está todo bien
     */
    public static function authenticate(?string $bearer): ?string
    {
        if ($bearer === null) {
            return 'missing_token';
        }
        try {
            $claims = Jwt::decode($bearer);
        } catch (\App\Core\JwtException $e) {
            return $e->reason === 'expired' ? 'token_expired' : 'invalid_token';
        }
        if (($claims['typ'] ?? null) !== 'access') {
            return 'invalid_token';
        }
        $tenant = Tenants::findByUuid((string) ($claims['tid'] ?? ''));
        try {
            Tenant::activate($tenant ?? throw new TenantSuspended(''));
        } catch (TenantSuspended) {
            return 'tenant_unavailable';
        }
        $device = UserDevices::findByUuid((string) ($claims['did'] ?? ''));
        $user = Users::findByUuid((string) ($claims['sub'] ?? ''));
        if ($device === null || !UserDevices::isUsable($device) || $user === null
            || (int) $device['user_id'] !== (int) $user['id'] || !Users::canSignIn($user)) {
            return 'session_revoked';
        }
        UserAuth::setCurrent($user);
        self::$device = $device;
        return null;
    }

    public static function device(): ?array
    {
        return self::$device;
    }

    public static function logout(): void
    {
        if (self::$device !== null) {
            UserDevices::revoke((int) self::$device['id'], 'Cierre de sesión en la app');
            Audit::tenant('user.logout.app', 'device', self::$device['uuid']);
        }
    }

    private static function newRefresh(string $tenantUuid): array
    {
        $token = $tenantUuid . '.' . Jwt::b64(random_bytes(32));
        return [$token, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + self::REFRESH_TTL)];
    }

    private static function tokens(array $tenant, array $user, string $deviceUuid, string $refresh): array
    {
        return [
            'access_token'  => Jwt::encode(['typ' => 'access', 'sub' => $user['uuid'], 'tid' => $tenant['uuid'], 'did' => $deviceUuid], self::ACCESS_TTL),
            'token_type'    => 'Bearer',
            'expires_in'    => self::ACCESS_TTL,
            'refresh_token' => $refresh,
        ];
    }
}
