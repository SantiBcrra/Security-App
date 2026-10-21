<?php
/**
 * Libro de guardia del día. @var ?array $site @var array $sites @var string $day @var string $today @var array $entries @var array $inside
 * @var ?array $pending @var ?array $summary @var ?array $verify @var bool $canCreate @var bool $canExport
 */
use App\Services\Logbook;
[$old] = App\Core\Flash::pullInput();
$o = fn (string $k) => $old[$k] ?? '';
$prev = date('Y-m-d', strtotime($day . ' -1 day'));
$next = date('Y-m-d', strtotime($day . ' +1 day'));
$q = fn (array $extra = []) => '?' . http_build_query(['predio' => $site['uuid'] ?? ''] + $extra);
$kindBadge = ['novedad' => 'secondary', 'persona' => 'primary', 'vehiculo' => 'info', 'salida' => 'light', 'correccion' => 'warning', 'cierre' => 'dark', 'recepcion' => 'success'];
$since = function (string $at): string {
    $m = intdiv(max(0, time() - strtotime($at . ' UTC')), 60);
    return $m < 60 ? "{$m} min" : intdiv($m, 60) . ' h ' . ($m % 60) . ' min';
};
?>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h4 m-0">Libro de guardia</h1>
        <div class="text-body-secondary small">Novedades, ingreso y egreso de personas y vehículos, y relevo de turno. Nada se borra: las correcciones quedan como anotaciones nuevas.</div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/libro/buscar')) ?>">Buscar</a>
        <?php if ($site): ?><a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= e(url('/panel/libro/imprimir' . $q(['fecha' => $day]))) ?>">Parte diario</a><?php endif; ?>
        <?php if ($canExport && $site): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/libro/exportar' . $q(['desde' => $day, 'hasta' => $day]))) ?>">CSV</a><?php endif; ?>
    </div>
</div>

