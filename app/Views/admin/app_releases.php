<?php
$v = fn (string $k, $d = '') => $old[$k] ?? $d;
$err = fn (string $k) => isset($errors[$k]) ? '<div class="text-danger small">' . e($errors[$k]) . '</div>' : '';
?>
<div class="page-head">
    <div><h1 class="h4 mb-1">App Android</h1>
        <div class="text-body-secondary small">Distribución propia, sin Google Play. Página de descarga para los empleados:
            <a href="<?= e($download) ?>" target="_blank"><?= e($download) ?></a></div></div>
</div>
<div class="row g-4">
    <div class="col-xl-7">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Versiones</strong></div>
            <div class="table-responsive">
                <table class="table table-sm small mb-0 align-middle">
                    <thead><tr><th>Versión</th><th>Código</th><th>Mínima obligatoria</th><th>Tamaño</th><th>Subida</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($releases as $r): ?>
                        <tr class="<?= (int) $r['is_active'] ? '' : 'text-body-secondary' ?>">
                            <td><strong><?= e($r['version_name']) ?></strong><?= $r['notes'] ? '<br><span class="text-body-secondary">' . e(mb_strimwidth($r['notes'], 0, 80, '…')) . '</span>' : '' ?></td>
                            <td><?= e($r['version_code']) ?></td>
                            <td><?= (int) $r['min_version_code'] ?: '—' ?></td>
                            <td class="text-nowrap"><?= e(number_format((int) $r['size_bytes'] / 1048576, 1, ',', '.')) ?> MB</td>
                            <td class="text-nowrap"><?= e(fecha($r['created_at'], 'd/m/Y H:i')) ?><br><span class="text-body-secondary"><?= e($r['uploaded_by_name'] ?? '') ?></span></td>
                            <td><?= (int) $r['is_active'] ? '<span class="badge text-bg-success">Publicada</span>' : '<span class="badge text-bg-secondary">Retirada</span>' ?></td>
                            <td class="text-end"><form method="post" action="<?= e(url('/admin/app-android/' . $r['uuid'] . '/estado')) ?>"><?= csrf_field() ?>
                                <button class="btn btn-link btn-sm p-0"><?= (int) $r['is_active'] ? 'Retirar' : 'Publicar' ?></button></form></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$releases): ?><tr><td colspan="7" class="text-body-secondary">Todavía no se subió ninguna versión.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <form class="card shadow-sm" method="post" action="<?= e(url('/admin/app-android')) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="card-header"><strong>Publicar una versión</strong></div>
            <div class="card-body">
                <div class="mb-2"><label class="form-label small mb-0">APK firmado (release)</label><input class="form-control form-control-sm" type="file" name="apk" accept=".apk" required><?= $err('apk') ?></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label small mb-0">Nombre de versión</label><input class="form-control form-control-sm" name="version_name" value="<?= e($v('version_name')) ?>" placeholder="1.0.0" required><?= $err('version_name') ?></div>
                    <div class="col-6"><label class="form-label small mb-0">Código (versionCode)</label><input class="form-control form-control-sm" name="version_code" inputmode="numeric" value="<?= e($v('version_code', $next)) ?>" required><?= $err('version_code') ?></div>
                </div>
                <div class="mb-2"><label class="form-label small mb-0">Versión mínima obligatoria (código)</label>
                    <input class="form-control form-control-sm" name="min_version_code" inputmode="numeric" value="<?= e($v('min_version_code')) ?>" placeholder="vacío = actualización opcional"><?= $err('min_version_code') ?>
                    <div class="form-text">Las apps con un código menor no dejan seguir hasta actualizar (para cambios incompatibles o de seguridad).</div></div>
                <div class="mb-2"><label class="form-label small mb-0">Novedades</label><textarea class="form-control form-control-sm" name="notes" rows="3"><?= e($v('notes')) ?></textarea></div>
                <div class="form-text mb-2">El código y el nombre tienen que coincidir con los de <code>android/app/build.gradle.kts</code>. Android solo acepta la actualización si el APK está firmado con la misma clave.</div>
                <button class="btn btn-primary btn-sm">Publicar</button>
            </div>
        </form>
    </div>
</div>
