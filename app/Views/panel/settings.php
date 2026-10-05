<?php $canEdit = App\Services\UserAuth::can('configuracion', 'editar'); ?>
<h1 class="h4 mb-3">Configuración</h1>
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
