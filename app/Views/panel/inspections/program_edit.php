<?php use App\Services\InspectionPlanner as P; ?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/programas')) ?>">←</a>
    <h1 class="h4 m-0"><?= e($p ? $p['name'] : 'Nuevo programa') ?></h1>
</div>
<form method="post" action="<?= e(url('/panel/inspecciones/programas')) ?>" class="card shadow-sm card-body" style="max-width: 820px"
      x-data="{ freq: <?= e(json_encode($form['frequency'])) ?>, target: <?= e(json_encode($form['target_type'])) ?>, who: <?= e(json_encode($form['assignee_type'])) ?> }">
    <?= csrf_field() ?>
    <input type="hidden" name="uuid" value="<?= e($p['uuid'] ?? '') ?>">
    <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Checklist</label>
            <select class="form-select" name="template" required><option value="">Elegí…</option>
                <?php foreach ($templates as $t): ?><option value="<?= e($t['uuid']) ?>" <?= $form['template'] === $t['uuid'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Nombre (opcional)</label><input class="form-control" name="name" maxlength="160" value="<?= e($form['name']) ?>" placeholder="Ej: Pre-uso diario autoelevadores"></div>

        <div class="col-md-4"><label class="form-label">Frecuencia</label>
            <select class="form-select" name="frequency" x-model="freq"><?php foreach (P::FREQUENCIES as $k => $n): ?><option value="<?= e($k) ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4" x-show="freq === 'semanal'"><label class="form-label">Día</label>
            <select class="form-select" name="weekday"><?php foreach (P::WEEKDAYS as $k => $n): ?><option value="<?= e($k) ?>" <?= $form['weekday'] === $k ? 'selected' : '' ?>><?= e(ucfirst($n)) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4" x-show="freq === 'mensual'"><label class="form-label">Día del mes</label>
            <input class="form-control" type="number" name="monthday" min="1" max="31" value="<?= e($form['monthday']) ?>"><div class="form-text">Si el mes es más corto, el último día.</div></div>
        <div class="col-12" x-show="freq === 'manual'"><div class="alert alert-secondary small mb-0">Manual: no se generan inspecciones programadas (ej. el pre-uso antes de cada uso, al escanear el QR). Sirve para indicar quién recibe las acciones.</div></div>

        <div class="col-md-4"><label class="form-label">Sobre qué</label>
            <select class="form-select" name="target_type" x-model="target"><?php foreach (P::TARGETS as $k => $n): ?><option value="<?= e($k) ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8" x-show="target === 'equipo'"><label class="form-label">Equipo</label>
            <select class="form-select" name="equipment"><option value="">Elegí…</option>
                <?php foreach ($equipment as $eq): ?><option value="<?= e($eq['uuid']) ?>" <?= $form['equipment'] === $eq['uuid'] ? 'selected' : '' ?>><?= e($eq['code'] . ' · ' . $eq['name'] . ($eq['type_name'] ? ' (' . $eq['type_name'] . ')' : '')) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8" x-show="target !== 'equipo'"><label class="form-label" x-text="target === 'tipo' ? 'Solo los del sector (opcional)' : 'Sector'"></label>
            <select class="form-select" name="sector"><option value="">—</option>
                <?php foreach ($sectors as $u => $n): ?><option value="<?= e($u) ?>" <?= $form['sector'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
            <div class="form-text" x-show="target === 'tipo'">Todos los equipos activos del tipo del checklist; los que se den de alta después entran solos.</div></div>

        <div class="col-md-4"><label class="form-label">A cargo</label>
            <select class="form-select" name="assignee_type" x-model="who"><?php foreach (P::ASSIGNEES as $k => $n): ?><option value="<?= e($k) ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8" x-show="who === 'user'"><label class="form-label">Usuario</label>
            <select class="form-select" name="assignee_user"><option value="">Elegí…</option>
                <?php foreach ($users as $u): ?><option value="<?= e($u['uuid']) ?>" <?= $form['assignee_user'] === $u['uuid'] ? 'selected' : '' ?>><?= e($u['name'] . ' · ' . $u['role_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8" x-show="who === 'role'"><label class="form-label">Rol</label>
            <select class="form-select" name="assignee_role"><option value="">Elegí…</option>
                <?php foreach ($roles as $r): ?><option value="<?= e($r['slug']) ?>" <?= $form['assignee_role'] === $r['slug'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>

        <div class="col-12"><label class="form-label">Responsable de las acciones correctivas que se generen (opcional)</label>
            <select class="form-select" name="action_responsible"><option value="">El supervisor del sector (o quien inspeccionó)</option>
                <?php foreach ($users as $u): ?><option value="<?= e($u['uuid']) ?>" <?= $form['action_responsible'] === $u['uuid'] ? 'selected' : '' ?>><?= e($u['name'] . ' · ' . $u['role_name']) ?></option><?php endforeach; ?></select>
            <div class="form-text">Ej.: el jefe de mantenimiento para los equipos.</div></div>
    </div>
    <div class="mt-3"><button class="btn btn-primary">Guardar programa</button></div>
</form>
<?php if ($p): ?>
    <div class="card shadow-sm mt-3 small" style="max-width: 820px"><div class="card-body">
        <strong><?= e(count($targets)) ?></strong> <?= $p['target_type'] === 'sector' ? 'sector' : 'equipo(s)' ?> hoy.
        <?php if ($preview): ?>Próximos vencimientos: <?= e(implode(', ', array_map(fn ($x) => date('d/m', strtotime($x[2])), $preview))) ?>.<?php endif; ?>
    </div></div>
<?php endif; ?>
