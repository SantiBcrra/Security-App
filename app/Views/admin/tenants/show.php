<?php
/** @var array $tenant @var array $old @var array $errors */
$isSuspended = $tenant['status'] === 'suspended';
$invalid = fn (string $f) => isset($errors[$f]) ? ' is-invalid' : '';
$feedback = fn (string $f) => isset($errors[$f]) ? '<div class="invalid-feedback">' . e($errors[$f]) . '</div>' : '';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/empresas')) ?>">←</a>
        <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="" style="height:40px" class="rounded border bg-white"><?php endif; ?>
        <h1 class="h4 m-0"><?= e($tenant['name']) ?></h1>
        <?php $status = $tenant['status']; require BASE_PATH . '/app/Views/partials/tenant_status.php'; ?>
    </div>
    <form method="post" action="<?= e(url('/admin/empresas/' . $tenant['uuid'] . '/entrar')) ?>">
        <?= csrf_field() ?>
        <button class="btn btn-outline-primary" <?= $isSuspended ? 'disabled' : '' ?>>Entrar como empresa (solo lectura)</button>
    </form>
</div>

<?php if ($isSuspended): ?>
    <div class="alert alert-danger">
        Suspendida el <?= e(fecha($tenant['suspended_at'], 'd/m/Y H:i')) ?>. Motivo: <?= e($tenant['status_reason']) ?>
    </div>
