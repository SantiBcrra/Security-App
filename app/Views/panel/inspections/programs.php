<?php use App\Services\InspectionPlanner as P; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/programadas')) ?>">←</a>
        <h1 class="h4 m-0">Programas de inspección</h1>
    </div>
    <a class="btn btn-primary btn-sm" href="<?= e(url('/panel/inspecciones/programas/nuevo')) ?>">+ Nuevo programa</a>
</div>
<div class="card shadow-sm">
    <div class="list-group list-group-flush">
        <?php foreach ($programs as $p):
            $when = match ($p['frequency']) {
                'semanal' => 'Semanal, los ' . P::WEEKDAYS[(int) $p['weekday']], 'mensual' => 'Mensual, el día ' . $p['monthday'], default => P::FREQUENCIES[$p['frequency']],
            };
            $what = match ($p['target_type']) {
                'equipo' => $p['equipment_code'] . ' · ' . $p['equipment_name'],
                'tipo'   => 'Todos los «' . $p['equipment_type_name'] . '»' . ($p['sector_name'] ? ' de ' . $p['sector_name'] : ''),
                default  => 'Sector ' . $p['sector_name'],
            };
            $who = match ($p['assignee_type']) { 'user' => $p['assignee_name'], 'role' => 'Rol ' . $p['assignee_role'], default => 'Supervisores del sector' }; ?>
            <div class="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2 <?= $p['is_active'] ? '' : 'opacity-50' ?>">
                <div>
                    <a class="fw-semibold" href="<?= e(url('/panel/inspecciones/programas/' . $p['uuid'])) ?>"><?= e($p['name']) ?></a>
                    <?= $p['is_active'] ? '' : '<span class="badge text-bg-secondary">Pausado</span>' ?>
                    <div class="small text-body-secondary"><?= e($p['template_name']) ?> · <?= e($what) ?> · <?= e($when) ?> · a cargo: <?= e($who) ?>
                        <?= $p['action_responsible_name'] ? ' · acciones: ' . e($p['action_responsible_name']) : '' ?></div>
                </div>
                <form method="post" action="<?= e(url('/panel/inspecciones/programas/' . $p['uuid'] . '/estado')) ?>"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-secondary"><?= $p['is_active'] ? 'Pausar' : 'Activar' ?></button></form>
            </div>
        <?php endforeach; ?>
        <?php if (!$programs): ?><div class="list-group-item small text-body-secondary">Todavía no hay programas. Ej.: pre-uso diario de todos los autoelevadores, extintores una vez por mes.</div><?php endif; ?>
    </div>
</div>
