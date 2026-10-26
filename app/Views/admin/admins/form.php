<?php
/** Alta / edición de un super-admin. @var ?array $admin @var array $old @var array $errors @var bool $isMe */
$v = fn (string $k) => (string) ($old[$k] ?? ($admin[$k] ?? ''));
$invalid = fn (string $f) => isset($errors[$f]) ? ' is-invalid' : '';
$feedback = fn (string $f) => isset($errors[$f]) ? '<div class="invalid-feedback">' . e($errors[$f]) . '</div>' : '';
$min = App\Controllers\Web\Admin\PlatformAdminsController::MIN_PASSWORD;
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/administradores')) ?>">←</a>
    <h1 class="h4 m-0"><?= $admin ? e($admin['name']) : 'Nuevo super-admin' ?></h1>
    <?php if ($isMe): ?><span class="badge text-bg-light">vos</span><?php endif; ?>
</div>
<form class="card card-body shadow-sm" method="post" action="<?= e(url('/admin/administradores' . ($admin ? '/' . $admin['uuid'] : ''))) ?>" style="max-width: 640px" autocomplete="off">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="f_name">Nombre</label>
            <input class="form-control<?= $invalid('name') ?>" id="f_name" name="name" value="<?= e($v('name')) ?>" maxlength="120" required><?= $feedback('name') ?></div>
        <div class="col-md-6"><label class="form-label" for="f_email">Email (para entrar)</label>
            <input class="form-control<?= $invalid('email') ?>" id="f_email" type="email" name="email" value="<?= e($v('email')) ?>" maxlength="191" required><?= $feedback('email') ?></div>
    </div>
    <h2 class="h6 mt-4 mb-1"><?= $admin ? 'Cambiar contraseña' : 'Contraseña' ?></h2>
    <p class="small text-body-secondary mb-2"><?= $admin ? 'Dejalo vacío para no cambiarla.' : 'La que va a usar para entrar.' ?> Mínimo <?= $min ?> caracteres.</p>
    <div class="row g-3">
        <?php if ($isMe): ?>
            <div class="col-12"><label class="form-label" for="f_cur">Tu contraseña actual (solo si la cambiás)</label>
                <input class="form-control<?= $invalid('current_password') ?>" id="f_cur" type="password" name="current_password" autocomplete="current-password" style="max-width: 300px"><?= $feedback('current_password') ?></div>
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label" for="f_pw">Nueva contraseña</label>
            <input class="form-control<?= $invalid('password') ?>" id="f_pw" type="password" name="password" minlength="<?= $min ?>" autocomplete="new-password" <?= $admin ? '' : 'required' ?>><?= $feedback('password') ?></div>
        <div class="col-md-6"><label class="form-label" for="f_pw2">Repetir contraseña</label>
            <input class="form-control<?= $invalid('password_confirmation') ?>" id="f_pw2" type="password" name="password_confirmation" autocomplete="new-password" <?= $admin ? '' : 'required' ?>><?= $feedback('password_confirmation') ?></div>
    </div>
    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary"><?= $admin ? 'Guardar cambios' : 'Crear super-admin' ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/admin/administradores')) ?>">Cancelar</a>
    </div>
</form>
<?php if ($admin && !$isMe): ?>
    <div class="d-flex gap-2 mt-3">
        <form method="post" action="<?= e(url('/admin/administradores/' . $admin['uuid'] . '/estado')) ?>"><?= csrf_field() ?>
            <button class="btn btn-outline-secondary btn-sm"><?= (int) $admin['is_active'] === 1 ? 'Desactivar' : 'Activar' ?></button></form>
        <form method="post" action="<?= e(url('/admin/administradores/' . $admin['uuid'] . '/eliminar')) ?>" onsubmit="return confirm('¿Eliminar a <?= e(addslashes($admin['name'])) ?>?')"><?= csrf_field() ?>
            <button class="btn btn-outline-danger btn-sm">Eliminar</button></form>
    </div>
<?php endif; ?>
