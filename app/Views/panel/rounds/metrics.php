<?php
/** @var array $r @var array $filters @var array $routes @var array $users @var bool $canExport */
use App\Services\RoundMetrics;
$ro = $r['rounds']; $sl = $r['slots']; $sc = $r['scans'];
$q = http_build_query($filters);
$pct = fn (int $a, int $b) => $b > 0 ? (int) round(100 * $a / $b) . ' %' : '—';
$alertClass = ['panic' => 'danger', 'missed' => 'danger', 'off_site' => 'danger', 'abandoned' => 'secondary', 'gps_off' => 'warning', 'battery' => 'warning', 'late' => 'warning', 'no_progress' => 'warning'];
?>
<?= App\Core\View::render('panel/rounds/_nav', ['tab' => 'reportes', 'subtitle' => 'Reportes › Métricas: cumplimiento por guardia y por punto, e historial de alertas'], null) ?>

<form method="get" class="card card-body mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input type="date" name="desde" value="<?= e($filters['desde']) ?>" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input type="date" name="hasta" value="<?= e($filters['hasta']) ?>" class="form-control"></div>
        <div class="col-md-3"><label class="form-label small">Ruta</label><select name="ruta" class="form-select"><option value="">Todas</option>
            <?php foreach ($routes as $rt): ?><option value="<?= e($rt['uuid']) ?>" <?= $filters['ruta'] === $rt['uuid'] ? 'selected' : '' ?>><?= e($rt['name']) ?></option><?php endforeach; ?></select></div>
        <?php if ($users): ?><div class="col-md-3"><label class="form-label small">Guardia</label><select name="guardia" class="form-select"><option value="">Todos</option>
            <?php foreach ($users as $u): ?><option value="<?= e($u['uuid']) ?>" <?= $filters['guardia'] === $u['uuid'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-md-2"><button class="btn btn-primary w-100">Ver</button></div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card card-body h-100"><div class="small text-body-secondary">Rondas</div><div class="fs-3 fw-bold"><?= $ro['rounds'] ?></div>
        <div class="small"><?= $ro['completas'] ?> completas · <span class="text-danger"><?= $ro['incompletas'] ?> incompletas</span><?= $ro['en_curso'] ? ' · ' . $ro['en_curso'] . ' en curso' : '' ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-body h-100"><div class="small text-body-secondary">Programadas a tiempo</div><div class="fs-3 fw-bold"><?= $sl['cumplimiento'] !== null ? $sl['cumplimiento'] . ' %' : '—' ?></div>
        <div class="small"><?= $sl['tiempo'] ?> a tiempo · <?= $sl['tarde'] ?> tarde · <span class="text-danger"><?= $sl['no'] ?> no realizadas</span></div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-body h-100"><div class="small text-body-secondary">Marcas en el lugar</div><div class="fs-3 fw-bold"><?= $pct($sc['ok'], $sc['total']) ?></div>
        <div class="small"><?= $sc['total'] ?> marcas · <span class="text-danger"><?= $sc['fuera'] ?> fuera</span> · <?= $sc['dudosas'] ?> dudosas · <?= $pct($sc['nfc'], $sc['total']) ?> NFC<?= $sc['fuera_orden'] ? ' · ' . $sc['fuera_orden'] . ' fuera de orden' : '' ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-body h-100"><div class="small text-body-secondary">Problemas</div><div class="fs-3 fw-bold"><?= $ro['atrasadas'] ?> <span class="fs-6 fw-normal">atrasadas</span></div>
        <div class="small"><?= $ro['abandonadas'] ?> abandonadas · <?= count(array_filter($r['alerts'], fn ($a) => $a['type'] === 'panic')) ?> pánicos · <?= count($r['idle']) ?> sin actividad</div></div></div>
</div>

<div class="d-flex justify-content-between align-items-center"><h2 class="h5">Por guardia</h2>
    <?php if ($canExport): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/metricas/exportar?tipo=guardias&' . $q)) ?>">CSV</a><?php endif; ?></div>
<div class="table-responsive mb-4"><table class="table table-sm align-middle">
    <thead><tr><th>Guardia</th><th>Rondas</th><th>Incompletas</th><th>Atrasadas</th><th>Duración media</th><th>Programadas a tiempo</th><th>No realizadas</th><th>Marcas fuera</th><th>Alertas</th><th>Última</th></tr></thead>
    <tbody>
    <?php foreach ($r['guards'] as $g): $closed = $g['prog_tiempo'] + $g['prog_tarde'] + $g['prog_no']; ?>
        <tr><td><?= e($g['user_name']) ?></td><td><?= $g['rounds'] ?></td><td class="<?= $g['incompletas'] ? 'text-danger' : '' ?>"><?= $g['incompletas'] ?></td>
            <td><?= $g['atrasadas'] ?></td><td><?= $g['duracion_media'] !== null ? $g['duracion_media'] . ' min' : '—' ?></td>
            <td><?= $closed ? $pct($g['prog_tiempo'], $closed) . ' <span class="small text-body-secondary">(' . $g['prog_tiempo'] . '/' . $closed . ')</span>' : '—' ?></td>
            <td class="<?= $g['prog_no'] ? 'text-danger fw-semibold' : '' ?>"><?= $g['prog_no'] ?></td>
            <td class="<?= $g['fuera'] ? 'text-danger fw-semibold' : '' ?>"><?= $g['fuera'] ?><?= $g['dudosas'] ? ' <span class="small text-body-secondary">+' . $g['dudosas'] . ' dudosas</span>' : '' ?><?= !empty($g['fuera_orden']) ? ' <span class="badge text-bg-warning">' . $g['fuera_orden'] . ' fuera de orden</span>' : '' ?></td>
            <td><?= $g['alertas'] ?></td><td class="small"><?= $g['ultima'] ? e(fecha($g['ultima'], 'd/m H:i')) : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php foreach ($r['idle'] as $i): ?>
        <tr class="table-warning"><td><?= e($i['name']) ?></td><td colspan="8" class="small">Sin rondas en el período (asignado a rutas activas)</td>
            <td class="small"><?= $i['ultima'] ? e(fecha($i['ultima'], 'd/m H:i')) : 'nunca' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$r['guards'] && !$r['idle']): ?><tr><td colspan="10" class="text-body-secondary">Sin rondas en el período.</td></tr><?php endif; ?>
    </tbody>
