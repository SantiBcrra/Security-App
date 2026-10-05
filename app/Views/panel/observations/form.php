<?php
/** @var array $old @var array $errors @var array $options @var array $employees @var bool $anonymousEnabled */
$invalid = fn (string $f) => isset($errors[$f]) ? ' is-invalid' : '';
$feedback = fn (string $f) => isset($errors[$f]) ? '<div class="invalid-feedback d-block">' . e($errors[$f]) . '</div>' : '';
$select = function (string $name, array $opts, string $placeholder) use ($old, $invalid): string {
    $html = '<select class="form-select' . $invalid($name) . '" id="f_' . e($name) . '" name="' . e($name) . '"><option value="">' . e($placeholder) . '</option>';
    foreach ($opts as $v => $label) {
        $html .= '<option value="' . e($v) . '"' . ((string) ($old[$name] ?? '') === (string) $v ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $html . '</select>';
};
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/observaciones')) ?>">←</a>
    <h1 class="h4 m-0">Nueva observación</h1>
</div>

<form method="post" enctype="multipart/form-data" action="<?= e(url('/panel/observaciones')) ?>" novalidate
      x-data="{ imminent: <?= $old['imminent'] ? 'true' : 'false' ?>, sending: false, resizing: false, photos: 0, gps: '', gpsOk: <?= $old['lat'] !== '' ? 'true' : 'false' ?> }"
      @submit="if (resizing) { $event.preventDefault(); return; } sending = true">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card shadow-sm mb-3">
                <div class="card-body row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">¿Qué es?</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($options['categories'] as $uuid => $name): ?>
                                <input type="radio" class="btn-check" name="category" id="cat_<?= e($uuid) ?>" value="<?= e($uuid) ?>" <?= $old['category'] === $uuid ? 'checked' : '' ?>>
                                <label class="btn btn-outline-primary" for="cat_<?= e($uuid) ?>"><?= e($name) ?></label>
                            <?php endforeach; ?>
                        </div>
                        <?= $feedback('category') ?>
                        <?php if (!$options['categories']): ?><div class="form-text text-danger">No hay categorías cargadas: Datos maestros → Catálogos.</div><?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold" for="f_description">¿Qué viste?</label>
                        <textarea class="form-control<?= $invalid('description') ?>" id="f_description" name="description" rows="4" maxlength="5000"
                                  placeholder="Ej: operario amolando sin protección facial cerca de la plegadora 2"><?= e($old['description']) ?></textarea>
                        <?= $feedback('description') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Severidad</label>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($options['severities'] as $s): ?>
                                <input type="radio" class="btn-check" name="severity" id="sev_<?= e($s['uuid']) ?>" value="<?= e($s['uuid']) ?>" <?= $old['severity'] === $s['uuid'] ? 'checked' : '' ?>>
                                <label class="btn btn-outline-secondary" for="sev_<?= e($s['uuid']) ?>" title="<?= e($s['description']) ?>">
                                    <span class="d-inline-block rounded-circle me-1" style="width:.7rem;height:.7rem;background:<?= e($s['color'] ?: '#6c757d') ?>"></span><?= e($s['name']) ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <?= $feedback('severity') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold" for="f_sector">Sector</label>
                        <?= $select('sector', $options['sectors'], 'Elegí el sector…') ?>
                        <?= $feedback('sector') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="f_risk_type">Tipo de riesgo</label>
                        <?= $select('risk_type', $options['risks'], 'Opcional') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="f_equipment">Equipo</label>
                        <?= $select('equipment', $options['equipment'], 'Ninguno') ?>
                        <?= $feedback('equipment') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="f_location_text">Lugar exacto</label>
                        <input class="form-control" id="f_location_text" name="location_text" value="<?= e($old['location_text']) ?>" maxlength="191" placeholder="Ej: al lado del tablero T3">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="f_occurred_at">Fecha y hora del hecho</label>
                        <input class="form-control<?= $invalid('occurred_at') ?>" type="datetime-local" id="f_occurred_at" name="occurred_at" value="<?= e($old['occurred_at']) ?>">
                        <?= $feedback('occurred_at') ?>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Ubicación GPS</label>
                        <input type="hidden" name="lat" x-ref="lat" value="<?= e($old['lat']) ?>">
                        <input type="hidden" name="lng" x-ref="lng" value="<?= e($old['lng']) ?>">
                        <input type="hidden" name="gps_accuracy" x-ref="acc" value="<?= e($old['gps_accuracy']) ?>">
                        <div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" @click="
                                if (!navigator.geolocation) { gps = 'El navegador no permite ubicación.'; return; }
                                gps = 'Buscando ubicación…';
                                navigator.geolocation.getCurrentPosition(p => {
                                    $refs.lat.value = p.coords.latitude.toFixed(7); $refs.lng.value = p.coords.longitude.toFixed(7);
                                    $refs.acc.value = Math.round(p.coords.accuracy); gpsOk = true;
                                    gps = 'Ubicación registrada (precisión ' + Math.round(p.coords.accuracy) + ' m).';
                                }, () => gps = 'No se pudo obtener la ubicación (¿permiso denegado?).', { enableHighAccuracy: true, timeout: 15000 });
                            "><span x-text="gpsOk ? '✓ Ubicación cargada' : 'Usar mi ubicación'"></span></button>
                            <span class="small text-body-secondary ms-1" x-text="gps"></span>
                        </div>
                        <?= $feedback('lat') ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="f_people">Personas involucradas <span class="text-body-secondary">(opcional)</span></label>
                        <select class="form-select" id="f_people" name="people[]" multiple size="4">
                            <?php foreach ($employees as $uuid => $name): ?>
                                <option value="<?= e($uuid) ?>" <?= in_array($uuid, (array) $old['people'], true) ? 'selected' : '' ?>><?= e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Ctrl/Cmd + clic para elegir varias. No es para buscar culpables: sirve para capacitar y prevenir.</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card shadow-sm mb-3 border-danger" :class="imminent ? 'bg-danger-subtle' : ''">
                <div class="card-body">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="f_imminent" name="imminent" value="1" x-model="imminent">
                        <label class="form-check-label fw-bold text-danger" for="f_imminent">RIESGO INMINENTE</label>
                    </div>
                    <div class="small mt-1">Alguien se puede lastimar ya mismo.</div>
                    <div class="alert alert-danger mt-2 mb-0 fw-semibold" x-show="imminent" x-cloak>
                        Avisá en persona o por radio al supervisor y frená la tarea. Este reporte no reemplaza el aviso inmediato.
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-body">
                    <label class="form-label fw-semibold" for="f_photos">Fotos</label>
                    <input class="form-control" type="file" id="f_photos" name="photos[]" accept="image/jpeg,image/png,image/webp" capture="environment" multiple data-resize
                           @resize-start="resizing = true" @resize-done="resizing = false; photos = $event.detail.length">
                    <div class="form-text"><span x-show="resizing">Preparando fotos…</span><span x-show="!resizing && photos" x-text="photos + ' foto(s) lista(s)'"></span>
                        <span x-show="!resizing && !photos">Hasta 10. Desde el celular abre la cámara.</span></div>
                    <?= $feedback('photos') ?>
                </div>
            </div>

            <?php if ($anonymousEnabled): ?>
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="f_anonymous" name="anonymous" value="1" <?= $old['anonymous'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="f_anonymous">Reportar como anónimo</label>
                        </div>
                        <div class="small text-body-secondary">No se guarda quién lo reportó (ni vos vas a poder verlo después en "mis reportes").</div>
                    </div>
                </div>
            <?php endif; ?>

            <button class="btn btn-lg w-100" :class="imminent ? 'btn-danger' : 'btn-primary'" :disabled="sending || resizing">
                <span x-show="sending" class="spinner-border spinner-border-sm me-1"></span>
                <span x-text="imminent ? 'Enviar RIESGO INMINENTE' : 'Enviar observación'"></span>
            </button>
        </div>
    </div>
</form>
<script src="<?= e(asset('js/image-resize.js')) ?>"></script>