<?php if (!$sites): ?>
    <div class="alert alert-warning">Primero cargá la planta / predio en <a href="<?= e(url('/panel/datos/plantas')) ?>">Datos maestros → Plantas</a>: el libro es de cada predio.</div>
    <?php return; ?>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <?php if (count($sites) > 1): ?>
        <form method="get" class="d-flex gap-2"><input type="hidden" name="fecha" value="<?= e($day) ?>">
            <select class="form-select form-select-sm" name="predio" onchange="this.form.submit()">
                <option value="">Elegí el predio…</option>
                <?php foreach ($sites as $s): ?><option value="<?= e($s['uuid']) ?>" <?= ($site['uuid'] ?? '') === $s['uuid'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
            </select></form>
    <?php else: ?><span class="fw-semibold"><?= e($site['name'] ?? '') ?></span><?php endif; ?>
    <?php if ($site): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/libro' . $q(['fecha' => $prev]))) ?>">‹</a>
        <form method="get"><input type="hidden" name="predio" value="<?= e($site['uuid']) ?>"><input type="date" name="fecha" value="<?= e($day) ?>" class="form-control form-control-sm" onchange="this.form.submit()"></form>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/libro' . $q(['fecha' => $next]))) ?>">›</a>
        <?php if ($day !== $today): ?><a class="btn btn-sm btn-link" href="<?= e(url('/panel/libro' . $q())) ?>">Hoy</a><?php endif; ?>
        <?php if ($verify): ?>
            <span class="ms-auto small <?= $verify['ok'] ? 'text-success' : 'text-danger fw-bold' ?>" title="Cada anotación está encadenada con la anterior por su huella: si alguien la alterara se detecta.">
                <?= $verify['ok'] ? '🔒 Libro íntegro · ' . (int) $verify['checked'] . ' anotaciones verificadas' : '⚠ Alteración detectada en el folio ' . (int) $verify['broken_folio'] ?></span>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if (!$site): ?><div class="alert alert-info">Elegí el predio para ver su libro.</div><?php return; endif; ?>

<?php if ($pending && $canCreate): ?>
    <div class="card border-success shadow-sm mb-3">
        <div class="card-header bg-success-subtle fw-semibold">Relevo: <?= e($pending['user_name'] ?? 'el guardia anterior') ?> cerró su turno a las <?= e(fecha($pending['occurred_at'], 'H:i')) ?> (folio <?= (int) $pending['folio'] ?>)</div>
        <div class="card-body">
            <?php $d = json_decode((string) $pending['data'], true) ?: []; ?>
            <p class="mb-1"><strong>Dejó anotado:</strong> <?= nl2br(e($pending['text'])) ?></p>
            <?php if (!empty($d['adentro'])): ?><p class="mb-1 text-danger small">Seguían adentro: <?= e(implode('; ', array_column($d['adentro'], 'que'))) ?></p><?php endif; ?>
            <form method="post" action="<?= e(url('/panel/libro/' . $pending['uuid'] . '/recibir' . $q())) ?>" class="mt-2" style="max-width: 520px">
                <?= csrf_field() ?>
                <textarea class="form-control mb-2" name="text" rows="2" placeholder="Recibo el puesto de conformidad (o anotá lo que encontraste distinto)"></textarea>
                <?= App\Core\View::render('panel/permits/_signature', ['sigName' => 'signature', 'sigLabel' => 'Tu firma'], null) ?>
                <button class="btn btn-success btn-sm">Recibir el puesto</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <?php if ($canCreate && $day === $today): ?>
        <div class="card shadow-sm mb-3" x-data="{ tab: <?= e(json_encode($o('kind') ?: 'novedad')) ?> }">
            <div class="card-header p-1">
                <div class="btn-group w-100" role="group">
                    <button type="button" class="btn btn-sm" :class="tab === 'novedad' ? 'btn-primary' : 'btn-outline-primary'" @click="tab = 'novedad'">+ Novedad</button>
                    <button type="button" class="btn btn-sm" :class="tab === 'persona' ? 'btn-primary' : 'btn-outline-primary'" @click="tab = 'persona'">+ Persona</button>
                    <button type="button" class="btn btn-sm" :class="tab === 'vehiculo' ? 'btn-primary' : 'btn-outline-primary'" @click="tab = 'vehiculo'">+ Vehículo</button>
                </div>
            </div>
            <form method="post" action="<?= e(url('/panel/libro/anotar' . $q())) ?>" enctype="multipart/form-data" class="card-body" data-logbook-form>
                <?= csrf_field() ?>
                <input type="hidden" name="kind" :value="tab">
                <template x-if="tab === 'novedad'"><div>
                    <select class="form-select form-select-sm mb-2" name="category">
                        <?php foreach (Logbook::CATEGORIES as $k => $l): ?><option value="<?= e($k) ?>" <?= $o('category') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                    <textarea class="form-control mb-2" name="text" rows="4" placeholder="¿Qué pasó? (hora, lugar, quién, qué se hizo)" required><?= e($o('text')) ?></textarea>
                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="important" value="1" id="imp"><label class="form-check-label small" for="imp">Importante: avisar al supervisor</label></div>
                </div></template>
                <template x-if="tab === 'persona'"><div>
                    <div class="position-relative"><input class="form-control form-control-sm mb-2" name="person_doc" placeholder="DNI (completa los datos si ya vino)" data-lookup="persona" autocomplete="off" value="<?= e($o('person_doc')) ?>"></div>
                    <input class="form-control form-control-sm mb-2" name="person_name" placeholder="Nombre y apellido" value="<?= e($o('person_name')) ?>">
                    <input class="form-control form-control-sm mb-2" name="company" placeholder="Empresa" value="<?= e($o('company')) ?>">
                    <input class="form-control form-control-sm mb-2" name="visiting" placeholder="A quién visita / sector" value="<?= e($o('visiting')) ?>">
                    <input class="form-control form-control-sm mb-2" name="reason" placeholder="Motivo" value="<?= e($o('reason')) ?>">
                    <div class="row g-2 mb-2"><div class="col"><input class="form-control form-control-sm" name="badge" placeholder="Tarjeta / credencial" value="<?= e($o('badge')) ?>"></div>
                        <div class="col"><input class="form-control form-control-sm" name="plate" placeholder="Patente (si viene en auto)" value="<?= e($o('plate')) ?>"></div></div>
                    <div class="form-text mb-2">Alcanza con el nombre, el DNI o la empresa.</div>
                </div></template>
                <template x-if="tab === 'vehiculo'"><div>
                    <div class="position-relative"><input class="form-control form-control-sm mb-2 text-uppercase" name="plate" placeholder="Patente *" data-lookup="vehiculo" autocomplete="off" required value="<?= e($o('plate')) ?>"></div>
                    <select class="form-select form-select-sm mb-2" name="vehicle_type"><option value="">Tipo de vehículo…</option>
                        <?php foreach (Logbook::VEHICLE_TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
                    <input class="form-control form-control-sm mb-2" name="driver" placeholder="Conductor" value="<?= e($o('driver')) ?>">
                    <div class="row g-2 mb-2"><div class="col"><input class="form-control form-control-sm" name="person_doc" placeholder="DNI del conductor" value="<?= e($o('person_doc')) ?>"></div>
                        <div class="col"><input class="form-control form-control-sm" name="company" placeholder="Empresa" value="<?= e($o('company')) ?>"></div></div>
                    <input class="form-control form-control-sm mb-2" name="visiting" placeholder="Destino / a quién" value="<?= e($o('visiting')) ?>">
                    <input class="form-control form-control-sm mb-2" name="load_note" placeholder="Carga / remito" value="<?= e($o('load_note')) ?>">
                </div></template>
                <template x-if="tab !== 'novedad'"><textarea class="form-control form-control-sm mb-2" name="text" rows="2" placeholder="Observaciones (opcional)"></textarea></template>
                <label class="form-label small mb-1">Foto (opcional)</label>
                <input class="form-control form-control-sm mb-2" type="file" name="photo" accept="image/*" capture="environment">
                <button class="btn btn-primary w-100">Anotar</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-3 <?= $inside ? 'border-primary' : '' ?>">
            <div class="card-header fw-semibold">Adentro ahora <span class="badge text-bg-primary"><?= count($inside) ?></span></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($inside as $e): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <span class="small"><?= $e['kind'] === 'vehiculo' ? '🚗' : '👤' ?> <strong><?= e(Logbook::label($e)) ?></strong>
                            <br><span class="text-body-secondary">desde <?= e(fecha($e['occurred_at'], 'd/m H:i')) ?> (<?= e($since($e['occurred_at'])) ?>)</span></span>
                        <?php if ($canCreate): ?><form method="post" action="<?= e(url('/panel/libro/' . $e['uuid'] . '/salida' . $q())) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary">Salió</button></form><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$inside): ?><li class="list-group-item small text-body-secondary">No queda nadie adentro.</li><?php endif; ?>
            </ul>
        </div>

        <?php if ($canCreate && $summary): ?>
        <details class="card shadow-sm mb-3">
            <summary class="card-header fw-semibold">Cerrar mi turno (relevo)</summary>
            <form method="post" action="<?= e(url('/panel/libro/cierre' . $q())) ?>" class="card-body">
                <?= csrf_field() ?>
                <?php $c = $summary['conteos']; ?>
                <p class="small mb-2">Desde las <?= e(fecha($summary['desde'], 'd/m H:i')) ?>: <?= (int) $c['novedades'] ?> novedad(es)<?= $c['importantes'] ? ' (' . (int) $c['importantes'] . ' importantes)' : '' ?>,
                    <?= (int) $c['personas'] ?> ingreso(s) de personas, <?= (int) $c['vehiculos'] ?> de vehículos.</p>
                <?php if ($summary['adentro']): ?><p class="small text-danger mb-2">Siguen adentro: <?= e(implode('; ', array_column($summary['adentro'], 'que'))) ?>. Se avisa al supervisor.</p><?php endif; ?>
                <textarea class="form-control mb-2" name="text" rows="3" placeholder="Cómo dejás el puesto: sin novedades, pendientes, fallas…"></textarea>
                <?= App\Core\View::render('panel/permits/_signature', ['sigName' => 'signature', 'sigLabel' => 'Tu firma'], null) ?>
                <button class="btn btn-dark btn-sm w-100">Cerrar turno y firmar</button>
                <div class="form-text">Si llega otro guardia, él recibe el puesto con su firma. Si no hay relevo, el cierre queda igual.</div>
            </form>
        </details>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Anotaciones del <?= e(date('d/m/Y', strtotime($day))) ?> <span class="text-body-secondary small">(<?= count($entries) ?>)</span></div>
            <ul class="list-group list-group-flush">
                <?php foreach (array_reverse($entries) as $e): ?>
                    <li class="list-group-item <?= $e['important'] ? 'list-group-item-danger' : '' ?>">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div class="small text-body-secondary">
                                <strong class="text-body"><?= e(fecha($e['occurred_at'], 'H:i')) ?></strong> · folio <?= (int) $e['folio'] ?>
                                · <span class="badge text-bg-<?= e($kindBadge[$e['kind']] ?? 'secondary') ?>"><?= e(Logbook::KINDS[$e['kind']] ?? $e['kind']) ?></span>
                                <?= $e['category'] && $e['kind'] === 'novedad' ? '<span class="badge text-bg-light">' . e(Logbook::CATEGORIES[$e['category']] ?? '') . '</span>' : '' ?>
                                <?= $e['important'] ? '<span class="badge text-bg-danger">Importante</span>' : '' ?>
                                · <?= e($e['user_name'] ?? '—') ?><?= $e['source'] === 'app' ? ' (app)' : '' ?>
                            </div>
                            <?php if ($canCreate && $day === $today && !in_array($e['kind'], ['cierre', 'recepcion', 'correccion'], true)): ?>
                                <details class="small"><summary class="text-body-secondary">Corregir</summary>
                                    <form method="post" action="<?= e(url('/panel/libro/' . $e['uuid'] . '/corregir' . $q())) ?>" class="mt-1 d-flex gap-1" style="min-width: 260px">
                                        <?= csrf_field() ?><input class="form-control form-control-sm" name="text" placeholder="Qué está mal y cómo es" required><button class="btn btn-sm btn-outline-warning">Anotar</button></form>
                                </details>
                            <?php endif; ?>
                        </div>
                        <div class="mt-1">
                            <?php if (in_array($e['kind'], ['persona', 'vehiculo'], true)): ?>
                                <strong><?= e(Logbook::label($e)) ?></strong>
                                <?= $e['visiting'] ? ' → ' . e($e['visiting']) : '' ?><?= $e['reason'] ? ' · ' . e($e['reason']) : '' ?>
                                <?= $e['badge'] ? ' · tarjeta ' . e($e['badge']) : '' ?><?= $e['kind'] === 'persona' && $e['plate'] ? ' · patente ' . e($e['plate']) : '' ?>
                                <?= $e['load_note'] ? ' · carga: ' . e($e['load_note']) : '' ?>
                                <div class="small"><?= $e['exited_at'] ? '<span class="text-success">Salió ' . e(fecha($e['exited_at'], 'd/m H:i')) . '</span>'
                                    : '<span class="text-primary">Sigue adentro</span>' ?></div>
                            <?php elseif ($e['kind'] === 'salida'): ?>
                                Salida de lo anotado en el folio <?= (int) $e['ref_folio'] ?><?= $e['load_note'] ? ' · carga: ' . e($e['load_note']) : '' ?>
                            <?php elseif ($e['kind'] === 'correccion'): ?>
                                <span class="text-warning-emphasis">Corrige el folio <?= (int) $e['ref_folio'] ?>:</span>
                            <?php elseif ($e['kind'] === 'recepcion'): ?>
                                Recibe el puesto (cierre del folio <?= (int) $e['ref_folio'] ?>).
                            <?php endif; ?>
                            <?php if ($e['text']): ?><div><?= nl2br(e($e['text'])) ?></div><?php endif; ?>
                            <?php if ($e['kind'] === 'cierre'): $d = json_decode((string) $e['data'], true) ?: []; ?>
                                <div class="small text-body-secondary">Cierre de turno<?= $e['received_entry_id'] ? ' · recibido' : ' · sin relevo todavía' ?>
                                    <?= !empty($d['adentro']) ? ' · quedaron adentro: ' . e(implode('; ', array_column($d['adentro'], 'que'))) : '' ?></div>
                            <?php endif; ?>
                            <?php if ($e['signature_path']): ?><img src="<?= e(url('/panel/libro/archivo/' . $e['uuid'] . '/firma')) ?>" alt="Firma" style="height: 42px" class="mt-1 border-bottom"><?php endif; ?>
                            <?php if ((int) $e['photos'] > 0): ?>
                                <div class="d-flex gap-1 mt-1"><?php foreach (Logbook::attachments((int) $e['id']) as $a): ?>
                                    <a href="<?= e(url('/panel/libro/archivo/' . $a['uuid'] . '/foto')) ?>" target="_blank"><img src="<?= e(url('/panel/libro/archivo/' . $a['uuid'] . '/mini')) ?>" alt="Foto" style="height: 60px" class="rounded border"></a>
                                <?php endforeach; ?></div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php if (!$entries): ?><li class="list-group-item text-body-secondary">No hay anotaciones este día.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<script src="<?= e(asset('js/signature-pad.js')) ?>"></script>
<script>
// Autocompletar por DNI o patente con lo que ya vino antes (padrón del libro).
(function () {
    var url = <?= json_encode(url('/panel/libro/autocompletar')) ?>, timer = null;
    document.addEventListener('input', function (ev) {
        var input = ev.target;
        if (!input.matches || !input.matches('[data-lookup]')) return;
        clearTimeout(timer);
        timer = setTimeout(function () {
            var box = input.parentNode.querySelector('.lookup-box');
            if (!box) { box = document.createElement('div'); box.className = 'lookup-box list-group position-absolute w-100 shadow'; box.style.zIndex = 20; input.parentNode.appendChild(box); }
            if (input.value.trim().length < 2) { box.innerHTML = ''; return; }
            fetch(url + '?tipo=' + input.dataset.lookup + '&q=' + encodeURIComponent(input.value), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
                .then(function (r) { return r.json(); }).then(function (j) {
                    box.innerHTML = '';
                    ((j.data && j.data.items) || []).forEach(function (it) {
                        var a = document.createElement('button'); a.type = 'button'; a.className = 'list-group-item list-group-item-action small';
                        a.textContent = input.dataset.lookup === 'vehiculo' ? [it.plate, it.driver, it.company].filter(Boolean).join(' · ') : [it.doc, it.name, it.company].filter(Boolean).join(' · ');
                        a.addEventListener('click', function () {
                            var f = input.form, set = function (n, v) { var el = f.querySelector('[name=' + n + ']'); if (el && v && !el.value) el.value = v; };
                            if (input.dataset.lookup === 'vehiculo') { input.value = it.plate; set('vehicle_type', it.type); set('driver', it.driver); set('person_doc', it.doc); set('company', it.company); }
                            else { input.value = it.doc; set('person_name', it.name); set('company', it.company); set('plate', it.plate); }
                            box.innerHTML = '';
                        });
                        box.appendChild(a);
                    });
                });
        }, 250);
    });
})();
</script>
