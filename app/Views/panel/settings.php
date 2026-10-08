<?php $canEdit = App\Services\UserAuth::can('configuracion', 'editar'); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0">Configuración</h1>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/panel/configuracion/notificaciones')) ?>">Notificaciones y alertas →</a>
</div>
<form class="card shadow-sm" style="max-width: 640px" method="post" action="<?= e(url('/panel/configuracion')) ?>">
    <?= csrf_field() ?>
    <div class="card-header"><strong>Observaciones</strong></div>
    <fieldset class="card-body" <?= $canEdit ? '' : 'disabled' ?>>
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="anonymous" name="anonymous" value="1" <?= $anonymous ? 'checked' : '' ?>>
            <label class="form-check-label" for="anonymous">Permitir reportes anónimos</label>
        </div>
        <div class="form-text mb-3">Quien reporta puede elegir no identificarse: no se guarda su nombre ni queda en la auditoría. Ayuda a que se reporten más actos inseguros.</div>
        <?php if ($canEdit): ?><button class="btn btn-primary btn-sm">Guardar</button><?php endif; ?>
    </fieldset>
</form>

<form class="card shadow-sm mt-3" style="max-width: 640px" method="post" action="<?= e(url('/panel/configuracion/empresa')) ?>">
    <?= csrf_field() ?>
    <div class="card-header"><strong>Datos del empleador</strong> <span class="small text-body-secondary">· para la denuncia de accidentes ante la ART</span></div>
    <fieldset class="card-body row g-3" <?= $canEdit ? '' : 'disabled' ?>>
        <div class="col-12 small text-body-secondary">Razón social: <strong><?= e($tenant['legal_name'] ?: $tenant['name']) ?></strong> · CUIT: <strong><?= e($tenant['cuit'] ? App\Core\Cuit::format($tenant['cuit']) : '—') ?></strong>
            (se cambian desde el alta de la empresa).</div>
        <div class="col-12"><label class="form-label">Domicilio del establecimiento</label><input class="form-control" name="domicilio" maxlength="191" value="<?= e($company['domicilio']) ?>"></div>
        <div class="col-md-8"><label class="form-label">Actividad principal</label><input class="form-control" name="actividad" maxlength="191" value="<?= e($company['actividad']) ?>" placeholder="Ej: Fabricación de productos metálicos"></div>
        <div class="col-md-4"><label class="form-label">Código CIIU</label><input class="form-control" name="ciiu" maxlength="20" value="<?= e($company['ciiu']) ?>"></div>
        <div class="col-md-5"><label class="form-label">ART</label><input class="form-control" name="art" maxlength="120" value="<?= e($company['art']) ?>"></div>
        <div class="col-md-4"><label class="form-label">N° de contrato</label><input class="form-control" name="art_contrato" maxlength="40" value="<?= e($company['art_contrato']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Tel. ART</label><input class="form-control" name="art_telefono" maxlength="40" value="<?= e($company['art_telefono']) ?>"></div>
        <?php if ($canEdit): ?><div class="col-12"><button class="btn btn-primary btn-sm">Guardar</button></div><?php endif; ?>
    </fieldset>
</form>
