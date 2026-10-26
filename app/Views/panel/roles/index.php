<?php use App\Services\UserAuth; ?>
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <h1 class="h4 m-0">Roles</h1>
    <?php if (UserAuth::can('roles', 'crear')): ?>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/panel/roles/nuevo')) ?>">+ Nuevo rol</a>
    <?php endif; ?>
</div>
<p class="small text-body-secondary">Los roles del sistema no se modifican ni se eliminan. Para otros permisos, creá un rol nuevo
    (podés partir de los permisos de uno existente).</p>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Rol</th><th>Usuarios</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($roles as $r): $system = (int) $r['is_system'] === 1; ?>
                <tr>
                    <td>
                        <a class="fw-semibold text-decoration-none" href="<?= e(url('/panel/roles/' . $r['uuid'])) ?>"><?= e($r['name']) ?></a>
                        <?php if ($system): ?><span class="badge text-bg-light">sistema</span><?php endif; ?>
                        <div class="small text-body-secondary"><?= e($r['description']) ?></div>
                    </td>
                    <td><?= e($r['users_count']) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/roles/' . $r['uuid'])) ?>"><?= !$system && UserAuth::can('roles', 'editar') ? 'Editar' : 'Ver' ?></a>
                        <?php if (!$system && UserAuth::can('roles', 'editar')): ?>
                            <?php if ((int) $r['users_count'] > 0): ?>
                                <a class="btn btn-outline-danger btn-sm" href="<?= e(url('/panel/roles/' . $r['uuid'] . '#eliminar')) ?>" title="Tiene usuarios: elegí a qué rol pasan">Eliminar</a>
                            <?php else: ?>
                                <form class="d-inline" method="post" action="<?= e(url('/panel/roles/' . $r['uuid'] . '/eliminar')) ?>" onsubmit="return confirm('¿Eliminar el rol «<?= e(addslashes($r['name'])) ?>»?')">
                                    <?= csrf_field() ?><button class="btn btn-outline-danger btn-sm">Eliminar</button></form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
