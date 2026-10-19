<?php $old = $old ?? []; ?><?= App\Core\View::render('panel/rounds/_nav', ['tab' => 'puntos', 'subtitle' => ''], null) ?>
<?php $point = $point ?? null; ?>
<h2 class="h4 mb-1"><?= $point ? 'Editar punto ' . e($point['code']) : 'Nuevo punto de ronda' ?></h2>
<?php if ($point): ?><p class="text-body-secondary small mb-3">El QR y la etiqueta NFC no cambian: los que están pegados siguen funcionando aunque cambies el nombre, el código, la ubicación o el radio.</p><?php else: ?><div class="mb-3"></div><?php endif; ?>
<form method="post" action="<?= e(url('/panel/rondas/puntos' . ($point ? '/' . $point['uuid'] : ''))) ?>" class="card card-body" style="max-width:680px"><?= csrf_field() ?><div class="row g-3">
<div class="col-md-8"><label class="form-label">Nombre</label><input class="form-control" name="name" required placeholder="Portón principal" value="<?= e($old['name'] ?? '') ?>"></div><div class="col-md-4"><label class="form-label">Código</label><input class="form-control" name="code" required placeholder="P-001" value="<?= e($old['code'] ?? '') ?>"></div>
<div class="col-12"><label class="form-label">Ubicación</label>
<div class="d-flex flex-wrap gap-2 align-items-center mb-2"><span class="small text-body-secondary">Hacé clic en el mapa donde va el punto (o arrastrá el pin).</span><button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="pt-here">Usar mi ubicación</button></div>
<div id="point-map" style="height:380px" class="rounded border"></div>
<div class="form-text">El círculo es el radio permitido: una marca dentro del círculo cuenta como "en el lugar". Después se puede ajustar desde la app con "Tomar GPS", parado en el punto.</div></div>
<div class="col-md-6"><label class="form-label">Latitud</label><input class="form-control" id="pt-lat" name="lat" required type="text" inputmode="decimal" placeholder="-34.6037" value="<?= e($old['lat'] ?? '') ?>"></div><div class="col-md-6"><label class="form-label">Longitud</label><input class="form-control" id="pt-lng" name="lng" required type="text" inputmode="decimal" placeholder="-58.3816" value="<?= e($old['lng'] ?? '') ?>"></div>
<div class="col-md-4"><label class="form-label">Radio permitido (m)</label><input class="form-control" id="pt-radius" name="radius_m" type="number" min="5" value="<?= e($old['radius_m'] ?? '50') ?>"></div><div class="col-md-4"><label class="form-label">Planta</label><select class="form-select" name="site"><option value="">—</option><?php foreach ($sites as $u=>$n): ?><option value="<?= e($u) ?>" <?= ($old['site'] ?? '') === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label">Sector</label><select class="form-select" name="sector"><option value="">—</option><?php foreach ($sectors as $u=>$n): ?><option value="<?= e($u) ?>" <?= ($old['sector'] ?? '') === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
<div class="col-12"><label class="form-label">Descripción</label><textarea class="form-control" name="description" rows="2"><?= e($old['description'] ?? '') ?></textarea></div><div class="col-12 form-check ms-2"><input class="form-check-input" type="checkbox" name="is_critical" id="critical" <?= !empty($old['is_critical']) ? 'checked' : '' ?>><label class="form-check-label" for="critical">Punto crítico</label></div>
</div><div class="mt-3 d-flex gap-2"><button class="btn btn-primary"><?= $point ? 'Guardar cambios' : 'Guardar punto' ?></button><a class="btn btn-outline-secondary" href="<?= e(url('/panel/rondas/puntos')) ?>">Cancelar</a></div></form>

<link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
<script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script><script src="<?= e(asset('js/maps.js')) ?>"></script>
<script>
(() => {
    const others = <?= json_encode(array_map(fn ($p) => ['code' => $p['code'], 'name' => $p['name'], 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'radius' => (int) $p['radius_m']],
        $points ?? []), JSON_UNESCAPED_UNICODE) ?>;
    const lat = document.getElementById('pt-lat'), lng = document.getElementById('pt-lng'), radius = document.getElementById('pt-radius');
    const map = L.map('point-map'); SAMaps.base(map, 'satellite');
    // Los puntos que ya existen, de referencia (gris)
    others.forEach(p => { L.circle([p.lat, p.lng], { radius: p.radius, color: '#adb5bd', weight: 1, fillOpacity: 0.1 }).addTo(map)
        .bindTooltip(p.code + ' · ' + p.name); });
    let marker = null, circle = null;
    const num = (v) => parseFloat(String(v).replace(',', '.'));
    function place(la, ln, fromInputs) {
        if (!isFinite(la) || !isFinite(ln)) return;
        if (!marker) {
            marker = L.marker([la, ln], { draggable: true }).addTo(map);
            circle = L.circle([la, ln], { radius: num(radius.value) || 50, color: '#f2c014', weight: 2, fillOpacity: 0.2 }).addTo(map);
            marker.on('drag', (e) => place(e.latlng.lat, e.latlng.lng));
        }
        marker.setLatLng([la, ln]); circle.setLatLng([la, ln]);
        if (!fromInputs) { lat.value = la.toFixed(7); lng.value = ln.toFixed(7); }
    }
    map.on('click', (e) => place(e.latlng.lat, e.latlng.lng));
    [lat, lng].forEach(i => i.addEventListener('change', () => { place(num(lat.value), num(lng.value), true); if (marker) map.setView(marker.getLatLng(), Math.max(map.getZoom(), 18)); }));
    radius.addEventListener('input', () => circle && circle.setRadius(num(radius.value) || 50));
    document.getElementById('pt-here').addEventListener('click', () => {
        if (!navigator.geolocation) return alert('Este navegador no da la ubicación.');
        navigator.geolocation.getCurrentPosition((p) => { place(p.coords.latitude, p.coords.longitude); map.setView([p.coords.latitude, p.coords.longitude], 19); },
            () => alert('No se pudo obtener tu ubicación (permiso denegado o sin GPS). Ubicá el punto con un clic en el mapa.'), { enableHighAccuracy: true, timeout: 10000 });
    });
    // Vista inicial: el punto cargado, si no los existentes, si no Argentina.
    if (isFinite(num(lat.value)) && isFinite(num(lng.value))) { place(num(lat.value), num(lng.value), true); map.setView([num(lat.value), num(lng.value)], 18); }
    else if (others.length) map.fitBounds(others.map(p => [p.lat, p.lng]), { padding: [40, 40], maxZoom: 18 });
    else map.setView([-34.6, -64.2], 4);
})();
</script>
