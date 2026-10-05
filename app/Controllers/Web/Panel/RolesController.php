<?php
declare(strict_types=1);

namespace App\Controllers\Web\Panel;

use App\Controllers\Web\Admin\TenantsController;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Models\Roles;
use App\Models\Users;
use App\Policies\Permissions;
use App\Services\Audit;

/** Roles: los del sistema no se editan; se clonan y se ajusta el clon (matriz módulo × acción). */
final class RolesController
{
    public function index(Request $request): Response
    {
        return Response::html(View::render('panel/roles/index', ['title' => 'Roles', 'roles' => Roles::all()], 'layouts/app'));
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
            'permissions' => Permissions::decode($role['permissions']),
            'usersCount'  => Users::countByRole((int) $role['id']),
        ], 'layouts/app'));
    }

    public function duplicate(Request $request, string $uuid): Response
    {
        $role = Roles::findByUuid($uuid);
        if ($role === null) {
            return Response::redirect('/panel/roles');
        }
        $base = TenantsController::slugify('copia-' . $role['slug']);
        $slug = $base;
        for ($i = 2; Roles::slugExists($slug); $i++) {
            $slug = substr($base, 0, 50) . '-' . $i;
        }
        $newUuid = Roles::create($slug, mb_substr('Copia de ' . $role['name'], 0, 80), $role['description'], Permissions::decode($role['permissions']));
        Audit::tenant('role.create', 'role', $newUuid, null, ['copia_de' => $role['name'], 'slug' => $slug]);
        Flash::add('success', 'Rol copiado. Ajustá el nombre y los permisos.');
        return Response::redirect('/panel/roles/' . $newUuid);
    }

    public function update(Request $request, string $uuid): Response
    {
        $role = Roles::findByUuid($uuid);
        if ($role === null || (int) $role['is_system'] === 1) {
            Flash::add('danger', 'Los roles del sistema no se editan: copialo y ajustá la copia.');
            return Response::redirect('/panel/roles' . ($role ? '/' . $uuid : ''));
        }
        $name = trim((string) $request->input('name', ''));
        if ($name === '' || mb_strlen($name) > 80) {
            Flash::add('danger', 'El nombre es obligatorio (máx. 80 caracteres).');
            return Response::redirect('/panel/roles/' . $uuid);
        }
        $description = trim((string) $request->input('description', '')) ?: null;
        $permissions = Permissions::normalize((array) $request->input('perm', []));
        $before = Permissions::decode($role['permissions']);
        Roles::update((int) $role['id'], $name, $description, $permissions);
        Audit::tenant('role.update', 'role', $uuid,
            ['name' => $role['name'], 'permissions' => $before], ['name' => $name, 'permissions' => $permissions]);
        Flash::add('success', 'Rol actualizado. Los usuarios con este rol ven el cambio en su próximo click.');
        return Response::redirect('/panel/roles/' . $uuid);
    }

    public function delete(Request $request, string $uuid): Response
    {
        $role = Roles::findByUuid($uuid);
        if ($role === null || (int) $role['is_system'] === 1) {
            Flash::add('danger', 'Los roles del sistema no se pueden borrar.');
            return Response::redirect('/panel/roles');
        }
        if (Users::countByRole((int) $role['id']) > 0) {
            Flash::add('danger', 'Hay usuarios con este rol: cambiales el rol antes de borrarlo.');
            return Response::redirect('/panel/roles/' . $uuid);
        }
        Roles::delete((int) $role['id']);
        Audit::tenant('role.delete', 'role', $uuid, ['name' => $role['name'], 'permissions' => Permissions::decode($role['permissions'])]);
        Flash::add('success', 'Rol borrado.');
        return Response::redirect('/panel/roles');
    }
}
