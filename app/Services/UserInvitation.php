<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use App\Core\Tenant;
use App\Models\Users;

/**
 * Invitación / activación de cuentas: link con token aleatorio (en la base solo su SHA-256),
 * válido 72 h. La persona elige su contraseña: nadie más la conoce.
 * El link lleva la empresa (/activar/{empresa}/{token}) porque el usuario vive en SU base.
 */
final class UserInvitation
{
    public const TTL_HOURS = 72;
    private const FLASH_KEY = '_invitation';

    /** Genera (o regenera) el link de activación de un usuario de la empresa activa. */
    public static function issue(array $user): string
    {
        $token = bin2hex(random_bytes(32));
        Users::update((int) $user['id'], [
            'activation_token_hash' => hash('sha256', $token),
            'activation_expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL_HOURS * 3600),
        ]);
        return absolute_url('/activar/' . Tenant::current()['slug'] . '/' . $token);
    }

    /** Usuario al que corresponde un token vigente (en la empresa activa). */
    public static function findValid(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $user = Users::findByActivationHash(hash('sha256', $token));
        if ($user === null || (int) $user['is_active'] !== 1
            || strtotime($user['activation_expires_at'] . ' UTC') < time()) {
            return null;
        }
        return $user;
    }

    public static function activate(array $user, string $password): void
    {
        Users::update((int) $user['id'], [
            'password_hash'         => password_hash($password, PASSWORD_DEFAULT),
            'password_changed_at'   => gmdate('Y-m-d H:i:s'),
            'activation_token_hash' => null,
            'activation_expires_at' => null,
        ]);
        // Quien activa es la propia persona (todavía sin sesión): queda a su nombre en la auditoría.
        UserAuth::setCurrent($user);
        Audit::tenant('user.activate', 'user', $user['uuid']);
        UserAuth::setCurrent(null);
    }

    /** Texto listo para compartir por WhatsApp (wa.me, sin API). */
    public static function whatsappUrl(array $user, string $link): string
    {
        $text = "Hola {$user['name']}, te damos acceso a " . (Tenant::current()['name'] ?? '') . ' en '
            . config('app.name') . ". Activá tu cuenta y elegí tu contraseña acá (vence en "
            . self::TTL_HOURS . " h): {$link}";
        return 'https://wa.me/?text=' . rawurlencode($text);
    }

    /** Para mostrar el link una sola vez después de un redirect. */
    public static function flash(array $user, string $link): void
    {
        Session::put(self::FLASH_KEY, ['name' => $user['name'], 'link' => $link, 'whatsapp' => self::whatsappUrl($user, $link)]);
    }

    public static function pullFlash(): ?array
    {
        $data = Session::get(self::FLASH_KEY);
        Session::forget(self::FLASH_KEY);
        return is_array($data) ? $data : null;
    }
}
