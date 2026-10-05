<?php
/** Formulario genérico de datos maestros. @var App\Resources\Resource $res @var ?array $row */
use App\Services\UserAuth;
$canSave = $row ? UserAuth::can('datos_maestros', 'editar') : UserAuth::can('datos_maestros', 'crear');
$action = url('/panel/datos/' . $res->key() . ($row ? '/' . $row['uuid'] : ''));
require __DIR__ . '/_nav.php';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/datos/' . $res->key())) ?>">←</a>
    <h1 class="h4 m-0"><?= e($title) ?></h1>
    <?php if ($row && (int) $row['is_active'] !== 1): ?><span class="badge text-bg-secondary">Inactivo</span><?php endif; ?>
</div>

<div class="row g-3">
    <div class="<?= $side ? 'col-lg-8' : 'col-lg-9' ?>">
        <form class="card shadow-sm" method="post" action="<?= e($action) ?>" novalidate>
            <?= csrf_field() ?>
            <fieldset class="card-body row g-3" <?= $canSave ? '' : 'disabled' ?>>
                <?php foreach ($fields as $f):
                    $name = $f['name']; $id = 'f_' . $name; $value = (string) ($values[$name] ?? '');
                    $invalid = isset($errors[$name]) ? ' is-invalid' : '';
                    $type = $f['type'] ?? 'text'; ?>
                    <div class="col-md-<?= e($f['col'] ?? 6) ?>">
                        <label class="form-label" for="<?= e($id) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="text-danger">*</span>' : '' ?></label>
                        <?php if ($type === 'textarea'): ?>
                            <textarea class="form-control<?= $invalid ?>" id="<?= e($id) ?>" name="<?= e($name) ?>" rows="3"><?= e($value) ?></textarea>
                        <?php elseif ($type === 'select' || $type === 'ref'):
                            $options = $type === 'ref' ? ($f['options'])($row ? (isset($row[$name]) ? (int) $row[$name] : null) : null) : $f['options']; ?>
                            <select class="form-select<?= $invalid ?>" id="<?= e($id) ?>" name="<?= e($name) ?>">
                                <?php if (empty($f['required']) || $value === ''): ?><option value="">—</option><?php endif; ?>
                                <?php foreach ($options as $optValue => $optLabel): ?>
                                    <option value="<?= e($optValue) ?>" <?= (string) $optValue === $value ? 'selected' : '' ?>><?= e($optLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else:
                            $htmlType = match ($type) { 'email' => 'email', 'tel' => 'tel', 'date' => 'date', 'color' => 'color', 'number' => 'number', default => 'text' };
                            $inputValue = $type === 'date' && $value !== '' ? (App\Resources\Resource::parseDate($value) ?? $value) : $value; ?>
                            <input class="form-control<?= $type === 'color' ? ' form-control-color' : '' ?><?= $invalid ?>" type="<?= e($htmlType) ?>"
                                   id="<?= e($id) ?>" name="<?= e($name) ?>" value="<?= e($inputValue) ?>"
                                   <?= isset($f['placeholder']) ? 'placeholder="' . e($f['placeholder']) . '"' : '' ?>
                                   <?= $type === 'decimal' ? 'inputmode="decimal"' : '' ?>
                                   <?= $type === 'number' && isset($f['min']) ? 'min="' . e($f['min']) . '"' : '' ?>
                                   <?= $type === 'number' && isset($f['max']) ? 'max="' . e($f['max']) . '"' : '' ?>>
                        <?php endif; ?>
                        <?php if (isset($errors[$name])): ?><div class="invalid-feedback"><?= e($errors[$name]) ?></div>
                        <?php elseif (!empty($f['help'])): ?><div class="form-text"><?= e($f['help']) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <?php if ($res->key() === 'plantas'): ?>
                    <div class="col-12" x-data="{ msg: '' }">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="
                            if (!navigator.geolocation) { msg = 'El navegador no permite ubicación.'; return; }
                            msg = 'Buscando ubicación…';
                            navigator.geolocation.getCurrentPosition(p => {
                                document.getElementById('f_lat').value = p.coords.latitude.toFixed(7);
                                document.getElementById('f_lng').value = p.coords.longitude.toFixed(7);
                                if (!document.getElementById('f_geofence_radius_m').value) document.getElementById('f_geofence_radius_m').value = 300;
                                msg = 'Ubicación cargada (precisión ' + Math.round(p.coords.accuracy) + ' m).';
                            }, () => msg = 'No se pudo obtener la ubicación (¿permiso denegado?).', { enableHighAccuracy: true });
                        ">Usar mi ubicación actual</button>
                        <span class="small text-body-secondary ms-2" x-text="msg"></span>
                    </div>
                <?php endif; ?>

                <?php if ($canSave): ?>
                    <div class="col-12 d-flex flex-wrap gap-2">
                        <button class="btn btn-primary"><?= $row ? 'Guardar cambios' : 'Guardar' ?></button>
                        <?php if (!$row): ?><button class="btn btn-outline-primary" name="_next" value="otro">Guardar y cargar otro</button><?php endif; ?>
                    </div>
                <?php endif; ?>
            </fieldset>
        </form>

        <?php if ($row && UserAuth::can('datos_maestros', 'editar')): ?>
            <form class="mt-3" method="post" action="<?= e($action . '/estado') ?>"
                  <?= (int) $row['is_active'] === 1 ? 'onsubmit="return confirm(\'¿Desactivar? Deja de aparecer para elegir, pero se conserva todo su historial.\')"' : '' ?>>
                <?= csrf_field() ?>
                <button class="btn btn-sm <?= (int) $row['is_active'] === 1 ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                    <?= (int) $row['is_active'] === 1 ? 'Desactivar' : 'Reactivar' ?>
                </button>
            </form>
        <?php endif; ?>
    </div>

    <?php if ($side): ?>
        <div class="col-lg-4">
            <?php extract($side['data'], EXTR_SKIP); require BASE_PATH . '/app/Views/' . $side['view'] . '.php'; ?>
        </div>
    <?php endif; ?>
</div>
