<?php
/** Turnos → alta / edición. @var ?array $shift @var array $sites @var array $guards @var array $userUuids @var int $defaultGrace @var ?array $old */
use App\Services\ShiftPlanner;
$v = $old ?? [];
$s = $shift ?? [];
$pattern = $v['pattern'] ?? ($s['pattern'] ?? 'weekly');
$days = array_map('intval', (array) ($v['days'] ?? explode(',', (string) ($s['days'] ?? '1,2,3,4,5'))));
$users = (array) ($v['users'] ?? $userUuids);
$site = $v['site'] ?? ($s['site_uuid'] ?? (count($sites) === 1 ? $sites[0]['uuid'] : ''));
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'programacion'], null) ?>
<h2 class="h4 mb-3"><?= $shift ? 'Editar turno' : 'Nuevo turno de guardia' ?></h2>
<?php if (!$sites): ?>
    <div class="alert alert-warning">Primero cargá la planta / predio en <a href="<?= e(url('/panel/datos/plantas')) ?>">Datos maestros → Plantas</a>, con su ubicación y radio (el área del predio).</div>
<?php endif; ?>
<form method="post" action="<?= e(url('/panel/rondas/turnos/programacion' . ($shift ? '/' . $shift['uuid'] : ''))) ?>" class="card card-body" style="max-width: 860px"
      x-data="{ pattern: <?= e(json_encode($pattern)) ?>, on: <?= (int) ($v['rotation_on'] ?? ($s['rotation_on'] ?? 1)) ?>, off: <?= (int) ($v['rotation_off'] ?? ($s['rotation_off'] ?? 1)) ?>,
               start: <?= e(json_encode($v['start_time'] ?? ($s['start_time'] ?? '08:00'))) ?>, end: <?= e(json_encode($v['end_time'] ?? ($s['end_time'] ?? '16:00'))) ?>,
               preset(on, off, start, end) { this.pattern = 'rotation'; this.on = on; this.off = off; this.start = start; this.end = end; } }">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Predio</label>
            <select class="form-select" name="site" required><option value="">Elegí la planta / predio…</option>
                <?php foreach ($sites as $p): ?><option value="<?= e($p['uuid']) ?>" <?= $site === $p['uuid'] ? 'selected' : '' ?>><?= e($p['name']) ?><?= $p['lat'] === null ? ' (sin ubicación cargada)' : '' ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">El control de salida usa la ubicación y el radio del predio (Datos maestros → Plantas).</div></div>
        <div class="col-md-6"><label class="form-label">Nombre (opcional)</label><input class="form-control" name="name" maxlength="120" value="<?= e($v['name'] ?? ($s['name'] ?? '')) ?>" placeholder="Noche portería"></div>
    </div>

    <label class="form-label mt-3">Tipo de turno</label>
    <div class="btn-group mb-2 flex-wrap" role="group">
        <input type="radio" class="btn-check" name="pattern" value="weekly" id="p-weekly" x-model="pattern"><label class="btn btn-outline-primary" for="p-weekly">Fijo (días de la semana)</label>
        <input type="radio" class="btn-check" name="pattern" value="rotation" id="p-rot" x-model="pattern"><label class="btn btn-outline-primary" for="p-rot">Rotativo (días sí / días no)</label>
    </div>
    <div x-show="pattern === 'rotation'" class="mb-2">
        <div class="small mb-1">Esquemas comunes:</div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="preset(1, 1, '08:00', '20:00')">12×36 (día por medio, 12 h)</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="preset(1, 2, '08:00', '07:59')">24×48 (24 h cada 3 días)</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="preset(2, 2, start, end)">2×2</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="preset(4, 4, start, end)">4×4</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="preset(6, 1, start, end)">6×1</button>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-sm-3"><label class="form-label">Entra</label><input class="form-control" type="time" name="start_time" x-model="start" required></div>
        <div class="col-sm-3"><label class="form-label">Sale</label><input class="form-control" type="time" name="end_time" x-model="end" required></div>
        <div class="col-sm-6 form-text pt-sm-4">Si sale a una hora menor que la de entrada, termina al día siguiente (22:00 → 06:00). Cuenta el día en que empieza.</div>
    </div>

    <div x-show="pattern === 'weekly'" class="mt-3">
        <label class="form-label">Días</label>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach (ShiftPlanner::DAYS as $n => $label): ?>
                <input type="checkbox" class="btn-check" name="days[]" value="<?= $n ?>" id="d<?= $n ?>" <?= in_array($n, $days, true) ? 'checked' : '' ?>><label class="btn btn-outline-secondary btn-sm" for="d<?= $n ?>"><?= e($label) ?></label>
            <?php endforeach; ?>
        </div>
    </div>
    <div x-show="pattern === 'rotation'" class="row g-3 mt-1">
        <div class="col-sm-3"><label class="form-label">Trabaja</label><div class="input-group"><input class="form-control" type="number" min="1" max="14" name="rotation_on" x-model="on"><span class="input-group-text">día(s)</span></div></div>
        <div class="col-sm-3"><label class="form-label">Descansa</label><div class="input-group"><input class="form-control" type="number" min="1" max="14" name="rotation_off" x-model="off"><span class="input-group-text">día(s)</span></div></div>
        <div class="col-sm-6"><label class="form-label">Primer día de trabajo del ciclo</label><input class="form-control" type="date" name="anchor_date" value="<?= e($v['anchor_date'] ?? ($s['anchor_date'] ?? '')) ?>">
            <div class="form-text">Desde esa fecha se repite: <span x-text="on"></span> día(s) trabaja, <span x-text="off"></span> descansa.</div></div>
    </div>

    <label class="form-label mt-3">Guardias</label>
    <div class="border rounded p-2" style="max-height: 220px; overflow:auto">
        <?php foreach ($guards as $g): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="users[]" value="<?= e($g['uuid']) ?>" id="u<?= e($g['uuid']) ?>" <?= in_array($g['uuid'], $users, true) ? 'checked' : '' ?>>
                <label class="form-check-label" for="u<?= e($g['uuid']) ?>"><?= e($g['name']) ?> <span class="small text-body-secondary"><?= e($g['role_name']) ?></span></label></div>
        <?php endforeach; ?>
        <?php if (!$guards): ?><div class="text-body-secondary small">No hay usuarios con el módulo Rondas.</div><?php endif; ?>
    </div>
    <div class="form-text">Cada guardia elegido tiene ese turno. Un guardia puede estar en turnos de varios predios.</div>

    <div class="row g-3 mt-1">
        <div class="col-md-3"><label class="form-label">Vigente desde</label><input class="form-control" type="date" name="valid_from" value="<?= e($v['valid_from'] ?? ($s['valid_from'] ?? '')) ?>"></div>
        <div class="col-md-3"><label class="form-label">Hasta</label><input class="form-control" type="date" name="valid_until" value="<?= e($v['valid_until'] ?? ($s['valid_until'] ?? '')) ?>"></div>
        <div class="col-md-6"><label class="form-label">Tolerancia para empezar</label><div class="input-group"><input class="form-control" type="number" min="1" max="120" name="grace_minutes" value="<?= e($v['grace_minutes'] ?? ($s['grace_minutes'] ?? '')) ?>" placeholder="<?= (int) $defaultGrace ?>"><span class="input-group-text">min</span></div>
            <div class="form-text">Después le avisa el celular; al doble, se avisa al supervisor. Vacío = la de la empresa (<?= (int) $defaultGrace ?>).</div></div>
    </div>

    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary"><?= $shift ? 'Guardar cambios' : 'Crear turno' ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/panel/rondas/turnos/programacion')) ?>">Cancelar</a>
    </div>
</form>
