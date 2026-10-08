<?php
use App\Services\InspectionStructure;
$answers = $old['answers'] ?? [];
$err = fn (string $k) => isset($errors[$k]) ? '<div class="text-danger small mt-1">' . e($errors[$k]) . '</div>' : '';
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/nueva')) ?>">←</a>
    <h1 class="h4 m-0"><?= e($template['name']) ?></h1>
    <span class="small text-body-secondary">versión <?= e($version['version']) ?></span>
</div>
<?php if ($equipment): ?><p class="mb-3"><strong><?= e($equipment['code']) ?></strong> · <?= e($equipment['name']) ?></p><?php endif; ?>
<?php if ($template['description']): ?><div class="alert alert-secondary small"><?= e($template['description']) ?></div><?php endif; ?>

<form method="post" enctype="multipart/form-data" action="<?= e(url('/panel/inspecciones')) ?>" style="max-width: 860px"
      x-data="{ resizing: 0, gps() { navigator.geolocation && navigator.geolocation.getCurrentPosition(p => { this.$refs.lat.value = p.coords.latitude; this.$refs.lng.value = p.coords.longitude; this.$refs.acc.value = Math.round(p.coords.accuracy); }, () => {}, { enableHighAccuracy: true, timeout: 8000 }); } }"
      x-init="gps()">
    <?= csrf_field() ?>
    <input type="hidden" name="template" value="<?= e($template['uuid']) ?>">
    <input type="hidden" name="version" value="<?= e($version['uuid']) ?>">
    <input type="hidden" name="equipment" value="<?= e($equipment['uuid'] ?? '') ?>">
    <input type="hidden" name="lat" x-ref="lat"><input type="hidden" name="lng" x-ref="lng"><input type="hidden" name="gps_accuracy" x-ref="acc">

    <?php if ($template['scope'] === 'sector'): ?>
        <div class="card shadow-sm mb-3"><div class="card-body">
            <label class="form-label">Sector inspeccionado</label>
            <select class="form-select" name="sector" required><option value="">Elegí…</option>
                <?php foreach ($sectors as $u => $n): ?><option value="<?= e($u) ?>" <?= ($old['sector'] ?? '') === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
            </select><?= $err('sector') ?>
        </div></div>
    <?php endif; ?>

    <?php foreach ($version['structure']['sections'] as $sec): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong><?= e($sec['title']) ?></strong></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($sec['items'] as $item): $k = $item['key']; $v = $answers[$k]['value'] ?? ''; ?>
                    <li class="list-group-item <?= isset($errors[$k]) ? 'list-group-item-danger' : '' ?>" x-data="{ v: <?= e(json_encode($v)) ?> }">
                        <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">
                            <div class="me-2">
                                <?= $item['critical'] ? '<span class="badge text-bg-danger me-1">Crítico</span>' : '' ?><?= e($item['text']) ?>
                                <?php if ($item['help']): ?><div class="small text-body-secondary"><?= e($item['help']) ?></div><?php endif; ?>
                            </div>
                            <?php if (in_array($item['type'], ['si_no', 'si_no_na'], true)): ?>
                                <div class="btn-group" role="group">
                                    <?php foreach (['si' => 'Sí', 'no' => 'No'] + ($item['type'] === 'si_no_na' ? ['na' => 'N/A'] : []) as $val => $lbl):
                                        $good = $val === 'na' ? 'secondary' : ($val === $item['ok_when'] ? 'success' : 'danger'); ?>
                                        <input type="radio" class="btn-check" name="answers[<?= e($k) ?>][value]" id="a_<?= e($k . $val) ?>" value="<?= e($val) ?>" x-model="v">
                                        <label class="btn btn-outline-<?= e($good) ?>" style="min-width: 58px" for="a_<?= e($k . $val) ?>"><?= e($lbl) ?></label>
                                    <?php endforeach; ?>
                                </div>
                            <?php elseif ($item['type'] === 'numero'): ?>
                                <div class="input-group" style="max-width: 220px">
                                    <input class="form-control" name="answers[<?= e($k) ?>][value]" inputmode="decimal" value="<?= e($v) ?>" x-model="v" required>
                                    <?php if ($item['unit']): ?><span class="input-group-text"><?= e($item['unit']) ?></span><?php endif; ?>
                                </div>
                                <div class="w-100 small text-body-secondary">Rango correcto: <?= e(($item['min'] ?? '—') . ' a ' . ($item['max'] ?? '—')) ?></div>
                            <?php else: ?>
                                <input class="form-control" name="answers[<?= e($k) ?>][value]" value="<?= e($v) ?>" maxlength="255">
                            <?php endif; ?>
                        </div>
                        <?php $fails = in_array($item['type'], ['si_no', 'si_no_na'], true) ? "v === '" . ($item['ok_when'] === 'si' ? 'no' : 'si') . "'" : 'false'; ?>
                        <?php if (in_array($item['type'], ['si_no', 'si_no_na'], true)): ?>
                            <div class="mt-2" x-show="<?= e($fails) ?> || <?= json_encode(($answers[$k]['comment'] ?? '') !== '') ?>">
                                <textarea class="form-control form-control-sm" name="answers[<?= e($k) ?>][comment]" rows="2" placeholder="¿Qué pasa? (obligatorio si no cumple: se crea una acción correctiva)"><?= e($answers[$k]['comment'] ?? '') ?></textarea>
                            </div>
                        <?php elseif ($item['type'] === 'numero'): ?>
                            <textarea class="form-control form-control-sm mt-2" name="answers[<?= e($k) ?>][comment]" rows="1" placeholder="Comentario (obligatorio si queda fuera de rango)"><?= e($answers[$k]['comment'] ?? '') ?></textarea>
                        <?php endif; ?>
                        <?php if ($item['photo'] !== 'nunca'): ?>
                            <div class="mt-2" x-show="<?= $item['photo'] === 'siempre' ? 'true' : e($fails) ?>">
                                <label class="small text-body-secondary">Foto <?= $item['photo'] === 'siempre' ? '(obligatoria)' : '(obligatoria si no cumple)' ?></label>
                                <input class="form-control form-control-sm" type="file" name="photos[<?= e($k) ?>][]" accept="image/jpeg,image/png,image/webp" multiple data-resize
                                       @resize-start="resizing++" @resize-done="resizing--">
                            </div>
                        <?php endif; ?>
                        <?= $err($k) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endforeach; ?>

    <div class="card shadow-sm mb-3"><div class="card-body">
        <div class="row g-3">
            <div class="col-md-5"><label class="form-label">Fecha y hora</label>
                <input class="form-control" type="datetime-local" name="done_at" value="<?= e($old['done_at'] ?? fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d\TH:i')) ?>"><?= $err('done_at') ?></div>
            <div class="col-md-7"><label class="form-label">Notas (opcional)</label><textarea class="form-control" name="notes" rows="2"><?= e($old['notes'] ?? '') ?></textarea></div>
        </div>
    </div></div>
    <?= $err('_') . $err('equipment') ?>
    <button class="btn btn-primary btn-lg mb-4" :disabled="resizing > 0">Guardar inspección</button>
</form>
<script src="<?= e(asset('js/image-resize.js')) ?>"></script>
