<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 m-0">Rondas de guardias</h1><div class="d-flex gap-2"><a class="btn btn-outline-primary" href="<?= e(url('/panel/rondas/rutas/nueva')) ?>">+ Nueva ruta</a><a class="btn btn-primary" href="<?= e(url('/panel/rondas/puntos/nuevo')) ?>">+ Nuevo punto</a></div></div>
<?php $openPanics = array_filter($panics ?? [], fn ($x) => $x['acked_at'] === null); ?>
<?php foreach ($openPanics as $pa): ?>
    <a class="alert alert-danger d-flex justify-content-between align-items-center text-decoration-none" href="<?= e(url('/panel/rondas/panico/' . $pa['uuid'])) ?>">
        <span><strong>⚠ PÁNICO SIN ATENDER</strong> · <?= e($pa['user_name']) ?> · <?= e(fecha($pa['triggered_at_device'], 'd/m H:i')) ?><?= $pa['route_name'] ? ' · ' . e($pa['route_name']) : '' ?></span>
        <span class="btn btn-sm btn-danger">Ver y atender</span>
    </a>
<?php endforeach; ?>
<div class="alert alert-info">Cada punto tiene un QR único. Imprimilo y colocálo en el lugar de control. La app Android registra hora, GPS y distancia.</div>
<h2 class="h5">Puntos de ronda</h2>
<?php if ($points): ?><link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>"><div id="round-map" style="height:280px" class="rounded border mb-3"></div><script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script><script>
const roundPoints=<?= json_encode(array_map(fn($p)=>['name'=>$p['name'],'code'=>$p['code'],'lat'=>(float)$p['lat'],'lng'=>(float)$p['lng']],$points),JSON_UNESCAPED_UNICODE) ?>;
const map=L.map('round-map'); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map); const bounds=[]; roundPoints.forEach(p=>{const el=document.createElement('div');el.textContent=p.code+' · '+p.name;L.marker([p.lat,p.lng]).addTo(map).bindPopup(el);bounds.push([p.lat,p.lng]);}); map.fitBounds(bounds,{padding:[20,20]});
</script><?php endif; ?>
<div class="table-responsive mb-4"><table class="table table-sm align-middle"><thead><tr><th>Código</th><th>Nombre</th><th>Ubicación</th><th>Radio</th><th></th></tr></thead><tbody>
<?php foreach ($points as $p): ?><tr><td><code><?= e($p['code']) ?></code></td><td><?= e($p['name']) ?><?= $p['is_critical'] ? ' ⚠' : '' ?></td><td><?= e($p['sector_name'] ?: $p['site_name'] ?: '—') ?></td><td><?= e($p['radius_m']) ?> m</td><td><a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= e(url('/panel/rondas/puntos/' . $p['uuid'] . '/qr')) ?>">Imprimir QR</a></td></tr><?php endforeach; ?>
<?php if (!$points): ?><tr><td colspan="5" class="text-body-secondary">Todavía no hay puntos.</td></tr><?php endif; ?></tbody></table></div>
<h2 class="h5">Rutas configuradas</h2><div class="table-responsive mb-4"><table class="table table-sm"><thead><tr><th>Ruta</th><th>Frecuencia</th><th>Puntos</th></tr></thead><tbody><?php foreach ($routes as $r): ?><tr><td><?= e($r['name']) ?></td><td><?= e($r['frequency']) ?></td><td><?= e($r['point_count']) ?></td></tr><?php endforeach; ?><?php if (!$routes): ?><tr><td colspan="3" class="text-body-secondary">No hay rutas creadas.</td></tr><?php endif; ?></tbody></table></div>
<h2 class="h5">Rondas recientes</h2><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Inicio</th><th>Guardia</th><th>Ruta</th><th>Estado</th><th>Puntos</th><th>Ubicación</th><th></th></tr></thead><tbody>
<?php $badge = ['completa' => 'success', 'incompleta' => 'danger', 'en_curso' => 'warning']; ?>
<?php foreach ($rounds as $r): ?><tr><td><?= e(fecha($r['started_at'])) ?></td><td><?= e($r['user_name']) ?></td><td><?= e($r['route_name'] ?: 'Libre') ?></td><td><span class="badge text-bg-<?= $badge[$r['status']] ?? 'secondary' ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td><td><?= e($r['scans_count']) ?><?= $r['route_id'] !== null ? ' / ' . e($r['route_points']) : '' ?></td><td><?= (int) $r['outside_count'] > 0 ? '<span class="badge text-bg-danger" title="Marcas lejos del punto o con GPS falso">' . e($r['outside_count']) . ' fuera</span>' : '' ?><?= (int) ($r['doubtful_count'] ?? 0) > 0 ? ' <span class="badge text-bg-warning" title="GPS impreciso o sin GPS">' . e($r['doubtful_count']) . ' dudosa(s)</span>' : '' ?><?= (int) $r['outside_count'] === 0 && (int) ($r['doubtful_count'] ?? 0) === 0 ? '<span class="text-success">OK</span>' : '' ?></td><td><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/rondas/ronda/' . $r['uuid'])) ?>">Ver</a></td></tr><?php endforeach; ?>
<?php if (!$rounds): ?><tr><td colspan="7" class="text-body-secondary">No hay rondas registradas todavía.</td></tr><?php endif; ?></tbody></table></div>

<?php if (!empty($panics)): ?>
<h2 class="h5 mt-4">Alertas de pánico</h2>
<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Hora</th><th>Guardia</th><th>Ronda</th><th>Llegó por</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach ($panics as $pa): ?><tr class="<?= $pa['acked_at'] === null ? 'table-danger' : '' ?>"><td><?= e(fecha($pa['triggered_at_device'], 'd/m H:i')) ?></td><td><?= e($pa['user_name']) ?></td><td><?= e($pa['route_name'] ?: ($pa['round_uuid'] ? 'Libre' : '—')) ?></td>
<td><?= e($pa['via'] === 'cola' ? 'datos (demorado)' : 'datos') ?><?= (int) $pa['sms_sent'] ? ' + SMS' : '' ?></td>
<td><?= $pa['acked_at'] === null ? '<strong class="text-danger">Sin atender</strong>' : 'Atendida por ' . e($pa['acked_name']) ?></td>
<td><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/rondas/panico/' . $pa['uuid'])) ?>">Ver</a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
