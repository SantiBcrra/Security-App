<?php
/** Detalle de un turno. @var array $slot @var array $exits @var array $positions @var array $measure @var ?array $replaces @var ?array $absence */
use App\Services\ShiftAbsences;
use App\Services\ShiftReport;
use App\Services\ShiftService;
[$label, $color] = ShiftService::label($slot);
$dist = fn ($d) => $d === null ? '—' : ((float) $d >= 1000 ? number_format((float) $d / 1000, 1, ',', '.') . ' km' : (int) round((float) $d) . ' m');
$dur = function (?string $a, ?string $b): string {
    if (!$a) return '—';
    $m = intdiv(max(0, ($b ? strtotime($b . ' UTC') : time()) - strtotime($a . ' UTC')), 60);
    return $m >= 60 ? intdiv($m, 60) . ' h ' . ($m % 60) . ' min' : $m . ' min';
};
?>
<?= App\Core\View::render('panel/rounds/_nav', ['tab' => 'turnos', 'subtitle' => 'Detalle de un turno'], null) ?>
<a class="small d-inline-block mb-2" href="<?= e(url('/panel/rondas/turnos?fecha=' . App\Services\ShiftPlanner::localDay($slot['start_at']))) ?>">← Turnos del día</a>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h2 class="h4 m-0"><?= e($slot['user_name']) ?> · <?= e($slot['site_name']) ?></h2>
    <span class="badge text-bg-<?= e($color) ?> fs-6"><?= e($label) ?></span>
    <?= $slot['unscheduled'] ? '<span class="badge text-bg-light">no programado</span>' : '' ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="small text-body-secondary">Programado</div>
        <div class="fw-semibold"><?= $slot['unscheduled'] ? '—' : e(fecha($slot['start_at'], 'd/m H:i') . ' a ' . fecha($slot['end_at'], 'H:i')) ?></div>
        <div class="small text-body-secondary"><?= e($slot['shift_name'] ?? '') ?></div></div></div></div>
    <div class="col-md-3"><div class="card h-100"><div class="card-body py-2"><div class="small text-body-secondary">Real</div>
        <div class="fw-semibold"><?= $slot['started_at'] ? e(fecha($slot['started_at'], 'd/m H:i') . ' a ' . ($slot['ended_at'] ? fecha($slot['ended_at'], 'H:i') : 'en curso')) : 'No empezó' ?></div>
        <div class="small text-body-secondary"><?= $slot['end_mode'] === 'auto' ? 'No marcó el fin: se cerró solo' : '' ?></div></div></div></div>
    <div class="col-md-2"><div class="card h-100"><div class="card-body py-2"><div class="small text-body-secondary">Horas trabajadas</div>
        <div class="fs-5 fw-semibold"><?= e(ShiftReport::hours($measure['worked'])) ?></div></div></div></div>
    <div class="col-md-2"><div class="card h-100"><div class="card-body py-2"><div class="small text-body-secondary">Llegó tarde / se fue antes</div>
        <div class="fw-semibold"><?= $measure['late'] ? $measure['late'] . ' min' : '—' ?> / <?= $measure['early'] ? $measure['early'] . ' min' : '—' ?></div></div></div></div>
    <div class="col-md-2"><div class="card h-100 <?= $exits ? 'border-danger' : '' ?>"><div class="card-body py-2"><div class="small text-body-secondary">Fuera del predio</div>
        <div class="fw-semibold"><?= $exits ? count($exits) . ' salida(s) · ' . $measure['out'] . ' min' : 'Nunca salió' ?></div></div></div></div>
</div>

<?php if ($absence): ?>
    <div class="alert alert-info">Ausencia <?= e(ShiftAbsences::STATUS[$absence['status']][0]) ?>: <?= e(ShiftAbsences::TYPES[$absence['type']] ?? '') ?><?= $absence['reason'] ? ' — ' . e($absence['reason']) : '' ?>.
        <?= $absence['replacement_name'] ? 'Reemplazo: <strong>' . e($absence['replacement_name']) . '</strong>.' : 'Sin reemplazo.' ?></div>
<?php endif; ?>
<?php if ($replaces): ?>
    <div class="alert alert-light border">Reemplaza a <strong><?= e($replaces['user_name']) ?></strong> (<a href="<?= e(url('/panel/rondas/turno/' . $replaces['uuid'])) ?>">ver su turno</a>).</div>
<?php endif; ?>

