<?php
/** @var array $route @var array $points @var array $guards @var array $schedules @var array $rounds @var bool $canEdit */
use App\Models\Patrols;
use App\Services\PatrolPlanner;
$mode = $route['order_mode'] ?? 'libre';
[$modeTitle, $modeHelp] = explode(': ', Patrols::ORDER_MODES[$mode] ?? Patrols::ORDER_MODES['libre'], 2);
$pts = array_map(fn ($p) => ['code' => $p['code'], 'name' => $p['name'], 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'radius' => (int) $p['radius_m'],
    'color' => $p['is_critical'] ? '#dc3545' : '#353c4f'], $points);
$badge = ['completa' => 'success', 'incompleta' => 'danger', 'en_curso' => 'warning'];
?>
<a class="btn btn-link px-0 mb-2" href="<?= e(url('/panel/rondas')) ?>">← Rondas</a>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
    <h1 class="h4 m-0"><?= e($route['name']) ?> <?= (int) $route['is_active'] === 1 ? '' : '<span class="badge text-bg-secondary">Inactiva</span>' ?></h1>
    <div class="d-flex gap-2">
        <?php if ($canEdit): ?>
            <a class="btn btn-outline-primary" href="<?= e(url('/panel/rondas/programacion/nueva')) ?>">Programar horarios</a>
            <a class="btn btn-primary" href="<?= e(url('/panel/rondas/rutas/' . $route['uuid'] . '/editar')) ?>">Editar ruta</a>
        <?php endif; ?>
    </div>
</div>
<p class="text-body-secondary mb-3">
    <?= count($points) ?> punto(s) · recorrido en línea recta <strong id="route-length">—</strong>
    <?= $route['expected_minutes'] ? ' · duración esperada ' . (int) $route['expected_minutes'] . ' min' : ' · sin duración esperada' ?>
    · orden <strong><?= e(mb_strtolower($modeTitle)) ?></strong> <span class="small">(<?= e($modeHelp) ?>)</span>
</p>
<?php if ($route['description']): ?><p><?= e($route['description']) ?></p><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <?php if ($pts): ?>
            <link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
            <div id="route-map" style="height: 520px" class="rounded border"></div>
            <div class="small text-body-secondary mt-1">Línea punteada = recorrido planificado, en el orden de la ruta (las flechas marcan el sentido). Cada círculo es el radio permitido del punto.
                <?= $mode === 'libre' ? 'Con orden libre el guardia puede hacerlo en cualquier orden.' : '' ?><?= $mode === 'azar' ? 'Con orden al azar, en cada ronda la app arma un orden distinto.' : '' ?></div>
            <script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script><script src="<?= e(asset('js/maps.js')) ?>"></script>
            <script>
            (() => {
                const pts = <?= json_encode($pts, JSON_UNESCAPED_UNICODE) ?>;
                const map = L.map('route-map'); SAMaps.base(map, 'satellite');
                pts.forEach(p => L.circle([p.lat, p.lng], { radius: p.radius, color: '#f2c014', weight: 1, fillOpacity: 0.12, interactive: false }).addTo(map));
                map.fitBounds(pts.map(p => [p.lat, p.lng]), { padding: [40, 40], maxZoom: 18 });
                SAMaps.route(map, pts, { line: <?= $mode === 'azar' ? 'false' : 'true' ?> });
                const m = SAMaps.length(pts);
                document.getElementById('route-length').textContent = <?= $mode === 'azar' ? "'varía (orden al azar)'" : "m >= 1000 ? (m / 1000).toFixed(1).replace('.', ',') + ' km' : Math.round(m) + ' m'" ?>;
            })();
            </script>
        <?php else: ?><div class="alert alert-warning">La ruta no tiene puntos.</div><?php endif; ?>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header"><strong>Puntos en orden</strong></div>
            <ol class="list-group list-group-flush list-group-numbered">
                <?php foreach ($points as $p): ?><li class="list-group-item"><code><?= e($p['code']) ?></code> · <?= e($p['name']) ?><?= $p['is_critical'] ? ' <span class="badge text-bg-danger">crítico</span>' : '' ?>
                    <div class="small text-body-secondary">radio <?= (int) $p['radius_m'] ?> m</div></li><?php endforeach; ?>
            </ol></div>
        <div class="card mb-3"><div class="card-header"><strong>Guardias asignados</strong></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($guards as $g): ?><li class="list-group-item"><?= e($g['name']) ?></li><?php endforeach; ?>
                <?php if (!$guards): ?><li class="list-group-item small text-danger">Sin guardias: nadie la ve en la app.</li><?php endif; ?>
            </ul></div>
        <div class="card mb-3"><div class="card-header"><strong>Programación</strong></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($schedules as $sc): ?><li class="list-group-item small"><?= e(PatrolPlanner::describe($sc)) ?><?= (int) $sc['is_active'] === 1 ? '' : ' <span class="badge text-bg-secondary">Pausada</span>' ?></li><?php endforeach; ?>
                <?php if (!$schedules): ?><li class="list-group-item small text-body-secondary">Sin horarios: se hace cuando el guardia la inicia.</li><?php endif; ?>
            </ul></div>
    </div>
</div>

<h2 class="h5 mt-4">Últimas rondas de esta ruta</h2>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>Inicio</th><th>Guardia</th><th>Estado</th><th>Puntos</th><th>Ubicación</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rounds as $r): ?>
        <tr><td><?= e(fecha($r['started_at'])) ?></td><td><?= e($r['user_name']) ?></td>
            <td><span class="badge text-bg-<?= $badge[$r['status']] ?? 'secondary' ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
            <td><?= e($r['scans_count']) ?> / <?= e($r['route_points']) ?></td>
            <td><?= (int) $r['outside_count'] > 0 ? '<span class="badge text-bg-danger">' . e($r['outside_count']) . ' fuera</span>' : '<span class="text-success">OK</span>' ?></td>
            <td><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/ronda/' . $r['uuid'])) ?>">Ver</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rounds): ?><tr><td colspan="6" class="text-body-secondary">Todavía no se hizo ninguna ronda de esta ruta.</td></tr><?php endif; ?>
    </tbody>
</table></div>
