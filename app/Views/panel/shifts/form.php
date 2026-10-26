<?php
/** Turnos → alta / edición. @var ?array $shift @var array $sites @var array $guards @var array $devices @var array $userUuids @var int $defaultGrace @var ?array $old */
use App\Services\ShiftPlanner;
$v = $old ?? [];
$s = $shift ?? [];
$pattern = $v['pattern'] ?? ($s['pattern'] ?? 'weekly');
$mode = $v['mode'] ?? ($s['mode'] ?? 'personal');
$device = $v['device'] ?? ($s['device_uuid'] ?? '');
$days = array_map('intval', (array) ($v['days'] ?? explode(',', (string) ($s['days'] ?? '1,2,3,4,5'))));
$users = (array) ($v['users'] ?? $userUuids);
$site = $v['site'] ?? ($s['site_uuid'] ?? (count($sites) === 1 ? $sites[0]['uuid'] : ''));
// Franjas del horario por día para el formulario: {day, start, plus (días hasta la salida), end}
$rawBlocks = isset($v['blocks']) ? (json_decode((string) $v['blocks'], true) ?: []) : ShiftPlanner::blocks($s);
$formBlocks = array_map(fn ($b) => ['day' => (int) ($b['day'] ?? 1), 'start' => (string) ($b['start'] ?? '08:00'), 'end' => (string) ($b['end'] ?? '16:00'),
    'plus' => isset($b['plus']) ? (int) $b['plus'] : (((int) ($b['end_day'] ?? $b['day'] ?? 1) - (int) ($b['day'] ?? 1) + 7) % 7)], $rawBlocks);
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'programacion'], null) ?>
<h2 class="h4 mb-3"><?= $shift ? 'Editar turno' : 'Nuevo turno de guardia' ?></h2>
<?php if (!$sites): ?>
    <div class="alert alert-warning">Primero cargá la planta / predio en <a href="<?= e(url('/panel/datos/plantas')) ?>">Datos maestros → Plantas</a>, con su ubicación y radio (el área del predio).</div>
