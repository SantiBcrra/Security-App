<?php
use App\Services\WorkPermitService;
$err = fn (string $k) => isset($errors[$k]) ? '<div class="text-danger small mt-1">' . e($errors[$k]) . '</div>' : '';
$workers = $old['workers'] ?: [['role' => 'ejecutor']];
$answers = $old['checklists'] ?? [];
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/permisos')) ?>">←</a>
    <h1 class="h4 m-0">Solicitar permiso de trabajo</h1>
</div>
<?php if ($errors): ?><div class="alert alert-danger small" style="max-width: 900px"><?php foreach (array_slice($errors, 0, 8) as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>

<form method="post" action="<?= e(url('/panel/permisos')) ?>" style="max-width: 900px"
      x-data="{ types: <?= e(json_encode(array_values((array) $old['types']))) ?>, workers: <?= e(json_encode(array_values($workers))) ?>,
               gps() { navigator.geolocation && navigator.geolocation.getCurrentPosition(p => { this.$refs.lat.value = p.coords.latitude; this.$refs.lng.value = p.coords.longitude; }, () => {}, { timeout: 8000 }); } }"
      x-init="gps()">
    <?= csrf_field() ?>
    <input type="hidden" name="lat" x-ref="lat"><input type="hidden" name="lng" x-ref="lng">

    <div class="card shadow-sm mb-3"><div class="card-body">
        <label class="form-label fw-semibold">1. Tipo de trabajo <span class="small text-body-secondary fw-normal">(se pueden combinar)</span></label>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach (WorkPermitService::TYPES as $k => $t): ?>
                <input type="checkbox" class="btn-check" name="types[]" id="t_<?= e($k) ?>" value="<?= e($k) ?>" x-model="types">
                <label class="btn btn-outline-<?= e($t['class']) ?>" for="t_<?= e($k) ?>"><?= e($t['label']) ?></label>
            <?php endforeach; ?>
        </div>
        <?= $err('types') ?>
    </div></div>

    <div class="card shadow-sm mb-3"><div class="card-body row g-3">
        <div class="col-12 fw-semibold">2. Dónde y qué</div>
        <div class="col-md-6"><label class="form-label">Sector</label>
            <select class="form-select" name="sector"><option value="">Elegí…</option>
                <?php foreach ($sectors as $u => $n): ?><option value="<?= e($u) ?>" <?= $old['sector'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select><?= $err('sector') ?></div>
        <div class="col-md-6"><label class="form-label">Equipo (si corresponde)</label>
            <select class="form-select" name="equipment"><option value="">—</option>
                <?php foreach ($equipment as $u => $n): ?><option value="<?= e($u) ?>" <?= $old['equipment'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Lugar exacto</label><input class="form-control" name="location_text" maxlength="191" value="<?= e($old['location_text']) ?>" placeholder="Ej: techo de la nave 2, tanque T-3"></div>
        <div class="col-md-6"><label class="form-label">Contratista (si lo hace una empresa externa)</label>
            <select class="form-select" name="contractor"><option value="">Personal propio</option>
                <?php foreach ($contractors as $u => $n): ?><option value="<?= e($u) ?>" <?= $old['contractor'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label">Tarea a realizar</label>
            <textarea class="form-control" name="task" rows="3" placeholder="Qué se va a hacer, con qué herramientas y cómo"><?= e($old['task']) ?></textarea><?= $err('task') ?></div>
        <div class="col-md-6"><label class="form-label">Inicio</label><input class="form-control" type="datetime-local" name="valid_from" value="<?= e($old['valid_from']) ?>"></div>
        <div class="col-md-6"><label class="form-label">Fin</label><input class="form-control" type="datetime-local" name="valid_until" value="<?= e($old['valid_until']) ?>">
            <div class="form-text">Máximo <?= e($maxHours) ?> horas. Vence solo al llegar a esta hora.</div></div>
        <div class="col-12"><?= $err('valid') ?></div>
    </div></div>

    <div class="card shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-2">3. Quiénes lo hacen <span class="small text-body-secondary fw-normal">(cada uno firma al iniciar)</span></div>
        <template x-for="(w, n) in workers" :key="n">
            <div class="row g-2 mb-2 align-items-center">
                <div class="col-md-2"><select class="form-select form-select-sm" :name="'workers[' + n + '][role]'" x-model="w.role"><option value="ejecutor">Ejecutor</option><option value="vigia">Vigía</option></select></div>
                <div class="col-md-5"><select class="form-select form-select-sm" :name="'workers[' + n + '][employee]'" x-model="w.employee"><option value="">Empleado… (o externo a la derecha)</option>
                    <?php foreach ($employees as $u => $l): ?><option value="<?= e($u) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3" x-show="!w.employee"><input class="form-control form-control-sm" :name="'workers[' + n + '][external_name]'" x-model="w.external_name" placeholder="Externo: apellido y nombre"></div>
                <div class="col-md-1" x-show="!w.employee"><input class="form-control form-control-sm" :name="'workers[' + n + '][external_dni]'" x-model="w.external_dni" placeholder="DNI"></div>
                <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" @click="workers.splice(n, 1)" x-show="workers.length > 1">✕</button></div>
            </div>
        </template>
        <button type="button" class="btn btn-sm btn-outline-primary" @click="workers.push({ role: 'ejecutor' })">+ Persona</button>
        <?= $err('workers') ?>
    </div></div>

    <div class="card shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-2">4. Checklist previo</div>
        <div class="small text-body-secondary" x-show="!types.length">Elegí el tipo de trabajo para ver su checklist.</div>
        <?php foreach (WorkPermitService::TYPES as $type => $t): ?>
            <div x-show="types.includes('<?= e($type) ?>')" x-cloak class="mb-3">
                <?php if (!isset($templates[$type])): ?>
                    <div class="alert alert-warning small">No hay checklist cargado para "<?= e($t['label']) ?>". Cargalo en Inspecciones → Plantillas (precargados del rubro).</div>
                <?php else: ?>
                    <div class="fw-semibold small mb-1"><?= e($t['label']) ?> <span class="text-body-secondary fw-normal">· <?= e($templates[$type]['name']) ?> v<?= e($templates[$type]['version']['version']) ?></span></div>
                    <?php foreach ($templates[$type]['version']['structure']['sections'] as $sec): ?>
                        <div class="small text-body-secondary mt-2"><?= e($sec['title']) ?></div>
                        <ul class="list-group mb-1">
                            <?php foreach ($sec['items'] as $it): $k = $it['key']; $base = 'checklists[' . $type . '][' . $k . ']'; $v = $answers[$type][$k]['value'] ?? '';
                                $errKey = $type . ':' . $k; ?>
                                <li class="list-group-item py-2 <?= isset($errors[$errKey]) ? 'list-group-item-danger' : '' ?>" x-data="{ v: <?= e(json_encode($v)) ?> }">
                                    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-center">
                                        <span class="small"><?= $it['critical'] ? '<span class="badge text-bg-danger me-1">Crítico</span>' : '' ?><?= e($it['text']) ?></span>
                                        <?php if (in_array($it['type'], ['si_no', 'si_no_na'], true)): ?>
                                            <div class="btn-group btn-group-sm">
                                                <?php foreach (['si' => 'Sí', 'no' => 'No'] + ($it['type'] === 'si_no_na' ? ['na' => 'N/A'] : []) as $val => $lbl): $id = 'c' . substr(md5($type . $k . $val), 0, 10); ?>
                                                    <input type="radio" class="btn-check" name="<?= e($base) ?>[value]" id="<?= e($id) ?>" value="<?= e($val) ?>" x-model="v">
                                                    <label class="btn btn-outline-<?= $val === 'na' ? 'secondary' : ($val === $it['ok_when'] ? 'success' : 'danger') ?>" for="<?= e($id) ?>"><?= e($lbl) ?></label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php else: ?>
                                            <input class="form-control form-control-sm" style="max-width: 160px" name="<?= e($base) ?>[value]" value="<?= e($v) ?>">
                                        <?php endif; ?>
                                    </div>
                                    <?php $fails = in_array($it['type'], ['si_no', 'si_no_na'], true) ? "v === '" . ($it['ok_when'] === 'si' ? 'no' : 'si') . "'" : 'true'; ?>
                                    <input class="form-control form-control-sm mt-1" x-show="<?= e($fails) ?>" name="<?= e($base) ?>[comment]" value="<?= e($answers[$type][$k]['comment'] ?? '') ?>"
                                           placeholder="<?= $it['critical'] ? 'Crítico sin cumplir: así no se puede autorizar. ¿Qué pasa?' : '¿Qué pasa?' ?>">
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?= $err('checklist_' . $type) ?>
            </div>
        <?php endforeach; ?>
    </div></div>

    <div class="card shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-2">5. Firma del solicitante</div>
        <?php $sigName = 'signature'; $sigLabel = 'Declaro que la información es correcta y que se tomarán las medidas indicadas'; require __DIR__ . '/_signature.php'; ?>
        <?= $err('signature') ?>
    </div></div>
    <button class="btn btn-primary btn-lg mb-4">Solicitar permiso</button>
</form>
<script src="<?= e(asset('js/signature-pad.js')) ?>"></script>
