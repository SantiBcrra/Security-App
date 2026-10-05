<?php require __DIR__ . '/_nav.php'; ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0">Catálogos</h1>
    <?php if (App\Services\UserAuth::can('datos_maestros', 'crear')): ?>
        <form method="post" action="<?= e(url('/panel/datos/plantilla')) ?>" class="d-flex gap-2"
              onsubmit="return confirm('Se agregan los ítems que falten (no se modifica ni se duplica nada). ¿Continuar?')">
            <?= csrf_field() ?>
            <select class="form-select form-select-sm" name="template">
                <?php foreach (App\Services\IndustryTemplates::available() as $key => $name): ?>
                    <option value="<?= e($key) ?>"><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary text-nowrap">Cargar plantilla de rubro</button>
        </form>
    <?php endif; ?>
</div>
<ul class="nav nav-tabs mb-3">
    <?php foreach (App\Resources\Registry::catalogs() as $key => $cat): ?>
        <li class="nav-item"><a class="nav-link <?= $key === $res->key() ? 'active' : '' ?>" href="<?= e(url('/panel/datos/' . $key)) ?>"><?= e($cat->plural()) ?></a></li>
    <?php endforeach; ?>
</ul>
<?php require __DIR__ . '/_toolbar.php'; ?>
<?php require __DIR__ . '/_table.php'; ?>
