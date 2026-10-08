<?php
use App\Models\WorkPermits;
use App\Services\InspectionStructure;
use App\Services\WorkPermitControls;
use App\Services\WorkPermitService;
require __DIR__ . '/_badges.php';
$base = '/panel/permisos/' . $p['uuid'];
$canApprove = WorkPermitService::canApprove($p);
$canOperate = WorkPermitService::canOperate($p);
$left = $minutesLeft($p);
$received = (bool) array_filter($signatures, fn ($s) => $s['role'] === 'recepcion');
$pendingWorkers = array_values(array_filter($workers, fn ($w) => $w['signature_uuid'] === null));
$sigUrl = fn (array $s) => url($base . '/firmas/' . $s['uuid']);
$labels = ['created' => 'Solicitado', 'status' => 'Cambio de estado', 'received' => 'Área recibida', 'measurement' => 'Medición de gases',
    'isolation' => 'Bloqueo (LOTO)', 'extended' => 'Extensión'];
$canWork = WorkPermitControls::canWork($p);
$canStop = $canWork || App\Services\UserAuth::can('permisos_trabajo', 'aprobar');
$isActive = in_array($p['status'], WorkPermits::ACTIVE, true);
$isConfined = in_array('espacio_confinado', $p['type_list'], true);
$isLoto = in_array('loto', $p['type_list'], true);
$placed = array_filter($isolations, fn ($i) => $i['removed_at'] === null);
$notReady = in_array($p['status'], ['aprobado', 'suspendido'], true) ? WorkPermitControls::readyToWork($p, $p['status'] === 'suspendido' ? $p['suspended_at'] : null) : null;
$fireWatchLeft = $p['fire_watch_until'] ? (int) ceil((strtotime($p['fire_watch_until']) - time()) / 60) : 0;
$localInput = fn (string $utc) => fecha($utc, 'Y-m-d\TH:i');
$num = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',');
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/permisos')) ?>">←</a>
    <h1 class="h4 m-0"><?= e(WorkPermits::format((int) $p['number'])) ?></h1>
    <?= $typeBadges($p['type_list']) ?> <?= $stateBadge($p['status']) ?>
    <a class="btn btn-sm btn-outline-secondary ms-auto" target="_blank" href="<?= e(url($base . '/imprimir')) ?>">Imprimir para colgar (QR)</a>
</div>
<?php if (in_array($p['status'], WorkPermits::ACTIVE, true)): ?>
    <div class="alert <?= $left < 30 ? 'alert-danger' : 'alert-info' ?> py-2 small">Vence el <?= e(fecha($p['ends_at'], 'd/m/Y H:i')) ?><?= $left >= 0 ? ' (faltan ' . e(intdiv($left, 60)) . ' h ' . e($left % 60) . ' min)' : '' ?>.</div>
<?php endif; ?>
<?php if ($p['status'] === 'suspendido'): ?>
    <div class="alert alert-danger small"><strong>SUSPENDIDO</strong> desde las <?= e(fecha($p['suspended_at'], 'H:i')) ?>: <?= e($p['status_reason'] ?? '') ?>. Nadie trabaja hasta reanudarlo.</div>
<?php endif; ?>
<?php if ($p['status'] === 'cerrado' && $fireWatchLeft > 0): ?>
    <div class="alert alert-warning small">🔥 <strong>Guardia de fuego</strong> hasta las <?= e(fecha($p['fire_watch_until'], 'H:i')) ?> (faltan <?= e($fireWatchLeft) ?> min): el vigía se queda controlando el área. Después se recibe.</div>
<?php endif; ?>
<?php if ($conflicts): ?>
    <div class="alert alert-warning small"><strong>Atención:</strong> hay otros permisos en el mismo lugar en esa franja:
        <?php foreach ($conflicts as $i => $c): ?><?= $i ? ', ' : ' ' ?><a href="<?= e(url('/panel/permisos/' . $c['uuid'])) ?>"><?= e(WorkPermits::format((int) $c['number'])) ?></a>
            (<?= e(implode(' + ', array_map(fn ($t) => WorkPermitService::TYPES[$t]['short'] ?? $t, $c['type_list']))) ?>, <?= e(WorkPermitService::STATES[$c['status']]['label']) ?>, <?= e(fecha($c['valid_from'], 'd/m H:i')) ?>–<?= e(fecha($c['ends_at'], 'H:i')) ?>)<?php endforeach; ?>.
        Coordiná para que un trabajo no genere riesgo en el otro (ej. soldadura junto a un espacio confinado).</div>
