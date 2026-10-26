<?php
/** Turnos → Guardias y PIN: empleados que se identifican en el celular compartido del puesto. @var array $rows @var string $q */
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'guardias'], null) ?>
<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h2 class="h4 m-0">Guardias y PIN</h2>
        <div class="text-body-secondary small" style="max-width: 760px">Los guardias son <strong>empleados</strong> (Datos maestros → Empleados), no usuarios: no tienen
            contraseña ni pueden entrar al sistema. En el celular del puesto cada uno elige su nombre y pone su <strong>PIN</strong> al empezar el turno o en el
            cambio de guardia; lo que hace queda a su nombre. Para que aparezca en el celular, además de tener PIN tiene que estar en el grupo de una
            <a href="<?= e(url('/panel/rondas/turnos/programacion')) ?>">programación por puesto</a>.</div>
    </div>
    <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/panel/datos/empleados/nuevo')) ?>">+ Nuevo empleado</a>
</div>
<form class="d-flex gap-2 mb-3" method="get"><input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Nombre, DNI o empresa" style="max-width: 320px">
    <button class="btn btn-sm btn-outline-secondary">Buscar</button></form>
<div class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Empleado</th><th>Puestos</th><th>PIN</th><th style="min-width: 260px"></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong>
                <div class="small text-body-secondary">DNI <?= e($r['dni']) ?><?= $r['contractor_name'] ? ' · ' . e($r['contractor_name']) : ' · Personal propio' ?></div></td>
            <td class="small"><?= $r['posts'] ? e(implode(', ', $r['posts'])) : '<span class="text-body-secondary">En ningún puesto</span>' ?></td>
            <td><?= $r['has_pin'] ? '<span class="badge text-bg-success">Con PIN</span><div class="small text-body-secondary">desde ' . e(fecha($r['pin_set_at'], 'd/m/Y')) . '</div>' : '<span class="badge text-bg-warning">Sin PIN</span>' ?></td>
            <td><form class="d-flex gap-1" method="post" action="<?= e(url('/panel/rondas/turnos/guardias/' . $r['uuid'] . '/pin')) ?>"><?= csrf_field() ?>
                <input type="hidden" name="q" value="<?= e($q) ?>">
                <input class="form-control form-control-sm" name="pin" inputmode="numeric" pattern="\d{4,6}" maxlength="6" autocomplete="off" placeholder="<?= $r['has_pin'] ? 'Nuevo PIN' : 'PIN (4 a 6 números)' ?>" style="max-width: 150px" required>
                <button class="btn btn-sm btn-primary"><?= $r['has_pin'] ? 'Cambiar' : 'Guardar' ?></button></form>
                <?php if ($r['has_pin']): ?><form method="post" class="mt-1" action="<?= e(url('/panel/rondas/turnos/guardias/' . $r['uuid'] . '/pin')) ?>" onsubmit="return confirm('¿Quitar el PIN? No va a poder identificarse en el celular.')"><?= csrf_field() ?>
                    <input type="hidden" name="quitar" value="1"><input type="hidden" name="q" value="<?= e($q) ?>"><button class="btn btn-link btn-sm p-0 text-danger">Quitar PIN</button></form><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="4" class="text-body-secondary">No hay empleados<?= $q !== '' ? ' con esa búsqueda' : '' ?>. Cargalos en Datos maestros → Empleados.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
