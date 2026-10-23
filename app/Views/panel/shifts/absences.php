<?php
/** Turnos → Ausencias y días sin servicio. @var ?array $editing @var array $pending @var array $current @var array $history @var array $closures @var array $guards @var array $sites @var bool $canEdit @var string $today */
use App\Services\ShiftAbsences;
$range = fn ($a) => $a['date_from'] === $a['date_to'] ? date('d/m/Y', strtotime($a['date_from']))
    : date('d/m', strtotime($a['date_from'])) . ' al ' . date('d/m/Y', strtotime($a['date_to']));
$badge = fn ($a) => '<span class="badge text-bg-' . ShiftAbsences::STATUS[$a['status']][1] . '">' . e(ShiftAbsences::STATUS[$a['status']][0]) . '</span>';
$guardOptions = function (?string $except = null, ?string $selected = null) use ($guards) {
    $out = '';
    foreach ($guards as $g) if ($g['uuid'] !== $except) $out .= '<option value="' . e($g['uuid']) . '"' . ($g['uuid'] === $selected ? ' selected' : '') . '>' . e($g['name']) . '</option>';
    return $out;
};
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'ausencias'], null) ?>

<div class="card shadow-sm mb-4 <?= $pending ? 'border-warning' : '' ?>">
    <div class="card-header fw-semibold">Pedidos para aprobar <?= $pending ? '<span class="badge text-bg-warning">' . count($pending) . '</span>' : '' ?></div>
    <div class="list-group list-group-flush">
        <?php foreach ($pending as $a): ?>
            <div class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div><strong><?= e($a['user_name']) ?></strong> · <?= e(ShiftAbsences::TYPES[$a['type']] ?? $a['type']) ?> · <?= e($range($a)) ?>
                        <?php if ($a['reason']): ?><div class="small text-body-secondary">“<?= e($a['reason']) ?>”</div><?php endif; ?>
                        <div class="small text-body-secondary">Pedido el <?= e(fecha($a['created_at'], 'd/m H:i')) ?></div></div>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/panel/rondas/turnos/ausencias/' . $a['uuid'] . '/decidir')) ?>" class="d-flex flex-wrap gap-2 align-items-start">
                        <?= csrf_field() ?>
                        <select class="form-select form-select-sm" name="replacement" style="max-width: 220px"><option value="">Sin reemplazo</option><?= $guardOptions($a['user_uuid']) ?></select>
                        <input class="form-control form-control-sm" name="note" maxlength="500" placeholder="Nota para el guardia (opcional)" style="max-width: 240px">
                        <button class="btn btn-sm btn-success" name="decision" value="aprobar">Aprobar</button>
                        <button class="btn btn-sm btn-outline-danger" name="decision" value="rechazar">Rechazar</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$pending): ?><div class="list-group-item text-body-secondary">No hay pedidos pendientes. Los guardias piden ausencias desde la app ("Pedir ausencia").</div><?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-4">
            <div class="card-header fw-semibold">Ausencias vigentes y próximas</div>
            <div class="table-responsive"><table class="table align-middle mb-0">
                <thead><tr><th>Guardia</th><th>Fechas</th><th>Tipo</th><th>Reemplazo</th><th>Estado</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($current as $a): ?>
                    <tr><td><?= e($a['user_name']) ?></td><td class="text-nowrap"><?= e($range($a)) ?></td><td><?= e(ShiftAbsences::TYPES[$a['type']] ?? $a['type']) ?></td>
                        <td><?= $a['replacement_name'] ? e($a['replacement_name']) : ($a['status'] === 'aprobada' ? '<span class="text-danger small">Sin cubrir</span>' : '—') ?></td>
                        <td><?= $badge($a) ?></td>
                        <td class="text-end text-nowrap"><?php if ($canEdit): ?>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/panel/rondas/turnos/ausencias?editar=' . $a['uuid'] . '#cargar')) ?>">Editar</a>
                            <form class="d-inline" method="post" action="<?= e(url('/panel/rondas/turnos/ausencias/' . $a['uuid'] . '/cancelar')) ?>" onsubmit="return confirm('¿Cancelar esta ausencia? Sus turnos futuros vuelven a estar programados.')">
                                <?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Cancelar</button></form>
                        <?php endif; ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$current): ?><tr><td colspan="6" class="text-body-secondary">No hay ausencias vigentes.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>

        <details class="mb-4"><summary class="fw-semibold mb-2">Historial</summary>
            <div class="table-responsive"><table class="table table-sm align-middle">
                <thead><tr><th>Guardia</th><th>Fechas</th><th>Tipo</th><th>Estado</th><th>Respondió</th></tr></thead>
                <tbody><?php foreach ($history as $a): ?>
                    <tr><td><?= e($a['user_name']) ?></td><td class="text-nowrap"><?= e($range($a)) ?></td><td><?= e(ShiftAbsences::TYPES[$a['type']] ?? $a['type']) ?></td><td><?= $badge($a) ?></td>
                        <td class="small"><?= e($a['decided_by_name'] ?? '—') ?><?= $a['decision_note'] ? ' · ' . e($a['decision_note']) : '' ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
        </details>
    </div>

    <div class="col-lg-5">
        <?php if ($canEdit): ?>
        <?php $ed = $editing ?? null; ?>
        <form method="post" action="<?= e(url('/panel/rondas/turnos/ausencias' . ($ed ? '/' . $ed['uuid'] . '/editar' : ''))) ?>" class="card shadow-sm mb-4 <?= $ed ? 'border-primary' : '' ?>" id="cargar">
            <?= csrf_field() ?>
            <div class="card-header fw-semibold"><?= $ed ? 'Editar ausencia de ' . e($ed['user_name']) : 'Cargar una ausencia' ?></div>
            <div class="card-body">
                <p class="small text-body-secondary"><?= $ed
                    ? ($ed['status'] === 'aprobada' ? 'Está aprobada: al guardar, los turnos que todavía no empezaron se rearman con los datos nuevos (lo que ya pasó no cambia).' : 'Está pendiente de aprobar: la corregís y después la aprobás o rechazás arriba.')
                    : 'Por ejemplo, el guardia avisó por teléfono que no viene. Queda aprobada: sus turnos de esos días no generan alarmas.' ?></p>
                <label class="form-label">Guardia</label>
                <select class="form-select mb-2" name="user" required><option value="">Elegí…</option><?= $guardOptions(null, $ed['user_uuid'] ?? null) ?></select>
                <div class="row g-2 mb-2">
                    <div class="col"><label class="form-label">Desde</label><input class="form-control" type="date" name="date_from" value="<?= e($ed['date_from'] ?? $today) ?>" required></div>
                    <div class="col"><label class="form-label">Hasta</label><input class="form-control" type="date" name="date_to" value="<?= e($ed['date_to'] ?? $today) ?>"></div>
                </div>
                <label class="form-label">Tipo</label>
                <select class="form-select mb-2" name="type"><?php foreach (ShiftAbsences::TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($ed['type'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <label class="form-label">Motivo (opcional)</label>
                <input class="form-control mb-2" name="reason" maxlength="500" value="<?= e($ed['reason'] ?? '') ?>">
                <label class="form-label">Reemplazo (opcional)</label>
                <select class="form-select" name="replacement"><option value="">Sin reemplazo (avisa "puesto sin cubrir")</option><?= $guardOptions(null, $ed['replacement_uuid'] ?? null) ?></select>
                <div class="form-text">El reemplazo recibe los mismos turnos y los ve en su app.</div>
                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-primary"><?= $ed ? 'Guardar cambios' : 'Cargar ausencia' ?></button>
                    <?php if ($ed): ?><a class="btn btn-outline-secondary" href="<?= e(url('/panel/rondas/turnos/ausencias')) ?>">Cancelar</a><?php endif; ?>
                </div>
            </div>
        </form>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Días sin servicio</div>
            <div class="card-body">
                <p class="small text-body-secondary">Feriados o planta cerrada: ese día no hay turnos ni avisos (de un predio o de todos). Las rondas programadas tienen sus propias fechas excluidas.</p>
                <ul class="list-unstyled small">
                    <?php foreach ($closures as $c): ?>
                        <li class="d-flex justify-content-between align-items-center border-bottom py-1"><span><strong><?= e(date('d/m/Y', strtotime($c['day']))) ?></strong> · <?= e($c['reason']) ?> · <?= e($c['site_name'] ?? 'Todos los predios') ?></span>
                            <?php if ($canEdit): ?><form method="post" action="<?= e(url('/panel/rondas/turnos/sin-servicio/' . $c['uuid'] . '/borrar')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger">Quitar</button></form><?php endif; ?></li>
                    <?php endforeach; ?>
                    <?php if (!$closures): ?><li class="text-body-secondary">No hay días cargados.</li><?php endif; ?>
                </ul>
                <?php if ($canEdit): ?>
                <form method="post" action="<?= e(url('/panel/rondas/turnos/sin-servicio')) ?>" class="row g-2">
                    <?= csrf_field() ?>
                    <div class="col-6"><input class="form-control form-control-sm" type="date" name="day" required></div>
                    <div class="col-6"><select class="form-select form-select-sm" name="site"><option value="">Todos los predios</option>
                        <?php foreach ($sites as $p): ?><option value="<?= e($p['uuid']) ?>"><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-8"><input class="form-control form-control-sm" name="reason" maxlength="191" placeholder="Feriado, planta cerrada…" required></div>
                    <div class="col-4"><button class="btn btn-sm btn-outline-primary w-100">Agregar</button></div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
