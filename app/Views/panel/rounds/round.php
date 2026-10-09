<?php
/** @var array $round */
$badge = ['completa' => 'success', 'incompleta' => 'danger', 'en_curso' => 'warning'];
$dist = fn ($d) => $d === null ? 'sin GPS' : number_format((float) $d, 0, ',', '.') . ' m';
?>
<a class="btn btn-link px-0 mb-2" href="<?= e(url('/panel/rondas')) ?>">← Rondas</a>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h4 m-0"><?= e($round['route_name'] ?: 'Ronda libre') ?></h1>
    <span class="badge text-bg-<?= $badge[$round['status']] ?? 'secondary' ?>"><?= e(str_replace('_', ' ', $round['status'])) ?></span>
</div>
<p class="text-body-secondary">
    <?= e($round['user_name']) ?> · inicio <?= e(fecha($round['started_at'])) ?>
    <?= $round['finished_at'] ? ' · fin ' . e(fecha($round['finished_at'])) : '' ?>
</p>

<?php
$mapScans = array_values(array_filter(array_merge($round['route_points'] ?? [], $round['extra_scans'] ?? []), fn ($s) => ($s['lat'] ?? null) !== null && ($s['scanned_at_device'] ?? null) !== null));
?>
<?php if ($tracks || $mapScans): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
    <div id="track-map" style="height: 340px" class="rounded border mb-2"></div>
    <div class="small text-body-secondary mb-4">
        <?= $tracks ? count($tracks) . ' posición(es) del recorrido' . ($round['last_track_at'] ?? null ? ' · última ' . e(fecha($round['last_track_at'], 'H:i')) : '') : 'Sin recorrido registrado (solo los escaneos).' ?>
        · Línea = recorrido del celular · círculos = escaneos (verde en el lugar, rojo fuera de radio).
    </div>
    <script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script>
    <script>
    (function () {
        var tracks = <?= json_encode(array_map(fn ($t) => [(float) $t['lat'], (float) $t['lng']], $tracks)) ?>;
        var scans = <?= json_encode(array_map(fn ($s) => ['lat' => (float) $s['lat'], 'lng' => (float) $s['lng'], 'ok' => (int) ($s['within_radius'] ?? 0) === 1,
            'label' => ($s['code'] ?? $s['point_code'] ?? '') . ' · ' . ($s['name'] ?? $s['point_name'] ?? '')], $mapScans), JSON_UNESCAPED_UNICODE) ?>;
        var map = L.map('track-map', { scrollWheelZoom: false });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
        var bounds = [];
        if (tracks.length) { L.polyline(tracks, { color: '#353c4f', weight: 4, opacity: .8 }).addTo(map); bounds = bounds.concat(tracks);
            L.circleMarker(tracks[tracks.length - 1], { radius: 7, color: '#f2c014', fillOpacity: 1 }).addTo(map).bindTooltip('Última posición'); }
        scans.forEach(function (s) { var el = document.createElement('div'); el.textContent = s.label;
            L.circleMarker([s.lat, s.lng], { radius: 8, color: s.ok ? '#1e7b45' : '#dc3545', fillOpacity: .7 }).addTo(map).bindPopup(el); bounds.push([s.lat, s.lng]); });
        if (bounds.length) map.fitBounds(bounds, { padding: [20, 20], maxZoom: 18 });
    })();
    </script>
<?php endif; ?>

<?php if ($round['route_id'] !== null): ?>
    <?php $missing = count(array_filter($round['route_points'], fn ($p) => $p['scanned_at_device'] === null)); ?>
    <?php if ($missing > 0 && $round['status'] !== 'en_curso'): ?>
        <div class="alert alert-danger">Se saltearon <?= e($missing) ?> punto(s) de la ruta.</div>
    <?php endif; ?>
    <h2 class="h5">Recorrido de la ruta</h2>
    <div class="table-responsive mb-4"><table class="table table-sm align-middle">
        <thead><tr><th>#</th><th>Punto</th><th>Hora (celular)</th><th>Distancia</th><th>Resultado</th></tr></thead>
        <tbody>
        <?php foreach ($round['route_points'] as $i => $p): ?>
            <tr class="<?= $p['scanned_at_device'] === null ? 'table-danger' : '' ?>">
                <td><?= $i + 1 ?></td>
                <td><code><?= e($p['code']) ?></code> <?= e($p['name']) ?><?= $p['is_critical'] ? ' <span class="badge text-bg-danger">crítico</span>' : '' ?></td>
                <?php if ($p['scanned_at_device'] === null): ?>
                    <td colspan="3"><?= $round['status'] === 'en_curso' ? 'Pendiente' : '<strong>Salteado</strong>' ?></td>
                <?php else: ?>
                    <td><?= e(fecha($p['scanned_at_device'])) ?><?= $p['method'] ? ' <span class="badge text-bg-light">' . e(strtoupper($p['method'])) . '</span>' : '' ?></td>
                    <td><?= e($dist($p['distance_m'])) ?><?= $p['accuracy_m'] !== null ? ' <span class="small text-body-secondary">(±' . e((int) $p['accuracy_m']) . ' m)</span>' : '' ?></td>
                    <td><?= (int) $p['within_radius'] === 1 ? '<span class="text-success">En el lugar</span>' : '<span class="text-danger">Fuera de radio (' . e($p['radius_m']) . ' m)</span>' ?></td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php endif; ?>

<?php if ($round['extra_scans']): ?>
    <h2 class="h5"><?= $round['route_id'] !== null ? 'Puntos fuera de la ruta' : 'Puntos registrados' ?></h2>
    <div class="table-responsive"><table class="table table-sm align-middle">
        <thead><tr><th>Punto</th><th>Hora (celular)</th><th>Recibido</th><th>Distancia</th><th>Resultado</th></tr></thead>
        <tbody>
        <?php foreach ($round['extra_scans'] as $sc): ?>
            <tr>
                <td><code><?= e($sc['point_code']) ?></code> <?= e($sc['point_name']) ?></td>
                <td><?= e(fecha($sc['scanned_at_device'])) ?><?= ($sc['method'] ?? null) ? ' <span class="badge text-bg-light">' . e(strtoupper($sc['method'])) . '</span>' : '' ?></td>
                <td><?= e(fecha($sc['received_at'])) ?></td>
                <td><?= e($dist($sc['distance_m'])) ?></td>
                <td><?= (int) $sc['within_radius'] === 1 ? '<span class="text-success">En el lugar</span>' : '<span class="text-danger">Fuera de radio</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php elseif ($round['route_id'] === null): ?>
    <p class="text-body-secondary">Todavía no registró puntos.</p>
<?php endif; ?>
