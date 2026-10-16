<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Jwt;
use App\Core\Tenant;
use App\Core\UserError;
use App\Models\Users;

/**
 * "Ver como" en la app Android: un administrador ve la app exactamente como la ve otro usuario (guardia, supervisor…),
 * con los datos que a ESE usuario le baja la sincronización. Es de SOLO LECTURA: el token de vista previa
 * (`typ = preview`, `pv` = quien mira) solo sirve para GET (RequireApiUser rechaza el resto) y dura PREVIEW_TTL.
 * Entrar y salir queda auditado.
 */
final class AppPreview
{
    public const PREVIEW_TTL = 1800;

    /** Quién está mirando (null si no es una vista previa). */
    private static ?array $viewer = null;

    public static function canUse(): bool
    {
        return UserAuth::can('usuarios', 'editar');
    }

    /** Usuarios que se pueden ver (activos, distintos de uno mismo), ordenados por rol y nombre. */
    public static function users(): array
    {
        $me = (int) (UserAuth::user()['id'] ?? 0);
        $rows = array_filter(Users::all(), fn ($u) => (int) $u['is_active'] === 1 && (int) $u['id'] !== $me);
        usort($rows, fn ($a, $b) => [$a['role_name'], $a['name']] <=> [$b['role_name'], $b['name']]);
        return array_values(array_map(fn ($u) => ['uuid' => $u['uuid'], 'nombre' => $u['name'],
            'rol' => ['slug' => $u['role_slug'], 'nombre' => $u['role_name']]], $rows));
    }

    /** @return array{access_token: string, expires_in: int, usuario: array} */
    public static function start(string $userUuid): array
    {
        if (!self::canUse()) {
            throw new UserError('No tenés permiso para ver la app como otro usuario.');
        }
        $target = Users::findByUuid($userUuid);
        $viewer = UserAuth::user();
        if ($target === null || (int) $target['is_active'] !== 1) {
            throw new UserError('El usuario no existe o está desactivado.');
        }
        if ((int) $target['id'] === (int) $viewer['id']) {
            throw new UserError('Elegí otro usuario.');
        }
        $device = ApiAuth::device();
        Audit::tenant('app.preview_start', 'user', $target['uuid'], null, ['dispositivo' => $device['uuid'] ?? null]);
        return [
            'access_token' => Jwt::encode(['typ' => 'preview', 'sub' => $target['uuid'], 'pv' => $viewer['uuid'],
                'tid' => Tenant::current()['uuid'], 'did' => $device['uuid'] ?? ''], self::PREVIEW_TTL),
            'expires_in' => self::PREVIEW_TTL,
            'usuario' => ['uuid' => $target['uuid'], 'nombre' => $target['name'], 'rol' => ['slug' => $target['role_slug'], 'nombre' => $target['role_name']]],
        ];
    }

    public static function stop(?string $userUuid): void
    {
        Audit::tenant('app.preview_stop', 'user', $userUuid);
    }

    /**
     * Valida un token de vista previa (ya decodificado): quien mira tiene que seguir pudiendo entrar, ser dueño del
     * dispositivo y conservar el permiso. Deja como usuario actual al que se está viendo.
     * @return ?string código de error o null
     */
    public static function authenticate(array $claims, ?array $device): ?string
    {
        $viewer = Users::findByUuid((string) ($claims['pv'] ?? ''));
        $target = Users::findByUuid((string) ($claims['sub'] ?? ''));
        if ($viewer === null || $target === null || $device === null || !\App\Models\UserDevices::isUsable($device)
            || (int) $device['user_id'] !== (int) $viewer['id'] || !Users::canSignIn($viewer) || (int) $target['is_active'] !== 1) {
            return 'session_revoked';
        }
        UserAuth::setCurrent($viewer);
        if (!self::canUse()) {
            UserAuth::setCurrent(null);
            return 'session_revoked';
        }
        UserAuth::setCurrent($target);
        self::$viewer = $viewer;
        return null;
    }

    public static function active(): bool
    {
        return self::$viewer !== null;
    }

    public static function viewer(): ?array
    {
        return self::$viewer;
    }

    /** Solo para tests. */
    public static function reset(): void
    {
        self::$viewer = null;
    }
}
