<?php /** Bloque de firma en pantalla. @var string $sigName nombre del input, @var string $sigLabel */ ?>
<div data-signature class="mb-2">
    <div class="d-flex justify-content-between align-items-end"><label class="form-label small mb-1"><?= e($sigLabel ?? 'Firma') ?></label>
        <button type="button" class="btn btn-link btn-sm p-0" data-clear>Borrar</button></div>
    <canvas class="border rounded bg-white w-100" style="height: 140px; cursor: crosshair"></canvas>
    <input type="hidden" name="<?= e($sigName ?? 'signature') ?>">
    <div class="form-text">Firmá con el dedo o el mouse dentro del recuadro.</div>
</div>
