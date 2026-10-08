<?php
use App\Services\InspectionStructure;
use App\Services\InspectionTemplateService;
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/plantillas')) ?>">←</a>
    <h1 class="h4 m-0"><?= e($t ? $t['name'] : 'Nueva plantilla') ?></h1>
    <?php if ($t): ?><span class="small text-body-secondary">versión <?= e($t['current_version']) ?></span><?php endif; ?>
</div>
<?php if ($t && (int) $t['inspections_count'] > 0): ?>
    <div class="alert alert-info small">Esta plantilla ya se usó: si cambiás los ítems se guarda como una <strong>versión nueva</strong>. Las inspecciones hechas conservan la suya.</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/panel/inspecciones/plantillas')) ?>" style="max-width: 960px"
      x-data="templateBuilder(<?= e(json_encode($structure, JSON_UNESCAPED_UNICODE)) ?>)" @submit="$refs.structure.value = JSON.stringify({ sections })">
    <?= csrf_field() ?>
    <input type="hidden" name="uuid" value="<?= e($t['uuid'] ?? '') ?>">
    <input type="hidden" name="structure" x-ref="structure">
    <div class="card shadow-sm mb-3"><div class="card-body row g-3" x-data="{ scope: <?= e(json_encode($form['scope'])) ?> }">
        <div class="col-md-6"><label class="form-label">Nombre</label><input class="form-control" name="name" required maxlength="160" value="<?= e($form['name']) ?>"></div>
        <div class="col-md-3"><label class="form-label">Se aplica a</label>
            <select class="form-select" name="scope" x-model="scope"><?php foreach (InspectionTemplateService::SCOPES as $k => $n): ?><option value="<?= e($k) ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3" x-show="scope === 'equipo'"><label class="form-label">Tipo de equipo</label>
            <select class="form-select" name="equipment_type"><option value="">Elegí…</option>
                <?php foreach ($types as $u => $n): ?><option value="<?= e($u) ?>" <?= $form['equipment_type'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label">Instrucciones (opcional)</label><textarea class="form-control" name="description" rows="2"><?= e($form['description']) ?></textarea></div>
    </div></div>

    <template x-for="(sec, si) in sections" :key="si">
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex gap-2 align-items-center">
                <input class="form-control form-control-sm fw-semibold" x-model="sec.title" placeholder="Título de la sección">
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="moveSection(si, -1)" :disabled="si === 0" title="Subir">↑</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="moveSection(si, 1)" :disabled="si === sections.length - 1" title="Bajar">↓</button>
                <button type="button" class="btn btn-sm btn-outline-danger" @click="sections.splice(si, 1)" title="Quitar sección">✕</button>
            </div>
            <ul class="list-group list-group-flush">
                <template x-for="(it, ii) in sec.items" :key="it.key">
                    <li class="list-group-item">
                        <div class="row g-2 align-items-center">
                            <div class="col-md-5"><input class="form-control form-control-sm" x-model="it.text" placeholder="Qué se controla (ej. ¿Frenos funcionan?)"></div>
                            <div class="col-6 col-md-2"><select class="form-select form-select-sm" x-model="it.type">
                                <?php foreach (InspectionStructure::TYPES as $k => $n): ?><option value="<?= e($k) ?>"><?= e($n) ?></option><?php endforeach; ?></select></div>
                            <div class="col-6 col-md-2" x-show="it.type === 'si_no' || it.type === 'si_no_na'"><select class="form-select form-select-sm" x-model="it.ok_when">
                                <option value="si">Cumple con «Sí»</option><option value="no">Cumple con «No»</option></select></div>
                            <div class="col-6 col-md-2 d-flex gap-1" x-show="it.type === 'numero'">
                                <input class="form-control form-control-sm" x-model="it.min" placeholder="mín"><input class="form-control form-control-sm" x-model="it.max" placeholder="máx">
                                <input class="form-control form-control-sm" x-model="it.unit" placeholder="unidad"></div>
                            <div class="col-6 col-md-auto"><select class="form-select form-select-sm" x-model="it.photo" title="Foto">
                                <?php foreach (InspectionStructure::PHOTO as $k => $n): ?><option value="<?= e($k) ?>">Foto: <?= e(mb_strtolower($n)) ?></option><?php endforeach; ?></select></div>
                            <div class="col-auto form-check ms-2" x-show="it.type !== 'texto'"><input class="form-check-input" type="checkbox" x-model="it.critical" :id="'c' + it.key">
                                <label class="form-check-label small text-danger" :for="'c' + it.key">Crítico</label></div>
                            <div class="col-auto ms-auto btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-secondary" @click="moveItem(sec, ii, -1)" :disabled="ii === 0">↑</button>
                                <button type="button" class="btn btn-outline-secondary" @click="moveItem(sec, ii, 1)" :disabled="ii === sec.items.length - 1">↓</button>
                                <button type="button" class="btn btn-outline-danger" @click="sec.items.splice(ii, 1)">✕</button>
                            </div>
                            <div class="col-12"><input class="form-control form-control-sm" x-model="it.help" placeholder="Ayuda para quien inspecciona (opcional)"></div>
                        </div>
                    </li>
                </template>
                <li class="list-group-item"><button type="button" class="btn btn-sm btn-outline-primary" @click="addItem(sec)">+ Ítem</button></li>
            </ul>
        </div>
    </template>
    <div class="d-flex gap-2 mb-4">
        <button type="button" class="btn btn-outline-secondary" @click="sections.push({ title: '', items: [] }); addItem(sections[sections.length - 1])">+ Sección</button>
        <button class="btn btn-primary ms-auto">Guardar plantilla</button>
    </div>
</form>

<?php if ($versions): ?>
    <div class="card shadow-sm" style="max-width: 960px">
        <div class="card-header small fw-semibold">Versiones</div>
        <ul class="list-group list-group-flush small">
            <?php foreach ($versions as $v): ?>
                <li class="list-group-item d-flex justify-content-between"><span>Versión <?= e($v['version']) ?> · <?= e($v['item_count']) ?> ítems · <?= e(fecha($v['created_at'], 'd/m/Y')) ?> <?= e($v['created_by_name'] ? '· ' . $v['created_by_name'] : '') ?></span>
                    <span class="text-body-secondary"><?= e($v['used']) ?> inspecciones</span></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<script>
function templateBuilder(initial) {
    const blank = () => ({ key: crypto.randomUUID(), text: '', help: '', type: 'si_no_na', ok_when: 'si', min: '', max: '', unit: '', critical: false, photo: 'nunca' });
    const sections = (initial.sections || []).map((s) => ({ title: s.title, items: (s.items || []).map((i) => Object.assign(blank(), i, {
        min: i.min ?? '', max: i.max ?? '', unit: i.unit ?? '', help: i.help ?? '', ok_when: i.ok_when ?? 'si' })) }));
    if (!sections.length) sections.push({ title: 'Control', items: [] });
    sections.forEach((s) => { if (!s.items.length) s.items.push(blank()); });
    return {
        sections,
        addItem(sec) { sec.items.push(blank()); },
        moveItem(sec, i, d) { const it = sec.items.splice(i, 1)[0]; sec.items.splice(i + d, 0, it); },
        moveSection(i, d) { const s = this.sections.splice(i, 1)[0]; this.sections.splice(i + d, 0, s); },
    };
}
</script>
