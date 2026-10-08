<?php
use App\Services\PpeService;
require __DIR__ . '/_badges.php';
$v = fn (string $k, $d = '') => $old[$k] ?? $d;
$err = fn (string $k) => isset($errors[$k]) ? '<div class="text-danger small">' . e($errors[$k]) . '</div>' : '';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/epp')) ?>">←</a>
    <h1 class="h4 m-0">EPP · catálogo</h1>
    <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= e(url('/panel/epp/matriz')) ?>">Matriz por puesto →</a>
</div>
<div class="row g-3">
    <div class="<?= $canManage ? 'col-lg-8' : 'col-12' ?>">
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-sm small mb-0 align-middle">
                    <thead><tr><th>Elemento</th><th>Categoría</th><th>Marca / modelo</th><th>Certificación</th><th>Vida útil</th><th>Talle</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $i): ?>
                        <tr class="<?= (int) $i['is_active'] ? '' : 'text-body-secondary' ?>">
                            <td><?= e($i['name']) ?><?= (int) $i['is_active'] ? '' : ' <span class="badge text-bg-secondary">baja</span>' ?></td>
                            <td><?= e(PpeService::CATEGORIES[$i['category']] ?? $i['category']) ?></td>
                            <td><?= e(trim(($i['brand'] ?? '') . ' ' . ($i['model'] ?? ''))) ?: '—' ?></td>
                            <td><?= (int) $i['certified'] ? '✓ ' . e($i['certification'] ?? '') : 'No' ?></td>
                            <td><?= e($ppeLife($i['life_days'] !== null ? (int) $i['life_days'] : null)) ?></td>
                            <td><?= e(PpeService::SIZE_TYPES[$i['size_type']]['label'] ?? '—') ?></td>
                            <td class="text-end text-nowrap"><?php if ($canManage): ?>
                                <a href="<?= e(url('/panel/epp/catalogo?editar=' . $i['uuid'])) ?>">Editar</a>
                                <form class="d-inline" method="post" action="<?= e(url('/panel/epp/catalogo/' . $i['uuid'] . '/estado')) ?>"><?= csrf_field() ?>
                                    <button class="btn btn-link btn-sm p-0 ms-2"><?= (int) $i['is_active'] ? 'Dar de baja' : 'Activar' ?></button></form>
                            <?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$items): ?><tr><td colspan="7" class="text-body-secondary">El catálogo está vacío. Cargá el del rubro o agregá elementos.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php if ($canManage): ?>
        <div class="col-lg-4">
            <form class="card shadow-sm mb-3" method="post" action="<?= e(url('/panel/epp/catalogo')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="uuid" value="<?= e($edit['uuid'] ?? '') ?>">
                <div class="card-header"><strong><?= $edit ? 'Editar ' . e($edit['name']) : 'Nuevo elemento' ?></strong></div>
                <div class="card-body">
                    <div class="mb-2"><label class="form-label small mb-0">Nombre</label><input class="form-control form-control-sm" name="name" required value="<?= e($v('name')) ?>"><?= $err('name') ?></div>
                    <div class="mb-2"><label class="form-label small mb-0">Categoría</label><select class="form-select form-select-sm" name="category"><option value="">…</option>
                        <?php foreach (PpeService::CATEGORIES as $k => $label): ?><option value="<?= e($k) ?>" <?= $v('category') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select><?= $err('category') ?></div>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small mb-0">Marca</label><input class="form-control form-control-sm" name="brand" value="<?= e($v('brand')) ?>"></div>
                        <div class="col-6"><label class="form-label small mb-0">Tipo / modelo</label><input class="form-control form-control-sm" name="model" value="<?= e($v('model')) ?>"></div>
                    </div>
                    <div class="form-check small"><input class="form-check-input" type="checkbox" name="certified" value="1" id="cert" <?= $v('certified') ? 'checked' : '' ?>><label class="form-check-label" for="cert">Posee certificación</label></div>
                    <input class="form-control form-control-sm mb-2" name="certification" value="<?= e($v('certification')) ?>" placeholder="Norma / sello (ej. IRAM 3620)">
                    <div class="row g-2 mb-2">
                        <div class="col-6"><label class="form-label small mb-0">Vida útil (días)</label><input class="form-control form-control-sm" name="life_days" inputmode="numeric" value="<?= e((string) $v('life_days')) ?>" placeholder="vacío = no vence"><?= $err('life_days') ?></div>
                        <div class="col-6"><label class="form-label small mb-0">Talle</label><select class="form-select form-select-sm" name="size_type"><option value="">No lleva</option>
                            <?php foreach (PpeService::SIZE_TYPES as $k => $t): ?><option value="<?= e($k) ?>" <?= $v('size_type') === $k ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?></select></div>
                    </div>
                    <div class="form-text mb-2">Cambiar un elemento no altera las entregas ya hechas: cada entrega guarda cómo era al entregarlo.</div>
                    <button class="btn btn-primary btn-sm"><?= $edit ? 'Guardar cambios' : 'Agregar' ?></button>
                    <?php if ($edit): ?><a class="btn btn-link btn-sm" href="<?= e(url('/panel/epp/catalogo')) ?>">Cancelar</a><?php endif; ?>
                </div>
            </form>
            <?php if ($industries): ?>
                <form class="card shadow-sm" method="post" action="<?= e(url('/panel/epp/catalogo/plantilla')) ?>">
                    <?= csrf_field() ?>
                    <div class="card-body">
                        <div class="small mb-2"><strong>EPP del rubro</strong>: elementos típicos y una matriz sugerida para los puestos del rubro. Solo agrega lo que falta.</div>
                        <div class="d-flex gap-2"><select class="form-select form-select-sm" name="industry"><?php foreach ($industries as $k => $name): ?><option value="<?= e($k) ?>"><?= e($name) ?></option><?php endforeach; ?></select>
                            <button class="btn btn-outline-primary btn-sm text-nowrap">Cargar</button></div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
