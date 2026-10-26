<?php
declare(strict_types=1);

namespace App\Controllers\Web\Admin;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\PlatformAdmin;
use App\Services\AdminAuth;
use App\Services\Audit;

/**
 * Super-admins de la plataforma: alta, edición (nombre, email, contraseña), activar/desactivar y eliminar. Nadie se desactiva
 * ni se elimina a sí mismo y siempre queda al menos un super-admin activo. Cambiar la propia contraseña pide la actual.
 */
final class PlatformAdminsController
{
    public const MIN_PASSWORD = 10; // igual que el instalador

    public function index(Request $request): Response
    {
        return Response::html(View::render('admin/admins/index', [
            'title' => 'Super-admins', 'admins' => PlatformAdmin::all(), 'me' => AdminAuth::user(),
        ], 'layouts/admin'));
    }

    public function form(Request $request, ?string $uuid = null): Response
    {
        $admin = $uuid ? PlatformAdmin::findByUuid($uuid) : null;
        if ($uuid && !$admin) return Response::redirect('/admin/administradores');
        [$old, $errors] = Flash::pullInput();
        return Response::html(View::render('admin/admins/form', [
            'title' => $admin ? $admin['name'] : 'Nuevo super-admin', 'admin' => $admin, 'old' => $old, 'errors' => $errors,
            'isMe' => $admin !== null && (int) $admin['id'] === (int) AdminAuth::user()['id'],
        ], 'layouts/admin'));
    }

    public function store(Request $request, ?string $uuid = null): Response
    {
        $admin = $uuid ? PlatformAdmin::findByUuid($uuid) : null;
        if ($uuid && !$admin) return Response::redirect('/admin/administradores');
        $isMe = $admin !== null && (int) $admin['id'] === (int) AdminAuth::user()['id'];
        $name = trim((string) $request->input('name', ''));
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $password = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirmation', '');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) $errors['name'] = 'El nombre es obligatorio (máx. 120 caracteres).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) $errors['email'] = 'Email inválido.';
        elseif (PlatformAdmin::emailTaken($email, $admin ? (int) $admin['id'] : null)) $errors['email'] = 'Ya hay otro super-admin con ese email.';
        $changePassword = $admin === null || $password !== '';
        if ($changePassword) {
            if (mb_strlen($password) < self::MIN_PASSWORD) $errors['password'] = 'La contraseña tiene que tener al menos ' . self::MIN_PASSWORD . ' caracteres.';
            elseif ($password !== $confirm) $errors['password_confirmation'] = 'Las contraseñas no coinciden.';
            if ($isMe && !password_verify((string) $request->input('current_password', ''), (string) $admin['password_hash'])) {
                $errors['current_password'] = 'Tu contraseña actual no es correcta.';
            }
        }
        if ($errors) {
            Flash::withInput(['name' => $name, 'email' => $email], $errors);
            return Response::redirect('/admin/administradores/' . ($uuid ?? 'nuevo'));
        }
        if ($admin === null) {
            $newUuid = PlatformAdmin::create($name, $email, $password);
            Audit::platform('platform_admin.create', 'platform_admin', $newUuid, null, ['name' => $name, 'email' => $email]); // nunca la contraseña
            Flash::add('success', "Super-admin {$name} creado. Ya puede entrar en /admin/login con su email y la contraseña que cargaste.");
            return Response::redirect('/admin/administradores');
        }
        PlatformAdmin::update((int) $admin['id'], $name, $email, $changePassword ? $password : null);
        Audit::platform('platform_admin.update', 'platform_admin', $uuid, ['name' => $admin['name'], 'email' => $admin['email']],
            ['name' => $name, 'email' => $email] + ($changePassword ? ['contrasena' => 'cambiada'] : []));
        Flash::add('success', 'Cambios guardados.' . ($changePassword ? ' La contraseña se cambió.' : ''));
        return Response::redirect('/admin/administradores/' . $uuid);
    }

    public function toggle(Request $request, string $uuid): Response
    {
        $admin = PlatformAdmin::findByUuid($uuid);
        if ($admin === null) return Response::redirect('/admin/administradores');
        $activate = (int) $admin['is_active'] !== 1;
        if (!$activate && ($error = $this->guard($admin, 'desactivar')) !== null) {
            Flash::add('danger', $error);
            return Response::redirect('/admin/administradores');
        }
        PlatformAdmin::setActive((int) $admin['id'], $activate);
        Audit::platform($activate ? 'platform_admin.activate' : 'platform_admin.deactivate', 'platform_admin', $uuid,
            ['activo' => (int) $admin['is_active']], ['activo' => $activate ? 1 : 0]);
        Flash::add('success', $activate ? "{$admin['name']} puede volver a entrar." : "{$admin['name']} ya no puede entrar (su sesión se corta en el próximo click).");
        return Response::redirect('/admin/administradores');
    }

    public function delete(Request $request, string $uuid): Response
    {
        $admin = PlatformAdmin::findByUuid($uuid);
        if ($admin === null) return Response::redirect('/admin/administradores');
        if (($error = $this->guard($admin, 'eliminar')) !== null) {
            Flash::add('danger', $error);
            return Response::redirect('/admin/administradores');
        }
        PlatformAdmin::delete((int) $admin['id']);
        Audit::platform('platform_admin.delete', 'platform_admin', $uuid, ['name' => $admin['name'], 'email' => $admin['email']], null);
        Flash::add('success', "Super-admin {$admin['name']} eliminado. Lo que hizo sigue en la auditoría con su nombre.");
        return Response::redirect('/admin/administradores');
    }

    /** Nunca a uno mismo ni al último activo. */
    private function guard(array $admin, string $verb): ?string
    {
        if ((int) $admin['id'] === (int) AdminAuth::user()['id']) return "No podés {$verb}te a vos mismo.";
        if ((int) $admin['is_active'] === 1 && PlatformAdmin::countActive((int) $admin['id']) === 0) {
            return "No se puede {$verb} al último super-admin activo.";
        }
        return null;
    }
}
