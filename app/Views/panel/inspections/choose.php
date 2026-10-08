<?php
use App\Services\InspectionTemplateService;
use App\Services\UserAuth;
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones')) ?>">←</a>
    <h1 class="h4 m-0">Nueva inspección</h1>
</div>
<?php if ($template && $template['scope'] === 'equipo'): ?>
    <div class="card shadow-sm" style="max-width: 640px">
        <div class="card-header"><strong><?= e($template['name']) ?></strong> · elegí el equipo</div>
        <div class="list-group list-group-flush">
            <?php foreach ($equipment as $eq): ?>
                <a class="list-group-item list-group-item-action" href="<?= e(url('/panel/inspecciones/nueva?' . http_build_query(['plantilla' => $template['uuid'], 'equipo' => $eq['uuid']]))) ?>">
                    <strong><?= e($eq['code']) ?></strong> · <?= e($eq['name']) ?> <span class="small text-body-secondary"><?= e($eq['sector_name'] ?? '') ?></span></a>
            <?php endforeach; ?>
            <?php if (!$equipment): ?><div class="list-group-item small text-body-secondary">No hay equipos activos de tipo «<?= e($template['equipment_type_name']) ?>». Cargalos en Datos maestros → Equipos.</div><?php endif; ?>
        </div>
        <div class="card-footer small text-body-secondary">En planta es más rápido escanear el QR del equipo.</div>
    </div>
<?php else: ?>
    <?php if (!$templates): ?>
        <div class="alert alert-info">Todavía no hay checklists.
            <?php if (UserAuth::can('inspecciones', 'editar')): ?><a href="<?= e(url('/panel/inspecciones/plantillas')) ?>">Creá uno o cargá los precargados del rubro</a>.<?php endif; ?></div>
    <?php endif; ?>
    <div class="row g-3">
        <?php foreach ($templates as $t): ?>
            <div class="col-md-6 col-lg-4">
                <a class="card shadow-sm h-100 text-decoration-none" href="<?= e(url('/panel/inspecciones/nueva?plantilla=' . $t['uuid'])) ?>">
                    <div class="card-body">
                        <div class="fw-semibold text-body"><?= e($t['name']) ?></div>
                        <div class="small text-body-secondary"><?= e($t['scope'] === 'equipo' ? 'Equipo: ' . $t['equipment_type_name'] : InspectionTemplateService::SCOPES[$t['scope']]) ?> · <?= e($t['item_count']) ?> ítems</div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
