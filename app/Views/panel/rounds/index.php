<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 m-0">Rondas de guardias</h1><div class="d-flex gap-2"><a class="btn btn-outline-primary" href="<?= e(url('/panel/rondas/metricas')) ?>">Métricas</a><a class="btn btn-outline-primary" href="<?= e(url('/panel/rondas/programacion')) ?>">Programación</a><a class="btn btn-outline-secondary" href="<?= e(url('/panel/rondas/marcas-fuera')) ?>">Marcas fuera de lugar</a><a class="btn btn-outline-primary" href="<?= e(url('/panel/rondas/rutas/nueva')) ?>">+ Nueva ruta</a><a class="btn btn-primary" href="<?= e(url('/panel/rondas/puntos/nuevo')) ?>">+ Nuevo punto</a></div></div>
<?php $openPanics = array_filter($panics ?? [], fn ($x) => $x['acked_at'] === null); ?>
<?php foreach ($openPanics as $pa): ?>
    <a class="alert alert-danger d-flex justify-content-between align-items-center text-decoration-none" href="<?= e(url('/panel/rondas/panico/' . $pa['uuid'])) ?>">
        <span><strong>⚠ PÁNICO SIN ATENDER</strong> · <?= e($pa['user_name']) ?> · <?= e(fecha($pa['triggered_at_device'], 'd/m H:i')) ?><?= $pa['route_name'] ? ' · ' . e($pa['route_name']) : '' ?></span>
        <span class="btn btn-sm btn-danger">Ver y atender</span>
    </a>
<?php endforeach; ?>
<div class="alert alert-info">Cada punto tiene un QR único. Imprimilo y colocálo en el lugar de control. La app Android registra hora, GPS y distancia.</div>
<h2 class="h5">Puntos de ronda</h2>
<?php if ($points || !empty($canCreate)): ?><link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>"><?php if (!empty($canCreate)): ?><div class="small text-body-secondary mb-1">Doble clic en el mapa para crear un punto en ese lugar.</div><?php endif; ?><div id="round-map" style="height:420px" class="rounded border mb-3"></div><script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script><script src="<?= e(asset('js/maps.js')) ?>"></script><script>
const roundPoints=<?= json_encode(array_map(fn($p)=>['name'=>$p['name'],'code'=>$p['code'],'lat'=>(float)$p['lat'],'lng'=>(float)$p['lng'],'radius'=>(int)$p['radius_m'],'critical'=>(bool)$p['is_critical']],$points),JSON_UNESCAPED_UNICODE) ?>;
const canCreate=<?= !empty($canCreate) ? 'true' : 'false' ?>, newUrl=<?= json_encode(url('/panel/rondas/puntos/nuevo')) ?>;
const map=L.map('round-map',{doubleClickZoom:!canCreate}); SAMaps.base(map, 'satellite'); const bounds=[];
if(canCreate){ map.on('dblclick',e=>{ const lat=e.latlng.lat.toFixed(7), lng=e.latlng.lng.toFixed(7);
  const box=document.createElement('div'); box.innerHTML='<div class="small mb-1">'+lat+', '+lng+'</div>';
  const a=document.createElement('a'); a.className='btn btn-sm btn-primary text-white'; a.textContent='Crear punto acá'; a.href=newUrl+'?lat='+lat+'&lng='+lng; box.appendChild(a);
  L.popup().setLatLng(e.latlng).setContent(box).openOn(map); }); }
roundPoints.forEach(p=>{const el=document.createElement('div');el.textContent=p.code+' · '+p.name+' (radio '+p.radius+' m)';
  L.circle([p.lat,p.lng],{radius:p.radius,color:p.critical?'#dc3545':'#f2c014',weight:2,fillOpacity:0.15}).addTo(map);
  L.marker([p.lat,p.lng]).addTo(map).bindPopup(el).bindTooltip(p.code,{permanent:true,direction:'top',offset:[0,-30]});bounds.push([p.lat,p.lng]);});