</table></div>

<div class="d-flex justify-content-between align-items-center"><h2 class="h5">Por punto <span class="small text-body-secondary fw-normal">(los que más fallan primero)</span></h2>
    <?php if ($canExport): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/metricas/exportar?tipo=puntos&' . $q)) ?>">CSV</a><?php endif; ?></div>
<div class="table-responsive mb-4"><table class="table table-sm align-middle">
    <thead><tr><th>Punto</th><th>Marcas</th><th>Con NFC</th><th>Salteado</th><th>Fuera de lugar</th><th>Dudosas</th></tr></thead>
    <tbody>
    <?php foreach ($r['points'] as $p): ?>
        <tr><td><code><?= e($p['code']) ?></code> <?= e($p['name']) ?></td><td><?= $p['marcas'] ?></td><td><?= $p['nfc'] ?></td>
            <td class="<?= $p['salteados'] ? 'text-danger fw-semibold' : '' ?>"><?= $p['salteados'] ?></td>
            <td class="<?= $p['fuera'] ? 'text-danger fw-semibold' : '' ?>"><?= $p['fuera'] ?></td><td><?= $p['dudosas'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$r['points']): ?><tr><td colspan="6" class="text-body-secondary">Sin marcas en el período.</td></tr><?php endif; ?>
    </tbody>
</table></div>
<p class="small text-body-secondary">Un punto que se saltea seguido o sale "dudoso" puede tener el QR o la etiqueta dañados, o las coordenadas mal cargadas.</p>

<div class="d-flex justify-content-between align-items-center"><h2 class="h5">Historial de alertas</h2>
    <?php if ($canExport): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/metricas/exportar?tipo=alertas&' . $q)) ?>">CSV</a><?php endif; ?></div>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>Fecha</th><th>Tipo</th><th>Guardia</th><th>Ruta</th><th>Detalle</th><th>Resolución</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($r['alerts'] as $a): ?>
        <tr><td class="small text-nowrap"><?= e(fecha($a['at'], 'd/m H:i')) ?></td>
            <td><span class="badge text-bg-<?= e($alertClass[$a['type']] ?? 'secondary') ?>"><?= e(RoundMetrics::ALERT_LABELS[$a['type']] ?? $a['type']) ?></span></td>
            <td><?= e($a['user']) ?></td><td><?= e($a['route']) ?></td><td class="small"><?= e($a['detail']) ?></td>
            <td class="small <?= $a['resolution'] === 'SIN ATENDER' ? 'text-danger fw-bold' : '' ?>"><?= e($a['resolution']) ?></td>
            <td><?php if (!empty($a['panic_uuid'])): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/panico/' . $a['panic_uuid'])) ?>">Ver</a>
                <?php elseif ($a['round_uuid']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/ronda/' . $a['round_uuid'])) ?>">Ver</a><?php endif; ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$r['alerts']): ?><tr><td colspan="7" class="text-body-secondary">Sin alertas en el período.</td></tr><?php endif; ?>
    </tbody>
</table></div>
