<?php
/** Rol: alta ($role null) o edición. @var ?array $role @var array $permissions @var array $roles @var int $usersCount @var ?array $base @var ?array $old */
use App\Policies\Permissions;
use App\Services\UserAuth;
$isNew = $role === null;
$system = !$isNew && (int) $role['is_system'] === 1;
$editable = $isNew ? UserAuth::can('roles', 'crear') : (!$system && UserAuth::can('roles', 'editar'));
$nameVal = $old['name'] ?? ($role['name'] ?? '');
$descVal = $old['description'] ?? ($role['description'] ?? ($base['description'] ?? ''));
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/roles')) ?>">←</a>
    <h1 class="h4 m-0"><?= $isNew ? 'Nuevo rol' : e($role['name']) ?></h1>
    <?php if ($system): ?><span class="badge text-bg-light">sistema</span><?php endif; ?>
</div>
<?php if ($system): ?>
    <div class="alert alert-secondary small d-flex flex-wrap align-items-center gap-2">Rol del sistema: no se modifica ni se elimina.
        <?php if (UserAuth::can('roles', 'crear')): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/panel/roles/nuevo?base=' . $role['uuid'])) ?>">Crear un rol partiendo de este</a><?php endif; ?></div>
<?php endif; ?>
<?php if ($isNew): ?>
    <form class="d-flex flex-wrap align-items-end gap-2 mb-3" method="get" action="<?= e(url('/panel/roles/nuevo')) ?>">
        <div><label class="form-label small mb-1" for="f_base">Partir de los permisos de</label>
            <select class="form-select form-select-sm" id="f_base" name="base" onchange="this.form.submit()" style="min-width: 240px">
                <option value="">Ninguno (en blanco)</option>
                <?php foreach ($roles as $r): ?><option value="<?= e($r['uuid']) ?>" <?= ($base['uuid'] ?? '') === $r['uuid'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?>
            </select></div>
        <noscript><button class="btn btn-sm btn-outline-secondary">Cargar</button></noscript>
    </form>
<?php endif; ?>

<form method="post" action="<?= e(url($isNew ? '/panel/roles' : '/panel/roles/' . $role['uuid'])) ?>">
    <?= csrf_field() ?>
    <fieldset <?= $editable ? '' : 'disabled' ?>>
        <div class="card shadow-sm mb-3">
            <div class="card-body row g-3">
                <div class="col-md-5">
                    <label class="form-label" for="f_name">Nombre</label>
                    <input class="form-control" id="f_name" name="name" value="<?= e($nameVal) ?>" maxlength="80" required placeholder="Ej.: Supervisor de turno">
                </div>
                <div class="col-md-7">
                    <label class="form-label" for="f_desc">Descripción</label>
                    <input class="form-control" id="f_desc" name="description" value="<?= e($descVal) ?>" maxlength="255">
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
        <?php if ($editable): ?><button class="btn btn-primary"><?= $isNew ? 'Crear rol' : 'Guardar rol' ?></button><?php endif; ?>
    </fieldset>
</form>

<?php if (!$isNew && $editable): ?>
    <div class="card border-danger-subtle mt-4" id="eliminar" style="max-width: 640px"><div class="card-body">
        <h2 class="h6">Eliminar rol</h2>
        <form method="post" action="<?= e(url('/panel/roles/' . $role['uuid'] . '/eliminar')) ?>" onsubmit="return confirm('¿Eliminar el rol «<?= e(addslashes($role['name'])) ?>»? No se puede deshacer.')">
            <?= csrf_field() ?>
            <?php if ($usersCount > 0): ?>
                <p class="small mb-2"><?= e($usersCount) ?> usuario(s) tienen este rol. Elegí a qué rol pasan:</p>
                <select class="form-select form-select-sm mb-2" name="move_to" required style="max-width: 320px">
                    <option value="">Elegí un rol…</option>
                    <?php foreach ($roles as $r): if ($r['uuid'] === $role['uuid']) continue; ?>
                        <option value="<?= e($r['uuid']) ?>"><?= e($r['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <p class="small text-body-secondary mb-2">Ningún usuario tiene este rol.</p>
            <?php endif; ?>
            <button class="btn btn-outline-danger btn-sm"><?= $usersCount > 0 ? 'Pasar usuarios y eliminar' : 'Eliminar rol' ?></button>
        </form>
    </div></div>
<?php endif; ?>
