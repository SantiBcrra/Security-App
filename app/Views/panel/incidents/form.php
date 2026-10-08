<?php
use App\Services\IncidentService;
$err = fn (string $k) => isset($errors[$k]) ? '<div class="text-danger small mt-1">' . e($errors[$k]) . '</div>' : '';
$people = $old['people'] ?: [['role' => 'lesionado']];
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/incidentes')) ?>">←</a>
    <h1 class="h4 m-0">Reportar incidente / accidente</h1>
</div>
<div class="alert alert-danger small" style="max-width: 860px">Si hay una persona lesionada: primero la atención (botiquín, emergencias, ART) y avisá al supervisor. Después completá este reporte.</div>
<form method="post" enctype="multipart/form-data" action="<?= e(url('/panel/incidentes')) ?>" style="max-width: 860px"
      x-data="{ type: <?= e(json_encode($old['type'])) ?>, people: <?= e(json_encode(array_values($people))) ?>, resizing: 0,
               gps() { navigator.geolocation && navigator.geolocation.getCurrentPosition(p => { this.$refs.lat.value = p.coords.latitude; this.$refs.lng.value = p.coords.longitude; }, () => {}, { timeout: 8000 }); } }"
      x-init="gps()">
    <?= csrf_field() ?>
    <input type="hidden" name="lat" x-ref="lat"><input type="hidden" name="lng" x-ref="lng">
    <div class="card shadow-sm mb-3"><div class="card-body">
        <label class="form-label fw-semibold">¿Qué pasó?</label>
        <div class="row g-2">
            <?php foreach (IncidentService::TYPES as $k => $t): ?>
                <div class="col-6 col-md-4">
                    <input type="radio" class="btn-check" name="type" id="t_<?= e($k) ?>" value="<?= e($k) ?>" x-model="type">
                    <label class="btn btn-outline-<?= e($t['class'] === 'secondary' ? 'secondary' : ($t['class'] === 'dark' ? 'dark' : $t['class'])) ?> w-100 h-100" for="t_<?= e($k) ?>"><?= e($t['label']) ?></label>
                </div>
            <?php endforeach; ?>
        </div>
        <?= $err('type') ?>
    </div></div>

    <div class="card shadow-sm mb-3"><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label">Cuándo</label><input class="form-control" type="datetime-local" name="occurred_at" value="<?= e($old['occurred_at']) ?>" required><?= $err('occurred_at') ?></div>
        <div class="col-md-8"><label class="form-label">Sector <span class="text-body-secondary small" x-show="type === 'in_itinere'">(opcional en in itinere)</span></label>
            <select class="form-select" name="sector"><option value="">Elegí…</option>
                <?php foreach ($sectors as $u => $n): ?><option value="<?= e($u) ?>" <?= $old['sector'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select><?= $err('sector') ?></div>
        <div class="col-md-6"><label class="form-label">Equipo (si hubo uno involucrado)</label>
            <select class="form-select" name="equipment"><option value="">—</option>
                <?php foreach ($equipment as $u => $n): ?><option value="<?= e($u) ?>" <?= $old['equipment'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Lugar exacto</label><input class="form-control" name="location_text" maxlength="191" value="<?= e($old['location_text']) ?>" placeholder="Ej: entrada de la nave, puesto 3"></div>
        <div class="col-12"><label class="form-label">Descripción de lo que pasó</label>
            <textarea class="form-control" name="description" rows="4" required minlength="10" placeholder="Qué tarea se hacía, qué pasó, cómo"><?= e($old['description']) ?></textarea><?= $err('description') ?></div>
        <div class="col-md-8"><label class="form-label">Qué se hizo en el momento</label>
            <textarea class="form-control" name="immediate_actions" rows="2" placeholder="Ej: primeros auxilios, se frenó la máquina, se llamó a la ART"><?= e($old['immediate_actions']) ?></textarea></div>
        <div class="col-md-4"><label class="form-label">¿Qué pudo haber pasado?</label>
            <select class="form-select" name="potential_severity"><option value="">—</option>
                <?php foreach ($severities as $s): ?><option value="<?= e($s['uuid']) ?>" <?= $old['potential_severity'] === $s['uuid'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
            <div class="form-text">Gravedad potencial.</div></div>
    </div></div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><strong>Personas</strong> <span class="small text-body-secondary">· lesionados, involucrados y testigos</span></div>
        <div class="card-body">
            <template x-for="(p, n) in people" :key="n">
                <div class="border rounded p-2 mb-2">
                    <div class="row g-2">
                        <div class="col-md-3"><select class="form-select form-select-sm" :name="'people[' + n + '][role]'" x-model="p.role">
                            <?php foreach (IncidentService::ROLES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-6"><select class="form-select form-select-sm" :name="'people[' + n + '][employee]'" x-model="p.employee"><option value="">Empleado… (o escribí abajo si es externo)</option>
                            <?php foreach ($employees as $u => $l): ?><option value="<?= e($u) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                        <div class="col-md-3 text-end"><button type="button" class="btn btn-sm btn-outline-danger" @click="people.splice(n, 1)" x-show="people.length > 1">Quitar</button></div>
                        <template x-if="!p.employee">
                            <div class="col-12 row g-2 m-0 p-0">
                                <div class="col-md-5"><input class="form-control form-control-sm" :name="'people[' + n + '][external_name]'" x-model="p.external_name" placeholder="Externo: apellido y nombre"></div>
                                <div class="col-md-3"><input class="form-control form-control-sm" :name="'people[' + n + '][external_dni]'" x-model="p.external_dni" placeholder="DNI"></div>
                                <div class="col-md-4"><input class="form-control form-control-sm" :name="'people[' + n + '][external_company]'" x-model="p.external_company" placeholder="Empresa (visitante, transporte…)"></div>
                            </div>
                        </template>
                        <div class="col-12" x-show="p.role === 'lesionado'"><input class="form-control form-control-sm" :name="'people[' + n + '][injury_description]'" x-model="p.injury_description" placeholder="Qué lesión tiene (ej. corte en la mano izquierda)"></div>
                        <div class="col-12" x-show="p.role === 'testigo'"><input class="form-control form-control-sm" :name="'people[' + n + '][statement]'" x-model="p.statement" placeholder="Qué vio (opcional)"></div>
                    </div>
                </div>
            </template>
            <button type="button" class="btn btn-sm btn-outline-primary" @click="people.push({ role: 'testigo' })">+ Persona</button>
            <?= $err('people') ?>
        </div>
    </div>

    <div class="card shadow-sm mb-3"><div class="card-body">
        <label class="form-label">Fotos del lugar o documentos (opcional)</label>
        <input class="form-control" type="file" name="files[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple data-resize @resize-start="resizing++" @resize-done="resizing--">
    </div></div>
    <button class="btn btn-danger btn-lg mb-4" :disabled="resizing > 0">Registrar</button>
</form>
<script src="<?= e(asset('js/image-resize.js')) ?>"></script>
