<?php
/** Alta / edición de un autorizado. @var ?array $row @var ?array $old @var array $sites @var string $kind */
use App\Services\ShiftPlanner;
$v = $old ?? [];
$r = $row ?? [];
$val = fn (string $k) => (string) ($v[$k] ?? ($r[$k] ?? ''));
$kind = $v['kind'] ?? ($r['kind'] ?? ($kind === 'vehiculo' ? 'vehiculo' : 'persona'));
$days = array_map('intval', (array) ($v['days'] ?? ($r['days'] ?? null ? explode(',', (string) $r['days']) : [])));
$site = $v['site'] ?? ($r['site_uuid'] ?? '');
?>
<a class="small d-inline-block mb-2" href="<?= e(url('/panel/libro/autorizados')) ?>">← Lista de autorizados</a>
<h1 class="h4 mb-3"><?= $row ? 'Editar autorizado' : ($kind === 'vehiculo' ? 'Nuevo vehículo autorizado' : 'Nueva persona autorizada') ?></h1>
<form method="post" action="<?= e(url('/panel/libro/autorizados' . ($row ? '/' . $row['uuid'] : ''))) ?>" class="card card-body shadow-sm" style="max-width: 760px">
    <?= csrf_field() ?>
    <input type="hidden" name="kind" value="<?= e($kind) ?>">
    <div class="row g-3">
        <?php if ($kind === 'vehiculo'): ?>
            <div class="col-md-4"><label class="form-label">Patente</label><input class="form-control text-uppercase" name="plate" required maxlength="15" value="<?= e($val('plate')) ?>" placeholder="AB123CD"></div>
            <div class="col-md-8"><label class="form-label">Empresa</label><input class="form-control" name="company" maxlength="150" value="<?= e($val('company')) ?>"></div>
        <?php else: ?>
            <div class="col-md-6"><label class="form-label">Nombre y apellido</label><input class="form-control" name="person_name" maxlength="150" value="<?= e($val('person_name')) ?>"></div>
            <div class="col-md-6"><label class="form-label">DNI</label><input class="form-control" name="person_doc" inputmode="numeric" maxlength="30" value="<?= e($val('person_doc')) ?>">
                <div class="form-text">El guardia busca por DNI. Sin DNI se compara el nombre exacto.</div></div>
            <div class="col-md-12"><label class="form-label">Empresa</label><input class="form-control" name="company" maxlength="150" value="<?= e($val('company')) ?>" placeholder="Propia, contratista, proveedor…"></div>
        <?php endif; ?>
        <div class="col-md-6"><label class="form-label">Predio</label>
            <select class="form-select" name="site"><option value="">Todos los predios</option>
                <?php foreach ($sites as $s): ?><option value="<?= e($s['uuid']) ?>" <?= $site === $s['uuid'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Quién lo autorizó (opcional)</label><input class="form-control" name="authorized_by" maxlength="150" value="<?= e($val('authorized_by')) ?>" placeholder="Jefe de planta, Compras…"></div>
        <div class="col-12"><label class="form-label">Motivo (opcional)</label><input class="form-control" name="reason" maxlength="255" value="<?= e($val('reason')) ?>" placeholder="Mantenimiento de calderas, entrega de insumos…"></div>
    </div>

    <h2 class="h6 mt-4 mb-1">Cuándo puede entrar</h2>
    <p class="small text-body-secondary mb-2">Todo vacío = siempre. Fuera de lo que pongas acá, el ingreso se anota igual y se avisa al supervisor.</p>
    <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Desde</label><input class="form-control" type="date" name="valid_from" value="<?= e($val('valid_from')) ?>"></div>
        <div class="col-md-3"><label class="form-label">Hasta</label><input class="form-control" type="date" name="valid_until" value="<?= e($val('valid_until')) ?>"></div>
        <div class="col-md-3"><label class="form-label">Hora desde</label><input class="form-control" type="time" name="time_from" value="<?= e($val('time_from')) ?>"></div>
        <div class="col-md-3"><label class="form-label">Hora hasta</label><input class="form-control" type="time" name="time_to" value="<?= e($val('time_to')) ?>"></div>
        <div class="col-12"><label class="form-label">Días (ninguno marcado = todos)</label>
            <div class="d-flex flex-wrap gap-2">
                <?php foreach (ShiftPlanner::DAYS as $n => $label): ?>
                    <input type="checkbox" class="btn-check" name="days[]" value="<?= $n ?>" id="d<?= $n ?>" <?= in_array($n, $days, true) ? 'checked' : '' ?>><label class="btn btn-outline-secondary btn-sm" for="d<?= $n ?>"><?= e($label) ?></label>
                <?php endforeach; ?>
            </div></div>
    </div>
    <div class="d-flex gap-2 mt-4">
        <button class="btn btn-primary"><?= $row ? 'Guardar cambios' : 'Agregar a la lista' ?></button>
        <a class="btn btn-outline-secondary" href="<?= e(url('/panel/libro/autorizados')) ?>">Cancelar</a>
    </div>
</form>
