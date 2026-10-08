<?php
use App\Models\WorkPermits;
use App\Services\InspectionStructure;
use App\Services\WorkPermitService;
require __DIR__ . '/_badges.php';
$base = '/panel/permisos/' . $p['uuid'];
$canApprove = WorkPermitService::canApprove($p);
$canOperate = WorkPermitService::canOperate($p);
$left = $minutesLeft($p);
$received = (bool) array_filter($signatures, fn ($s) => $s['role'] === 'recepcion');
$pendingWorkers = array_values(array_filter($workers, fn ($w) => $w['signature_uuid'] === null));
$sigUrl = fn (array $s) => url($base . '/firmas/' . $s['uuid']);
$labels = ['created' => 'Solicitado', 'status' => 'Cambio de estado', 'received' => 'Área recibida'];
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

                <?php if ($p['status'] === 'cerrado' && !$received && App\Services\UserAuth::can('permisos_trabajo', 'aprobar')): ?>
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
