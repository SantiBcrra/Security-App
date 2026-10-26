<?php
/** Turnos → Hoy. @var array $posts @var array $slots @var array $pending @var string $day @var string $today @var bool $hasShifts @var bool $canEdit */
use App\Services\ShiftReport;
use App\Services\ShiftService;
$prev = date('Y-m-d', strtotime($day . ' -1 day'));
$next = date('Y-m-d', strtotime($day . ' +1 day'));
$k = ['inside' => 0, 'outside' => 0, 'late' => 0, 'absent' => 0, 'justified' => 0, 'done' => 0];
foreach ($slots as $s) {
    [$label] = ShiftService::label($s);
    if ($s['status'] === 'en_curso') $k[$s['open_exit_uuid'] ? 'outside' : 'inside']++;
    elseif ($label === 'Sin empezar') $k['late']++;
    elseif ($s['status'] === 'ausente') $k['absent']++;
    elseif ($s['status'] === 'justificado') $k['justified']++;
    elseif ($s['status'] === 'cumplido') $k['done']++;
}
$card = fn (string $title, int $n, string $hint, string $color) => '<div class="col-6 col-lg"><div class="card h-100 border-' . $color . '"><div class="card-body py-2">'
    . '<div class="small text-body-secondary">' . e($title) . '</div><div class="fs-3 fw-semibold text-' . $color . '">' . $n . '</div><div class="small text-body-secondary">' . e($hint) . '</div></div></div></div>';
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'hoy'], null) ?>

<?php if ($pending): ?>
    <a class="alert alert-warning d-flex justify-content-between align-items-center text-decoration-none" href="<?= e(url('/panel/rondas/turnos/ausencias')) ?>">
        <span><strong><?= count($pending) ?> pedido(s) de ausencia</strong> esperando aprobación.</span><span class="btn btn-sm btn-warning">Revisar</span>
    </a>
<?php endif; ?>

<?php if (!$hasShifts): ?>
    <div class="card shadow-sm mb-3"><div class="card-body">
        <h2 class="h5">Todavía no hay turnos cargados</h2>
        <p class="mb-2">Cargá el horario de trabajo de cada guardia (fijo, por ejemplo Lun a Vie 22 a 6, o rotativo como 12×36 o 2×2). Con eso:</p>
        <ul class="mb-3">
            <li>el guardia toca <strong>Empezar turno</strong> en la app y queda registrada la hora real y si estaba en el predio;</li>
            <li>si no empieza a horario, si sale del predio sin motivo o si se queda sin señal, se avisa a SyH y supervisores;</li>
            <li>las ausencias aprobadas (enfermedad, vacaciones…) evitan alarmas ese día;</li>
            <li>tenés el reporte de horas trabajadas.</li>
        </ul>
        <?php if ($canEdit): ?><a class="btn btn-primary" href="<?= e(url('/panel/rondas/turnos/programacion/nuevo')) ?>">+ Cargar el primer turno</a><?php endif; ?>
    </div></div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <?php if ($canEdit): ?><a class="btn btn-sm btn-primary order-last ms-auto" href="<?= e(url('/panel/rondas/turno/nuevo?fecha=' . $day)) ?>">+ Turno suelto</a><?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/turnos?fecha=' . $prev)) ?>">‹</a>
    <form method="get"><input type="date" name="fecha" value="<?= e($day) ?>" class="form-control form-control-sm" onchange="this.form.submit()"></form>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/turnos?fecha=' . $next)) ?>">›</a>
    <?php if ($day !== $today): ?><a class="btn btn-sm btn-link" href="<?= e(url('/panel/rondas/turnos')) ?>">Hoy</a><?php endif; ?>
    <span class="text-body-secondary small ms-1"><?= e(App\Services\ShiftPlanner::DAYS[(int) date('N', strtotime($day))]) ?> <?= e(date('d/m/Y', strtotime($day))) ?></span>
</div>

<?php if (!empty($posts)): ?><?= App\Core\View::render('panel/shifts/_posts_today', ['posts' => $posts], null) ?><?php endif; ?>

<div class="row g-2 mb-3">
    <?= $card('En turno, en el predio', $k['inside'], 'ahora', 'success') ?>
    <?= $card('Fuera del predio', $k['outside'], 'en turno, salió', 'danger') ?>
    <?= $card('Sin empezar', $k['late'], 'pasó la tolerancia', 'warning') ?>
    <?= $card('No vinieron', $k['absent'], 'sin justificar', 'danger') ?>
    <?= $card('Ausencias justificadas', $k['justified'], 'sin alarmas', 'info') ?>
    <?= $card('Cumplidos', $k['done'], 'turnos terminados', 'secondary') ?>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold">Turnos del día</div>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead><tr><th>Guardia</th><th>Predio</th><th>Horario</th><th>Estado</th><th>Inicio real</th><th>Fin real</th><th>Fuera del predio</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($slots as $s): [$label, $color] = ShiftService::label($s); $m = ShiftReport::measure($s); ?>
            <tr role="button" onclick="if (!event.target.closest('a')) location.href='<?= e(url('/panel/rondas/turno/' . $s['uuid'])) ?>'">
                <td class="fw-semibold"><?= e($s['user_name']) ?><?= $s['coverage_id'] ? ' <span class="badge text-bg-info">puesto</span>' : '' ?><?= $s['replaces_slot_id'] ? ' <span class="badge text-bg-light">reemplazo</span>' : '' ?><?= $s['unscheduled'] ? ' <span class="badge text-bg-light">no programado</span>' : '' ?></td>
                <td><?= e($s['site_name']) ?></td>
                <td class="text-nowrap"><?= e(fecha($s['start_at'], 'H:i')) ?> – <?= e(fecha($s['end_at'], 'H:i')) ?><?= fecha($s['start_at'], 'Y-m-d') !== fecha($s['end_at'], 'Y-m-d') ? ' <span class="small text-body-secondary">(+1)</span>' : '' ?></td>
                <td><span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span></td>
                <td class="text-nowrap"><?php if ($s['started_at']): ?><?= e(fecha($s['started_at'], 'H:i')) ?>
                    <?= $m['late'] > 0 ? '<span class="badge text-bg-warning">' . $m['late'] . ' min tarde</span>' : '' ?>
                    <?= $s['start_inside'] === null ? '' : ((int) $s['start_inside'] === 1 ? '' : ' <span class="badge text-bg-danger">fuera del predio</span>') ?>
                <?php else: ?>—<?php endif; ?></td>
                <td class="text-nowrap"><?= $s['ended_at'] ? e(fecha($s['ended_at'], 'H:i')) . ($s['end_mode'] === 'auto' ? ' <span class="badge text-bg-secondary">automático</span>' : '')
                    . ($s['end_mode'] === 'relevo' ? ' <span class="badge text-bg-light">relevo</span>' : '') . ($s['end_mode'] === 'sin_persona' ? ' <span class="badge text-bg-secondary">sin persona</span>' : '')
                    . ($m['early'] > 0 ? ' <span class="badge text-bg-warning">' . $m['early'] . ' min antes</span>' : '') : '—' ?></td>
                <td><?= (int) $s['exits_count'] > 0 ? e($s['exits_count'] . ' salida(s) · ' . $m['out'] . ' min') : '—' ?></td>
                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/turno/' . $s['uuid'])) ?>">Ver</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$slots): ?><tr><td colspan="8" class="text-body-secondary">No hay turnos este día.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
