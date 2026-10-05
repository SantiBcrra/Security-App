<?php /** Buscador, filtros y botón "nuevo". @var App\Resources\Resource $res */ ?>
<form class="row g-2 align-items-center mb-3" method="get">
    <div class="col-12 col-md-4">
        <input class="form-control form-control-sm" type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar…">
    </div>
    <?php foreach ($res->filterControls() as $name => [$label, $options]): ?>
        <div class="col-6 col-md-auto">
            <select class="form-select form-select-sm" name="<?= e($name) ?>" onchange="this.form.submit()">
                <option value=""><?= e($label) ?>: todos</option>
                <?php foreach ($options as $value => $text): ?>
                    <option value="<?= e($value) ?>" <?= ($filters[$name] ?? '') === (string) $value ? 'selected' : '' ?>><?= e($text) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endforeach; ?>
    <div class="col-auto form-check ms-1">
        <input class="form-check-input" type="checkbox" id="inactivos" name="inactivos" value="1" <?= $inactive ? 'checked' : '' ?> onchange="this.form.submit()">
        <label class="form-check-label small" for="inactivos">Ver inactivos</label>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Buscar</button></div>
    <?php if (App\Services\UserAuth::can('datos_maestros', 'crear')): ?>
        <div class="col-auto ms-md-auto"><a class="btn btn-sm btn-primary" href="<?= e(url('/panel/datos/' . $res->key() . '/nuevo')) ?>"><?= e($res->newLabel()) ?></a></div>
    <?php endif; ?>
</form>
