<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\DB;
use App\Core\Session;
use App\Core\Tenant;
use App\Core\Uuid;
use App\Models\Tenants;
use App\Models\Users;

/**
 * Notebook del puesto: la cuenta del puesto (la misma del celular compartido, ver ActingGuard) queda abierta en el navegador de la
 * notebook. Al ingresar con esa cuenta se guarda un token "recordar este equipo" (cookie httponly, 90 días renovables; en la base
 * solo su SHA-256): si la sesión vence o se apaga la notebook, vuelve a entrar sola. Los guardias nunca necesitan la contraseña
 * del puesto. "Salir" en la notebook lo revoca. Solo para cuentas de puesto: los usuarios comunes siguen con la sesión de 12 h.
 */
final class PostTerminal
{
    public const COOKIE = 'secapp_terminal';
    public const DAYS = 90;

    /** Al ingresar con la cuenta del puesto: recordar este equipo. */
    public static function issue(array $tenant, array $user, ?string $label = null): void
    {
        $secret = bin2hex(random_bytes(32));
        DB::tenant()->prepare('INSERT INTO post_terminals (uuid, user_id, token_hash, label, created_at, last_used_at, expires_at)
            VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)')
            ->execute([Uuid::v4(), (int) $user['id'], hash('sha256', $secret), $label ? mb_substr($label, 0, 191) : null, gmdate('Y-m-d H:i:s', time() + self::DAYS * 86400)]);
        self::cookie($tenant['uuid'] . '.' . $secret, time() + self::DAYS * 86400);
        Audit::tenant('post_terminal.remember', 'user', $user['uuid'], null, ['equipo' => $label]);
    }

    /**
     * Sin sesión pero con el token del equipo: vuelve a abrir la sesión de la cuenta del puesto (si sigue siendo de un puesto y
     * está habilitada). Renueva el vencimiento una vez por día. @return bool true si quedó adentro
     */
    public static function restore(): bool
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (!preg_match('/^([0-9a-f-]{36})\.([0-9a-f]{64})$/', $raw, $m)) return false;
        $tenant = Tenants::findByUuid($m[1]);
        if ($tenant === null) return false;
        try {
            Tenant::activate($tenant);
        } catch (\Throwable) {
            return false;
        }
        $q = DB::tenant()->prepare('SELECT * FROM post_terminals WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()');
        $q->execute([hash('sha256', $m[2])]);
        $t = $q->fetch();
        $user = $t ? Users::findById((int) $t['user_id']) : null;
        if ($user === null || !Users::canSignIn($user) || !ActingGuard::isDevice((int) $user['id'])) {
            self::forgetCookie();
            return false;
        }
        Session::regenerate();
        \App\Core\Csrf::rotate();
        Session::put(Impersonation::TENANT_KEY, $tenant['uuid']);
        Session::put(UserAuth::USER_KEY, $user['uuid']);
        if ($t['last_used_at'] === null || strtotime($t['last_used_at'] . ' UTC') < time() - 86400) {
            DB::tenant()->prepare('UPDATE post_terminals SET last_used_at = UTC_TIMESTAMP(), expires_at = ? WHERE id = ?')
                ->execute([gmdate('Y-m-d H:i:s', time() + self::DAYS * 86400), (int) $t['id']]);
            self::cookie($raw, time() + self::DAYS * 86400);
        }
        return true;
    }

    /** "Salir" en la notebook: deja de recordarla. */
    public static function revokeCurrent(): void
    {
        $raw = (string) ($_COOKIE[self::COOKIE] ?? '');
        if (preg_match('/^[0-9a-f-]{36}\.([0-9a-f]{64})$/', $raw, $m) && Tenant::current() !== null) {
            DB::tenant()->prepare('UPDATE post_terminals SET revoked_at = UTC_TIMESTAMP() WHERE token_hash = ? AND revoked_at IS NULL')->execute([hash('sha256', $m[1])]);
        }
        self::forgetCookie();
    }

    /** Equipos recordados de una cuenta (ficha del usuario). */
    public static function forUser(int $userId): array
    {
        $q = DB::tenant()->prepare('SELECT * FROM post_terminals WHERE user_id = ? AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY last_used_at DESC');
        $q->execute([$userId]);
        return $q->fetchAll();
    }

    public static function revoke(string $uuid, int $userId): void
    {
        DB::tenant()->prepare('UPDATE post_terminals SET revoked_at = UTC_TIMESTAMP() WHERE uuid = ? AND user_id = ? AND revoked_at IS NULL')->execute([$uuid, $userId]);
    }

    private static function cookie(string $value, int $expires): void
    {
        $_COOKIE[self::COOKIE] = $value;
        if (headers_sent() || PHP_SAPI === 'cli') return;
        setcookie(self::COOKIE, $value, ['expires' => $expires, 'path' => App::baseUrl() . '/', 'secure' => App::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }

    private static function forgetCookie(): void
    {
        unset($_COOKIE[self::COOKIE]);
        if (headers_sent() || PHP_SAPI === 'cli') return;
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => App::baseUrl() . '/', 'secure' => App::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
    }
}
