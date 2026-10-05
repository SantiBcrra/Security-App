<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Tenant;
use App\Core\Validator;
use App\Core\View;
use App\Models\Roles;
use App\Models\UserDevices;
use App\Models\Users;
use App\Policies\Permissions;
use App\Services\Audit;
use App\Services\UserAuth;
use App\Services\UserInvitation;

/** Usuarios de la empresa: alta por invitación, edición, activar/desactivar, dispositivos. */
final class UsersController
{
    private const LABELS = ['name' => 'Nombre', 'email' => 'Email', 'dni' => 'DNI', 'role' => 'Rol', 'access_expires' => 'Acceso hasta'];

    public function index(Request $request): Response
    {
        return Response::html(View::render('panel/users/index', [
            'title'      => 'Usuarios',
            'users'      => Users::all(),
            'invitation' => UserInvitation::pullFlash(),
        ], 'layouts/app'));
    }

    public function create(Request $request): Response
    {
        [$old, $errors] = Flash::pullInput();
        return $this->form(null, $old + ['name' => '', 'email' => '', 'dni' => '', 'role' => '', 'access_expires' => ''], $errors);
    }

    public function store(Request $request): Response
    {
        $data = $request->post;
        $role = Roles::findByUuid((string) ($data['role'] ?? ''));
        $errors = self::validate($data, $role, null);
        if ($errors) {
            return self::back('/panel/usuarios/nuevo', $data, $errors);
        }
        $fields = self::fields($data, $role);
        $id = Users::create($fields);
        $user = Users::findById($id);
        Audit::tenant('user.create', 'user', $user['uuid'], null, self::auditable($user));
        UserInvitation::flash($user, UserInvitation::issue($user));
        Flash::add('success', "Usuario {$user['name']} creado. Compartile el link para que active su cuenta.");
        return Response::redirect('/panel/usuarios');
    }

    public function edit(Request $request, string $uuid): Response
    {
        $user = Users::findByUuid($uuid);
        if ($user === null) {
            return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
        }
        [$old, $errors] = Flash::pullInput();
        $defaults = [
            'name' => $user['name'], 'email' => $user['email'] ?? '', 'dni' => $user['dni'] ?? '', 'role' => $user['role_uuid'],
            'access_expires' => $user['access_expires_at'] ? fecha($user['access_expires_at'], 'Y-m-d') : '',
        ];
        return $this->form($user, $old + $defaults, $errors);
    }

    public function update(Request $request, string $uuid): Response
    {
        $user = Users::findByUuid($uuid);
        if ($user === null) {
            return Response::redirect('/panel/usuarios');
        }
        $data = $request->post;
        $role = Roles::findByUuid((string) ($data['role'] ?? ''));
        $errors = self::validate($data, $role, $user);
        if (!$errors && $user['role_slug'] === Permissions::ADMIN_ROLE && $role['slug'] !== Permissions::ADMIN_ROLE
            && (int) $user['is_active'] === 1 && Users::countActiveAdmins((int) $user['id']) === 0) {
            $errors['role'] = 'Rol: es el único administrador activo; la empresa no puede quedarse sin administrador.';
        }
        if ($errors) {
            return self::back('/panel/usuarios/' . $uuid, $data, $errors);
        }
        $fields = self::fields($data, $role);
        $before = self::auditable($user);
        Users::update((int) $user['id'], $fields);
        $after = self::auditable(Users::findById((int) $user['id']));
        if ($before !== $after) {
            Audit::tenant('user.update', 'user', $uuid, array_diff_assoc($before, $after), array_diff_assoc($after, $before));
        }
        Flash::add('success', 'Usuario actualizado.');
        return Response::redirect('/panel/usuarios/' . $uuid);
    }

    public function toggle(Request $request, string $uuid): Response
    {
        $user = Users::findByUuid($uuid);
        if ($user === null) {
            return Response::redirect('/panel/usuarios');
        }
        $activate = (int) $user['is_active'] !== 1;
        if (!$activate) {
            if ((int) $user['id'] === (int) UserAuth::user()['id']) {
                Flash::add('danger', 'No podés desactivar tu propio usuario.');
                return Response::redirect('/panel/usuarios/' . $uuid);
            }
            if ($user['role_slug'] === Permissions::ADMIN_ROLE && Users::countActiveAdmins((int) $user['id']) === 0) {
                Flash::add('danger', 'Es el único administrador activo: la empresa no puede quedarse sin administrador.');
                return Response::redirect('/panel/usuarios/' . $uuid);
            }
        }
        Users::update((int) $user['id'], ['is_active' => $activate ? 1 : 0]);
        if (!$activate) {
            UserDevices::revokeAllForUser((int) $user['id'], 'Usuario desactivado');
        }
        Audit::tenant($activate ? 'user.activate_access' : 'user.deactivate', 'user', $uuid,
            ['is_active' => (int) $user['is_active']], ['is_active' => $activate ? 1 : 0]);
        Flash::add('success', $activate ? 'Usuario reactivado.' : 'Usuario desactivado: ya no puede entrar y se cerraron sus sesiones de la app.');
        return Response::redirect('/panel/usuarios/' . $uuid);
    }

