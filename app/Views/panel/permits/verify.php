<?php
use App\Models\WorkPermits;
use App\Services\WorkPermitService;
require __DIR__ . '/_badges.php';
?>
<div class="mx-auto" style="max-width: 520px">
    <div class="card shadow-sm border-<?= $valid ? 'success' : 'danger' ?> border-3 text-center mb-3">
        <div class="card-body">
            <div class="display-6 fw-semibold text-<?= $valid ? 'success' : 'danger' ?>"><?= $valid ? 'VIGENTE' : 'NO VIGENTE' ?></div>
            <div class="mt-1"><?= e(WorkPermits::format((int) $p['number'])) ?> · <?= $stateBadge($p['status']) ?></div>
            <div class="small text-body-secondary mt-1"><?= $valid ? 'Hasta el ' . e(fecha($p['ends_at'], 'd/m H:i')) : ($p['status'] === 'aprobado' ? 'Autorizado pero todavía no se inició (faltan las firmas de los ejecutores).' : 'No se puede trabajar con este permiso.') ?></div>
        </div>
    </div>
    <div class="card shadow-sm">
        <ul class="list-group list-group-flush small">
            <li class="list-group-item"><?= $typeBadges($p['type_list']) ?></li>
            <li class="list-group-item"><?= e($p['sector_name'] ?? '') ?><?= $p['equipment_code'] ? ' · ' . e($p['equipment_code']) : '' ?><?= $p['location_text'] ? ' · ' . e($p['location_text']) : '' ?></li>
            <li class="list-group-item"><?= e($p['task']) ?></li>
            <li class="list-group-item"><strong>Ejecutores:</strong> <?= e(implode(', ', array_map($workerName, $workers))) ?></li>
            <li class="list-group-item">Autorizó: <?= e($p['approved_by_name'] ?? '—') ?></li>
        </ul>
    </div>
    <?php if (WorkPermitService::canView($p)): ?><a class="btn btn-link mt-2" href="<?= e(url('/panel/permisos/' . $p['uuid'])) ?>">Ver el permiso completo</a><?php endif; ?>
</div>
