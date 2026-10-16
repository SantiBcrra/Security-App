<?php
/** Área del predio: centro + radio sobre el mapa satelital. @var array $site @var array $points @var int $tolerance */
$lat = $site['lat'] !== null ? (float) $site['lat'] : null;
$lng = $site['lng'] !== null ? (float) $site['lng'] : null;
$radius = (int) ($site['geofence_radius_m'] ?: 200);
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'programacion'], null) ?>
<h2 class="h4 mb-1">Área del predio · <?= e($site['name']) ?></h2>
<p class="text-body-secondary small">Tocá el mapa en el centro del predio y ajustá el radio para que el círculo cubra todo el terreno. Durante el turno, si el guardia
    queda fuera del círculo más el margen (<?= (int) $tolerance ?> m, línea punteada), la app le pregunta el motivo. Los puntos amarillos son los puntos de ronda.</p>
<link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
<form method="post" action="<?= e(url('/panel/rondas/turnos/predio/' . $site['uuid'])) ?>" class="card card-body shadow-sm">
    <?= csrf_field() ?>
    <div id="site-map" style="height: 460px" class="rounded border mb-3"></div>
    <div class="row g-2 align-items-end">
        <div class="col-md-6"><label class="form-label">Radio: <strong id="radius-label"><?= $radius ?></strong> m</label>
            <input type="range" class="form-range" min="20" max="2000" step="10" name="radius" id="radius" value="<?= $radius ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Latitud</label><input class="form-control form-control-sm" name="lat" id="lat" value="<?= e($lat ?? '') ?>" readonly></div>
        <div class="col-6 col-md-2"><label class="form-label small">Longitud</label><input class="form-control form-control-sm" name="lng" id="lng" value="<?= e($lng ?? '') ?>" readonly></div>
        <div class="col-md-2"><button class="btn btn-primary w-100">Guardar</button></div>
    </div>
</form>
<script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script><script src="<?= e(asset('js/maps.js')) ?>"></script>
<script>
(function () {
    var pts = <?= json_encode(array_map(fn ($p) => [(float) $p['lat'], (float) $p['lng']], array_filter($points, fn ($p) => $p['lat'] !== null))) ?>;
    var center = <?= json_encode($lat !== null ? [$lat, $lng] : null) ?>;
    var tol = <?= (int) $tolerance ?>;
    var map = L.map('site-map', { doubleClickZoom: false });
    SAMaps.base(map, 'satellite');
    pts.forEach(function (p) { L.circleMarker(p, { radius: 5, color: '#f2c014', fillOpacity: 1 }).addTo(map); });
    if (center) map.setView(center, 17); else if (pts.length) map.fitBounds(pts, { padding: [40, 40], maxZoom: 17 }); else map.setView([-34.6, -58.4], 5);
    var radius = document.getElementById('radius'), circle = null, outer = null, marker = null;
    function draw() {
        document.getElementById('radius-label').textContent = radius.value;
        if (!center) return;
        var r = parseInt(radius.value, 10);
        if (!circle) { circle = L.circle(center, { radius: r, color: '#f2c014', weight: 2, fillOpacity: .12 }).addTo(map);
            outer = L.circle(center, { radius: r + tol, color: '#f2c014', weight: 1, dashArray: '4 6', fill: false }).addTo(map);
            marker = L.marker(center).addTo(map); }
        circle.setLatLng(center).setRadius(r); outer.setLatLng(center).setRadius(r + tol); marker.setLatLng(center);
        document.getElementById('lat').value = center[0].toFixed(7); document.getElementById('lng').value = center[1].toFixed(7);
    }
    map.on('click', function (e) { center = [e.latlng.lat, e.latlng.lng]; draw(); });
    radius.addEventListener('input', draw);
    draw();
})();
</script>
