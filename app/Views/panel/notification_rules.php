<?php
use App\Services\Notify\Channels;
use App\Services\Notify\Messages;
$canEdit = App\Services\UserAuth::can('configuracion', 'editar');
$recipientLabel = function (array $r) use ($roles, $users): string {
    return match ($r['type']) {
        'role' => 'Rol: ' . (array_column($roles, 'name', 'slug')[$r['value']] ?? $r['value']),
        'user' => 'Usuario: ' . (array_column($users, 'name', 'uuid')[$r['value']] ?? '?'),
        'sector_supervisors' => 'Supervisores del sector',
        'assignee' => 'Responsable asignado',
        'reporter' => 'Quien reportó / creó',
        'verifiers' => 'Quienes verifican acciones',
        'approvers' => 'Quienes autorizan permisos de trabajo',
        default => $r['type'],
    };
};
$form = $edit ?? ($creating ? ['uuid' => '', 'name' => '', 'event' => 'observation.created', 'min_severity_level' => null, 'sector_id' => null, 'recipients' => [], 'channels' => ['app'], 'is_active' => 1] : null);
$has = fn (string $type, ?string $value = null) => $form && (bool) array_filter($form['recipients'], fn ($r) => $r['type'] === $type && ($value === null || ($r['value'] ?? null) === $value));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0">Notificaciones</h1>
    <?php if ($canEdit && !$form): ?><a class="btn btn-primary btn-sm" href="?regla=nueva">+ Nueva regla</a><?php endif; ?>
</div>