<?php endif; ?>
<?php if ((int) $p['critical_fails'] > 0 && $p['status'] === 'solicitado'): ?>
    <div class="alert alert-danger small">El checklist tiene <?= e($p['critical_fails']) ?> ítem(s) crítico(s) sin cumplir: no se puede autorizar. Rechazalo para que se corrija y se solicite de nuevo.</div>
<?php endif; ?>
<?php if ($p['status_reason'] && in_array($p['status'], ['rechazado', 'cancelado', 'vencido', 'cerrado'], true)): ?>
    <div class="alert alert-secondary small"><strong><?= e(WorkPermitService::STATES[$p['status']]['label']) ?>:</strong> <?= e($p['status_reason']) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between"><span>Lugar</span><span class="text-end"><?= e($p['sector_name'] ?? '—') ?><?= $p['equipment_code'] ? ' · ' . e($p['equipment_code'] . ' ' . $p['equipment_name']) : '' ?><?= $p['location_text'] ? '<br>' . e($p['location_text']) : '' ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Ventana</span><strong><?= e(fecha($p['valid_from'], 'd/m/Y H:i')) ?> → <?= e(fecha($p['ends_at'], 'd/m H:i')) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between"><span>Contratista</span><span><?= e($p['contractor_name'] ?? 'Personal propio') ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Solicitó</span><span><?= e($p['requested_by_name'] ?? '—') ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Autorizó</span><span><?= $p['approved_by_name'] ? e($p['approved_by_name']) . ' · ' . e(fecha($p['approved_at'], 'd/m H:i')) : '—' ?></span></li>
                <li class="list-group-item" style="white-space: pre-wrap"><strong>Tarea:</strong> <?= e($p['task']) ?></li>
            </ul>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Ejecutores</strong></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($workers as $w): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                        <span><?= e($workerName($w)) ?> <span class="badge text-bg-light border"><?= $w['role'] === 'vigia' ? 'Vigía' : 'Ejecutor' ?></span>
                            <span class="text-body-secondary"><?= e($w['employee_id'] ? 'DNI ' . $w['employee_dni'] . ($w['contractor_name'] ? ' · ' . $w['contractor_name'] : '') : ($w['external_dni'] ? 'DNI ' . $w['external_dni'] : 'externo')) ?></span></span>
                        <?php if ($w['signature_uuid']): ?><span class="text-success text-nowrap">✓ firmó <?= e(fecha($w['signed_at'], 'd/m H:i')) ?></span>
                        <?php else: ?><span class="text-body-secondary text-nowrap">firma al iniciar</span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <?php if ($isConfined): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between"><strong>Mediciones de gases</strong>
                    <span class="small text-body-secondary">O₂ <?= e($num($gasLimits['o2_min'])) ?>–<?= e($num($gasLimits['o2_max'])) ?> % · LIE ≤ <?= e($num($gasLimits['lel_max'])) ?> % · CO ≤ <?= e($num($gasLimits['co_max'])) ?> ppm · H₂S ≤ <?= e($num($gasLimits['h2s_max'])) ?> ppm</span></div>
                <?php if ($isActive && $canWork): ?>
                    <form method="post" action="<?= e(url($base . '/medir')) ?>" class="card-body border-bottom">
                        <?= csrf_field() ?>
                        <div class="row g-2">
                            <div class="col-3"><label class="form-label small mb-0">O₂ %</label><input class="form-control form-control-sm" name="o2" inputmode="decimal" required></div>
                            <div class="col-3"><label class="form-label small mb-0">LIE %</label><input class="form-control form-control-sm" name="lel" inputmode="decimal" required></div>
                            <div class="col-3"><label class="form-label small mb-0">CO ppm</label><input class="form-control form-control-sm" name="co" inputmode="decimal"></div>
                            <div class="col-3"><label class="form-label small mb-0">H₂S ppm</label><input class="form-control form-control-sm" name="h2s" inputmode="decimal"></div>
                            <div class="col-6"><input class="form-control form-control-sm" name="instrument" placeholder="Instrumento (marca / n.º de serie)"></div>
                            <div class="col-6"><input class="form-control form-control-sm" name="measured_by" placeholder="Midió (si no fuiste vos)"></div>
                        </div>
                        <button class="btn btn-sm btn-primary mt-2">Registrar medición</button>
                        <span class="small text-body-secondary ms-2">Fuera de rango con el trabajo en curso → se suspende solo y se avisa.</span>
                    </form>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-sm small mb-0 align-middle">
                        <thead><tr><th>Hora</th><th>O₂</th><th>LIE</th><th>CO</th><th>H₂S</th><th>Midió</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($measurements as $m): ?>
                            <tr class="<?= (int) $m['ok'] ? '' : 'table-danger' ?>">
                                <td class="text-nowrap"><?= e(fecha($m['measured_at'], 'd/m H:i')) ?></td>
                                <td><?= e($num($m['o2'])) ?></td><td><?= e($num($m['lel'])) ?></td><td><?= e($num($m['co'])) ?></td><td><?= e($num($m['h2s'])) ?></td>
                                <td><?= e($m['measured_by'] ?? '') ?><?= $m['instrument'] ? '<br><span class="text-body-secondary">' . e($m['instrument']) . '</span>' : '' ?></td>
                                <td><?= (int) $m['ok'] ? '<span class="text-success">✓</span>' : '<span class="text-danger fw-semibold" title="' . e($m['out_of_range'] ?? '') . '">✗ fuera de rango</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$measurements): ?><tr><td colspan="7" class="text-body-secondary">Sin mediciones. Antes de ingresar hace falta una en rango (vale <?= e(WorkPermitControls::MEASUREMENT_VALID_MINUTES) ?> min).</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($isLoto): ?>
            <div class="card shadow-sm mb-3" x-data="{ add: false }">
                <div class="card-header d-flex justify-content-between align-items-center"><strong>Bloqueos (LOTO)</strong>
                    <span class="small <?= $placed ? 'text-danger fw-semibold' : 'text-body-secondary' ?>"><?= count($placed) ?> colocado(s) · <?= count($isolations) - count($placed) ?> retirado(s)</span></div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($isolations as $iso): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center gap-2 <?= $iso['removed_at'] ? 'text-body-secondary' : '' ?>">
                            <span><?= $iso['removed_at'] ? '🔓' : '🔒' ?> <strong><?= e($iso['point']) ?></strong> · <?= e(WorkPermitControls::ENERGIES[$iso['energy']] ?? $iso['energy']) ?>
                                <?= $iso['device'] ? ' · ' . e($iso['device']) : '' ?><?= $iso['lock_number'] ? ' · candado ' . e($iso['lock_number']) : '' ?>
                                <br>Colocó <?= e($iso['placed_by']) ?> <?= e(fecha($iso['placed_at'], 'd/m H:i')) ?>
                                <?= (int) $iso['zero_verified'] ? '<span class="text-success">· energía cero verificada</span>' : '<span class="text-danger">· energía cero SIN verificar</span>' ?>
                                <?= $iso['removed_at'] ? '<br>Retiró ' . e($iso['removed_by']) . ' ' . e(fecha($iso['removed_at'], 'd/m H:i')) : '' ?></span>
                            <?php if (!$iso['removed_at'] && $canWork && in_array($p['status'], ['en_ejecucion', 'suspendido', 'aprobado'], true)): ?>
                                <form method="post" action="<?= e(url($base . '/desbloquear')) ?>" class="d-flex gap-1" onsubmit="return confirm('¿Retirar este bloqueo? Confirmá que no queda nadie expuesto.')">
                                    <?= csrf_field() ?><input type="hidden" name="isolation" value="<?= e($iso['uuid']) ?>">
                                    <input class="form-control form-control-sm" name="removed_by" placeholder="Retira" style="width: 110px">
                                    <button class="btn btn-sm btn-outline-secondary">Retirar</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if (!$isolations): ?><li class="list-group-item text-body-secondary">Sin bloqueos registrados. Antes de iniciar: cada punto bloqueado, etiquetado y con energía cero verificada.</li><?php endif; ?>
                </ul>
                <?php if ($isActive && $canWork): ?>
                    <div class="card-body border-top">
                        <button type="button" class="btn btn-sm btn-outline-primary" @click="add = !add">+ Punto de bloqueo</button>
                        <form method="post" action="<?= e(url($base . '/bloquear')) ?>" class="mt-2" x-show="add" x-cloak>
                            <?= csrf_field() ?>
                            <div class="row g-2">
                                <div class="col-sm-7"><input class="form-control form-control-sm" name="point" required minlength="3" placeholder="Punto (ej. seccionador TG-2, válvula V-12)"></div>
                                <div class="col-sm-5"><select class="form-select form-select-sm" name="energy" required><option value="">Energía…</option>
                                    <?php foreach (WorkPermitControls::ENERGIES as $k => $label): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
                                <div class="col-sm-4"><input class="form-control form-control-sm" name="device" placeholder="Dispositivo (candado, pinza…)"></div>
                                <div class="col-sm-4"><input class="form-control form-control-sm" name="lock_number" placeholder="N.º de candado / tarjeta"></div>
                                <div class="col-sm-4"><input class="form-control form-control-sm" name="placed_by" placeholder="Colocó (si no fuiste vos)"></div>
                            </div>
                            <div class="form-check small mt-2"><input class="form-check-input" type="checkbox" name="zero_verified" value="1" id="zero"><label class="form-check-label" for="zero">Energía cero verificada (prueba de arranque / medición)</label></div>
                            <button class="btn btn-sm btn-primary mt-2">Registrar bloqueo</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php foreach ($checklists as $c): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between"><strong><?= e(WorkPermitService::TYPES[$c['permit_type']]['label'] ?? $c['permit_type']) ?></strong>
                    <span class="small <?= (int) $c['critical_fails'] ? 'text-danger fw-semibold' : 'text-body-secondary' ?>"><?= e($c['template_name']) ?> v<?= e($c['version']) ?><?= (int) $c['fails'] ? ' · ' . e($c['fails']) . ' no cumple(n)' : ' · todo en orden' ?></span></div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($c['answers'] as $a): ?>
                        <li class="list-group-item py-1 d-flex justify-content-between gap-2 <?= $a['ok'] === false ? 'list-group-item-danger' : '' ?>">
                            <span><?= $a['critical'] ? '<span class="badge text-bg-danger me-1">Crítico</span>' : '' ?><?= e($a['text']) ?><?= $a['comment'] ? '<br><em>' . e($a['comment']) . '</em>' : '' ?></span>
                            <strong class="text-nowrap"><?= $a['ok'] === true ? '✓ ' : ($a['ok'] === false ? '✗ ' : '') ?><?= e(InspectionStructure::valueLabel($a['value'])) ?></strong>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between"><strong>Firmas</strong>
                <span class="small <?= $hashOk ? 'text-success' : 'text-danger fw-semibold' ?>" title="SHA-256 <?= e($p['original_hash']) ?>"><?= $hashOk ? '✓ permiso íntegro' : '✗ verificación de integridad falló' ?></span></div>
            <div class="card-body d-flex flex-wrap gap-3">
                <?php foreach ($signatures as $s): ?>
                    <figure class="m-0 text-center small" style="width: 170px">
                        <img src="<?= e($sigUrl($s)) ?>" alt="Firma" class="border rounded bg-white w-100" style="height: 70px; object-fit: contain" title="SHA-256 <?= e($s['sha256']) ?>">
                        <figcaption><strong><?= e(WorkPermitService::SIGNATURE_ROLES[$s['role']] ?? $s['role']) ?></strong><br><?= e($s['signer_name']) ?><br>
                            <span class="text-body-secondary"><?= e(fecha($s['signed_at'], 'd/m H:i')) ?></span></figcaption>
                    </figure>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Qué sigue</strong></div>
            <div class="card-body" x-data="{ open: null }">
                <?php if ($p['status'] === 'solicitado' && $canApprove): ?>
                    <form method="post" action="<?= e(url($base . '/autorizar')) ?>" class="mb-3">
                        <?= csrf_field() ?>
                        <p class="small mb-2">Revisá el checklist y la tarea. Al firmar autorizás el trabajo en la ventana indicada.</p>
                        <?php $sigName = 'signature'; $sigLabel = 'Firma del autorizante'; require __DIR__ . '/_signature.php'; ?>
                        <input class="form-control form-control-sm mb-2" name="comment" placeholder="Condiciones / observaciones (opcional)">
                        <button class="btn btn-success w-100" <?= (int) $p['critical_fails'] ? 'disabled' : '' ?>>Autorizar</button>
                    </form>
                    <button type="button" class="btn btn-sm btn-outline-danger" @click="open = open === 'rechazar' ? null : 'rechazar'">Rechazar</button>
                    <form method="post" action="<?= e(url($base . '/rechazar')) ?>" class="mt-2" x-show="open === 'rechazar'" x-cloak>
                        <?= csrf_field() ?>
                        <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" required minlength="5" placeholder="Qué hay que corregir"></textarea>
                        <button class="btn btn-danger btn-sm">Confirmar rechazo</button>
                    </form>
                <?php elseif ($p['status'] === 'solicitado'): ?>
                    <p class="small text-body-secondary mb-2"><?= WorkPermitService::isRequester($p) ? 'Esperando que lo autorice otra persona (vos no podés autorizar tu propio permiso).' : 'Esperando la autorización.' ?></p>
                <?php endif; ?>

                <?php if ($notReady !== null && ($canOperate || $canWork)): ?>
                    <div class="alert alert-warning small py-2"><?= e($notReady) ?></div>
                <?php endif; ?>
                <?php if ($p['status'] === 'aprobado' && $canOperate): ?>
                    <form method="post" action="<?= e(url($base . '/iniciar')) ?>">
                        <?= csrf_field() ?>
                        <p class="small mb-2">En el lugar, cada ejecutor firma que fue informado de los riesgos y de las medidas de este permiso.</p>
                        <?php foreach ($pendingWorkers as $w): ?>
                            <?php $sigName = 'worker_signatures[' . $w['uuid'] . ']'; $sigLabel = 'Firma de ' . $workerName($w) . ($w['role'] === 'vigia' ? ' (vigía)' : ''); require __DIR__ . '/_signature.php'; ?>
                        <?php endforeach; ?>
                        <button class="btn btn-success w-100">Iniciar el trabajo</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($p['status'], ['en_ejecucion', 'suspendido'], true) && $canOperate): ?>
                    <form method="post" action="<?= e(url($base . '/cerrar')) ?>">
                        <?= csrf_field() ?>
                        <p class="small mb-2">Al terminar: herramientas retiradas, área ordenada<?= in_array('loto', $p['type_list'], true) ? ', candados retirados' : '' ?><?= in_array('caliente', $p['type_list'], true) ? ', guardia de fuego cumplida' : '' ?>.</p>
                        <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" required minlength="5" placeholder="Cómo quedó el área"></textarea>
                        <?php $sigName = 'signature'; $sigLabel = 'Firma de cierre'; require __DIR__ . '/_signature.php'; ?>
                        <button class="btn btn-dark w-100">Cerrar el permiso</button>
                    </form>
                <?php endif; ?>

                <?php if ($p['status'] === 'en_ejecucion' && $canStop): ?>
                    <hr>
                    <button type="button" class="btn btn-sm btn-outline-danger" @click="open = open === 'suspender' ? null : 'suspender'">Suspender el trabajo</button>
                    <form method="post" action="<?= e(url($base . '/suspender')) ?>" class="mt-2" x-show="open === 'suspender'" x-cloak>
                        <?= csrf_field() ?>
                        <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" required minlength="5" placeholder="Motivo (alarma, cambio de condiciones, lluvia, viento…)"></textarea>
                        <button class="btn btn-danger btn-sm">Suspender</button>
                    </form>
                <?php endif; ?>
                <?php if ($p['status'] === 'suspendido' && $canStop): ?>
                    <hr>
                    <form method="post" action="<?= e(url($base . '/reanudar')) ?>">
                        <?= csrf_field() ?>
                        <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" required minlength="5" placeholder="Qué se verificó para seguir"></textarea>
                        <button class="btn btn-success btn-sm" <?= $notReady !== null ? 'disabled' : '' ?>>Reanudar el trabajo</button>
                    </form>
                <?php endif; ?>
                <?php if ($isActive && $canApprove && $p['extended_until'] === null && $left > 0): ?>
                    <hr>
                    <button type="button" class="btn btn-sm btn-outline-primary" @click="open = open === 'extender' ? null : 'extender'">Extender (una vez, hasta <?= e(WorkPermitService::maxHours()) ?> h)</button>
                    <form method="post" action="<?= e(url($base . '/extender')) ?>" class="mt-2" x-show="open === 'extender'" x-cloak>
                        <?= csrf_field() ?>
                        <label class="form-label small mb-0">Nuevo vencimiento</label>
                        <input type="datetime-local" class="form-control form-control-sm mb-2" name="until" required min="<?= e($localInput($p['ends_at'])) ?>"
                            max="<?= e($localInput(gmdate('Y-m-d H:i:s', strtotime($p['valid_until']) + WorkPermitService::maxHours() * 3600))) ?>">
                        <?php $sigName = 'signature'; $sigLabel = 'Firma del autorizante'; require __DIR__ . '/_signature.php'; ?>
                        <button class="btn btn-primary btn-sm w-100">Extender</button>
                    </form>
                <?php elseif ($isActive && $p['extended_until'] !== null): ?>
                    <p class="small text-body-secondary mt-2 mb-0">Ya se extendió una vez: para seguir después de las <?= e(fecha($p['ends_at'], 'H:i')) ?> hace falta un permiso nuevo.</p>
                <?php endif; ?>

                <?php if ($p['status'] === 'cerrado' && !$received && $fireWatchLeft > 0): ?>
                    <p class="small text-body-secondary">La recepción del área se habilita al terminar la guardia de fuego (<?= e(fecha($p['fire_watch_until'], 'H:i')) ?>).</p>
                <?php elseif ($p['status'] === 'cerrado' && !$received && App\Services\UserAuth::can('permisos_trabajo', 'aprobar')): ?>
                    <form method="post" action="<?= e(url($base . '/recibir')) ?>">
                        <?= csrf_field() ?>
                        <p class="small mb-2">Recepción del área por quien autoriza.</p>
                        <?php $sigName = 'signature'; $sigLabel = 'Firma de recepción'; require __DIR__ . '/_signature.php'; ?>
                        <button class="btn btn-outline-dark w-100">Recibir el área</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($p['status'], ['solicitado', 'aprobado'], true) && $canOperate): ?>
                    <hr>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="open = open === 'cancelar' ? null : 'cancelar'">Cancelar el permiso</button>
                    <form method="post" action="<?= e(url($base . '/cancelar')) ?>" class="mt-2" x-show="open === 'cancelar'" x-cloak>
                        <?= csrf_field() ?>
                        <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" required minlength="5" placeholder="Motivo"></textarea>
                        <button class="btn btn-secondary btn-sm">Confirmar cancelación</button>
                    </form>
                <?php endif; ?>
                <?php if (in_array($p['status'], ['cerrado', 'vencido', 'rechazado', 'cancelado'], true) && ($received || $p['status'] !== 'cerrado')): ?>
                    <p class="small text-body-secondary mb-0">Permiso finalizado.</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="card shadow-sm">
            <div class="card-header"><strong>Línea de tiempo</strong></div>
            <ul class="list-group list-group-flush small">
                <?php foreach (array_reverse($events) as $ev): ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between"><strong><?= e($labels[$ev['type']] ?? $ev['type']) ?> <?= $ev['to_status'] ? $stateBadge($ev['to_status']) : '' ?></strong>
                            <span class="text-body-secondary"><?= e(fecha($ev['created_at'], 'd/m H:i')) ?></span></div>
                        <div class="text-body-secondary"><?= e($ev['actor_name'] ?? '') ?></div>
                        <?php if ($ev['comment']): ?><div><?= e($ev['comment']) ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
<script src="<?= e(asset('js/signature-pad.js')) ?>"></script>
