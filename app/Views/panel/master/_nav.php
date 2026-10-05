<?php
/** Submenú de datos maestros. @var App\Resources\Resource $res */
$items = [
    'plantas' => 'Plantas', 'sectores' => 'Sectores', 'puestos' => 'Puestos', 'empleados' => 'Empleados',
    'contratistas' => 'Contratistas', 'equipos' => 'Equipos', 'riesgos' => 'Catálogos',
];
$current = $res instanceof App\Resources\CatalogResource ? 'riesgos' : $res->key();
?>
<ul class="nav nav-pills small mb-3 flex-nowrap overflow-auto">
    <?php foreach ($items as $key => $label): ?>
        <li class="nav-item"><a class="nav-link py-1 <?= $current === $key ? 'active' : '' ?>" href="<?= e(url('/panel/datos/' . $key)) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
    <?php if (App\Services\UserAuth::can('datos_maestros', 'crear')): ?>
        <li class="nav-item ms-auto"><a class="nav-link py-1" href="<?= e(url('/panel/importar')) ?>">Importar Excel / CSV</a></li>
    <?php endif; ?>
</ul>