<?php if ($form && $canEdit): ?>
    <form class="card shadow-sm mb-3" method="post" action="<?= e(url('/panel/configuracion/notificaciones')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="uuid" value="<?= e($form['uuid']) ?>">
        <div class="card-header"><strong><?= $form['uuid'] ? 'Editar regla' : 'Nueva regla' ?></strong></div>
        <div class="card-body row g-3">
            <div class="col-md-6"><label class="form-label">Nombre</label><input class="form-control" name="name" value="<?= e($form['name']) ?>" required maxlength="120"></div>
            <div class="col-md-6"><label class="form-label">Evento</label>
                <select class="form-select" name="event">
                    <?php foreach (Messages::EVENTS as $k => $label): if (in_array($k, Messages::INTERNAL, true)) continue; ?>
                        <option value="<?= e($k) ?>" <?= $form['event'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-6"><label class="form-label">Severidad mínima</label>
                <select class="form-select" name="min_level"><option value="">Cualquiera</option>
                    <?php foreach ($levels as $l): ?><option value="<?= e($l['level']) ?>" <?= (string) $form['min_severity_level'] === (string) $l['level'] ? 'selected' : '' ?>><?= e($l['name']) ?> o más</option><?php endforeach; ?>
                </select><div class="form-text">En los avisos de acciones se compara con la prioridad (Baja = 1 … Crítica = 4).</div></div>
            <div class="col-md-6"><label class="form-label">Solo en el sector (incluye los de abajo)</label>
                <select class="form-select" name="sector"><option value="">Toda la empresa</option>
                    <?php $currentSector = $form['sector_id'] ? (App\Models\Sectors::findById((int) $form['sector_id'])['uuid'] ?? '') : '';
                    foreach ($sectors as $u => $n): ?><option value="<?= e($u) ?>" <?= $currentSector === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-6"><label class="form-label d-block">Avisar a</label>
                <?php foreach (['sector_supervisors' => 'Supervisores del sector', 'assignee' => 'Responsable asignado', 'reporter' => 'Quien reportó / creó', 'verifiers' => 'Quienes verifican acciones', 'approvers' => 'Quienes autorizan permisos de trabajo'] as $t => $l): ?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="r_<?= e($t) ?>" value="1" id="r_<?= e($t) ?>" <?= $has($t) ? 'checked' : '' ?>><label class="form-check-label" for="r_<?= e($t) ?>"><?= e($l) ?></label></div>
                <?php endforeach; ?>
                <?php foreach ($roles as $r): ?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="roles[]" value="<?= e($r['slug']) ?>" id="ro_<?= e($r['slug']) ?>" <?= $has('role', $r['slug']) ? 'checked' : '' ?>><label class="form-check-label" for="ro_<?= e($r['slug']) ?>">Rol: <?= e($r['name']) ?></label></div>
                <?php endforeach; ?>
                <label class="form-label small mt-2 mb-0">Usuarios puntuales</label>
                <select class="form-select form-select-sm" name="users[]" multiple size="3">
                    <?php foreach ($users as $u): if ((int) $u['is_active'] !== 1) continue; ?><option value="<?= e($u['uuid']) ?>" <?= $has('user', $u['uuid']) ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
                </select></div>
            <div class="col-md-6"><label class="form-label d-block">Canales</label>
                <?php foreach (Channels::LABELS as $k => $l): ?>
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="channels[]" value="<?= e($k) ?>" id="ch_<?= e($k) ?>" <?= in_array($k, $form['channels'], true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ch_<?= e($k) ?>"><?= e($l) ?><?= !Channels::enabled($k) ? ' <span class="badge text-bg-light">no configurado</span>' : '' ?></label></div>
                <?php endforeach; ?>
                <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="is_active" <?= $form['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Regla activa</label></div>
            </div>
            <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Guardar regla</button><a class="btn btn-link" href="<?= e(url('/panel/configuracion/notificaciones')) ?>">Cancelar</a></div>
        </div>
    </form>
<?php endif; ?>

<div class="card shadow-sm mb-3">
    <div class="table-responsive">
        <table class="table mb-0 align-middle small">
            <thead><tr><th>Regla</th><th>Evento</th><th>Condición</th><th>Avisa a</th><th>Canales</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rules as $r): ?>
                <tr class="<?= $r['is_active'] ? '' : 'text-body-tertiary' ?>">
                    <td class="fw-semibold"><?= e($r['name']) ?><?= $r['is_active'] ? '' : ' <span class="badge text-bg-secondary">inactiva</span>' ?></td>
                    <td><?= e(Messages::EVENTS[$r['event']] ?? $r['event']) ?></td>
                    <td><?= $r['min_severity_level'] ? 'Severidad ' . e(array_column($levels, 'name', 'level')[$r['min_severity_level']] ?? $r['min_severity_level']) . ' o más' : 'Cualquier severidad' ?><?= $r['sector_name'] ? '<br>Sector: ' . e($r['sector_name']) : '' ?></td>
                    <td><?= implode('<br>', array_map(fn ($x) => e($recipientLabel($x)), $r['recipients'])) ?></td>
                    <td><?= e(implode(', ', array_map(fn ($c) => Channels::LABELS[$c] ?? $c, $r['channels']))) ?></td>
                    <td><?php if ($canEdit): ?><a href="?regla=<?= e($r['uuid']) ?>">Editar</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <form class="card shadow-sm" method="post" action="<?= e(url('/panel/configuracion/notificaciones/ajustes')) ?>">
            <?= csrf_field() ?>
            <div class="card-header"><strong>Escalamiento y resúmenes</strong></div>
            <fieldset class="card-body" <?= $canEdit ? '' : 'disabled' ?>>
                <label class="form-label">Si nadie confirma un riesgo inminente, escalar a los</label>
                <div class="input-group mb-1" style="max-width: 200px"><input class="form-control" type="number" name="escalation" min="1" max="240" value="<?= e($settings['escalation']) ?>"><span class="input-group-text">minutos</span></div>
                <div class="form-text mb-3">Nivel 1 y nivel 2: avisa a todos los responsables SyH y administradores por todos los canales.</div>
                <div class="form-check"><input class="form-check-input" type="checkbox" name="daily" value="1" id="daily" <?= $settings['daily'] ? 'checked' : '' ?>><label class="form-check-label" for="daily">Resumen diario por email (8 h)</label></div>
                <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="weekly" value="1" id="weekly" <?= $settings['weekly'] ? 'checked' : '' ?>><label class="form-check-label" for="weekly">Resumen semanal (lunes)</label></div>
                <div class="fw-semibold small mb-2">Acciones correctivas</div>
                <div class="row g-2 mb-3 small">
                    <div class="col-12"><div class="input-group input-group-sm"><span class="input-group-text">Avisar</span><input class="form-control" type="number" name="soon_days" min="1" max="30" value="<?= e($settings['soon']) ?>"><span class="input-group-text">días antes de que venza</span></div></div>
                    <div class="col-12"><div class="input-group input-group-sm"><span class="input-group-text">Escalar con</span><input class="form-control" type="number" name="escalate_days" min="1" max="60" value="<?= e($settings['escalate']) ?>"><span class="input-group-text">días de atraso</span></div></div>
                    <div class="col-12"><div class="input-group input-group-sm"><span class="input-group-text">Verificar dentro de</span><input class="form-control" type="number" name="verify_days" min="1" max="180" value="<?= e($settings['verify']) ?>"><span class="input-group-text">días del cierre</span></div></div>
                </div>
                <div class="fw-semibold small mb-2">Incidentes y accidentes</div>
                <div class="row g-2 mb-3 small">
                    <div class="col-12"><div class="input-group input-group-sm"><span class="input-group-text">Avisar si la investigación no empezó a los</span><input class="form-control" type="number" name="inv_days" min="1" max="60" value="<?= e($settings['inv_days']) ?>"><span class="input-group-text">días</span></div></div>
                    <div class="col-12"><div class="input-group input-group-sm"><span class="input-group-text">Avisar si falta el N° de siniestro ART a las</span><input class="form-control" type="number" name="art_hours" min="1" max="720" value="<?= e($settings['art_hours']) ?>"><span class="input-group-text">horas</span></div>
                        <div class="form-text">Ajustalo al plazo que te indique tu ART.</div></div>
                </div>
                <?php if ($canEdit): ?><button class="btn btn-primary btn-sm">Guardar</button><?php endif; ?>
            </fieldset>
        </form>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between"><strong>Últimos envíos</strong>
                <span class="small"><?= e(($stats['sent'] ?? 0) . ' enviados · ' . ($stats['pending'] ?? 0) . ' pendientes · ' . ($stats['failed'] ?? 0) . ' fallidos') ?></span></div>
            <div class="table-responsive" style="max-height: 320px">
                <table class="table table-sm mb-0 small">
                    <tbody>
                    <?php foreach ($queue as $q): ?>
                        <tr>
                            <td class="text-nowrap"><?= e(fecha($q['created_at'], 'd/m H:i')) ?></td>
                            <td><?= e(Channels::LABELS[$q['channel']] ?? $q['channel']) ?></td>
                            <td><?= e($q['user_name'] ?? $q['to_address']) ?></td>
                            <td><?= e(mb_strimwidth((string) $q['subject'], 0, 50, '…')) ?></td>
                            <td><?= $q['status'] === 'sent' ? '<span class="text-success">enviado</span>' : ($q['status'] === 'failed' ? '<span class="text-danger" title="' . e($q['last_error']) . '">falló</span>' : '<span class="text-warning-emphasis" title="' . e($q['last_error']) . '">pendiente (' . e($q['attempts']) . ')</span>') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
