<?php
use App\Models\Observations;
use App\Services\ObservationWorkflow;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$query = fn (array $over) => '?' . http_build_query(array_filter(array_merge($form, $over), fn ($v) => $v !== '' && $v !== null));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0">Observaciones</h1>
    <div class="d-flex gap-2">
        <div class="btn-group btn-group-sm">
            <a class="btn btn-outline-secondary <?= $map ? '' : 'active' ?>" href="<?= e($query(['vista' => ''])) ?>">Lista</a>
            <a class="btn btn-outline-secondary <?= $map ? 'active' : '' ?>" href="<?= e($query(['vista' => 'mapa'])) ?>">Mapa</a>
        </div>
        <?php if (UserAuth::can('observaciones', 'crear')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/panel/observaciones/nueva')) ?>">+ Nueva observación</a>
        <?php endif; ?>
    </div>
</div>

<ul class="nav nav-pills small mb-3 flex-nowrap overflow-auto">
    <?php $pend = ($byStatus['abierta'] ?? 0) + ($byStatus['en_analisis'] ?? 0) + ($byStatus['accion_asignada'] ?? 0); ?>
    <li class="nav-item"><a class="nav-link py-1 <?= $form['estado'] === '' ? 'active' : '' ?>" href="<?= e($query(['estado' => ''])) ?>">Todas <span class="badge text-bg-light"><?= e(array_sum($byStatus)) ?></span></a></li>
    <li class="nav-item"><a class="nav-link py-1 <?= $form['estado'] === 'pendientes' ? 'active' : '' ?>" href="<?= e($query(['estado' => 'pendientes'])) ?>">Pendientes <span class="badge text-bg-light"><?= e($pend) ?></span></a></li>
    <?php foreach (ObservationWorkflow::STATES as $key => $s): ?>
        <li class="nav-item"><a class="nav-link py-1 <?= $form['estado'] === $key ? 'active' : '' ?>" href="<?= e($query(['estado' => $key])) ?>"><?= e($s['label']) ?> <span class="badge text-bg-light"><?= e($byStatus[$key] ?? 0) ?></span></a></li>
    <?php endforeach; ?>
</ul>

<form class="row g-2 align-items-end mb-3" method="get">
    <input type="hidden" name="estado" value="<?= e($form['estado']) ?>">
    <?php if ($map): ?><input type="hidden" name="vista" value="mapa"><?php endif; ?>
    <div class="col-12 col-md-3"><input class="form-control form-control-sm" type="search" name="q" value="<?= e($form['q']) ?>" placeholder="Buscar texto o número…"></div>
    <div class="col-6 col-md-2">
        <select class="form-select form-select-sm" name="severidad"><option value="">Severidad</option>
            <?php foreach ($options['severities'] as $s): ?><option value="<?= e($s['uuid']) ?>" <?= $form['severidad'] === $s['uuid'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <select class="form-select form-select-sm" name="categoria"><option value="">Categoría</option>
            <?php foreach ($options['categories'] as $u => $n): ?><option value="<?= e($u) ?>" <?= $form['categoria'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <select class="form-select form-select-sm" name="sector"><option value="">Sector (incluye los de abajo)</option>
            <?php foreach ($options['sectors'] as $u => $n): ?><option value="<?= e($u) ?>" <?= $form['sector'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <select class="form-select form-select-sm" name="riesgo"><option value="">Tipo de riesgo</option>
            <?php foreach ($options['risks'] as $u => $n): ?><option value="<?= e($u) ?>" <?= $form['riesgo'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2"><label class="small text-body-secondary">Desde</label><input class="form-control form-control-sm" type="date" name="desde" value="<?= e($form['desde']) ?>"></div>
    <div class="col-6 col-md-2"><label class="small text-body-secondary">Hasta</label><input class="form-control form-control-sm" type="date" name="hasta" value="<?= e($form['hasta']) ?>"></div>
    <div class="col-auto form-check ms-2"><input class="form-check-input" type="checkbox" name="inminente" value="1" id="f_inm" <?= $form['inminente'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="f_inm">Solo riesgo inminente</label></div>
    <?php if (UserAuth::user()): ?>
        <div class="col-auto form-check"><input class="form-check-input" type="checkbox" name="asignadas" value="1" id="f_asg" <?= $form['asignadas'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="f_asg">Asignadas a mí</label></div>
    <?php endif; ?>
    <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Filtrar</button> <a class="btn btn-sm btn-link" href="?">Limpiar</a></div>
</form>

<?php if ($map): ?>
    <link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
    <script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script>
    <div class="card shadow-sm">
        <?php if (!$rows): ?>
            <p class="text-body-secondary text-center py-5 mb-0">Ninguna observación con ubicación GPS para estos filtros.</p>
        <?php else: ?>
            <div id="obs-map" style="height: 560px" class="rounded"></div>
            <script>
            (function () {
                var points = <?= json_encode(array_map(fn ($r) => [
                    'lat' => (float) $r['lat'], 'lng' => (float) $r['lng'], 'color' => $r['severity_color'] ?: '#6c757d',
                    'title' => Observations::format((int) $r['number']) . ' · ' . ($r['severity_name'] ?? ''),
                    'text' => mb_strimwidth($r['description'], 0, 140, '…'), 'url' => url('/panel/observaciones/' . $r['uuid']),
                    'imminent' => (bool) $r['imminent_risk'],
                ], $rows), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
                var map = L.map('obs-map');
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
                var bounds = [];
                points.forEach(function (p) {
                    var m = L.circleMarker([p.lat, p.lng], { radius: p.imminent ? 11 : 8, color: p.imminent ? '#000' : '#fff', weight: 2, fillColor: p.color, fillOpacity: .9 }).addTo(map);
                    var div = document.createElement('div');
                    var a = document.createElement('a'); a.href = p.url; a.textContent = p.title; a.className = 'fw-semibold d-block';
                    var t = document.createElement('div'); t.textContent = p.text; t.className = 'small';
                    div.append(a, t); m.bindPopup(div);
                    bounds.push([p.lat, p.lng]);
                });
                map.fitBounds(bounds, { maxZoom: 17, padding: [30, 30] });
            })();
            </script>
        <?php endif; ?>
        <div class="card-footer small text-body-secondary"><?= e(count($rows)) ?> observación(es) con GPS · color = severidad · borde negro = riesgo inminente</div>
    </div>
<?php else: ?>
    <div class="card shadow-sm">
        <?php if (!$rows): ?>
            <p class="text-body-secondary text-center py-5 mb-0">No hay observaciones para estos filtros.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead><tr><th>N°</th><th>Fecha</th><th>Observación</th><th>Sector</th><th>Severidad</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): $href = url('/panel/observaciones/' . $r['uuid']); ?>
                        <tr class="<?= $r['imminent_risk'] && ObservationWorkflow::isOpen($r['status']) ? 'table-danger' : '' ?>" style="cursor:pointer" onclick="location.href='<?= e($href) ?>'">
                            <td class="text-nowrap"><a class="fw-semibold text-decoration-none" href="<?= e($href) ?>"><?= e(Observations::format((int) $r['number'])) ?></a></td>
                            <td class="small text-nowrap"><?= e(fecha($r['created_at_device'], 'd/m/Y H:i')) ?></td>
                            <td>
                                <?php if ($r['imminent_risk']): ?><?= $imminentBadge ?><br><?php endif; ?>
                                <span class="small text-body-secondary"><?= e($r['category_name']) ?><?= $r['risk_name'] ? ' · ' . e($r['risk_name']) : '' ?></span>
                                <div><?= e(mb_strimwidth($r['description'], 0, 110, '…')) ?></div>
                                <?php if ($r['assigned_name']): ?><div class="small text-body-secondary">Responsable: <?= e($r['assigned_name']) ?><?= $r['action_due_on'] ? ' · vence ' . e(date('d/m/Y', strtotime($r['action_due_on']))) : '' ?></div><?php endif; ?>
                            </td>
                            <td class="small"><?= e($r['sector_name']) ?></td>
                            <td><?= $severityBadge($r['severity_name'], $r['severity_color']) ?></td>
                            <td><?= $statusBadge($r['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center small">
                <span class="text-body-secondary"><?= e($total) ?> observación(es)</span>
                <?php $pages = (int) ceil($total / $perPage); if ($pages > 1): ?>
                    <span>
                        <?php if ($page > 1): ?><a href="<?= e($query(['pagina' => $page - 1])) ?>">← anteriores</a><?php endif; ?>
                        <span class="mx-2">página <?= e($page) ?> de <?= e($pages) ?></span>
                        <?php if ($page < $pages): ?><a href="<?= e($query(['pagina' => $page + 1])) ?>">siguientes →</a><?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
