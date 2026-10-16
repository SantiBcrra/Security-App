<?php
/** Turnos → Programación. @var array $shifts @var bool $canEdit @var array $sites */
use App\Services\ShiftPlanner;
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'programacion'], null) ?>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Turnos programados</span>
        <?php if ($canEdit): ?><a class="btn btn-sm btn-primary" href="<?= e(url('/panel/rondas/turnos/programacion/nuevo')) ?>">+ Nuevo turno</a><?php endif; ?>
    </div>
    <div class="table-responsive"><table class="table align-middle mb-0">
        <thead><tr><th>Turno</th><th>Predio</th><th>Horario</th><th>Guardias</th><th>Vigencia</th><th>Estado</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($shifts as $s): ?>
            <tr class="<?= (int) $s['is_active'] === 1 ? '' : 'text-body-secondary' ?>">
                <td class="fw-semibold"><?= e($s['name'] ?: 'Sin nombre') ?></td>
                <td><?= e($s['site_name']) ?></td>
                <td><?= e(ShiftPlanner::describe($s)) ?></td>
                <td><?= e(ShiftPlanner::guardNames($s)) ?></td>
                <td class="small"><?= $s['valid_from'] || $s['valid_until']
                    ? e(($s['valid_from'] ? 'desde ' . date('d/m/Y', strtotime($s['valid_from'])) : '') . ($s['valid_until'] ? ' hasta ' . date('d/m/Y', strtotime($s['valid_until'])) : ''))
                    : 'Sin vencimiento' ?></td>
                <td><?= (int) $s['is_active'] === 1 ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Pausado</span>' ?></td>
                <td class="text-end text-nowrap">
                    <?php if ($canEdit): ?>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/turnos/programacion/' . $s['uuid'])) ?>">Editar</a>
                        <form method="post" action="<?= e(url('/panel/rondas/turnos/programacion/' . $s['uuid'] . '/estado')) ?>" class="d-inline">
                            <?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary"><?= (int) $s['is_active'] === 1 ? 'Pausar' : 'Activar' ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$shifts): ?><tr><td colspan="7" class="text-body-secondary">Todavía no hay turnos. <?= $canEdit ? 'Cargá el primero con "+ Nuevo turno".' : '' ?></td></tr><?php endif; ?>
        </tbody>
    </table></div>
    <div class="card-footer small text-body-secondary">
        Los turnos de cada día se generan solos una semana adelante. Editar o pausar recalcula los que todavía no empezaron (lo pasado queda).
        Para un día sin servicio (feriado, planta cerrada) o la ausencia de un guardia usá <a href="<?= e(url('/panel/rondas/turnos/ausencias')) ?>">Ausencias y días sin servicio</a>.
    </div>
</div>

<div class="card shadow-sm mt-4">
    <div class="card-header fw-semibold">Predios</div>
    <div class="card-body small text-body-secondary pb-0">Para saber si el guardia está en el predio hace falta su centro y radio. Ubicalo sobre el mapa satelital.</div>
    <ul class="list-group list-group-flush">
        <?php foreach ($sites as $p): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><strong><?= e($p['name']) ?></strong> ·
                    <?= $p['lat'] !== null ? e('radio ' . (int) ($p['geofence_radius_m'] ?: 200) . ' m') : '<span class="text-danger">sin ubicación: el control de salida no funciona</span>' ?></span>
                <?php if ($canEdit): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/panel/rondas/turnos/predio/' . $p['uuid'])) ?>">Ubicar en el mapa</a><?php endif; ?>
            </li>
        <?php endforeach; ?>
        <?php if (!$sites): ?><li class="list-group-item text-body-secondary">No hay plantas cargadas (Datos maestros → Plantas).</li><?php endif; ?>
    </ul>
</div>
