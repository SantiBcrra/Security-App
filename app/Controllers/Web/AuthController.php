<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Core\App;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Tenant;
use App\Core\TenantSuspended;
use App\Core\View;
use App\Models\Tenants;
use App\Services\UserAuth;
use App\Services\UserInvitation;

/** Login de los usuarios de las empresas: empresa + email/DNI + contraseña (como el ERP). */
final class AuthController
{
    private const COMPANY_COOKIE = 'secapp_empresa';

    public function show(Request $request, ?string $slug = null): Response
    {
        if (UserAuth::user() !== null) {
            return Response::redirect('/panel');
        }
        [$old] = Flash::pullInput();
        $remembered = $_COOKIE[self::COMPANY_COOKIE] ?? '';
        return Response::html(View::render('auth/login', [
            'title'      => 'Ingresar',
            'empresa'    => $slug ?? $old['empresa'] ?? (is_string($remembered) ? $remembered : ''),
            'fixed'      => $slug !== null,
            'usuario'    => $old['usuario'] ?? '',
            'width'      => '420px',
        ], 'layouts/blank'));
    }

    public function login(Request $request): Response
    {
        $empresa = mb_strtolower(trim((string) $request->input('empresa', '')));
        $usuario = trim((string) $request->input('usuario', ''));
        $password = (string) $request->input('password', '');
        $back = $request->input('fixed') ? '/login/' . rawurlencode($empresa) : '/login';

        $status = ($empresa === '' || $usuario === '' || $password === '')
            ? 'invalid'
            : UserAuth::attempt($empresa, $usuario, $password, $request->ip());

        if ($status === 'ok' || $status === 'totp') {
            $this->rememberCompany($empresa);
            return Response::redirect($status === 'ok' ? self::intended() : '/login/codigo');
        }
        Flash::add('danger', $status === 'locked'
            ? 'Demasiados intentos fallidos. Esperá ' . UserAuth::WINDOW_MINUTES . ' minutos y volvé a probar.'
            : 'Empresa, usuario o contraseña incorrectos.');
        Flash::withInput(['empresa' => $empresa, 'usuario' => $usuario]);
        return Response::redirect($back);
    }

    public function showCode(Request $request): Response
    {
        if (!UserAuth::hasPending2fa()) {
            return Response::redirect('/login');
        }
        return Response::html(View::render('auth/code', ['title' => 'Código de verificación', 'width' => '420px'], 'layouts/blank'));
    }

    public function verifyCode(Request $request): Response
    {
        $result = UserAuth::verifyPending2fa((string) $request->input('code', ''), $request->ip());
        if ($result === 'ok') {
            return Response::redirect(self::intended());
        }
        if ($result === 'expired') {
            Flash::add('warning', 'El ingreso venció o tuvo demasiados intentos. Volvé a ingresar.');
            return Response::redirect('/login');
        }
        Flash::add('danger', 'Código incorrecto. Revisá la hora del celular y probá con el código nuevo.');
        return Response::redirect('/login/codigo');
    }

    /** Ruta dentro de /panel: RequireTenant ya cargó empresa y usuario, así la salida queda auditada. */
    public function logout(Request $request): Response
    {
        UserAuth::logout();
        Flash::add('info', 'Sesión cerrada.');
        return Response::redirect('/login');
    }

    // ── Activación de cuenta (link de invitación) ──────────────────────

    public function showActivate(Request $request, string $slug, string $token): Response
    {
        $user = $this->invitedUser($slug, $token);
        return Response::html(View::render('auth/activate', [
            'title'  => 'Activar cuenta',
            'user'   => $user,
            'tenant' => Tenant::current(),
            'action' => url('/activar/' . $slug . '/' . $token),
            'width'  => '460px',
        ], 'layouts/blank'), $user ? 200 : 410);
    }

    public function activate(Request $request, string $slug, string $token): Response
    {
        $user = $this->invitedUser($slug, $token);
        if ($user === null) {
            return Response::redirect('/activar/' . $slug . '/' . $token);
        }
        $password = (string) $request->input('password', '');
        if (mb_strlen($password) < 10) {
            Flash::add('danger', 'La contraseña tiene que tener al menos 10 caracteres.');
            return Response::redirect('/activar/' . $slug . '/' . $token);
        }
        if ($password !== (string) $request->input('password_confirmation', '')) {
            Flash::add('danger', 'Las contraseñas no coinciden.');
            return Response::redirect('/activar/' . $slug . '/' . $token);
        }
        UserInvitation::activate($user, $password);
        $this->rememberCompany($slug);
        Flash::add('success', 'Cuenta activada. Ya podés ingresar con tu ' . ($user['email'] ? 'email' : 'DNI') . ' y tu contraseña.');
        Flash::withInput(['empresa' => $slug, 'usuario' => $user['email'] ?: $user['dni']]);
        return Response::redirect('/login/' . rawurlencode($slug));
    }

    private function invitedUser(string $slug, string $token): ?array
    {
        $tenant = Tenants::findBySlug($slug);
        try {
            Tenant::activate($tenant ?? throw new TenantSuspended(''));
        } catch (TenantSuspended) {
            return null;
        }
        return UserInvitation::findValid($token);
    }

    /** Adónde ir después de ingresar: la página que se pidió antes del login (ej: un QR) o el inicio. */
    private static function intended(): string
    {
        $target = Session::get('intended');
        Session::forget('intended');
        return is_string($target) && str_starts_with($target, '/panel') ? $target : '/panel';
    }

    private function rememberCompany(string $slug): void
    {
        if (!headers_sent()) {
            setcookie(self::COMPANY_COOKIE, $slug, [
                'expires'  => time() + 365 * 86400,
                'path'     => App::baseUrl() . '/',
                'secure'   => App::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}
