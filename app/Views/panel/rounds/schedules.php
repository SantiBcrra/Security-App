<?php
/** @var array $slots @var array $schedules @var string $day @var string $today @var bool $canEdit */
use App\Services\PatrolPlanner;
$prev = (new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d');
$next = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
$count = ['ok' => 0, 'late' => 0, 'missed' => 0, 'open' => 0];
foreach ($slots as $s) {
    if ($s['status'] === 'realizada') $count[(int) $s['on_time'] === 1 ? 'ok' : 'late']++;
    elseif ($s['status'] === 'no_realizada') $count['missed']++;
    else $count['open']++;
}
$closed = $count['ok'] + $count['late'] + $count['missed'];
?>
<?= App\Core\View::render('panel/rounds/_nav', ['tab' => 'programacion', 'subtitle' => 'Horarios en que se tiene que hacer cada ruta, y si se cumplieron (a tiempo, tarde o no realizadas)'], null) ?>


<div class="card shadow-sm mb-4">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/programacion?fecha=' . $prev)) ?>">‹</a>
        <form method="get" class="d-flex gap-2"><input type="date" name="fecha" value="<?= e($day) ?>" class="form-control form-control-sm" onchange="this.form.submit()"></form>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/programacion?fecha=' . $next)) ?>">›</a>
        <?php if ($day !== $today): ?><a class="btn btn-sm btn-link" href="<?= e(url('/panel/rondas/programacion')) ?>">Hoy</a><?php endif; ?>
        <span class="ms-auto small">
            <span class="badge text-bg-success"><?= $count['ok'] ?> a tiempo</span>
            <span class="badge text-bg-warning"><?= $count['late'] ?> tarde</span>
            <span class="badge text-bg-danger"><?= $count['missed'] ?> no realizadas</span>
            <span class="badge text-bg-secondary"><?= $count['open'] ?> pendientes</span>
            <?php if ($closed > 0): ?> · cumplimiento <strong><?= (int) round(100 * $count['ok'] / $closed) ?> %</strong> a tiempo<?php endif; ?>
        </span>
    </div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Hora</th><th>Ruta</th><th>Estado</th><th>Empezó</th><th>Guardia</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($slots as $s): [$label, $class] = PatrolPlanner::label($s); ?>
            <tr>
                <td><strong><?= e(fecha($s['due_at'], 'H:i')) ?></strong> <span class="small text-body-secondary">±<?= (int) $s['grace_minutes'] ?> min</span></td>
                <td><?= e($s['route_name']) ?><?= $s['schedule_name'] ? '<div class="small text-body-secondary">' . e($s['schedule_name']) . '</div>' : '' ?></td>
                <td><span class="badge text-bg-<?= e($class) ?>"><?= e($label) ?></span></td>
                <td><?= $s['started_at'] ? e(fecha($s['started_at'], 'H:i')) : '—' ?></td>
                <td><?= e($s['user_name'] ?? '—') ?></td>
                <td><?php if ($s['round_uuid']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/ronda/' . $s['round_uuid'])) ?>">Ver ronda</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$slots): ?><tr><td colspan="6" class="text-body-secondary p-3">No hay rondas programadas para este día.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<?php if ($schedules): ?>
<h2 class="h5">Programaciones</h2>
<div class="table-responsive"><table class="table table-sm align-middle">
    <thead><tr><th>Ruta</th><th>Cuándo</th><th>Vigencia</th><th>Gracia / demora máx.</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($schedules as $sc): ?>
        <tr class="<?= (int) $sc['is_active'] === 1 ? '' : 'text-body-secondary' ?>">
            <td><?= e($sc['route_name']) ?><?= $sc['name'] ? '<div class="small text-body-secondary">' . e($sc['name']) . '</div>' : '' ?></td>
            <td><?= e(PatrolPlanner::describe($sc)) ?></td>
            <td class="small"><?= $sc['valid_from'] || $sc['valid_until'] ? e(($sc['valid_from'] ? date('d/m/Y', strtotime($sc['valid_from'])) : '…') . ' → ' . ($sc['valid_until'] ? date('d/m/Y', strtotime($sc['valid_until'])) : '…')) : 'Siempre' ?>
                <?php $ex = json_decode((string) $sc['except_dates'], true) ?: []; ?><?= $ex ? '<div>' . count($ex) . ' fecha(s) excluida(s)</div>' : '' ?></td>
            <td class="small">±<?= e($sc['grace_minutes'] ?? PatrolPlanner::defaultGrace()) ?> min / <?= e($sc['max_delay_minutes']) ?> min</td>
            <td><?= (int) $sc['is_active'] === 1 ? '<span class="badge text-bg-success">Activa</span>' : '<span class="badge text-bg-secondary">Pausada</span>' ?></td>
            <td class="text-end text-nowrap"><?php if ($canEdit): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/programacion/' . $sc['uuid'])) ?>">Editar</a>
                <form method="post" action="<?= e(url('/panel/rondas/programacion/' . $sc['uuid'] . '/estado')) ?>" class="d-inline"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-<?= (int) $sc['is_active'] === 1 ? 'warning' : 'success' ?>"><?= (int) $sc['is_active'] === 1 ? 'Pausar' : 'Activar' ?></button></form>
            <?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php elseif ($canEdit): ?>
<p class="text-body-secondary">Todavía no hay programaciones. Creá una para que las rondas tengan horario y se controle si se hacen.</p>
<?php endif; ?>
