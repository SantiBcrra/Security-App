<?php
use App\Policies\Permissions;
use App\Services\UserAuth;
$canEdit = $user ? UserAuth::can('usuarios', 'editar') : UserAuth::can('usuarios', 'crear');
$invalid = fn (string $f) => isset($errors[$f]) ? ' is-invalid' : '';
$feedback = fn (string $f) => isset($errors[$f]) ? '<div class="invalid-feedback">' . e($errors[$f]) . '</div>' : '';
$auditorUuid = null;
foreach ($roles as $r) { if ($r['slug'] === Permissions::AUDITOR_ROLE) { $auditorUuid = $r['uuid']; } }
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/usuarios')) ?>">←</a>
    <h1 class="h4 m-0"><?= e($user ? $user['name'] : 'Nuevo usuario') ?></h1>
    <?php if ($user && (int) $user['is_active'] !== 1): ?><span class="badge text-bg-secondary">Desactivado</span><?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <form class="card shadow-sm" method="post" action="<?= e(url($user ? '/panel/usuarios/' . $user['uuid'] : '/panel/usuarios')) ?>"
              x-data="{ role: '<?= e($old['role']) ?>' }">
            <?= csrf_field() ?>
            <fieldset class="card-body row g-3" <?= $canEdit ? '' : 'disabled' ?>>
                <div class="col-12">
                    <label class="form-label" for="f_name">Nombre y apellido</label>
                    <input class="form-control<?= $invalid('name') ?>" id="f_name" name="name" value="<?= e($old['name']) ?>" required maxlength="120">
                    <?= $feedback('name') ?>
                </div>
                <div class="col-md-7">
                    <label class="form-label" for="f_email">Email</label>
                    <input class="form-control<?= $invalid('email') ?>" type="email" id="f_email" name="email" value="<?= e($old['email']) ?>" maxlength="191">
                    <?= $feedback('email') ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="f_dni">DNI</label>
                    <input class="form-control<?= $invalid('dni') ?>" id="f_dni" name="dni" value="<?= e($old['dni']) ?>" inputmode="numeric" maxlength="12">
                    <?= $feedback('dni') ?>
                </div>
                <div class="col-12 form-text mt-1">Con el email o el DNI se ingresa. El DNI sirve para operarios sin email.</div>
                <div class="col-md-7">
                    <label class="form-label" for="f_role">Rol</label>
                    <select class="form-select<?= $invalid('role') ?>" id="f_role" name="role" x-model="role" required>
                        <option value="">Elegí un rol…</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= e($r['uuid']) ?>" <?= $old['role'] === $r['uuid'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?= $feedback('role') ?>
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="f_exp">Acceso hasta <span class="text-body-secondary" x-show="role !== '<?= e($auditorUuid) ?>'">(opcional)</span></label>
                    <input class="form-control<?= $invalid('access_expires') ?>" type="date" id="f_exp" name="access_expires" value="<?= e($old['access_expires']) ?>">
                    <?= $feedback('access_expires') ?>
                </div>
                <?php if ($canEdit): ?>
                    <div class="col-12"><button class="btn btn-primary"><?= $user ? 'Guardar cambios' : 'Crear y generar link de activación' ?></button></div>
                <?php endif; ?>
            </fieldset>
        </form>
    </div>

    <?php if ($user): ?>
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Acceso</strong></div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between"><span>Cuenta</span><span><?= $user['password_hash'] ? 'activada' : 'pendiente de activar' ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Verificación en dos pasos</span><span><?= (int) $user['totp_enabled'] === 1 ? 'activada' : 'no' ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Último ingreso</span><span><?= $user['last_login_at'] ? e(fecha($user['last_login_at'])) : '—' ?></span></li>
            </ul>
            <?php if ($canEdit): ?>
                <div class="card-body d-flex flex-wrap gap-2">
                    <?php if ((int) $user['is_active'] === 1): ?>
                        <form method="post" action="<?= e(url('/panel/usuarios/' . $user['uuid'] . '/invitacion')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-outline-primary btn-sm"><?= $user['password_hash'] ? 'Link para nueva contraseña' : 'Nuevo link de activación' ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if (!$isSelf): ?>
                        <form method="post" action="<?= e(url('/panel/usuarios/' . $user['uuid'] . '/estado')) ?>"
                              <?= (int) $user['is_active'] === 1 ? 'onsubmit="return confirm(\'¿Desactivar el usuario? No va a poder entrar y se cierran sus sesiones de la app.\')"' : '' ?>>
                            <?= csrf_field() ?>
                            <button class="btn btn-sm <?= (int) $user['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                <?= (int) $user['is_active'] === 1 ? 'Desactivar' : 'Reactivar' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><strong>Dispositivos (app móvil)</strong></div>
            <?php if (!$devices): ?>
                <p class="small text-body-secondary p-3 mb-0">Todavía no ingresó desde la app.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($devices as $d): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span>
                                <?= e($d['device_name'] ?: 'Dispositivo') ?><br>
                                <span class="text-body-secondary"><?= $d['revoked_at'] ? 'cerrada: ' . e($d['revoked_reason']) : 'último uso ' . e(fecha($d['last_used_at'])) ?></span>
                            </span>
                            <?php if (!$d['revoked_at'] && $canEdit): ?>
                                <form method="post" action="<?= e(url('/panel/usuarios/' . $user['uuid'] . '/dispositivos/' . $d['uuid'] . '/revocar')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-outline-danger btn-sm">Cerrar sesión</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
