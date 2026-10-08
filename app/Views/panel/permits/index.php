<?php
use App\Models\WorkPermits;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
?>
<meta http-equiv="refresh" content="60">
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0">Permisos de trabajo</h1>
    <div class="d-flex gap-2 align-items-center">
        <span class="small text-body-secondary">Se actualiza solo cada minuto</span>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/permisos/historial')) ?>">Historial y filtros</a>
        <?php if (UserAuth::can('permisos_trabajo', 'crear')): ?><a class="btn btn-primary btn-sm" href="<?= e(url('/panel/permisos/nuevo')) ?>">+ Solicitar permiso</a><?php endif; ?>
    </div>
</div>

<h2 class="h6 text-body-secondary">Activos ahora</h2>
<?php if (!$bySite): ?><div class="card card-body small text-body-secondary mb-3">No hay permisos activos en este momento.</div><?php endif; ?>
<?php foreach ($bySite as $site => $list): ?>
    <div class="fw-semibold small mb-2"><?= e($site) ?> <span class="badge text-bg-light border"><?= count($list) ?></span></div>
    <div class="row g-2 mb-3">
        <?php foreach ($list as $p): $left = $minutesLeft($p); ?>
            <div class="col-md-6 col-xl-4">
                <a class="card shadow-sm h-100 text-decoration-none border-<?= $p['status'] === 'suspendido' || $left < 30 ? 'danger' : ($p['status'] === 'en_ejecucion' ? 'success' : 'primary') ?>"
                   href="<?= e(url('/panel/permisos/' . $p['uuid'])) ?>">
                    <div class="card-body py-2">
                        <div class="d-flex justify-content-between gap-2"><span class="small text-body-secondary"><?= e(WorkPermits::format((int) $p['number'])) ?></span><?= $stateBadge($p['status']) ?></div>
                        <div class="my-1"><?= $typeBadges($p['type_list']) ?></div>
                        <div class="text-body fw-semibold"><?= e($p['sector_name'] ?? '') ?><?= $p['equipment_code'] ? ' · ' . e($p['equipment_code']) : '' ?></div>
                        <div class="small text-body-secondary text-truncate"><?= e($p['task']) ?></div>
                        <div class="small <?= $left < 30 ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">Vence <?= e(fecha($p['ends_at'], 'd/m H:i')) ?><?= $left >= 0 && $left < 120 ? ' (en ' . e($left) . ' min)' : '' ?>
                            <?= $p['contractor_name'] ? ' · ' . e($p['contractor_name']) : '' ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Para autorizar</strong> <span class="badge text-bg-warning"><?= count($pending) ?></span></div>
            <div class="list-group list-group-flush small">
                <?php foreach ($pending as $p): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('/panel/permisos/' . $p['uuid'])) ?>">
                        <div class="d-flex justify-content-between gap-2"><span><?= e(WorkPermits::format((int) $p['number'])) ?> <?= $typeBadges($p['type_list']) ?></span>
                            <?= (int) $p['critical_fails'] ? '<span class="badge text-bg-danger">críticos sin cumplir</span>' : '' ?></div>
                        <div><?= e($p['sector_name'] ?? '') ?> · <?= e(mb_strimwidth($p['task'], 0, 70, '…')) ?></div>
                        <div class="text-body-secondary"><?= e($p['requested_by_name'] ?? '') ?> · <?= e(fecha($p['valid_from'], 'd/m H:i')) ?> a <?= e(fecha($p['valid_until'], 'H:i')) ?></div>
                    </a>
                <?php endforeach; ?>
                <?php if (!$pending): ?><div class="list-group-item text-body-secondary">Nada pendiente.</div><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Recientes</strong></div>
            <div class="list-group list-group-flush small">
                <?php foreach ($recent as $p): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between gap-2" href="<?= e(url('/panel/permisos/' . $p['uuid'])) ?>">
                        <span><?= e(WorkPermits::format((int) $p['number'])) ?> <?= $typeBadges($p['type_list']) ?> <span class="text-body-secondary"><?= e($p['sector_name'] ?? '') ?> · <?= e(fecha($p['valid_from'], 'd/m')) ?></span></span>
                        <?= $stateBadge($p['status']) ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!$recent): ?><div class="list-group-item text-body-secondary">Sin permisos todavía.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