<?php if ($slot['started_at'] && ($slot['site_lat'] !== null || $positions)): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
    <div id="shift-map" style="height: 380px" class="rounded border mb-2"></div>
    <div class="small text-body-secondary mb-4">Círculo amarillo = predio (radio <?= (int) ($slot['site_radius'] ?: 200) ?> m + tolerancia <?= ShiftService::toleranceM() ?> m) ·
        verde/rojo = dónde empezó el turno (en el predio / fuera) · líneas rojas = recorrido mientras estuvo fuera del predio. Solo se guarda la ubicación fuera del predio.</div>
    <script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script><script src="<?= e(asset('js/maps.js')) ?>"></script>
    <script>
    (function () {
        var site = <?= json_encode($slot['site_lat'] !== null ? ['lat' => (float) $slot['site_lat'], 'lng' => (float) $slot['site_lng'], 'r' => (int) ($slot['site_radius'] ?: 200), 't' => ShiftService::toleranceM()] : null) ?>;
        var start = <?= json_encode($slot['start_lat'] !== null ? ['lat' => (float) $slot['start_lat'], 'lng' => (float) $slot['start_lng'], 'in' => (int) $slot['start_inside'] === 1] : null) ?>;
        var paths = <?= json_encode(array_values(array_reduce($positions, function ($acc, $p) { $acc[$p['exit_uuid'] ?? '-'][] = [(float) $p['lat'], (float) $p['lng']]; return $acc; }, []))) ?>;
        var exits = <?= json_encode(array_map(fn ($e) => ['lat' => $e['lat'] !== null ? (float) $e['lat'] : null, 'lng' => $e['lng'] !== null ? (float) $e['lng'] : null,
            'label' => fecha($e['left_at'], 'H:i') . ' · ' . (ShiftService::REASONS[$e['reason']] ?? 'sin motivo')], $exits), JSON_UNESCAPED_UNICODE) ?>;
        var map = L.map('shift-map', { scrollWheelZoom: false });
        SAMaps.base(map, 'satellite');
        var bounds = [];
        if (site) { L.circle([site.lat, site.lng], { radius: site.r, color: '#f2c014', weight: 2, fillOpacity: .08 }).addTo(map);
            L.circle([site.lat, site.lng], { radius: site.r + site.t, color: '#f2c014', weight: 1, dashArray: '4 6', fill: false }).addTo(map);
            bounds.push([site.lat, site.lng]); map.setView([site.lat, site.lng], 16); }
        if (start) { L.circleMarker([start.lat, start.lng], { radius: 8, color: start.in ? '#1e7b45' : '#dc3545', fillOpacity: .9 }).addTo(map).bindTooltip('Empezó el turno'); bounds.push([start.lat, start.lng]); }
        paths.forEach(function (p) { L.polyline(p, { color: '#dc3545', weight: 4, opacity: .85 }).addTo(map); bounds = bounds.concat(p); });
        exits.forEach(function (e) { if (e.lat === null) return; var el = document.createElement('div'); el.textContent = 'Salió ' + e.label;
            L.circleMarker([e.lat, e.lng], { radius: 6, color: '#dc3545', fillOpacity: 1 }).addTo(map).bindPopup(el); bounds.push([e.lat, e.lng]); });
        if (bounds.length > 1) map.fitBounds(bounds, { padding: [30, 30], maxZoom: 17 });
    })();
    </script>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-header fw-semibold">Línea de tiempo</div>
    <ul class="list-group list-group-flush">
        <?php if (!$slot['unscheduled']): ?><li class="list-group-item"><span class="text-body-secondary"><?= e(fecha($slot['start_at'], 'd/m H:i')) ?></span> · Inicio programado (tolerancia <?= (int) $slot['grace_minutes'] ?> min)</li><?php endif; ?>
        <?php if ($slot['started_at']): ?>
            <li class="list-group-item"><span class="text-body-secondary"><?= e(fecha($slot['started_at'], 'd/m H:i')) ?></span> · <strong>Empezó el turno</strong>
                <?= $slot['start_inside'] === null ? '(sin GPS)' : ((int) $slot['start_inside'] === 1 ? 'en el predio' : '<span class="badge text-bg-danger">a ' . e($dist($slot['start_distance_m'])) . ' del predio</span>') ?>
                <?= (int) $slot['start_mock'] === 1 ? '<span class="badge text-bg-danger">GPS falso</span>' : '' ?>
                <?= $slot['start_accuracy_m'] !== null ? '<span class="small text-body-secondary">(±' . (int) $slot['start_accuracy_m'] . ' m)</span>' : '' ?></li>
        <?php endif; ?>
        <?php foreach ($exits as $e): ?>
            <li class="list-group-item list-group-item-<?= $e['reason'] ? 'warning' : 'danger' ?>">
                <span class="text-body-secondary"><?= e(fecha($e['left_at'], 'H:i')) ?></span> · <strong>Salió del predio</strong>
                <?= (int) $e['announced'] === 1 ? '<span class="badge text-bg-light">avisó antes de salir</span>' : '' ?>
                · Motivo: <?= $e['reason'] ? e(ShiftService::REASONS[$e['reason']] ?? $e['reason']) . ($e['note'] ? ' — ' . e($e['note']) : '') : '<strong>sin motivo</strong>' ?>
                · hasta <?= e($dist($e['max_distance_m'])) ?> del predio
                · <?= $e['returned_at'] ? 'volvió a las ' . e(fecha($e['returned_at'], 'H:i')) . ' (' . e($dur($e['left_at'], $e['returned_at'])) . ')' : '<strong>todavía afuera</strong> (' . e($dur($e['left_at'], null)) . ')' ?>
            </li>
        <?php endforeach; ?>
        <?php if ($slot['ended_at']): ?>
            <li class="list-group-item"><span class="text-body-secondary"><?= e(fecha($slot['ended_at'], 'd/m H:i')) ?></span> · <strong>Terminó el turno</strong><?= $slot['end_mode'] === 'auto' ? ' (no lo marcó: se cerró solo con la hora programada)' : '' ?></li>
        <?php elseif ($slot['status'] === 'en_curso'): ?>
            <li class="list-group-item">Último contacto del celular: <?= $slot['last_contact_at'] ? e(fecha($slot['last_contact_at'], 'd/m H:i')) : '—' ?></li>
        <?php endif; ?>
        <?php if (!$slot['unscheduled']): ?><li class="list-group-item"><span class="text-body-secondary"><?= e(fecha($slot['end_at'], 'd/m H:i')) ?></span> · Fin programado</li><?php endif; ?>
    </ul>
</div>
