<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Controllers\Web\Admin\TenantsController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\Roles;
use App\Models\Users;
use App\Policies\Permissions;
use App\Services\Audit;

/**
 * Roles: crear (en blanco o partiendo de los permisos de otro), editar y eliminar. Los roles del sistema no se editan ni se
 * eliminan. Eliminar un rol con usuarios exige elegir a qué rol pasan.
 */
final class RolesController
{
    public function index(Request $request): Response
    {
        return Response::html(View::render('panel/roles/index', ['title' => 'Roles', 'roles' => Roles::all()], 'layouts/app'));
    }

    public function create(Request $request): Response
    {
        $base = $request->input('base') ? Roles::findByUuid((string) $request->input('base')) : null;
        $old = Session::get('role_old');
        Session::forget('role_old');
        return Response::html(View::render('panel/roles/edit', [
            'title' => 'Nuevo rol', 'role' => null, 'base' => $base, 'roles' => Roles::all(), 'old' => $old,
            'permissions' => $old ? Permissions::normalize((array) ($old['perm'] ?? [])) : ($base ? Permissions::decode($base['permissions']) : []),
            'usersCount' => 0,
        ], 'layouts/app'));
    }

    public function store(Request $request): Response
    {
        [$name, $description, $error] = $this->input($request, null);
        if ($error !== null) {
            Flash::add('danger', $error);
            Session::put('role_old', $request->post);
            return Response::redirect('/panel/roles/nuevo');
        }
        $base = TenantsController::slugify($name) ?: 'rol';
        $slug = substr($base, 0, 50);
        for ($i = 2; Roles::slugExists($slug); $i++) $slug = substr($base, 0, 50) . '-' . $i;
        $permissions = Permissions::normalize((array) $request->input('perm', []));
        $uuid = Roles::create($slug, $name, $description, $permissions);
        Audit::tenant('role.create', 'role', $uuid, null, ['name' => $name, 'permissions' => $permissions]);
        Flash::add('success', 'Rol creado. Ya lo podés asignar a los usuarios.');
        return Response::redirect('/panel/roles/' . $uuid);
    }

    public function edit(Request $request, string $uuid): Response
    {
        $role = Roles::findByUuid($uuid);
        if ($role === null) {
            return Response::html(View::render('errors/404', [], 'layouts/app'), 404);
        }
        return Response::html(View::render('panel/roles/edit', [
            'title'       => $role['name'],
            'role'        => $role,
            'roles'       => Roles::all(),
            'permissions' => Permissions::decode($role['permissions']),
            'usersCount'  => Users::countByRole((int) $role['id']),
        ], 'layouts/app'));
    }

    public function update(Request $request, string $uuid): Response
    {
        $role = Roles::findByUuid($uuid);
        if ($role === null || (int) $role['is_system'] === 1) {
            Flash::add('danger', 'Los roles del sistema no se editan: creá un rol nuevo partiendo de este.');
            return Response::redirect('/panel/roles' . ($role ? '/' . $uuid : ''));
        }
        [$name, $description, $error] = $this->input($request, (int) $role['id']);
        if ($error !== null) {
            Flash::add('danger', $error);
            return Response::redirect('/panel/roles/' . $uuid);
        }
        $permissions = Permissions::normalize((array) $request->input('perm', []));
        $before = Permissions::decode($role['permissions']);
        Roles::update((int) $role['id'], $name, $description, $permissions);
        Audit::tenant('role.update', 'role', $uuid,
            ['name' => $role['name'], 'permissions' => $before], ['name' => $name, 'permissions' => $permissions]);
        Flash::add('success', 'Rol actualizado. Los usuarios con este rol ven el cambio en su próximo click.');
        return Response::redirect('/panel/roles/' . $uuid);
    }

    /** Eliminar: si tiene usuarios, pasan al rol elegido (`move_to`). */
    public function delete(Request $request, string $uuid): Response
    {
        $role = Roles::findByUuid($uuid);
        if ($role === null || (int) $role['is_system'] === 1) {
            Flash::add('danger', 'Los roles del sistema no se pueden eliminar.');
            return Response::redirect('/panel/roles');
        }
        $count = Users::countByRole((int) $role['id']);
        $target = $request->input('move_to') ? Roles::findByUuid((string) $request->input('move_to')) : null;
        if ($target !== null && (int) $target['id'] === (int) $role['id']) $target = null;
        if ($count > 0 && $target === null) {
            Flash::add('danger', "Este rol tiene {$count} usuario(s): elegí a qué rol pasan antes de eliminarlo.");
            return Response::redirect('/panel/roles/' . $uuid . '#eliminar');
        }
        // También los usuarios eliminados y los perfiles internos (no se ven, pero apuntan al rol).
        $moved = Roles::moveUsers((int) $role['id'], (int) ($target['id'] ?? Roles::findBySlug('reportante')['id']));
        Roles::delete((int) $role['id']);
        Audit::tenant('role.delete', 'role', $uuid, ['name' => $role['name'], 'permissions' => Permissions::decode($role['permissions'])],
            $moved ? ['usuarios_pasados_a' => $target['name'] ?? 'Reportante', 'cantidad' => $moved] : null);
        Flash::add('success', 'Rol eliminado.' . ($count > 0 ? " Sus {$count} usuario(s) pasaron a «{$target['name']}»." : ''));
        return Response::redirect('/panel/roles');
    }

    /** @return array{0: string, 1: ?string, 2: ?string} nombre, descripción, error */
    private function input(Request $request, ?int $exceptId): array
    {
        $name = trim((string) $request->input('name', ''));
        $description = trim((string) $request->input('description', '')) ?: null;
        if ($name === '' || mb_strlen($name) > 80) return [$name, $description, 'El nombre es obligatorio (máx. 80 caracteres).'];
        if ($description !== null && mb_strlen($description) > 255) return [$name, $description, 'La descripción admite hasta 255 caracteres.'];
        if (Roles::nameTaken($name, $exceptId)) return [$name, $description, "Ya existe un rol llamado «{$name}»."];
        return [$name, $description, null];
    }
}