if(bounds.length) map.fitBounds(bounds,{padding:[30,30],maxZoom:18}); else map.setView([-34.6,-64.2],4);
</script><?php endif; ?>
<div class="table-responsive mb-4"><table class="table table-sm align-middle"><thead><tr><th>Código</th><th>Nombre</th><th>Ubicación</th><th>Radio</th><th></th></tr></thead><tbody>
<?php foreach ($points as $p): ?><tr><td><code><?= e($p['code']) ?></code></td><td><?= e($p['name']) ?><?= $p['is_critical'] ? ' ⚠' : '' ?></td><td><?= e($p['sector_name'] ?: $p['site_name'] ?: '—') ?></td><td><?= e($p['radius_m']) ?> m</td><td><a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= e(url('/panel/rondas/puntos/' . $p['uuid'] . '/qr')) ?>">Imprimir QR</a><?php if (!empty($canEdit)): ?> <form method="post" action="<?= e(url('/panel/rondas/puntos/' . $p['uuid'] . '/eliminar')) ?>" class="d-inline" onsubmit="return confirm('¿Eliminar el punto <?= e($p['code']) ?> · <?= e($p['name']) ?>? Se saca del mapa, de las rutas y de los celulares. Las rondas anteriores conservan sus marcas.')"><?= csrf_field() ?><button class="btn btn-outline-danger btn-sm">Eliminar</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$points): ?><tr><td colspan="5" class="text-body-secondary">Todavía no hay puntos.</td></tr><?php endif; ?></tbody></table></div>
<h2 class="h5">Rutas configuradas</h2><div class="table-responsive mb-4"><table class="table table-sm align-middle"><thead><tr><th>Ruta</th><th>Orden</th><th>Puntos</th><th>Duración esperada</th><th></th></tr></thead><tbody><?php foreach ($routes as $r): ?><tr><td><a href="<?= e(url('/panel/rondas/rutas/' . $r['uuid'])) ?>"><?= e($r['name']) ?></a><?= (int) $r['is_active'] === 1 ? '' : ' <span class="badge text-bg-secondary">Inactiva</span>' ?></td><td><?= e(['libre' => 'Libre', 'secuencial' => 'Secuencial', 'azar' => 'Al azar'][$r['order_mode'] ?? 'libre'] ?? 'Libre') ?></td><td><?= e($r['point_count']) ?></td><td><?= $r['expected_minutes'] ? e($r['expected_minutes']) . ' min' : '—' ?></td><td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/rutas/' . $r['uuid'])) ?>">Ver en el mapa</a><?php if (!empty($canEdit)): ?> <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/panel/rondas/rutas/' . $r['uuid'] . '/editar')) ?>">Editar</a><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$routes): ?><tr><td colspan="5" class="text-body-secondary">No hay rutas creadas.</td></tr><?php endif; ?></tbody></table></div>
<?php if (!empty($devices)): ?>
<h2 class="h5">Celulares de los guardias <span class="small text-body-secondary fw-normal">(últimas 24 h)</span></h2>
<div class="table-responsive mb-4"><table class="table table-sm align-middle"><thead><tr><th>Guardia</th><th>Último contacto</th><th>Batería</th><th>GPS</th><th>Sin enviar</th><th>App</th><th>Estado</th></tr></thead><tbody>
<?php foreach ($devices as $dv): $st = $dv['status']; ?><tr>
    <td><?= e($dv['user_name']) ?><?= $dv['device_name'] ? '<div class="small text-body-secondary">' . e($dv['device_name']) . '</div>' : '' ?></td>
    <td><?= e(fecha($st['received_at'] ?? null, 'd/m H:i')) ?></td>
    <td><?= $st['battery'] !== null ? e($st['battery']) . ' %' . (!empty($st['charging']) ? ' ⚡' : '') : '—' ?></td>
    <td><?= !empty($st['permission']) ? (!empty($st['gps']) ? 'Encendido' : '<span class="text-danger">Apagado</span>') : '<span class="text-danger">Sin permiso</span>' ?></td>
    <td><?= (int) ($st['pending'] ?? 0) ?></td><td class="small"><?= e($st['app_version'] ?? '—') ?></td>
    <td><?php foreach ($dv['problems'] as $pr): ?><span class="badge text-bg-warning me-1"><?= e($pr) ?></span><?php endforeach; ?><?= !$dv['problems'] ? '<span class="text-success">OK</span>' : '' ?></td>
</tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
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