    public function invite(Request $request, string $uuid): Response
    {
        $user = Users::findByUuid($uuid);
        if ($user === null || (int) $user['is_active'] !== 1) {
            Flash::add('danger', 'Solo se puede generar el link para usuarios activos.');
            return Response::redirect('/panel/usuarios');
        }
        UserInvitation::flash($user, UserInvitation::issue($user));
        Audit::tenant('user.invite', 'user', $uuid);
        Flash::add('success', 'Link generado. Si la persona ya tenía contraseña, con este link puede elegir una nueva.');
        return Response::redirect('/panel/usuarios');
    }

    public function revokeDevice(Request $request, string $uuid, string $deviceUuid): Response
    {
        $user = Users::findByUuid($uuid);
        $device = UserDevices::findByUuid($deviceUuid);
        if ($user && $device && (int) $device['user_id'] === (int) $user['id']) {
            UserDevices::revoke((int) $device['id'], 'Revocado desde el panel');
            Audit::tenant('device.revoke', 'device', $deviceUuid, null, ['usuario' => $user['name'], 'dispositivo' => $device['device_name']]);
            Flash::add('success', 'Sesión del dispositivo cerrada.');
        }
        return Response::redirect('/panel/usuarios/' . $uuid);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function form(?array $user, array $old, array $errors): Response
    {
        return Response::html(View::render('panel/users/form', [
            'title'   => $user ? $user['name'] : 'Nuevo usuario',
            'user'    => $user,
            'old'     => $old,
            'errors'  => $errors,
            'roles'   => Roles::all(),
            'devices' => $user ? UserDevices::forUser((int) $user['id']) : [],
            'isSelf'  => $user && (int) $user['id'] === (int) (UserAuth::user()['id'] ?? 0),
        ], 'layouts/app'));
    }

    private static function validate(array $data, ?array $role, ?array $user): array
    {
        $errors = Validator::validate($data, ['name' => 'required|max:120', 'email' => 'email|max:191', 'dni' => 'max:12'], self::LABELS);
        $email = trim((string) ($data['email'] ?? ''));
        $dni = Users::normalizeDni((string) ($data['dni'] ?? ''));
        if ($email === '' && $dni === '') {
            $errors['email'] ??= 'Cargá el email o el DNI (con cualquiera de los dos se ingresa).';
        }
        if ($dni !== '' && !preg_match('/^\d{7,8}$/', $dni)) {
            $errors['dni'] = 'DNI: entre 7 y 8 números.';
        }
        $exceptId = $user ? (int) $user['id'] : null;
        if ($email !== '' && !isset($errors['email']) && Users::emailTaken($email, $exceptId)) {
            $errors['email'] = 'Email: ya hay un usuario con ese email en la empresa.';
        }
        if ($dni !== '' && !isset($errors['dni']) && Users::dniTaken($dni, $exceptId)) {
            $errors['dni'] = 'DNI: ya hay un usuario con ese DNI en la empresa.';
        }
        if ($role === null) {
            $errors['role'] = 'Rol: elegí un rol.';
        }
        $expires = trim((string) ($data['access_expires'] ?? ''));
        if ($expires !== '' && !\DateTimeImmutable::createFromFormat('!Y-m-d', $expires)) {
            $errors['access_expires'] = 'Acceso hasta: fecha inválida.';
        } elseif ($expires === '' && $role !== null && $role['slug'] === Permissions::AUDITOR_ROLE) {
            $errors['access_expires'] = 'Acceso hasta: obligatorio para auditores externos.';
        }
        return $errors;
    }

    private static function fields(array $data, array $role): array
    {
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $dni = Users::normalizeDni((string) ($data['dni'] ?? ''));
        $expires = trim((string) ($data['access_expires'] ?? ''));
        $expiresUtc = null;
        if ($expires !== '') {
            // Vence al final de ese día en la zona horaria de la empresa; se guarda en UTC.
            $local = new \DateTimeImmutable($expires . ' 23:59:59', new \DateTimeZone(Tenant::timezone() ?? 'UTC'));
            $expiresUtc = $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        return [
            'name'              => trim((string) $data['name']),
            'email'             => $email !== '' ? $email : null,
            'dni'               => $dni !== '' ? $dni : null,
            'role_id'           => (int) $role['id'],
            'access_expires_at' => $expiresUtc,
        ];
    }

    private static function auditable(array $user): array
    {
        return [
            'name' => $user['name'], 'email' => $user['email'], 'dni' => $user['dni'],
            'rol' => $user['role_name'], 'activo' => (int) $user['is_active'], 'acceso_hasta' => $user['access_expires_at'],
        ];
    }

    private static function back(string $path, array $data, array $errors): Response
    {
        unset($data['csrf_token']);
        Flash::withInput($data, $errors);
        return Response::redirect($path);
    }
}
