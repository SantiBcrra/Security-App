<?php
/** Pestaña "Hoy". @var array $todayRounds @var array $inProgress @var array $slots @var array $alerts @var array $devices @var array $last @var string $today */
use App\Services\PatrolPlanner;
use App\Services\RoundMetrics;
$done = array_filter($todayRounds, fn ($r) => $r['status'] !== 'en_curso');
$complete = count(array_filter($done, fn ($r) => $r['status'] === 'completa'));
$sl = ['ok' => 0, 'late' => 0, 'missed' => 0, 'open' => 0];
foreach ($slots as $s) $sl[$s['status'] === 'realizada' ? ((int) $s['on_time'] === 1 ? 'ok' : 'late') : ($s['status'] === 'no_realizada' ? 'missed' : 'open')]++;
$upcoming = array_values(array_filter($slots, fn ($s) => $s['status'] === 'pendiente'));
$problemDevices = array_filter($devices, fn ($d) => $d['problems']);
$alertClass = ['panic' => 'danger', 'missed' => 'danger', 'off_site' => 'danger', 'abandoned' => 'secondary'];
$ago = fn (?string $utc) => $utc === null ? 'sin datos' : (($m = intdiv(max(0, time() - strtotime($utc . ' UTC')), 60)) < 1 ? 'recién' : ($m < 60 ? "hace {$m} min" : 'hace ' . intdiv($m, 60) . ' h'));
?>
<?= App\Core\View::render('panel/rounds/_nav', ['tab' => 'hoy', 'subtitle' => 'Hoy, ' . date('d/m/Y', strtotime($today)) . ' — lo que está pasando y lo último que se hizo'], null) ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3"><div class="card card-body h-100 <?= $inProgress ? 'border-warning' : '' ?>"><div class="small text-body-secondary">En curso ahora</div>
        <div class="fs-2 fw-bold"><?= count($inProgress) ?></div><div class="small">ronda(s) abiertas</div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-body h-100"><div class="small text-body-secondary">Realizadas hoy</div>
        <div class="fs-2 fw-bold"><?= count($done) ?></div><div class="small"><?= $complete ?> completas<?= count($done) - $complete ? ' · <span class="text-danger">' . (count($done) - $complete) . ' incompletas</span>' : '' ?></div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-body h-100"><div class="small text-body-secondary">Programadas hoy</div>
        <div class="fs-2 fw-bold"><?= count($slots) ?></div><div class="small"><?= $sl['ok'] ?> a tiempo · <?= $sl['late'] ?> tarde<?= $sl['missed'] ? ' · <span class="text-danger">' . $sl['missed'] . ' no realizadas</span>' : '' ?> · <?= $sl['open'] ?> por hacer</div></div></div>
    <div class="col-6 col-lg-3"><div class="card card-body h-100 <?= $alerts ? 'border-danger' : '' ?>"><div class="small text-body-secondary">Alertas hoy</div>
        <div class="fs-2 fw-bold <?= $alerts ? 'text-danger' : '' ?>"><?= count($alerts) ?></div><div class="small"><?= $problemDevices ? count($problemDevices) . ' celular(es) con problemas' : 'Celulares sin problemas' ?></div></div></div>
</div>

<h2 class="h5">En curso ahora</h2>
<div class="table-responsive mb-4"><table class="table table-hover align-middle">
    <thead class="small text-body-secondary"><tr><th>Guardia</th><th>Ruta</th><th>Empezó</th><th>Avance</th><th>Última posición</th><th>Ubicación</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($inProgress as $r): ?>
        <tr><td><?= e($r['user_name']) ?></td><td><?= $r['route_name'] ? e($r['route_name']) : '<span class="text-body-secondary">Ronda libre</span>' ?></td>
            <td><?= e(fecha($r['started_at'], 'H:i')) ?> <span class="small text-body-secondary">(<?= e($ago($r['started_at'])) ?>)</span></td>
            <td><?php if ($r['route_id'] && (int) $r['route_points']): $pct = (int) round(100 * (int) $r['scans_count'] / (int) $r['route_points']); ?>
                <div class="progress" style="height: 8px; min-width: 90px"><div class="progress-bar bg-warning" style="width: <?= min(100, $pct) ?>%"></div></div>
                <span class="small"><?= (int) $r['scans_count'] ?> de <?= (int) $r['route_points'] ?> puntos</span><?php else: ?><?= (int) $r['scans_count'] ?> punto(s)<?php endif; ?></td>
            <td class="small"><?= e($ago($r['last_track_at'])) ?></td>
            <td><?= (int) $r['outside_count'] ? '<span class="badge text-bg-danger">' . (int) $r['outside_count'] . ' fuera</span>' : '<span class="text-success">OK</span>' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/ronda/' . $r['uuid'])) ?>">Seguir en el mapa</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$inProgress): ?><tr><td colspan="7" class="text-body-secondary text-center py-3">No hay rondas en curso en este momento.</td></tr><?php endif; ?>
    </tbody>
