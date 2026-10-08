<?php
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$canDo = UserAuth::can('inspecciones', 'crear');
$canSkip = UserAuth::can('inspecciones', 'cerrar');
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones')) ?>">←</a>
        <h1 class="h4 m-0">Inspecciones programadas</h1>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-sm <?= $mine ? 'btn-primary' : 'btn-outline-primary' ?>" href="?<?= e(http_build_query(['ver' => $tab, 'mias' => $mine ? '' : '1'])) ?>">A mi cargo</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/cumplimiento')) ?>">Cumplimiento</a>
        <?php if (UserAuth::can('inspecciones', 'editar')): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/programas')) ?>">Programas</a><?php endif; ?>
    </div>
</div>
<ul class="nav nav-pills small mb-3 flex-nowrap overflow-auto">
    <?php foreach ($tabs as $key => [$label, $n]): ?>
        <li class="nav-item"><a class="nav-link py-1 <?= $tab === $key ? 'active' : '' ?> <?= $key === 'vencidas' && $n && $tab !== $key ? 'text-danger' : '' ?>"
            href="?<?= e(http_build_query(['ver' => $key, 'mias' => $mine ? '1' : ''])) ?>"><?= e($label) ?> <span class="badge text-bg-light"><?= e($n) ?></span></a></li>
    <?php endforeach; ?>
</ul>
<div class="card shadow-sm">
    <div class="list-group list-group-flush">
        <?php foreach ($rows as $s):
            $late = $s['status'] === 'pendiente' && $s['due_on'] < $today;
            $link = url('/panel/inspecciones/nueva?' . http_build_query(array_filter(['plantilla' => $s['template_uuid'], 'equipo' => $s['equipment_uuid'],
                'sector' => $s['equipment_uuid'] ? null : $s['sector_uuid'], 'programada' => $s['uuid']]))); ?>
            <div class="list-group-item <?= $late ? 'border-start border-danger border-4' : '' ?>" x-data="{ skip: false }">
                <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">
                    <div>
                        <strong><?= e($s['equipment_code'] ? $s['equipment_code'] . ' · ' . $s['equipment_name'] : $s['sector_name']) ?></strong>
                        <span class="text-body-secondary">· <?= e($s['template_name']) ?></span>
                        <div class="small <?= $late ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">
                            <?= $s['due_from'] !== $s['due_on'] ? 'Del ' . e(date('d/m', strtotime($s['due_from']))) . ' al ' : 'El ' ?><?= e(date('d/m/Y', strtotime($s['due_on']))) ?>
                            <?= $late ? '· vencida' : '' ?> · <?= e($s['program_name']) ?><?= $s['equipment_code'] && $s['sector_name'] ? ' · ' . e($s['sector_name']) : '' ?>
                        </div>
                        <?php if ($s['status'] === 'hecha'): ?><div class="small"><a href="<?= e(url('/panel/inspecciones/' . $s['inspection_uuid'])) ?>">Hecha el <?= e(date('d/m', strtotime($s['done_on']))) ?></a>
                            por <?= e($s['inspector_name'] ?? '—') ?> <?= (int) $s['on_time'] ? '' : '<span class="text-warning-emphasis">(tarde)</span>' ?> <?= $resultBadge($s['inspection_result']) ?></div><?php endif; ?>
                        <?php if ($s['status'] === 'omitida'): ?><div class="small text-body-secondary">Omitida: <?= e($s['skip_reason']) ?></div><?php endif; ?>
                    </div>
                    <?php if ($s['status'] === 'pendiente'): ?>
                        <div class="d-flex gap-2">
                            <?php if ($canDo): ?><a class="btn btn-sm btn-primary" href="<?= e($link) ?>">Hacer</a><?php endif; ?>
                            <?php if ($canSkip): ?><button type="button" class="btn btn-sm btn-outline-secondary" @click="skip = !skip">Omitir</button><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($canSkip && $s['status'] === 'pendiente'): ?>
                    <form method="post" action="<?= e(url('/panel/inspecciones/programadas/' . $s['uuid'] . '/omitir')) ?>" class="d-flex gap-2 mt-2" x-show="skip" x-cloak>
                        <?= csrf_field() ?>
                        <input class="form-control form-control-sm" name="reason" required minlength="5" placeholder="Motivo (ej. equipo en reparación)">
                        <button class="btn btn-sm btn-outline-danger">Confirmar</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="list-group-item small text-body-secondary">No hay inspecciones en esta lista.</div><?php endif; ?>
    </div>
</div>
