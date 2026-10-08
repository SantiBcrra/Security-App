<?php
use App\Models\WorkPermits;
use App\Services\WorkPermitService;
require __DIR__ . '/_badges.php';
$query = http_build_query(array_filter($form, fn ($v) => $v !== ''));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/permisos')) ?>">←</a>
        <h1 class="h4 m-0">Permisos · historial</h1>
    </div>
    <?php if ($canExport): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/permisos/exportar' . ($query ? '?' . $query : ''))) ?>">Exportar CSV</a><?php endif; ?>
</div>
<form method="get" class="card card-body shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small mb-0">Estado</label>
            <select class="form-select form-select-sm" name="estado"><option value="">Todos</option>
                <option value="activos" <?= $form['estado'] === 'activos' ? 'selected' : '' ?>>Activos</option>
                <?php foreach (WorkPermitService::STATES as $k => $st): ?><option value="<?= e($k) ?>" <?= $form['estado'] === $k ? 'selected' : '' ?>><?= e($st['label']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0">Tipo</label>
            <select class="form-select form-select-sm" name="tipo"><option value="">Todos</option>
                <?php foreach (WorkPermitService::TYPES as $k => $t): ?><option value="<?= e($k) ?>" <?= $form['tipo'] === $k ? 'selected' : '' ?>><?= e($t['short']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-2"><label class="form-label small mb-0">Planta</label>
            <select class="form-select form-select-sm" name="planta"><option value="">Todas</option>
                <?php foreach ($sites as $uuid => $name): ?><option value="<?= e($uuid) ?>" <?= $form['planta'] === $uuid ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-3"><label class="form-label small mb-0">Sector</label>
            <select class="form-select form-select-sm" name="sector"><option value="">Todos</option>
                <?php foreach ($sectors as $uuid => $name): ?><option value="<?= e($uuid) ?>" <?= $form['sector'] === $uuid ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-6 col-md-1"><label class="form-label small mb-0">Desde</label><input type="date" class="form-control form-control-sm" name="desde" value="<?= e($form['desde']) ?>"></div>
        <div class="col-6 col-md-1"><label class="form-label small mb-0">Hasta</label><input type="date" class="form-control form-control-sm" name="hasta" value="<?= e($form['hasta']) ?>"></div>
        <div class="col-12 col-md-1"><button class="btn btn-sm btn-primary w-100">Filtrar</button></div>
        <div class="col-md-6"><input class="form-control form-control-sm" name="q" value="<?= e($form['q']) ?>" placeholder="Buscar: PT-000012, tarea o lugar"></div>
        <div class="col-md-3"><div class="form-check small"><input class="form-check-input" type="checkbox" name="mios" value="1" id="mios" <?= $form['mios'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="mios">Solo los que solicité</label></div></div>
    </div>
</form>
<div class="small text-body-secondary mb-2"><?= e($total) ?> permiso(s)<?= $total > count($rows) ? ' · se muestran los ' . count($rows) . ' más recientes (el CSV trae todos)' : '' ?></div>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover small mb-0 align-middle">
            <thead><tr><th>Número</th><th>Tipos</th><th>Estado</th><th>Lugar</th><th>Tarea</th><th>Ventana</th><th>Solicitó</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $p): ?>
                <tr>
                    <td class="text-nowrap"><a href="<?= e(url('/panel/permisos/' . $p['uuid'])) ?>"><?= e(WorkPermits::format((int) $p['number'])) ?></a></td>
                    <td><?= $typeBadges($p['type_list']) ?></td>
                    <td><?= $stateBadge($p['status']) ?></td>
                    <td><?= e($p['sector_name'] ?? '') ?><?= $p['equipment_code'] ? ' · ' . e($p['equipment_code']) : '' ?></td>
                    <td class="text-truncate" style="max-width: 260px"><?= e($p['task']) ?></td>
                    <td class="text-nowrap"><?= e(fecha($p['valid_from'], 'd/m/y H:i')) ?> → <?= e(fecha($p['ends_at'], 'H:i')) ?><?= $p['extended_until'] ? ' <span class="badge text-bg-light border">ext.</span>' : '' ?></td>
                    <td><?= e($p['requested_by_name'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="7" class="text-body-secondary">No hay permisos con esos filtros.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
