<?php
/** Alta suelta o edición de un turno. @var ?array $slot @var ?array $old @var array $guards @var array $sites @var int $defaultGrace @var string $day */
$v = $old ?? [];
$s = $slot ?? [];
// UTC de la base → "Y-m-d\TH:i" en hora de la empresa (lo que espera <input type="datetime-local">)
$local = fn (?string $utc) => $utc ? fecha($utc, 'Y-m-d\TH:i') : '';
$val = fn (string $k, string $default = '') => (string) ($v[$k] ?? $default);
$started = $slot !== null && $slot['started_at'] !== null;
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'hoy'], null) ?>
<h2 class="h4 mb-1"><?= $slot ? 'Editar turno' : 'Nuevo turno suelto' ?></h2>
<p class="text-body-secondary small mb-3"><?= $slot
    ? 'Cambiá el guardia, el predio o el horario. Si corregís la hora real de inicio o de fin, indicá el motivo: queda en la auditoría con el valor anterior.'
    : 'Un turno de un día, fuera de las programaciones (por ejemplo una cobertura o un refuerzo). Al guardia le llega a la app con la próxima sincronización.' ?></p>
<?php if ($slot && $slot['shift_id']): ?>
    <div class="alert alert-info small">Este turno viene de la programación <strong><?= e($slot['shift_name'] ?: 'sin nombre') ?></strong>. Lo que cambies acá vale solo para este día:
        la programación no se modifica y no lo vuelve a crear.</div>
<?php endif; ?>
<form method="post" action="<?= e(url($slot ? '/panel/rondas/turno/' . $slot['uuid'] : '/panel/rondas/turno/nuevo')) ?>" class="card card-body shadow-sm" style="max-width: 760px">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Guardia</label>
            <select class="form-select" name="user" required><option value="">Elegí…</option>
                <?php foreach ($guards as $g): $sel = $val('user', $s['user_uuid'] ?? ''); ?><option value="<?= e($g['uuid']) ?>" <?= $sel === $g['uuid'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-6"><label class="form-label">Predio</label>
            <select class="form-select" name="site" required><option value="">Elegí…</option>
                <?php foreach ($sites as $p): $sel = $val('site', $s['site_uuid'] ?? (count($sites) === 1 ? $sites[0]['uuid'] : '')); ?><option value="<?= e($p['uuid']) ?>" <?= $sel === $p['uuid'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-4"><label class="form-label">Entra (programado)</label><input class="form-control" type="datetime-local" name="start_at" required value="<?= e($val('start_at', $local($s['start_at'] ?? null) ?: $day . 'T08:00')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Sale (programado)</label><input class="form-control" type="datetime-local" name="end_at" required value="<?= e($val('end_at', $local($s['end_at'] ?? null) ?: $day . 'T16:00')) ?>"></div>
        <div class="col-md-4"><label class="form-label">Tolerancia para empezar</label><div class="input-group"><input class="form-control" type="number" min="1" max="120" name="grace_minutes" value="<?= e($val('grace_minutes', (string) ($s['grace_minutes'] ?? ''))) ?>" placeholder="<?= (int) $defaultGrace ?>"><span class="input-group-text">min</span></div></div>
    </div>

    <h3 class="h6 mt-4 mb-1">Inicio y fin reales</h3>
    <p class="small text-body-secondary mb-2">Normalmente los carga el guardia con Empezar y Terminar turno en la app. Corregilos acá solo si hace falta
        (por ejemplo se olvidó de terminar, o no tenía el celular). Dejá vacío el fin si sigue en turno.</p>
    <div class="row g-3">
        <div class="col-md-4"><label class="form-label">Empezó</label><input class="form-control" type="datetime-local" name="started_at" value="<?= e($val('started_at', $local($s['started_at'] ?? null))) ?>"></div>
        <div class="col-md-4"><label class="form-label">Terminó</label><input class="form-control" type="datetime-local" name="ended_at" value="<?= e($val('ended_at', $local($s['ended_at'] ?? null))) ?>"></div>
    </div>
    <label class="form-label mt-3">Motivo <?= $slot ? '' : '(opcional)' ?></label>
    <input class="form-control" name="note" maxlength="500" value="<?= e($val('note')) ?>" placeholder="<?= $started ? 'Obligatorio si cambiás el inicio o el fin real' : 'Por qué se carga o se cambia' ?>">

    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary"><?= $slot ? 'Guardar cambios' : 'Cargar turno' ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(url($slot ? '/panel/rondas/turno/' . $slot['uuid'] : '/panel/rondas/turnos')) ?>">Cancelar</a>
    </div>
</form>
