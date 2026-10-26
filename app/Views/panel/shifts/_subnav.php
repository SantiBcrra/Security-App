<?php
/** Pestaña Turnos: barra del módulo + sub-secciones. @var string $sub hoy | programacion | ausencias | horas @var ?string $subtitle */
$sub ??= 'hoy';
$items = [
    'hoy'          => ['Hoy', '/panel/rondas/turnos'],
    'programacion' => ['Programación de turnos', '/panel/rondas/turnos/programacion'],
    'ausencias'    => ['Ausencias y días sin servicio', '/panel/rondas/turnos/ausencias'],
    'horas'        => ['Horas trabajadas', '/panel/rondas/turnos/horas'],
    'cobertura'    => ['Cobertura de puestos', '/panel/rondas/turnos/cobertura'],
];
?>
<?= App\Core\View::render('panel/rounds/_nav', ['tab' => in_array($sub, ['horas', 'cobertura'], true) ? 'reportes' : 'turnos', 'subtitle' => $subtitle ?? 'Horarios de trabajo de los guardias, asistencia, salidas del predio y ausencias'], null) ?>
<ul class="nav nav-pills small mb-3 gap-1">
    <?php foreach ($items as $key => [$label, $href]): ?>
        <li class="nav-item"><a class="nav-link py-1 px-2 <?= $sub === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>
