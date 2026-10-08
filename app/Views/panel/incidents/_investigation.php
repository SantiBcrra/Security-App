<?php
/** Investigación del incidente (incluida desde show.php). */
use App\Models\Actions;
use App\Services\ActionWorkflow;
use App\Services\IncidentInvestigation;
use App\Services\IncidentService;
use App\Services\UserAuth;

$required = IncidentService::TYPES[$i['type']]['investigation'];
$editable = $investigation && $i['status'] === 'en_investigacion' && IncidentInvestigation::canEdit($i);
$teamUuids = $investigation ? array_values(array_filter(array_map(fn ($id) => App\Models\Users::findById((int) $id)['uuid'] ?? null, $investigation['team']))) : [];
$causeUuids = $investigation ? array_values(array_filter(array_map(fn ($id) => App\Models\CatalogItems::findById((int) $id)['uuid'] ?? null, $investigation['root_causes']))) : [];
$state = [
    'problem' => $investigation['five_whys']['problem'] ?? '',
    'whys'    => $investigation ? ($investigation['five_whys']['whys'] ?: ['']) : [''],
    'nodes'   => $investigation['cause_tree'] ?? [],
];
$typeClass = ['hecho' => 'danger', 'causa_inmediata' => 'warning', 'causa_basica' => 'primary'];
?>
<div class="card shadow-sm mb-3" id="investigacion">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Investigación</strong>
        <span class="small text-body-secondary">
            <?php if ($investigation): ?>Iniciada <?= e(fecha($investigation['started_at'], 'd/m/Y')) ?> por <?= e($investigation['started_by_name'] ?? '—') ?>
                <?= $investigation['completed_at'] ? ' · terminada ' . e(fecha($investigation['completed_at'], 'd/m/Y')) . ' por ' . e($investigation['completed_by_name'] ?? '—') : '' ?>
            <?php else: ?><?= $required ? 'Obligatoria para cerrar' : 'Opcional' ?><?php endif; ?>
        </span>
    </div>
    <div class="card-body">
        <?php if (!$investigation): ?>
            <?php if ($i['status'] === 'reportado' && IncidentInvestigation::canEdit($i)): ?>
                <form method="post" action="<?= e(url($base . '/investigacion/empezar')) ?>"><?= csrf_field() ?>
                    <button class="btn btn-primary btn-sm">Empezar la investigación</button>
                    <span class="small text-body-secondary ms-2">5 porqués, árbol de causas, causas raíz y acciones.</span></form>
            <?php else: ?><p class="small text-body-secondary mb-0">Sin investigación.</p><?php endif; ?>
        <?php elseif ($editable): ?>
            <form method="post" x-data="investigationEditor(<?= e(json_encode($state, JSON_UNESCAPED_UNICODE)) ?>)" @submit="$refs.tree.value = JSON.stringify(nodes)">
                <?= csrf_field() ?>
                <input type="hidden" name="cause_tree" x-ref="tree">
                <div class="mb-3"><label class="form-label fw-semibold small">Equipo investigador</label>
                    <div class="d-flex flex-wrap gap-3 small">
                        <?php foreach ($users as $u): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="team[]" value="<?= e($u['uuid']) ?>" <?= in_array($u['uuid'], $teamUuids, true) ? 'checked' : '' ?>> <?= e($u['name']) ?></label><?php endforeach; ?>
                    </div></div>

                <div class="mb-3"><label class="form-label fw-semibold small">5 porqués</label>
                    <input class="form-control form-control-sm mb-2" name="problem" x-model="problem" placeholder="Problema: ¿qué pasó? (ej. el operario se cortó la mano)">
                    <template x-for="(w, n) in whys" :key="n">
                        <div class="input-group input-group-sm mb-1">
                            <span class="input-group-text" x-text="'¿Por qué? ' + (n + 1)"></span>
                            <input class="form-control" name="whys[]" x-model="whys[n]" :placeholder="n === 0 ? 'Porque…' : 'Y eso porque…'">
                            <button type="button" class="btn btn-outline-secondary" @click="whys.splice(n, 1)" x-show="whys.length > 1">✕</button>
                        </div>
                    </template>
                    <button type="button" class="btn btn-sm btn-link px-0" @click="whys.push('')" x-show="whys.length < <?= IncidentInvestigation::MAX_WHYS ?>">+ otro porqué</button>
                </div>

                <div class="mb-3"><label class="form-label fw-semibold small">Árbol de causas</label>
                    <div class="small text-body-secondary mb-2">Arrancá por el hecho (lo que pasó) y agregá debajo sus causas: inmediatas (actos o condiciones) y básicas (factores personales o del trabajo).</div>
                    <template x-for="n in ordered" :key="n.id">
                        <div class="d-flex gap-1 align-items-center mb-1" :style="'padding-left:' + (n.depth * 22) + 'px'">
                            <span class="text-body-secondary" x-show="n.depth > 0">↳</span>
                            <select class="form-select form-select-sm" style="max-width: 150px" x-model="byId(n.id).type">
                                <?php foreach (IncidentInvestigation::NODE_TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
                            </select>
                            <input class="form-control form-control-sm" x-model="byId(n.id).text" maxlength="300">
                            <button type="button" class="btn btn-sm btn-outline-primary text-nowrap" @click="add(n.id)" title="Agregar una causa debajo">+ causa</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="remove(n.id)" title="Quitar (con lo que tiene debajo)">✕</button>
                        </div>
                    </template>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="add(null)">+ Hecho</button>
                </div>

                <div class="mb-3"><label class="form-label fw-semibold small">Causas raíz</label>
                    <div class="d-flex flex-wrap gap-3 small">
                        <?php foreach ($causes as $u => $n): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="root_causes[]" value="<?= e($u) ?>" <?= in_array($u, $causeUuids, true) ? 'checked' : '' ?>> <?= e($n) ?></label><?php endforeach; ?>
                        <?php if (!$causes): ?><span class="text-body-secondary">Cargá causas en Datos maestros → Catálogos → Causas.</span><?php endif; ?>
                    </div></div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6"><label class="form-label fw-semibold small">Conclusiones</label><textarea class="form-control form-control-sm" name="conclusions" rows="3"><?= e($investigation['conclusions'] ?? '') ?></textarea></div>
                    <div class="col-md-6"><label class="form-label fw-semibold small">Lecciones aprendidas</label><textarea class="form-control form-control-sm" name="lessons" rows="3"><?= e($investigation['lessons'] ?? '') ?></textarea></div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-outline-primary btn-sm" formaction="<?= e(url($base . '/investigacion/guardar')) ?>">Guardar</button>
                    <button class="btn btn-success btn-sm" formaction="<?= e(url($base . '/investigacion/terminar')) ?>">Guardar y terminar la investigación</button>
                </div>
            </form>
        <?php else: ?>
            <?php if ($investigation['team']): ?><p class="small mb-2"><strong>Equipo:</strong> <?= e(implode(', ', array_filter(array_map(fn ($id) => App\Models\Users::findById((int) $id)['name'] ?? null, $investigation['team'])))) ?></p><?php endif; ?>
            <?php if (array_filter($investigation['five_whys']['whys'])): ?>
                <div class="small mb-2"><strong>5 porqués</strong><?= $investigation['five_whys']['problem'] ? ' — ' . e($investigation['five_whys']['problem']) : '' ?>
                    <ol class="mb-0"><?php foreach ($investigation['five_whys']['whys'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ol></div>
            <?php endif; ?>
            <?php if ($tree): ?>
                <div class="small mb-2"><strong>Árbol de causas</strong>
                    <?php foreach ($tree as $n): ?><div style="padding-left: <?= (int) $n['depth'] * 22 ?>px"><?= $n['depth'] ? '↳ ' : '' ?><span class="badge text-bg-<?= e($typeClass[$n['type']]) ?>"><?= e(IncidentInvestigation::NODE_TYPES[$n['type']]) ?></span> <?= e($n['text']) ?></div><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($causeUuids): ?><p class="small mb-2"><strong>Causas raíz:</strong> <?= e(implode(', ', array_map(fn ($u) => $causes[$u] ?? '?', $causeUuids))) ?></p><?php endif; ?>
            <?php if ($investigation['conclusions']): ?><p class="small mb-2" style="white-space: pre-wrap"><strong>Conclusiones:</strong> <?= e($investigation['conclusions']) ?></p><?php endif; ?>
            <?php if ($investigation['lessons']): ?><p class="small mb-2" style="white-space: pre-wrap"><strong>Lecciones aprendidas:</strong> <?= e($investigation['lessons']) ?></p><?php endif; ?>
            <?php if ($i['status'] === 'investigado' && IncidentInvestigation::canEdit($i)): ?>
                <form method="post" action="<?= e(url($base . '/investigacion/reabrir')) ?>" class="d-flex gap-2 mt-2" x-data="{ o: false }">
                    <?= csrf_field() ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="o = !o">Reabrir la investigación</button>
                    <input class="form-control form-control-sm" name="comment" minlength="5" placeholder="Motivo" x-show="o" x-cloak required>
                    <button class="btn btn-sm btn-primary" x-show="o" x-cloak>Confirmar</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($investigation): ?>
            <hr>
            <div class="d-flex justify-content-between align-items-center mb-2"><strong class="small">Acciones derivadas (<?= count($derived) ?>)</strong>
                <?php $pending = count(array_filter($derived, fn ($a) => !in_array($a['status'], ['verificada', 'cancelada'], true))); ?>
                <?php if ($pending): ?><span class="small text-warning-emphasis"><?= e($pending) ?> sin verificar</span><?php endif; ?></div>
            <div class="list-group list-group-flush small mb-2">
                <?php foreach ($derived as $a): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between gap-2 px-0" href="<?= e(url('/panel/acciones/' . $a['uuid'])) ?>">
                        <span><span class="text-body-secondary"><?= e(Actions::format((int) $a['number'])) ?></span> <?= e($a['title']) ?> · <?= e($a['responsible_name']) ?></span>
                        <span class="badge text-bg-<?= e(ActionWorkflow::STATES[$a['status']]['class']) ?>"><?= e(ActionWorkflow::label($a['status'])) ?></span></a>
                <?php endforeach; ?>
            </div>
            <?php if (in_array($i['status'], ['en_investigacion', 'investigado'], true) && IncidentInvestigation::canEdit($i) && UserAuth::can('acciones', 'crear')): ?>
                <form method="post" action="<?= e(url($base . '/acciones')) ?>" class="row g-2 small" x-data="{ o: false }">
                    <?= csrf_field() ?>
                    <div class="col-12"><button type="button" class="btn btn-sm btn-outline-primary" @click="o = !o">+ Acción correctiva / preventiva</button></div>
                    <div class="col-md-12" x-show="o" x-cloak><input class="form-control form-control-sm" name="title" maxlength="191" placeholder="Qué hay que hacer" required></div>
                    <div class="col-md-4" x-show="o" x-cloak><select class="form-select form-select-sm" name="responsible" required><option value="">Responsable…</option>
                        <?php foreach ($users as $u): ?><option value="<?= e($u['uuid']) ?>"><?= e($u['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3" x-show="o" x-cloak><input class="form-control form-control-sm" type="date" name="due_on" min="<?= e($today) ?>" required></div>
                    <div class="col-md-2" x-show="o" x-cloak><select class="form-select form-select-sm" name="priority"><?php foreach (ActionWorkflow::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === 'alta' ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-3" x-show="o" x-cloak><select class="form-select form-select-sm" name="type"><?php foreach (ActionWorkflow::TYPES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <?php if ($tree): ?><div class="col-12" x-show="o" x-cloak><select class="form-select form-select-sm" name="cause"><option value="">Causa que ataca (opcional)</option>
                        <?php foreach ($tree as $n): if ($n['type'] === 'hecho') continue; ?><option value="<?= e($n['text']) ?>"><?= e($n['text']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
                    <div class="col-12" x-show="o" x-cloak><button class="btn btn-primary btn-sm">Crear acción</button></div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<script>
function investigationEditor(s) {
    return {
        problem: s.problem, whys: s.whys, nodes: s.nodes,
        byId(id) { return this.nodes.find((n) => n.id === id); },
        /** Árbol en orden (padre y debajo sus hijos), con profundidad para la sangría. */
        get ordered() {
            const out = [];
            const walk = (parent, depth) => this.nodes.filter((n) => (n.parent || null) === parent).forEach((n) => { out.push({ id: n.id, depth }); walk(n.id, depth + 1); });
            walk(null, 0);
            return out;
        },
        add(parent) {
            if (this.nodes.length >= <?= IncidentInvestigation::MAX_NODES ?>) return;
            this.nodes.push({ id: crypto.randomUUID().slice(0, 8), parent, text: '', type: parent ? 'causa_inmediata' : 'hecho' });
        },
        remove(id) {
            const drop = new Set([id]);
            let grew = true;
            while (grew) { grew = false; this.nodes.forEach((n) => { if (n.parent && drop.has(n.parent) && !drop.has(n.id)) { drop.add(n.id); grew = true; } }); }
            this.nodes = this.nodes.filter((n) => !drop.has(n.id));
        },
    };
}
</script>