</table></div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="d-flex justify-content-between align-items-center"><h2 class="h5">Programadas de hoy</h2><a class="small" href="<?= e(url('/panel/rondas/programacion')) ?>">Ver programación →</a></div>
        <ul class="list-group">
            <?php foreach ($slots as $s): [$label, $class] = PatrolPlanner::label($s); ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <span><strong><?= e(fecha($s['due_at'], 'H:i')) ?></strong> · <?= e($s['route_name']) ?><?= $s['user_name'] ? ' <span class="small text-body-secondary">· ' . e($s['user_name']) . '</span>' : '' ?></span>
                    <span class="badge text-bg-<?= e($class) ?>"><?= e($label) ?></span></li>
            <?php endforeach; ?>
            <?php if (!$slots): ?><li class="list-group-item text-body-secondary small">No hay rondas programadas para hoy. Se configuran en Programación.</li><?php endif; ?>
        </ul>
    </div>
    <div class="col-lg-6">
        <div class="d-flex justify-content-between align-items-center"><h2 class="h5">Alertas de hoy</h2><a class="small" href="<?= e(url('/panel/rondas/metricas')) ?>">Ver historial →</a></div>
        <ul class="list-group">
            <?php foreach (array_slice($alerts, 0, 8) as $a): ?>
                <li class="list-group-item">
                    <div class="d-flex justify-content-between gap-2"><span><span class="badge text-bg-<?= e($alertClass[$a['type']] ?? 'warning') ?>"><?= e(RoundMetrics::ALERT_LABELS[$a['type']] ?? $a['type']) ?></span>
                        <?= e($a['user']) ?> · <?= e($a['route']) ?></span><span class="small text-body-secondary text-nowrap"><?= e(fecha($a['at'], 'H:i')) ?></span></div>
                    <?php if ($a['detail'] || $a['resolution']): ?><div class="small <?= $a['resolution'] === 'SIN ATENDER' ? 'text-danger fw-bold' : 'text-body-secondary' ?>"><?= e(trim($a['detail'] . ' ' . $a['resolution'])) ?></div><?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php if (!$alerts): ?><li class="list-group-item text-body-secondary small">Sin alertas hoy.</li><?php endif; ?>
        </ul>
    </div>
</div>

<div class="d-flex justify-content-between align-items-center"><h2 class="h5">Últimas rondas</h2><a class="small" href="<?= e(url('/panel/rondas/historial')) ?>">Ver todas las rondas realizadas →</a></div>
<?= App\Core\View::render('panel/rounds/_rounds_table', ['rows' => $last, 'empty' => 'Todavía no se hizo ninguna ronda.'], null) ?>

<?php if ($devices): ?>
<h2 class="h5 mt-4">Celulares de los guardias <span class="small text-body-secondary fw-normal">(últimas 24 h)</span></h2>
<div class="table-responsive"><table class="table table-sm align-middle"><thead class="small text-body-secondary"><tr><th>Guardia</th><th>Último contacto</th><th>Batería</th><th>GPS</th><th>Sin enviar</th><th>App</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($devices as $dv): $st = $dv['status']; ?><tr>
    <td><?= e($dv['user_name']) ?><?= $dv['device_name'] ? '<div class="small text-body-secondary">' . e($dv['device_name']) . '</div>' : '' ?></td>
    <td><?= e(fecha($st['received_at'] ?? null, 'd/m H:i')) ?></td>
    <td><?= $st['battery'] !== null ? e($st['battery']) . ' %' . (!empty($st['charging']) ? ' ⚡' : '') : '—' ?></td>
    <td><?= !empty($st['permission']) ? (!empty($st['gps']) ? 'Encendido' : '<span class="text-danger">Apagado</span>') : '<span class="text-danger">Sin permiso</span>' ?></td>
    <td><?= (int) ($st['pending'] ?? 0) ?></td><td class="small"><?= e($st['app_version'] ?? '—') ?></td>
    <td><?php foreach ($dv['problems'] as $pr): ?><span class="badge text-bg-warning me-1"><?= e($pr) ?></span><?php endforeach; ?><?= !$dv['problems'] ? '<span class="text-success">OK</span>' : '' ?></td>
</tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
