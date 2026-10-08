<?php
use App\Policies\Permissions;
use App\Services\UserAuth;
$editable = (int) $role['is_system'] !== 1 && UserAuth::can('roles', 'editar');
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/roles')) ?>">←</a>
    <h1 class="h4 m-0"><?= e($role['name']) ?></h1>
    <?php if ((int) $role['is_system'] === 1): ?><span class="badge text-bg-light">sistema</span><?php endif; ?>
</div>
<?php if ((int) $role['is_system'] === 1): ?>
    <div class="alert alert-secondary small">Rol del sistema: no se modifica. Si necesitás otros permisos, copialo y ajustá la copia.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/panel/roles/' . $role['uuid'])) ?>">
    <?= csrf_field() ?>
    <fieldset <?= $editable ? '' : 'disabled' ?>>
        <div class="card shadow-sm mb-3">
            <div class="card-body row g-3">
                <div class="col-md-5">
                    <label class="form-label" for="f_name">Nombre</label>
                    <input class="form-control" id="f_name" name="name" value="<?= e($role['name']) ?>" maxlength="80" required>
                </div>
                <div class="col-md-7">
                    <label class="form-label" for="f_desc">Descripción</label>
                    <input class="form-control" id="f_desc" name="description" value="<?= e($role['description']) ?>" maxlength="255">
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle text-center">
                    <thead>
                        <tr>
                            <th class="text-start">Módulo</th>
                            <?php foreach (Permissions::ACTIONS as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
                            <th>Alcance</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (Permissions::MODULES as $module => $label):
                        $granted = $permissions[$module]['acciones'] ?? [];
                        $scope = $permissions[$module]['alcance'] ?? 'todo'; ?>
                        <tr>
                            <td class="text-start"><?= e($label) ?></td>
                            <?php foreach (Permissions::ACTIONS as $action => $_): ?>
                                <td><?php if (in_array($action, Permissions::actionsFor($module), true)): ?><input class="form-check-input" type="checkbox" name="perm[<?= e($module) ?>][acciones][]"
                                           value="<?= e($action) ?>" <?= in_array($action, $granted, true) ? 'checked' : '' ?>
                                           aria-label="<?= e($label . ': ' . $action) ?>"><?php endif; ?></td>
                            <?php endforeach; ?>
                            <td>
                                <select class="form-select form-select-sm" name="perm[<?= e($module) ?>][alcance]">
                                    <?php foreach (Permissions::SCOPES as $key => $scopeLabel): ?>
                                        <option value="<?= e($key) ?>" <?= $scope === $key ? 'selected' : '' ?>><?= e($scopeLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer small text-body-secondary">
                Marcar cualquier acción incluye "Ver". "Sus sectores" se aplica cuando se asignan sectores a los usuarios.
            </div>
        </div>
        <?php if ($editable): ?><button class="btn btn-primary">Guardar rol</button><?php endif; ?>
    </fieldset>
</form>

<?php if ($editable): ?>
    <form class="mt-3" method="post" action="<?= e(url('/panel/roles/' . $role['uuid'] . '/borrar')) ?>"
          onsubmit="return confirm('¿Borrar este rol?')">
        <?= csrf_field() ?>
        <button class="btn btn-outline-danger btn-sm" <?= $usersCount > 0 ? 'disabled title="Tiene usuarios asignados"' : '' ?>>Borrar rol</button>
        <?php if ($usersCount > 0): ?><span class="small text-body-secondary ms-2"><?= e($usersCount) ?> usuario(s) con este rol</span><?php endif; ?>
    </form>
<?php endif; ?>
