<?php
use App\Services\ActionWorkflow;
$err = fn (string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block">' . e($errors[$k]) . '</div>' : '';
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/acciones')) ?>">←</a>
    <h1 class="h4 m-0">Nueva acción</h1>
</div>
<form method="post" enctype="multipart/form-data" action="<?= e(url('/panel/acciones')) ?>" class="card shadow-sm card-body" style="max-width: 820px" x-data="{ resizing: false }">
    <?= csrf_field() ?>
    <div class="small text-body-secondary mb-3">Para una acción que surge de una observación, usá "Asignar acción" desde la observación: así queda vinculada.</div>
    <label class="form-label">Acción a realizar</label>
    <input class="form-control mb-1" name="title" maxlength="191" required value="<?= e($old['title']) ?>" placeholder="Ej: Instalar guarda en la amoladora de banco">
    <?= $err('title') ?>
    <label class="form-label mt-2">Detalle (opcional)</label>
    <textarea class="form-control mb-2" name="description" rows="3"><?= e($old['description']) ?></textarea>
    <div class="row g-3 mb-2">
        <div class="col-md-6">
            <label class="form-label">Responsable</label>
            <select class="form-select" name="responsible" required>
                <option value="">Elegí…</option>
                <?php foreach ($users as $u): ?><option value="<?= e($u['uuid']) ?>" <?= $old['responsible'] === $u['uuid'] ? 'selected' : '' ?>><?= e($u['name'] . ' · ' . $u['role_name']) ?></option><?php endforeach; ?>
            </select>
            <?= $err('responsible') ?>
        </div>
        <div class="col-md-6">
            <label class="form-label">Fecha límite</label>
            <input class="form-control" type="date" name="due_on" required min="<?= e(App\Services\ActionService::today()) ?>" value="<?= e($old['due_on']) ?>">
            <?= $err('due_on') ?>
        </div>
        <div class="col-md-4">
            <label class="form-label">Prioridad</label>
            <select class="form-select" name="priority"><?php foreach (ActionWorkflow::PRIORITIES as $k => $n): ?><option value="<?= e($k) ?>" <?= $old['priority'] === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Tipo</label>
            <select class="form-select" name="type"><?php foreach (ActionWorkflow::TYPES as $k => $n): ?><option value="<?= e($k) ?>" <?= $old['type'] === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Origen</label>
            <select class="form-select" name="origin">
                <option value="manual" <?= $old['origin'] === 'manual' ? 'selected' : '' ?>>Manual</option>
                <option value="auditoria" <?= $old['origin'] === 'auditoria' ? 'selected' : '' ?>>Auditoría</option>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Sector (opcional)</label>
            <select class="form-select" name="sector"><option value="">—</option>
                <?php foreach ($sectors as $u => $n): ?><option value="<?= e($u) ?>" <?= $old['sector'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
            </select>
            <?= $err('sector') ?>
        </div>
    </div>
    <label class="form-label">Archivos de referencia (opcional: fotos o PDF)</label>
    <input class="form-control mb-1" type="file" name="files[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple data-resize
           @resize-start="resizing = true" @resize-done="resizing = false">
    <?= $err('files') ?>
    <script src="<?= e(asset('js/image-resize.js')) ?>"></script>
    <div class="mt-3"><button class="btn btn-primary" :disabled="resizing">Crear acción</button></div>
</form>
