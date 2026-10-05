<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Crypto;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Core\Totp;
use App\Core\View;
use App\Models\UserDevices;
use App\Models\Users;
use App\Services\Audit;
use App\Services\UserAuth;

/** "Mi perfil": contraseña, verificación en dos pasos (TOTP) y mis dispositivos. */
final class ProfileController
{
    private const TOTP_SETUP_KEY = 'totp_setup_secret';

    public function show(Request $request): Response
    {
        $user = UserAuth::user();
        if ($user === null) {
            Flash::add('info', 'En modo soporte no hay perfil de usuario.');
            return Response::redirect('/panel');
        }
        $setupSecret = Session::get(self::TOTP_SETUP_KEY);
        $account = $user['email'] ?: $user['dni'];
        return Response::html(View::render('panel/profile', [
            'title'       => 'Mi perfil',
            'user'        => $user,
            'devices'     => UserDevices::forUser((int) $user['id']),
            'setupSecret' => is_string($setupSecret) ? $setupSecret : null,
            'setupUri'    => is_string($setupSecret) ? Totp::uri($setupSecret, $account, config('app.name') . ' · ' . Tenant::current()['name']) : null,
        ], 'layouts/app'));
    }

    public function changePassword(Request $request): Response
    {
        $user = UserAuth::user();
        $current = (string) $request->input('current_password', '');
        $new = (string) $request->input('password', '');
        if (!password_verify($current, (string) $user['password_hash'])) {
            Flash::add('danger', 'La contraseña actual no es correcta.');
        } elseif (mb_strlen($new) < 10) {
            Flash::add('danger', 'La nueva contraseña tiene que tener al menos 10 caracteres.');
        } elseif ($new !== (string) $request->input('password_confirmation', '')) {
            Flash::add('danger', 'Las contraseñas nuevas no coinciden.');
        } else {
            Users::update((int) $user['id'], ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'password_changed_at' => gmdate('Y-m-d H:i:s')]);
            Session::regenerate();
            Audit::tenant('user.password_change', 'user', $user['uuid']);
            Flash::add('success', 'Contraseña actualizada.');
        }
        return Response::redirect('/panel/perfil');
    }

    public function totpStart(Request $request): Response
    {
        Session::put(self::TOTP_SETUP_KEY, Totp::generateSecret());
        return Response::redirect('/panel/perfil#dos-pasos');
    }

    public function totpConfirm(Request $request): Response
    {
        $user = UserAuth::user();
        $secret = Session::get(self::TOTP_SETUP_KEY);
        if (!is_string($secret)) {
            return Response::redirect('/panel/perfil');
        }
        $step = Totp::verify($secret, (string) $request->input('code', ''));
        if ($step === null) {
            Flash::add('danger', 'El código no coincide. Revisá que la hora del celular sea automática y probá con el código nuevo.');
            return Response::redirect('/panel/perfil#dos-pasos');
        }
        Users::update((int) $user['id'], ['totp_secret_enc' => Crypto::encrypt($secret), 'totp_enabled' => 1, 'totp_last_step' => $step]);
        Session::forget(self::TOTP_SETUP_KEY);
        Audit::tenant('user.2fa.enable', 'user', $user['uuid']);
        Flash::add('success', 'Verificación en dos pasos activada. Desde ahora se te va a pedir el código al ingresar.');
        return Response::redirect('/panel/perfil');
    }

    public function totpDisable(Request $request): Response
    {
        $user = UserAuth::user();
        if (!password_verify((string) $request->input('current_password', ''), (string) $user['password_hash'])) {
            Flash::add('danger', 'Para desactivarla confirmá tu contraseña.');
            return Response::redirect('/panel/perfil#dos-pasos');
        }
        Users::update((int) $user['id'], ['totp_secret_enc' => null, 'totp_enabled' => 0, 'totp_last_step' => null]);
        Audit::tenant('user.2fa.disable', 'user', $user['uuid']);
        Flash::add('success', 'Verificación en dos pasos desactivada.');
        return Response::redirect('/panel/perfil');
    }

    public function revokeDevice(Request $request, string $deviceUuid): Response
    {
        $user = UserAuth::user();
        $device = UserDevices::findByUuid($deviceUuid);
        if ($device && (int) $device['user_id'] === (int) $user['id']) {
            UserDevices::revoke((int) $device['id'], 'Revocado por el usuario');
            Audit::tenant('device.revoke', 'device', $deviceUuid, null, ['dispositivo' => $device['device_name']]);
            Flash::add('success', 'Se cerró la sesión de ese dispositivo.');
        }
        return Response::redirect('/panel/perfil');
    }
}
