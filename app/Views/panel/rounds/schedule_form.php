<?php
/** @var ?array $schedule @var array $routes @var array $assignees @var array $userUuids @var int $defaultGrace @var ?array $old */
use App\Services\PatrolPlanner;
$v = $old ?? [];
$s = $schedule ?? [];
$route = $v['route'] ?? ($s['route_uuid'] ?? '');
$mode = $v['mode'] ?? ($s['mode'] ?? 'fixed');
$times = $v['times'] ?? implode(', ', json_decode((string) ($s['times'] ?? ''), true) ?: []);
$days = array_map('intval', (array) ($v['days'] ?? explode(',', (string) ($s['days'] ?? '1,2,3,4,5,6,7'))));
$except = $v['except_dates'] ?? implode(', ', array_map(fn ($d) => date('d/m/Y', strtotime($d)), json_decode((string) ($s['except_dates'] ?? ''), true) ?: []));
$users = (array) ($v['users'] ?? $userUuids);
?>
<?= App\Core\View::render('panel/rounds/_nav', ['tab' => 'programacion', 'subtitle' => ''], null) ?>
<h2 class="h4 mb-3"><?= $schedule ? 'Editar programación' : 'Nueva programación' ?></h2>
<form method="post" action="<?= e(url('/panel/rondas/programacion' . ($schedule ? '/' . $schedule['uuid'] : ''))) ?>" class="card card-body" style="max-width: 820px"
      x-data="{ mode: <?= e(json_encode($mode)) ?>, route: <?= e(json_encode($route)) ?> }">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-7"><label class="form-label">Ruta</label>
            <select class="form-select" name="route" x-model="route" required><option value="">Elegí la ruta…</option>
                <?php foreach ($routes as $r): ?><option value="<?= e($r['uuid']) ?>"><?= e($r['name']) ?><?= $r['expected_minutes'] ? ' (≈ ' . (int) $r['expected_minutes'] . ' min)' : '' ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-5"><label class="form-label">Nombre (opcional)</label><input class="form-control" name="name" maxlength="120" value="<?= e($v['name'] ?? ($s['name'] ?? '')) ?>" placeholder="Turno noche"></div>
    </div>

    <label class="form-label mt-3">¿Cuándo?</label>
    <div class="btn-group mb-2" role="group">
        <input type="radio" class="btn-check" name="mode" value="fixed" id="m-fixed" x-model="mode"><label class="btn btn-outline-primary" for="m-fixed">Horarios fijos</label>
        <input type="radio" class="btn-check" name="mode" value="interval" id="m-interval" x-model="mode"><label class="btn btn-outline-primary" for="m-interval">Cada cierto tiempo, en una franja</label>
    </div>
    <div x-show="mode === 'fixed'">
        <input class="form-control" name="times" value="<?= e(is_array($times) ? implode(', ', $times) : $times) ?>" placeholder="22:00, 02:00, 06:00">
        <div class="form-text">Separados por coma, en hora de la empresa.</div>
    </div>
    <div x-show="mode === 'interval'" class="row g-2 align-items-end">
        <div class="col-sm-4"><label class="form-label small">Cada</label><div class="input-group"><input class="form-control" type="number" min="15" max="720" name="every_minutes" value="<?= e($v['every_minutes'] ?? ($s['every_minutes'] ?? '120')) ?>"><span class="input-group-text">min</span></div></div>
        <div class="col-sm-4"><label class="form-label small">Desde</label><input class="form-control" type="time" name="window_start" value="<?= e($v['window_start'] ?? ($s['window_start'] ?? '22:00')) ?>"></div>
        <div class="col-sm-4"><label class="form-label small">Hasta</label><input class="form-control" type="time" name="window_end" value="<?= e($v['window_end'] ?? ($s['window_end'] ?? '06:00')) ?>"></div>
        <div class="form-text">Si "hasta" es menor que "desde", la franja sigue al día siguiente (22:00 → 06:00). Incluye la hora final.</div>
    </div>

    <label class="form-label mt-3">Días</label>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach (PatrolPlanner::DAYS as $n => $label): ?>
            <input type="checkbox" class="btn-check" name="days[]" value="<?= $n ?>" id="d<?= $n ?>" <?= in_array($n, $days, true) ? 'checked' : '' ?>><label class="btn btn-outline-secondary btn-sm" for="d<?= $n ?>"><?= e($label) ?></label>
        <?php endforeach; ?>
    </div>
    <div class="form-text">En una franja que cruza la medianoche cuenta el día en que empieza.</div>

    <div class="row g-3 mt-1">
        <div class="col-md-3"><label class="form-label">Vigente desde</label><input class="form-control" type="date" name="valid_from" value="<?= e($v['valid_from'] ?? ($s['valid_from'] ?? '')) ?>"></div>
        <div class="col-md-3"><label class="form-label">Hasta</label><input class="form-control" type="date" name="valid_until" value="<?= e($v['valid_until'] ?? ($s['valid_until'] ?? '')) ?>"></div>
        <div class="col-md-6"><label class="form-label">Fechas excluidas (feriados)</label><input class="form-control" name="except_dates" value="<?= e($except) ?>" placeholder="25/12/2026, 01/01/2027"></div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-md-6"><label class="form-label">Gracia</label><div class="input-group"><span class="input-group-text">±</span><input class="form-control" type="number" min="1" max="120" name="grace_minutes" value="<?= e($v['grace_minutes'] ?? ($s['grace_minutes'] ?? '')) ?>" placeholder="<?= (int) $defaultGrace ?>"><span class="input-group-text">min</span></div>
            <div class="form-text">"A tiempo" = empieza hasta estos minutos antes o después de la hora. Vacío = la de la empresa (<?= (int) $defaultGrace ?>).</div></div>
        <div class="col-md-6"><label class="form-label">Puede empezar tarde hasta</label><div class="input-group"><input class="form-control" type="number" min="1" max="720" name="max_delay_minutes" value="<?= e($v['max_delay_minutes'] ?? ($s['max_delay_minutes'] ?? '60')) ?>"><span class="input-group-text">min después</span></div>
            <div class="form-text">Pasado ese tiempo sin empezar queda <strong>no realizada</strong> y se avisa.</div></div>
    </div>

    <label class="form-label mt-3">Guardias a cargo</label>
    <?php foreach ($assignees as $routeUuid => $list): ?>
        <div x-show="route === <?= e(json_encode($routeUuid)) ?>" class="list-group mb-1">
            <?php foreach ($list as $u): ?>
                <label class="list-group-item"><input class="form-check-input me-2" type="checkbox" name="users[]" value="<?= e($u['uuid']) ?>" <?= in_array($u['uuid'], $users, true) ? 'checked' : '' ?> :disabled="route !== <?= e(json_encode($routeUuid)) ?>"><?= e($u['name']) ?></label>
            <?php endforeach; ?>
            <?php if (!$list): ?><div class="list-group-item text-body-secondary small">La ruta no tiene guardias asignados: asignalos en la ruta.</div><?php endif; ?>
        </div>
    <?php endforeach; ?>
    <div class="form-text">Sin marcar ninguno = todos los guardias asignados a la ruta.</div>

    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary"><?= $schedule ? 'Guardar cambios' : 'Crear programación' ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/panel/rondas/programacion')) ?>">Cancelar</a>
    </div>
</form>
