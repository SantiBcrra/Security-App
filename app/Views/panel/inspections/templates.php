<?php use App\Services\InspectionTemplateService; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones')) ?>">←</a>
        <h1 class="h4 m-0">Plantillas de checklist</h1>
    </div>
    <a class="btn btn-primary btn-sm" href="<?= e(url('/panel/inspecciones/plantillas/nueva')) ?>">+ Nueva plantilla</a>
</div>
<div class="card shadow-sm mb-3">
    <div class="list-group list-group-flush">
        <?php foreach ($templates as $t): ?>
            <div class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2 <?= $t['is_active'] ? '' : 'opacity-50' ?>">
                <div>
                    <a class="fw-semibold" href="<?= e(url('/panel/inspecciones/plantillas/' . $t['uuid'])) ?>"><?= e($t['name']) ?></a>
                    <?= $t['is_active'] ? '' : '<span class="badge text-bg-secondary">Inactiva</span>' ?>
                    <div class="small text-body-secondary">
                        <?= e($t['scope'] === 'equipo' ? 'Equipo: ' . ($t['equipment_type_name'] ?? '—') : ($t['scope'] === 'permiso' ? 'Permiso de trabajo: ' . (App\Services\WorkPermitService::TYPES[$t['permit_type']]['label'] ?? '—') : InspectionTemplateService::SCOPES[$t['scope']])) ?>
                        · <?= e($t['item_count']) ?> ítems · versión <?= e($t['current_version']) ?> · <?= e($t['inspections_count']) ?> inspecciones
                    </div>
                </div>
                <form method="post" action="<?= e(url('/panel/inspecciones/plantillas/' . $t['uuid'] . '/estado')) ?>"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-secondary"><?= $t['is_active'] ? 'Desactivar' : 'Activar' ?></button></form>
            </div>
        <?php endforeach; ?>
        <?php if (!$templates): ?><div class="list-group-item small text-body-secondary">Todavía no hay plantillas. Creá una o cargá las precargadas del rubro.</div><?php endif; ?>
    </div>
</div>
<?php if ($industries): ?>
    <form class="card shadow-sm" method="post" action="<?= e(url('/panel/inspecciones/plantillas/precargadas')) ?>" style="max-width: 640px">
        <?= csrf_field() ?>
        <div class="card-body d-flex flex-wrap gap-2 align-items-end">
            <div class="flex-grow-1"><label class="form-label small">Cargar checklists precargados del rubro</label>
                <select class="form-select form-select-sm" name="industry"><?php foreach ($industries as $k => $n): ?><option value="<?= e($k) ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
            <button class="btn btn-outline-primary btn-sm">Cargar</button>
            <div class="w-100 small text-body-secondary">Solo agrega los que faltan; los que ya cargaste (y ajustaste) no se tocan.</div>
        </div>
    </form>
<?php endif; ?>
