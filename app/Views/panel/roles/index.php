<?php use App\Services\UserAuth; ?>
<h1 class="h4 mb-2">Roles</h1>
<p class="small text-body-secondary">Los roles del sistema no se modifican. Para ajustar uno, copialo y editá la copia.</p>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Rol</th><th>Usuarios</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($roles as $r): ?>
                <tr>
                    <td>
                        <a class="fw-semibold text-decoration-none" href="<?= e(url('/panel/roles/' . $r['uuid'])) ?>"><?= e($r['name']) ?></a>
                        <?php if ((int) $r['is_system'] === 1): ?><span class="badge text-bg-light">sistema</span><?php endif; ?>
                        <div class="small text-body-secondary"><?= e($r['description']) ?></div>
                    </td>
                    <td><?= e($r['users_count']) ?></td>
                    <td class="text-end">
                        <?php if (UserAuth::can('roles', 'crear')): ?>
                            <form method="post" action="<?= e(url('/panel/roles/' . $r['uuid'] . '/copiar')) ?>">
                                <?= csrf_field() ?>
                                <button class="btn btn-outline-secondary btn-sm">Copiar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
