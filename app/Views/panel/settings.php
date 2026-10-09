<?php $canEdit = App\Services\UserAuth::can('configuracion', 'editar'); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0">Configuración</h1>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/panel/configuracion/notificaciones')) ?>">Notificaciones y alertas →</a>
</div>
<div class="card shadow-sm mb-3" style="max-width: 640px" id="logo">
    <div class="card-header"><strong>Logo de la empresa</strong> <span class="small text-body-secondary">· se muestra arriba del menú lateral</span></div>
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="logo-preview"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="Logo actual"><?php else: ?><span class="small text-body-secondary">Sin logo</span><?php endif; ?></div>
            <?php if ($canEdit): ?>
                <div class="flex-fill">
                    <form method="post" action="<?= e(url('/panel/configuracion/logo')) ?>" enctype="multipart/form-data" class="d-flex flex-wrap gap-2">
                        <?= csrf_field() ?>
                        <input class="form-control form-control-sm" style="max-width: 300px" type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
                        <button class="btn btn-primary btn-sm"><?= $logoUrl ? 'Reemplazar' : 'Subir logo' ?></button>
                    </form>
                    <?php if ($logoUrl): ?>
                        <form method="post" action="<?= e(url('/panel/configuracion/logo/quitar')) ?>" class="mt-2" onsubmit="return confirm('¿Quitar el logo?')">
                            <?= csrf_field() ?><button class="btn btn-link btn-sm text-danger p-0">Quitar logo</button>
                        </form>
                    <?php endif; ?>
                    <div class="form-text">PNG, JPG o WEBP de hasta 1 MB. Ideal: horizontal (unos 400 × 120 px) y con fondo transparente.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
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

<form class="card shadow-sm mt-3" style="max-width: 640px" method="post" action="<?= e(url('/panel/configuracion/epp')) ?>">
 <?= csrf_field() ?><div class="card-header"><strong>EPP</strong></div><fieldset class="card-body" <?= $canEdit ? '' : 'disabled' ?>><label class="form-label">Avisar antes del vencimiento (días)</label><input class="form-control" type="number" min="1" max="90" name="aviso_dias" value="<?= e($epp['aviso_dias'] ?? '15') ?>"><div class="form-text">Se usa para el tablero y los avisos de EPP por vencer.</div><?php if ($canEdit): ?><button class="btn btn-primary btn-sm mt-2">Guardar</button><?php endif; ?></fieldset>
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

<form class="card shadow-sm mt-3" style="max-width: 640px" method="post" action="<?= e(url('/panel/configuracion/permisos')) ?>">
    <?= csrf_field() ?>
    <div class="card-header"><strong>Permisos de trabajo</strong></div>
    <fieldset class="card-body row g-3" <?= $canEdit ? '' : 'disabled' ?>>
        <div class="col-md-6"><label class="form-label">Duración máxima (horas)</label><input class="form-control" name="max_horas" inputmode="numeric" value="<?= e($permits['max_horas']) ?>">
            <div class="form-text">También es lo máximo que se puede extender (una vez).</div></div>
        <div class="col-md-6"><label class="form-label">Guardia de fuego (min, por defecto)</label><input class="form-control" name="vigia" inputmode="numeric" value="<?= e($permits['vigia']) ?>">
            <div class="form-text">Después de cerrar un trabajo en caliente.</div></div>
        <div class="col-12 small fw-semibold">Límites de gases (espacio confinado) — fuera de rango, el trabajo se suspende solo y se avisa</div>
        <div class="col-6 col-md-2"><label class="form-label small">O₂ mín. %</label><input class="form-control form-control-sm" name="gas_o2_min" inputmode="decimal" value="<?= e($permits['gas_o2_min']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">O₂ máx. %</label><input class="form-control form-control-sm" name="gas_o2_max" inputmode="decimal" value="<?= e($permits['gas_o2_max']) ?>"></div>
        <div class="col-4 col-md-2"><label class="form-label small">LIE máx. %</label><input class="form-control form-control-sm" name="gas_lel_max" inputmode="decimal" value="<?= e($permits['gas_lel_max']) ?>"></div>
        <div class="col-4 col-md-3"><label class="form-label small">CO máx. ppm</label><input class="form-control form-control-sm" name="gas_co_max" inputmode="decimal" value="<?= e($permits['gas_co_max']) ?>"></div>
        <div class="col-4 col-md-3"><label class="form-label small">H₂S máx. ppm</label><input class="form-control form-control-sm" name="gas_h2s_max" inputmode="decimal" value="<?= e($permits['gas_h2s_max']) ?>"></div>
        <?php if ($canEdit): ?><div class="col-12"><button class="btn btn-primary btn-sm">Guardar</button></div><?php endif; ?>
    </fieldset>
</form>