<?php endif; ?>
<?php require BASE_PATH . '/app/Views/partials/invitation.php'; ?>
<?php if ($dbError): ?>
    <div class="alert alert-danger">No se puede conectar a la base de esta empresa: <?= e($dbError) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Datos</strong></div>
            <form class="card-body row g-3" method="post" action="<?= e(url('/admin/empresas/' . $tenant['uuid'])) ?>">
                <?= csrf_field() ?>
                <div class="col-md-6">
                    <label class="form-label" for="f_name">Nombre</label>
                    <input class="form-control<?= $invalid('name') ?>" id="f_name" name="name" value="<?= e($old['name']) ?>" required maxlength="120">
                    <?= $feedback('name') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Identificador</label>
                    <input class="form-control" value="<?= e($tenant['slug']) ?>" disabled>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f_legal">Razón social</label>
                    <input class="form-control<?= $invalid('legal_name') ?>" id="f_legal" name="legal_name" value="<?= e($old['legal_name']) ?>" maxlength="191">
                    <?= $feedback('legal_name') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f_cuit">CUIT</label>
                    <input class="form-control<?= $invalid('cuit') ?>" id="f_cuit" name="cuit" value="<?= e(isset($errors['cuit']) ? $old['cuit'] : App\Core\Cuit::format($old['cuit'])) ?>" inputmode="numeric">
                    <?= $feedback('cuit') ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f_tz">Zona horaria</label>
                    <select class="form-select" id="f_tz" name="timezone">
                        <?php foreach (App\Core\Tenant::TIMEZONES as $tz): ?>
                            <option value="<?= e($tz) ?>" <?= $old['timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f_plan">Plan</label>
                    <input class="form-control" id="f_plan" name="plan" value="<?= e($old['plan']) ?>" maxlength="40">
                </div>
                <div class="col-12"><button class="btn btn-primary">Guardar cambios</button></div>
            </form>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Actividad en la plataforma</strong></div>
            <?php $events = $platformEvents; require BASE_PATH . '/app/Views/partials/audit_table.php'; ?>
        </div>
        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Auditoría en la base de la empresa</strong></div>
            <?php $events = $tenantEvents; require BASE_PATH . '/app/Views/partials/audit_table.php'; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Logo</strong></div>
            <form class="card-body" method="post" enctype="multipart/form-data" action="<?= e(url('/admin/empresas/' . $tenant['uuid'] . '/logo')) ?>">
                <?= csrf_field() ?>
                <input class="form-control mb-2" type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
                <div class="form-text mb-2">PNG, JPG o WebP, hasta 1 MB.</div>
                <button class="btn btn-outline-primary btn-sm">Subir logo</button>
            </form>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Administradores de la empresa</strong></div>
            <?php if ($admins === null): ?>
                <p class="small text-body-secondary p-3 mb-0">La base de la empresa tiene actualizaciones pendientes: andá a <a href="<?= e(url('/admin/migraciones')) ?>">Base de datos</a>.</p>
            <?php else: ?>
                <?php if ($admins): ?>
                    <ul class="list-group list-group-flush small">
                        <?php foreach ($admins as $a): ?>
                            <li class="list-group-item d-flex justify-content-between">
                                <span><?= e($a['name']) ?><br><span class="text-body-secondary"><?= e($a['email']) ?></span></span>
                                <span><?= !$a['is_active'] ? 'desactivado' : ($a['activated'] ? 'activo' : 'sin activar') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <form class="card-body" method="post" action="<?= e(url('/admin/empresas/' . $tenant['uuid'] . '/administrador')) ?>">
                    <?= csrf_field() ?>
                    <p class="small text-body-secondary"><?= $admins ? 'Agregar otro administrador:' : 'La empresa todavía no tiene administrador. Crealo y compartile el link de activación: elige su contraseña él mismo.' ?></p>
                    <input class="form-control form-control-sm mb-2" name="name" placeholder="Nombre y apellido" required maxlength="120">
                    <input class="form-control form-control-sm mb-2" type="email" name="email" placeholder="Email" required maxlength="191">
                    <button class="btn btn-outline-primary btn-sm" <?= $isSuspended ? 'disabled' : '' ?>>Crear administrador</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Base de datos</strong></div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between"><span>Servidor</span><code><?= e($tenant['db_host']) ?>:<?= e($tenant['db_port']) ?></code></li>
                <li class="list-group-item d-flex justify-content-between"><span>Base</span><code><?= e($tenant['db_name']) ?></code></li>
                <li class="list-group-item d-flex justify-content-between"><span>Usuario</span><code><?= e($tenant['db_user']) ?></code></li>
                <li class="list-group-item d-flex justify-content-between"><span>Contraseña</span><span><?= $tenant['db_pass_enc'] ? 'guardada (cifrada)' : 'sin contraseña' ?></span></li>
            </ul>
        </div>

        <div class="card shadow-sm mb-3 <?= $isSuspended ? '' : 'border-danger-subtle' ?>">
            <div class="card-header"><strong><?= $isSuspended ? 'Reactivar' : 'Suspender' ?></strong></div>
            <?php if ($isSuspended): ?>
                <form class="card-body" method="post" action="<?= e(url('/admin/empresas/' . $tenant['uuid'] . '/activar')) ?>">
                    <?= csrf_field() ?>
                    <input class="form-control mb-2" name="reason" placeholder="Nota (opcional)" maxlength="255">
                    <button class="btn btn-success btn-sm">Reactivar empresa</button>
                </form>
            <?php else: ?>
                <form class="card-body" method="post" action="<?= e(url('/admin/empresas/' . $tenant['uuid'] . '/suspender')) ?>"
                      onsubmit="return confirm('¿Suspender la empresa? Nadie va a poder entrar hasta reactivarla.')">
                    <?= csrf_field() ?>
                    <p class="small text-body-secondary">Nadie de la empresa puede entrar mientras esté suspendida. Los datos no se tocan.</p>
                    <input class="form-control mb-2" name="reason" placeholder="Motivo (obligatorio)" maxlength="255" required>
                    <button class="btn btn-outline-danger btn-sm">Suspender empresa</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="small text-body-secondary">
            UUID: <code><?= e($tenant['uuid']) ?></code><br>
            Alta: <?= e(fecha($tenant['created_at'], 'd/m/Y H:i')) ?> · Última modificación: <?= e(fecha($tenant['updated_at'], 'd/m/Y H:i')) ?>
        </div>
    </div>
</div>
