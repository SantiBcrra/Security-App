<?php use App\Services\UserAuth; ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Usuarios</h1>
    <?php if (UserAuth::can('usuarios', 'crear')): ?>
        <a class="btn btn-primary" href="<?= e(url('/panel/usuarios/nuevo')) ?>">Nuevo usuario</a>
    <?php endif; ?>
</div>
<?php require BASE_PATH . '/app/Views/partials/invitation.php'; ?>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead><tr><th>Nombre</th><th>Ingresa con</th><th>Rol</th><th>Estado</th><th>Último ingreso</th></tr></thead>
            <tbody>
            <?php foreach ($users as $u):
                $expired = $u['access_expires_at'] && strtotime($u['access_expires_at'] . ' UTC') < time(); ?>
                <tr class="<?= (int) $u['is_active'] !== 1 ? 'text-body-tertiary' : '' ?>">
                    <td><a class="fw-semibold text-decoration-none" href="<?= e(url('/panel/usuarios/' . $u['uuid'])) ?>"><?= e($u['name']) ?></a></td>
                    <td class="small"><?= e($u['email'] ?: '') ?><?= $u['email'] && $u['dni'] ? '<br>' : '' ?><?= $u['dni'] ? 'DNI ' . e($u['dni']) : '' ?></td>
                    <td><?= e($u['role_name']) ?></td>
                    <td>
                        <?php if ((int) $u['is_active'] !== 1): ?><span class="badge text-bg-secondary">Desactivado</span>
                        <?php elseif ($expired): ?><span class="badge text-bg-danger">Acceso vencido</span>
                        <?php elseif (!$u['password_hash']): ?><span class="badge text-bg-warning">Sin activar</span>
                        <?php else: ?><span class="badge text-bg-success">Activo</span><?php endif; ?>
                        <?php if ((int) $u['totp_enabled'] === 1): ?><span class="badge text-bg-light" title="Verificación en dos pasos">2FA</span><?php endif; ?>
                    </td>
                    <td class="small"><?= $u['last_login_at'] ? e(fecha($u['last_login_at'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