<?php endif; ?>
<form method="post" action="<?= e(url('/panel/rondas/turnos/programacion' . ($shift ? '/' . $shift['uuid'] : ''))) ?>" class="card card-body" style="max-width: 860px"
      x-data="{ mode: <?= e(json_encode($mode)) ?>, pattern: <?= e(json_encode($pattern)) ?>, on: <?= (int) ($v['rotation_on'] ?? ($s['rotation_on'] ?? 1)) ?>, off: <?= (int) ($v['rotation_off'] ?? ($s['rotation_off'] ?? 1)) ?>,
               start: <?= e(json_encode($v['start_time'] ?? ($s['start_time'] ?? '08:00'))) ?>, end: <?= e(json_encode($v['end_time'] ?? ($s['end_time'] ?? '16:00'))) ?>,
               preset(on, off, start, end) { this.pattern = 'rotation'; this.on = on; this.off = off; this.start = start; this.end = end; },
               dayNames: <?= e(json_encode(array_values(ShiftPlanner::DAYS))) ?>, maxHours: <?= ShiftPlanner::MAX_BLOCK_HOURS ?>,
               blocks: <?= e(json_encode($formBlocks)) ?>,
               min(t) { const [h, m] = (t || '0:0').split(':').map(Number); return h * 60 + m; },
               minutes(b) { return Number(b.plus) * 1440 + this.min(b.end) - this.min(b.start); },
               hours(b) { const m = this.minutes(b); return m <= 0 ? 'la salida es antes de la entrada' : (Math.floor(m / 60) + ' h' + (m % 60 ? ' ' + (m % 60) + ' min' : '')); },
               bad(b) { const m = this.minutes(b); return m <= 0 || m > this.maxHours * 60; },
               endName(b, plus) { return this.dayNames[(Number(b.day) - 1 + plus) % 7] + (plus === 0 ? ' (mismo día)' : (plus === 1 ? ' (día siguiente)' : '')); },
               fixEnd(b) { if (Number(b.plus) === 0 && this.min(b.end) <= this.min(b.start)) b.plus = 1; },
               add() { const last = this.blocks[this.blocks.length - 1]; this.blocks.push(last ? { ...last, day: last.day % 7 + 1 } : { day: 1, start: '08:00', plus: 0, end: '16:00' }); },
               copyWeekdays(b) { for (let d = 1; d <= 5; d++) { if (!this.blocks.some(x => Number(x.day) === d)) this.blocks.push({ ...b, day: d }); } this.sort(); },
               sort() { this.blocks.sort((a, b) => (a.day - b.day) || (this.min(a.start) - this.min(b.start))); },
               fromWeekly() {
                   if (this.blocks.length) return;
                   const days = [...document.querySelectorAll('input[name=\'days[]\']:checked')].map(i => Number(i.value));
                   const plus = this.min(this.end) <= this.min(this.start) ? 1 : 0;
                   this.blocks = (days.length ? days : [1, 2, 3, 4, 5]).map(d => ({ day: d, start: this.start, plus, end: this.end }));
               },
               usePreset(list) { this.blocks = list.map(([day, start, plus, end]) => ({ day, start, plus, end })); },
               payload() { return JSON.stringify(this.blocks.map(b => ({ day: Number(b.day), start: b.start, end_day: (Number(b.day) - 1 + Number(b.plus)) % 7 + 1, end: b.end }))); } }"
      x-init="$watch('pattern', p => { if (p === 'custom') fromWeekly(); })">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Predio</label>
            <select class="form-select" name="site" required><option value="">Elegí la planta / predio…</option>
                <?php foreach ($sites as $p): ?><option value="<?= e($p['uuid']) ?>" <?= $site === $p['uuid'] ? 'selected' : '' ?>><?= e($p['name']) ?><?= $p['lat'] === null ? ' (sin ubicación cargada)' : '' ?></option><?php endforeach; ?>
            </select>
            <div class="form-text">El control de salida usa la ubicación y el radio del predio (Datos maestros → Plantas).</div></div>
        <div class="col-md-6"><label class="form-label">Nombre (opcional)</label><input class="form-control" name="name" maxlength="120" value="<?= e($v['name'] ?? ($s['name'] ?? '')) ?>" placeholder="Noche portería"></div>
    </div>

    <label class="form-label mt-3">¿Quién cubre este horario?</label>
    <div class="btn-group mb-2 flex-wrap" role="group">
        <input type="radio" class="btn-check" name="mode" value="personal" id="m-personal" x-model="mode"><label class="btn btn-outline-primary" for="m-personal">Guardias fijos (sé quién viene)</label>
        <input type="radio" class="btn-check" name="mode" value="puesto" id="m-puesto" x-model="mode"><label class="btn btn-outline-primary" for="m-puesto">Un puesto que cubre un grupo (no sé quién viene)</label>
    </div>
    <div class="alert alert-info small" x-show="mode === 'puesto'">
        <strong>Turno por puesto</strong> (por ejemplo guardias de una empresa de seguridad que se organizan solos): cargás el horario que hay que
        cubrir y el grupo de guardias. Cualquiera del grupo lo cubre tocando <strong>Empezar turno</strong>; el que llega releva al que estaba.
        Se avisa si nadie empezó, si alguien se fue y nadie lo relevó, o si una persona lleva demasiadas horas seguidas. No hay avisos de "no vino"
        por persona ni ausencias: ellos se organizan. Si el guardia deja el puesto <strong>sin persona</strong> con un motivo (viene el encargado, el dueño…), no se avisa.
    </div>

    <label class="form-label mt-1">Tipo de horario</label>
    <div class="btn-group mb-2 flex-wrap" role="group">
        <input type="radio" class="btn-check" name="pattern" value="weekly" id="p-weekly" x-model="pattern"><label class="btn btn-outline-primary" for="p-weekly">Fijo (días de la semana)</label>
        <input type="radio" class="btn-check" name="pattern" value="custom" id="p-custom" x-model="pattern"><label class="btn btn-outline-primary" for="p-custom">Horario por día (franjas)</label>
        <input type="radio" class="btn-check" name="pattern" value="rotation" id="p-rot" x-model="pattern"><label class="btn btn-outline-primary" for="p-rot">Rotativo (días sí / días no)</label>
    </div>
    <div class="form-text mb-2" x-show="pattern === 'custom'">Horarios distintos según el día y turnos largos de fin de semana: cada franja tiene día y hora de entrada y de
        salida (por ejemplo, sábado 14:00 a lunes 08:00). Cada franja es un turno en la app del guardia, de hasta <?= ShiftPlanner::MAX_BLOCK_HOURS ?> h.</div>

    <div x-show="pattern === 'custom'" class="mb-3">
        <input type="hidden" name="blocks" :value="payload()">
        <div class="small mb-1">Atajos (reemplazan las franjas):</div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="usePreset([[1,'20:00',1,'08:00'],[2,'20:00',1,'08:00'],[3,'20:00',1,'08:00'],[4,'20:00',1,'08:00'],[5,'20:00',1,'08:00'],[6,'14:00',2,'08:00']])">Noches Lun a Vie + fin de semana (Sáb 14:00 a Lun 08:00)</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="usePreset([[1,'08:00',0,'17:00'],[2,'08:00',0,'17:00'],[3,'08:00',0,'17:00'],[4,'08:00',0,'17:00'],[5,'08:00',0,'17:00'],[6,'08:00',0,'13:00']])">Lun a Vie 08–17 + Sáb 08–13</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="usePreset([[1,'08:00',1,'08:00'],[2,'08:00',1,'08:00'],[3,'08:00',1,'08:00'],[4,'08:00',1,'08:00'],[5,'08:00',1,'08:00'],[6,'08:00',1,'08:00'],[7,'08:00',1,'08:00']])">24 h todos los días (08 a 08)</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="usePreset([[5,'18:00',3,'06:00']])">Fin de semana completo (Vie 18:00 a Lun 06:00)</button>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-2">
            <thead><tr><th>Entra el día</th><th>a las</th><th>Sale el día</th><th>a las</th><th>Duración</th><th></th></tr></thead>
            <tbody>
            <template x-for="(b, i) in blocks" :key="i">
                <tr :class="bad(b) ? 'table-danger' : ''">
                    <td><select class="form-select form-select-sm" x-model.number="b.day" style="min-width: 90px">
                        <template x-for="(n, k) in dayNames" :key="k"><option :value="k + 1" x-text="n" :selected="k + 1 === Number(b.day)"></option></template></select></td>
                    <td><input class="form-control form-control-sm" type="time" x-model="b.start" @change="fixEnd(b)" required></td>
                    <td><select class="form-select form-select-sm" x-model.number="b.plus" style="min-width: 150px">
                        <template x-for="p in [0, 1, 2, 3]" :key="p"><option :value="p" x-text="endName(b, p)" :selected="p === Number(b.plus)"></option></template></select></td>
                    <td><input class="form-control form-control-sm" type="time" x-model="b.end" @change="fixEnd(b)" required></td>
                    <td class="small text-nowrap" :class="bad(b) ? 'text-danger fw-semibold' : 'text-body-secondary'" x-text="hours(b) + (minutes(b) > maxHours * 60 ? ' (máx. ' + maxHours + ' h)' : '')"></td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-link p-0 me-2" @click="copyWeekdays(b)" title="Agrega esta misma franja a los días de lunes a viernes que no tengan ninguna">Copiar a Lun–Vie</button>
                        <button type="button" class="btn btn-sm btn-outline-danger py-0" @click="blocks.splice(i, 1)" title="Quitar">×</button>
                    </td>
                </tr>
            </template>
            <tr x-show="!blocks.length"><td colspan="6" class="text-body-secondary small">Sin franjas: usá un atajo o agregá una.</td></tr>
            </tbody>
        </table></div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-sm btn-outline-primary" @click="add()">+ Agregar franja</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="sort()">Ordenar por día</button>
            <span class="small text-body-secondary">Total semanal: <strong x-text="Math.round(blocks.reduce((t, b) => t + Math.max(0, minutes(b)), 0) / 6) / 10 + ' h'"></strong>
                por guardia · cuenta el día en que entra (ausencias y días sin servicio).</span>
        </div>
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

    <div class="row g-3" x-show="pattern !== 'custom'">
        <div class="col-sm-3"><label class="form-label">Entra</label><input class="form-control" type="time" name="start_time" x-model="start" :required="pattern !== 'custom'"></div>
        <div class="col-sm-3"><label class="form-label">Sale</label><input class="form-control" type="time" name="end_time" x-model="end" :required="pattern !== 'custom'"></div>
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

    <label class="form-label mt-3" x-text="mode === 'puesto' ? 'Guardias del grupo (los que pueden cubrir el puesto)' : 'Guardias'">Guardias</label>
    <div class="border rounded p-2" style="max-height: 220px; overflow:auto">
        <?php foreach ($guards as $g): ?>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="users[]" value="<?= e($g['uuid']) ?>" id="u<?= e($g['uuid']) ?>" <?= in_array($g['uuid'], $users, true) ? 'checked' : '' ?>>
                <label class="form-check-label" for="u<?= e($g['uuid']) ?>"><?= e($g['name']) ?> <span class="small text-body-secondary"><?= e($g['role_name']) ?></span>
                    <?php if (empty($g['pin_hash'])): ?><span class="badge text-bg-warning" x-show="mode === 'puesto'" title="Sin PIN no puede identificarse en el celular compartido">sin PIN</span><?php endif; ?></label></div>
        <?php endforeach; ?>
        <?php if (!$guards): ?><div class="text-body-secondary small">No hay usuarios con el módulo Rondas.</div><?php endif; ?>
    </div>
    <div class="form-text" x-show="mode !== 'puesto'">Cada guardia elegido tiene ese turno. Un guardia puede estar en turnos de varios predios.</div>
    <div class="form-text" x-show="mode === 'puesto'">Cualquiera de ellos puede cubrir el puesto, de a uno por vez. Cuando la empresa de seguridad cambie de personal, agregalos o sacalos acá.</div>

    <div class="mt-3" x-show="mode === 'puesto'">
        <label class="form-label">Celular compartido del puesto (opcional)</label>
        <select class="form-select" name="device" style="max-width: 420px">
            <option value="">Cada guardia usa su propio celular</option>
            <?php foreach ($devices ?? [] as $d): ?><option value="<?= e($d['uuid']) ?>" <?= $device === $d['uuid'] ? 'selected' : '' ?>><?= e($d['name']) ?> (<?= e($d['role_name']) ?>)</option><?php endforeach; ?>
        </select>
        <div class="form-text">Si la empresa de seguridad tiene <strong>un solo celular</strong> para todos sus guardias: creá un usuario para ese celular (por ejemplo
            "Celular portería") con el rol de guardia, abrí la app con él una sola vez y elegilo acá. Al empezar el turno cada guardia elige su nombre y pone
            su <strong>PIN</strong> (se carga en Usuarios); las rondas, el libro y el pánico quedan a su nombre. El celular nunca se cierra entre guardias.</div>
    </div>

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
