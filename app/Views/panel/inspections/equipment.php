<?php
use App\Models\Inspections;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$last = $latest[0] ?? null;
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
    <h1 class="h4 m-0"><?= e($eq['code']) ?> · <?= e($eq['name']) ?></h1>
    <?php if (($eq['status'] ?? 'operativo') !== 'operativo'): ?><span class="badge text-bg-dark"><?= e(App\Models\Equipment::STATUSES[$eq['status']] ?? $eq['status']) ?></span><?php endif; ?>
</div>
<p class="text-body-secondary"><?= e(implode(' · ', array_filter([$type['name'] ?? null, $sector]))) ?></p>

<?php if ($last && $last['result'] === 'no_conforme_critico'): ?>
    <div class="alert alert-danger">La última inspección (<?= e(fecha($last['done_at_device'], 'd/m H:i')) ?>) falló en un ítem crítico.
        <a class="alert-link" href="<?= e(url('/panel/inspecciones/' . $last['uuid'])) ?>">Ver detalle</a> antes de usar el equipo.</div>
<?php endif; ?>

<div class="row g-3" style="max-width: 900px">
    <div class="col-md-6">
        <div class="card shadow-sm h-100">
            <div class="card-header"><strong>Checklists</strong></div>
            <div class="card-body d-grid gap-2">
                <?php if (UserAuth::can('inspecciones', 'crear')): ?>
                    <?php foreach ($templates as $t): ?>
                        <a class="btn btn-primary" href="<?= e(url('/panel/inspecciones/nueva?' . http_build_query(['plantilla' => $t['uuid'], 'equipo' => $eq['uuid']]))) ?>">Hacer: <?= e($t['name']) ?></a>
                    <?php endforeach; ?>
                    <?php if (!$templates): ?><div class="small text-body-secondary">No hay checklists para este tipo de equipo.</div><?php endif; ?>
                <?php endif; ?>
                <?php if (UserAuth::can('observaciones', 'crear')): ?>
                    <a class="btn btn-outline-danger" href="<?= e(url('/panel/observaciones/nueva?equipo=' . $eq['uuid'])) ?>">Reportar una observación</a>
                <?php endif; ?>
                <?php if (UserAuth::can('datos_maestros', 'ver')): ?>
                    <a class="btn btn-link btn-sm" href="<?= e(url('/panel/datos/equipos/' . $eq['uuid'])) ?>">Ficha en datos maestros</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($latest): ?>
        <div class="col-md-6">
            <div class="card shadow-sm h-100">
                <div class="card-header"><strong>Últimas inspecciones</strong></div>
                <div class="list-group list-group-flush small">
                    <?php foreach ($latest as $r): ?>
                        <a class="list-group-item list-group-item-action d-flex justify-content-between gap-2" href="<?= e(url('/panel/inspecciones/' . $r['uuid'])) ?>">
                            <span><?= e(fecha($r['done_at_device'], 'd/m H:i')) ?> · <?= e($r['template_name']) ?><br><span class="text-body-secondary"><?= e($r['inspector_name'] ?? '') ?></span></span>
                            <span class="text-nowrap"><?= $resultBadge($r['result']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
