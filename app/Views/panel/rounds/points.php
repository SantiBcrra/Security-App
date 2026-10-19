<?php
/** Pestaña "Puntos": mapa satelital (doble clic crea un punto) y tabla con QR y eliminar. @var array $points @var bool $canCreate @var bool $canEdit */
?>
<?= App\Core\View::render('panel/rounds/_nav', ['tab' => 'puntos', 'subtitle' => 'Los lugares de control: cada uno con su QR (o etiqueta NFC), ubicación y radio permitido'], null) ?>
<div class="small text-body-secondary mb-2">Imprimí el QR de cada punto y pegalo en el lugar. Al marcar, la app registra hora, GPS y distancia al punto.</div>
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
<?php foreach ($points as $p): ?><tr><td><code><?= e($p['code']) ?></code></td><td><?= e($p['name']) ?><?= $p['is_critical'] ? ' ⚠' : '' ?></td><td><?= e($p['sector_name'] ?: $p['site_name'] ?: '—') ?></td><td><?= e($p['radius_m']) ?> m</td><td><a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= e(url('/panel/rondas/puntos/' . $p['uuid'] . '/qr')) ?>">Imprimir QR</a><?php if (!empty($canEdit)): ?> <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/panel/rondas/puntos/' . $p['uuid'] . '/editar')) ?>">Editar</a> <form method="post" action="<?= e(url('/panel/rondas/puntos/' . $p['uuid'] . '/eliminar')) ?>" class="d-inline" onsubmit="return confirm('¿Eliminar el punto <?= e($p['code']) ?> · <?= e($p['name']) ?>? Se saca del mapa, de las rutas y de los celulares. Las rondas anteriores conservan sus marcas.')"><?= csrf_field() ?><button class="btn btn-outline-danger btn-sm">Eliminar</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$points): ?><tr><td colspan="5" class="text-body-secondary">Todavía no hay puntos.</td></tr><?php endif; ?></tbody></table></div>
