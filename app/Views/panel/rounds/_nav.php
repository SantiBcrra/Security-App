<?php
/**
 * Barra del módulo Rondas: siempre la misma en todas sus pantallas (pestañas + "Reportes ▾" + "+ Nuevo ▾"), y arriba los
 * pánicos sin atender. @var string $tab hoy | rondas | programacion | rutas | puntos | reportes @var ?string $subtitle
 */
use App\Services\UserAuth;
$tab ??= '';
$canCreate = UserAuth::can('rondas', 'crear');
$canEdit = UserAuth::can('rondas', 'editar');
$openPanics = UserAuth::scope('rondas') !== 'propios' ? App\Services\GuardSafety::openPanics() : [];
$tabs = [
    'hoy'          => ['Hoy', '/panel/rondas'],
    'rondas'       => ['Rondas realizadas', '/panel/rondas/historial'],
    'programacion' => ['Programación', '/panel/rondas/programacion'],
    'rutas'        => ['Rutas', '/panel/rondas/rutas'],
    'puntos'       => ['Puntos', '/panel/rondas/puntos'],
];
?>
<?php foreach ($openPanics as $pa): ?>
    <a class="alert alert-danger d-flex flex-wrap justify-content-between align-items-center gap-2 text-decoration-none mb-2" href="<?= e(url('/panel/rondas/panico/' . $pa['uuid'])) ?>">
        <span><strong>⚠ PÁNICO SIN ATENDER</strong> · <?= e($pa['user_name']) ?> · <?= e(fecha($pa['triggered_at_device'], 'd/m H:i')) ?><?= $pa['route_name'] ? ' · ' . e($pa['route_name']) : '' ?></span>
        <span class="btn btn-sm btn-danger">Ver y atender</span>
    </a>
<?php endforeach; ?>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-1">
    <div>
        <h1 class="h4 m-0">Rondas de guardias</h1>
        <?php if (!empty($subtitle)): ?><div class="text-body-secondary small"><?= e($subtitle) ?></div><?php endif; ?>
    </div>
    <?php if ($canCreate || $canEdit): ?>
        <div class="dropdown">
            <button class="btn btn-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">+ Nuevo</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php if ($canCreate): ?>
                    <li><a class="dropdown-item" href="<?= e(url('/panel/rondas/puntos/nuevo')) ?>"><strong>Punto de ronda</strong><div class="small text-body-secondary">Un lugar con QR / NFC donde se marca</div></a></li>
                    <li><a class="dropdown-item" href="<?= e(url('/panel/rondas/rutas/nueva')) ?>"><strong>Ruta</strong><div class="small text-body-secondary">Puntos en orden + guardias asignados</div></a></li>
                <?php endif; ?>
                <?php if ($canEdit): ?>
                    <li><a class="dropdown-item" href="<?= e(url('/panel/rondas/programacion/nueva')) ?>"><strong>Programación</strong><div class="small text-body-secondary">Horarios en que se tiene que hacer una ruta</div></a></li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
<ul class="nav nav-tabs mb-3">
    <?php foreach ($tabs as $key => [$label, $href]): ?>
        <li class="nav-item"><a class="nav-link text-nowrap <?= $tab === $key ? 'active fw-semibold' : '' ?>" href="<?= e(url($href)) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
    <li class="nav-item dropdown">
        <a class="nav-link dropdown-toggle text-nowrap <?= $tab === 'reportes' ? 'active fw-semibold' : '' ?>" data-bs-toggle="dropdown" href="#" role="button" aria-expanded="false">Reportes</a>
        <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="<?= e(url('/panel/rondas/metricas')) ?>"><strong>Métricas</strong><div class="small text-body-secondary">Cumplimiento por guardia y por punto, alertas, CSV</div></a></li>
            <li><a class="dropdown-item" href="<?= e(url('/panel/rondas/marcas-fuera')) ?>"><strong>Marcas fuera de lugar</strong><div class="small text-body-secondary">Marcas lejos del punto o con GPS falso</div></a></li>
        </ul>
    </li>
</ul>
