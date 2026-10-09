<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Marcas fuera de lugar</h1>
    <a class="btn btn-outline-secondary" href="<?= e(url('/panel/rondas')) ?>">Volver a rondas</a>
</div>
<p class="text-body-secondary">Escaneos realizados fuera del radio del punto o con ubicación simulada.</p>
<form method="get" class="card card-body mb-4">
    <div class="row g-3 align-items-end">
        <div class="col-md-3"><label class="form-label">Guardia</label><select name="guardia" class="form-select"><option value="">Todos</option><?php foreach ($users as $u): ?><option value="<?= e($u['uuid']) ?>" <?= $filters['guardia'] === $u['uuid'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Punto</label><select name="punto" class="form-select"><option value="">Todos</option><?php foreach ($points as $p): ?><option value="<?= e($p['uuid']) ?>" <?= $filters['punto'] === $p['uuid'] ? 'selected' : '' ?>><?= e($p['code'] . ' · ' . $p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label">Desde</label><input type="date" name="desde" value="<?= e($filters['desde']) ?>" class="form-control"></div>
        <div class="col-md-2"><label class="form-label">Hasta</label><input type="date" name="hasta" value="<?= e($filters['hasta']) ?>" class="form-control"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Filtrar</button></div>
    </div>
</form>
<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Fecha</th><th>Guardia</th><th>Punto</th><th>Motivo</th><th>Distancia</th><th>Precisión</th><th></th></tr></thead><tbody>
<?php foreach ($scans as $s): ?><tr><td><?= e(fecha($s['scanned_at_device'])) ?></td><td><?= e($s['user_name']) ?></td><td><code><?= e($s['point_code']) ?></code> · <?= e($s['point_name']) ?></td><td><span class="badge text-bg-<?= $s['location_status'] === 'simulada' ? 'warning' : 'danger' ?>"><?= e($s['location_status'] === 'simulada' ? 'GPS simulado' : 'Lejos del punto') ?></span></td><td><?= $s['distance_m'] !== null ? e(number_format((float) $s['distance_m'], 1, ',', '.')) . ' m' : '—' ?></td><td><?= $s['accuracy_m'] !== null ? e(number_format((float) $s['accuracy_m'], 1, ',', '.')) . ' m' : '—' ?></td><td><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/rondas/ronda/' . $s['round_uuid'])) ?>">Ver ronda</a></td></tr><?php endforeach; ?>
<?php if (!$scans): ?><tr><td colspan="7" class="text-body-secondary">No hay marcas fuera de lugar con estos filtros.</td></tr><?php endif; ?></tbody></table></div>
