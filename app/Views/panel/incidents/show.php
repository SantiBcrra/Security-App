<?php
use App\Models\Incidents;
use App\Services\IncidentService;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$base = '/panel/incidentes/' . $i['uuid'];
$canEdit = UserAuth::can('incidentes', 'editar');
$transitions = array_filter(IncidentService::TRANSITIONS, fn ($t) => in_array($i['status'], $t['from'], true) && UserAuth::can('incidentes', $t['perm']));
$openPerson = $old['person'] ?? null;
$opt = function (array $options, ?string $current, string $empty = '—'): string {
    $html = '<option value="">' . e($empty) . '</option>';
    foreach ($options as $v => $l) {
        $html .= '<option value="' . e($v) . '"' . ((string) $v === (string) $current ? ' selected' : '') . '>' . e($l) . '</option>';
    }
    return $html;
};
$uuidOf = fn (?int $id) => $id ? (App\Models\CatalogItems::findById($id)['uuid'] ?? null) : null;
$eventLabels = ['created' => 'Reportado', 'status' => 'Cambio de estado', 'comment' => 'Comentario', 'correction' => 'Corrección de clasificación',
    'person' => 'Persona agregada', 'follow_up' => 'Seguimiento de la persona', 'attachment' => 'Archivos agregados',
    'investigation' => 'Investigación actualizada', 'action_created' => 'Acción derivada creada'];
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/incidentes')) ?>">←</a>
    <h1 class="h4 m-0"><?= e(Incidents::format((int) $i['number'])) ?></h1>
    <?= $typeBadge($i['type']) ?> <?= $stateBadge($i['status']) ?>
    <a class="btn btn-sm btn-outline-secondary ms-auto" target="_blank" href="<?= e(url($base . '/imprimir')) ?>">Imprimir informe</a>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between"><span>Fecha del hecho</span><strong><?= e(fecha($i['occurred_at'], 'd/m/Y H:i')) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between"><span>Sector</span><span><?= e($i['sector_name'] ?? '—') ?></span></li>
                <?php if ($i['equipment_code']): ?><li class="list-group-item d-flex justify-content-between"><span>Equipo</span><span><?= e($i['equipment_code'] . ' · ' . $i['equipment_name']) ?></span></li><?php endif; ?>
                <?php if ($i['location_text']): ?><li class="list-group-item d-flex justify-content-between"><span>Lugar</span><span><?= e($i['location_text']) ?></span></li><?php endif; ?>
                <li class="list-group-item d-flex justify-content-between"><span>Gravedad potencial</span><span><?= $i['severity_name'] ? '<span class="badge" style="background:' . e($i['severity_color'] ?: '#6c757d') . '">' . e($i['severity_name']) . '</span>' : '—' ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Reportado por</span><span><?= e($i['reporter_name'] ?? '—') ?> · <?= e(fecha($i['received_at'], 'd/m/Y H:i')) ?></span></li>
            </ul>
        </div>
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between"><strong>Reporte original</strong>
                <span class="small <?= $hashOk ? 'text-success' : 'text-danger fw-semibold' ?>" title="SHA-256 <?= e($i['original_hash']) ?>"><?= $hashOk ? '✓ íntegro' : '✗ la verificación de integridad falló' ?></span></div>
            <div class="card-body">
                <p style="white-space: pre-wrap"><?= e($original['descripcion'] ?? $i['description']) ?></p>
                <?php if (!empty($original['acciones_inmediatas'])): ?><div class="small"><strong>Qué se hizo en el momento:</strong> <?= e($original['acciones_inmediatas']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-3" id="personas">
            <div class="card-header d-flex justify-content-between"><strong>Personas</strong>
                <?php if (!$health): ?><span class="small text-body-secondary">Los datos de salud están reservados a Seguridad e Higiene.</span><?php endif; ?></div>
            <div class="list-group list-group-flush" x-data="{ open: <?= e(json_encode($openPerson)) ?> }">
                <?php foreach ($people as $p): $lost = IncidentService::lostDays($p, $today); ?>
                    <div class="list-group-item">
                        <div class="d-flex flex-wrap justify-content-between gap-2">
                            <div><strong><?= e($personName($p)) ?></strong> <span class="badge text-bg-light border"><?= e(IncidentService::ROLES[$p['role']]) ?></span>
                                <div class="small text-body-secondary">
                                    <?= $p['employee_id'] ? e(implode(' · ', array_filter(['DNI ' . $p['employee_dni'], $p['position_name'], $p['contractor_name'] ?: 'Personal propio']))) : e(implode(' · ', array_filter(['Externo', $p['external_dni'] ? 'DNI ' . $p['external_dni'] : null, $p['external_company']]))) ?>
                                </div></div>
                            <?php if ($health && $p['role'] === 'lesionado'): ?>
                                <div class="text-end small">
                                    <?php if ((int) $p['lost_time']): ?>
                                        <div class="<?= $lost['provisional'] ? 'text-danger fw-semibold' : '' ?>"><?= e($lost['days']) ?> día(s) perdidos<?= $lost['provisional'] ? ' (sigue de baja)' : '' ?></div>
                                    <?php else: ?><div class="text-body-secondary">Sin baja</div><?php endif; ?>
                                    <a target="_blank" href="<?= e(url($base . '/art/' . $p['uuid'])) ?>">Datos para la ART</a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($p['statement']): ?><div class="small mt-1"><em>Declaración:</em> <?= e($p['statement']) ?></div><?php endif; ?>
                        <?php if ($health && $p['role'] === 'lesionado'): ?>
                            <dl class="row small mb-0 mt-2">
                                <dt class="col-sm-4">Lesión</dt><dd class="col-sm-8"><?= e(implode(' · ', array_filter([$p['injury_type_name'], $p['body_part_name']])) ?: '—') ?><?= $p['injury_description'] ? '<br>' . e($p['injury_description']) : '' ?></dd>
                                <dt class="col-sm-4">Forma / agente</dt><dd class="col-sm-8"><?= e(implode(' · ', array_filter([$p['accident_form_name'], $p['injury_agent']])) ?: '—') ?></dd>
                                <dt class="col-sm-4">Atención</dt><dd class="col-sm-8"><?= e(IncidentService::ATTENTION[$p['medical_attention']] ?? '—') ?></dd>
                                <?php if ((int) $p['lost_time']): ?>
                                    <dt class="col-sm-4">Baja</dt><dd class="col-sm-8">desde <?= e(date('d/m/Y', strtotime($p['leave_start']))) ?>
                                        <?= $p['discharge_date'] ? ' · alta ' . e(date('d/m/Y', strtotime($p['discharge_date']))) : '' ?><?= $p['return_date'] ? ' · reingreso ' . e(date('d/m/Y', strtotime($p['return_date']))) : '' ?></dd>
                                <?php endif; ?>
                                <dt class="col-sm-4">Seguimiento</dt><dd class="col-sm-8"><?= e(IncidentService::FOLLOW_UP[$p['follow_up_status']] ?? '—') ?></dd>
                                <dt class="col-sm-4">N° siniestro ART</dt><dd class="col-sm-8"><?= $p['art_case_number'] ? e($p['art_case_number']) : '<span class="text-warning-emphasis">sin cargar</span>' ?></dd>
                            </dl>
                        <?php endif; ?>
                        <?php if ($canEdit && $health): $v = fn (string $k, $d) => ($openPerson === $p['uuid'] ? ($old[$k] ?? $d) : $d); ?>
                            <button type="button" class="btn btn-sm btn-link px-0" @click="open = open === '<?= e($p['uuid']) ?>' ? null : '<?= e($p['uuid']) ?>'">Editar lesión y seguimiento</button>
                            <form method="post" action="<?= e(url($base . '/personas/' . $p['uuid'])) ?>" class="border rounded p-2 small" x-show="open === '<?= e($p['uuid']) ?>'" x-cloak x-data="{ lost: <?= (int) $v('lost_time', $p['lost_time']) ? 'true' : 'false' ?> }">
                                <?= csrf_field() ?>
                                <div class="row g-2">
                                    <div class="col-md-4"><label class="form-label mb-0">Rol</label><select class="form-select form-select-sm" name="role"><?= $opt(IncidentService::ROLES, $v('role', $p['role']), 'Elegí…') ?></select></div>
                                    <div class="col-md-4"><label class="form-label mb-0">Lesión</label><select class="form-select form-select-sm" name="injury_type"><?= $opt($catalogs['lesion'], $v('injury_type', $uuidOf($p['injury_type_id'] ? (int) $p['injury_type_id'] : null))) ?></select></div>
                                    <div class="col-md-4"><label class="form-label mb-0">Parte del cuerpo</label><select class="form-select form-select-sm" name="body_part"><?= $opt($catalogs['parte_cuerpo'], $v('body_part', $uuidOf($p['body_part_id'] ? (int) $p['body_part_id'] : null))) ?></select></div>
                                    <div class="col-md-6"><label class="form-label mb-0">Forma del accidente</label><select class="form-select form-select-sm" name="accident_form"><?= $opt($catalogs['forma_accidente'], $v('accident_form', $uuidOf($p['accident_form_id'] ? (int) $p['accident_form_id'] : null))) ?></select></div>
                                    <div class="col-md-6"><label class="form-label mb-0">Agente material</label><input class="form-control form-control-sm" name="injury_agent" maxlength="191" value="<?= e($v('injury_agent', $p['injury_agent'] ?? '')) ?>" placeholder="Máquina, herramienta o sustancia"></div>
                                    <div class="col-12"><label class="form-label mb-0">Descripción de la lesión</label><input class="form-control form-control-sm" name="injury_description" value="<?= e($v('injury_description', $p['injury_description'] ?? '')) ?>"></div>
                                    <div class="col-md-6"><label class="form-label mb-0">Atención</label><select class="form-select form-select-sm" name="medical_attention"><?= $opt(IncidentService::ATTENTION, $v('medical_attention', $p['medical_attention'])) ?></select></div>
                                    <div class="col-md-6"><label class="form-label mb-0">N° de siniestro ART</label><input class="form-control form-control-sm" name="art_case_number" maxlength="40" value="<?= e($v('art_case_number', $p['art_case_number'] ?? '')) ?>"></div>
                                    <div class="col-12 form-check ms-1"><input class="form-check-input" type="checkbox" name="lost_time" value="1" id="lt_<?= e($p['uuid']) ?>" x-model="lost"><label class="form-check-label" for="lt_<?= e($p['uuid']) ?>">Con baja (días perdidos)</label></div>
                                    <div class="col-md-4" x-show="lost"><label class="form-label mb-0">Baja desde</label><input class="form-control form-control-sm" type="date" name="leave_start" value="<?= e($v('leave_start', $p['leave_start'] ?? '')) ?>"></div>
                                    <div class="col-md-4" x-show="lost"><label class="form-label mb-0">Alta médica</label><input class="form-control form-control-sm" type="date" name="discharge_date" max="<?= e($today) ?>" value="<?= e($v('discharge_date', $p['discharge_date'] ?? '')) ?>"></div>
                                    <div class="col-md-4"><label class="form-label mb-0">Reingreso</label><input class="form-control form-control-sm" type="date" name="return_date" value="<?= e($v('return_date', $p['return_date'] ?? '')) ?>"></div>
                                    <div class="col-md-6"><label class="form-label mb-0">Seguimiento</label><select class="form-select form-select-sm" name="follow_up_status"><?= $opt(IncidentService::FOLLOW_UP, $v('follow_up_status', $p['follow_up_status'])) ?></select></div>
                                    <div class="col-12"><label class="form-label mb-0">Declaración (testigos)</label><input class="form-control form-control-sm" name="statement" value="<?= e($v('statement', $p['statement'] ?? '')) ?>"></div>
                                    <div class="col-12"><button class="btn btn-primary btn-sm">Guardar</button> <span class="text-body-secondary">Cada cambio queda en la línea de tiempo.</span></div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$people): ?><div class="list-group-item small text-body-secondary">Sin personas registradas.</div><?php endif; ?>
                <?php if ($canEdit): ?>
                    <div class="list-group-item">
                        <button type="button" class="btn btn-sm btn-outline-primary" @click="open = open === 'nueva' ? null : 'nueva'">+ Agregar persona</button>
                        <form method="post" action="<?= e(url($base . '/personas')) ?>" class="row g-2 mt-1 small" x-show="open === 'nueva'" x-cloak>
                            <?= csrf_field() ?>
                            <div class="col-md-3"><select class="form-select form-select-sm" name="role"><?= $opt(IncidentService::ROLES, 'testigo', 'Elegí…') ?></select></div>
                            <div class="col-md-5"><select class="form-select form-select-sm" name="employee"><?= $opt($employees, null, 'Empleado… (o externo abajo)') ?></select></div>
                            <div class="col-md-4"><input class="form-control form-control-sm" name="external_name" placeholder="Externo: apellido y nombre"></div>
                            <div class="col-md-9"><input class="form-control form-control-sm" name="statement" placeholder="Declaración (testigo, opcional)"></div>
                            <div class="col-md-3"><button class="btn btn-primary btn-sm w-100">Agregar</button></div>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php require __DIR__ . '/_investigation.php'; ?>

        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Fotos y documentos (<?= count($files) ?>)</strong></div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <?php foreach ($files as $f): $src = url($base . '/archivos/' . $f['uuid']); ?>
                        <?php if (str_starts_with($f['mime'], 'image/')): ?>
                            <a href="<?= e($src) ?>" target="_blank" title="SHA-256 <?= e($f['sha256']) ?>"><img src="<?= e($src . '?t=1') ?>" class="rounded border" style="height: 90px" alt="Foto" loading="lazy"></a>
                        <?php else: ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e($src) ?>" target="_blank">📄 <?= e($f['original_name'] ?? 'PDF') ?><?= (int) $f['health'] ? ' · médico' : '' ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (!$files): ?><span class="small text-body-secondary">Sin archivos.</span><?php endif; ?>
                </div>
                <?php if (UserAuth::can('incidentes', 'crear')): ?>
                    <form method="post" enctype="multipart/form-data" action="<?= e(url($base . '/archivos')) ?>" class="d-flex flex-wrap gap-2 align-items-center" x-data="{ resizing: 0 }">
                        <?= csrf_field() ?>
                        <input class="form-control form-control-sm" style="max-width: 320px" type="file" name="files[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple required data-resize @resize-start="resizing++" @resize-done="resizing--">
                        <?php if ($health): ?><div class="form-check small"><input class="form-check-input" type="checkbox" name="health" value="1" id="fhealth"><label class="form-check-label" for="fhealth">Documento médico (reservado)</label></div><?php endif; ?>
                        <button class="btn btn-outline-primary btn-sm" :disabled="resizing > 0">Agregar</button>
                    </form>
                    <script src="<?= e(asset('js/image-resize.js')) ?>"></script>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($transitions || $canEdit): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header"><strong>Gestión</strong></div>
                <div class="card-body d-grid gap-2" x-data="{ open: null }">
                    <?php if (IncidentService::TYPES[$i['type']]['investigation'] && !in_array($i['status'], ['investigado', 'cerrado', 'anulado'], true)): ?>
                        <div class="alert alert-info small mb-0">En un <?= e(mb_strtolower(IncidentService::typeLabel($i['type']))) ?> hay que <a href="#investigacion">terminar la investigación</a> para poder cerrar.</div>
                    <?php endif; ?>
                    <?php $openDerived = count(array_filter($derived, fn ($a) => !in_array($a['status'], ['verificada', 'cancelada'], true))); ?>
                    <?php if ($i['status'] === 'investigado' && $openDerived): ?>
                        <div class="alert alert-warning small mb-0"><?= e($openDerived) ?> acción(es) derivada(s) todavía sin verificar. Se puede cerrar igual; las acciones siguen su curso.</div>
                    <?php endif; ?>
                    <?php foreach ($transitions as $key => $t): ?>
                        <button type="button" class="btn btn-sm <?= $key === 'cerrar' ? 'btn-success' : 'btn-outline-secondary' ?>" @click="open = open === '<?= e($key) ?>' ? null : '<?= e($key) ?>'"><?= e($t['label']) ?></button>
                        <form method="post" action="<?= e(url($base . '/accion/' . $key)) ?>" class="border rounded p-2" x-show="open === '<?= e($key) ?>'" x-cloak>
                            <?= csrf_field() ?>
                            <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" required minlength="5" placeholder="Motivo / conclusión"></textarea>
                            <button class="btn btn-primary btn-sm">Confirmar: <?= e(mb_strtolower($t['label'])) ?></button>
                        </form>
                    <?php endforeach; ?>
                    <?php if ($canEdit && !in_array($i['status'], ['cerrado', 'anulado'], true)): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="open = open === 'corregir' ? null : 'corregir'">Corregir clasificación</button>
                        <form method="post" action="<?= e(url($base . '/correccion')) ?>" class="border rounded p-2 small" x-show="open === 'corregir'" x-cloak>
                            <?= csrf_field() ?>
                            <div class="text-body-secondary mb-2">El reporte original queda intacto; la corrección se registra.</div>
                            <label class="form-label mb-0">Tipo</label><select class="form-select form-select-sm mb-2" name="type"><?= $opt(array_map(fn ($t) => $t['label'], IncidentService::TYPES), $i['type'], 'Elegí…') ?></select>
                            <label class="form-label mb-0">Sector</label><select class="form-select form-select-sm mb-2" name="sector"><?= $opt($sectors, $i['sector_id'] ? (App\Models\Sectors::findById((int) $i['sector_id'])['uuid'] ?? null) : null) ?></select>
                            <label class="form-label mb-0">Equipo</label><select class="form-select form-select-sm mb-2" name="equipment"><?= $opt($equipment, $i['equipment_id'] ? (App\Models\Equipment::findById((int) $i['equipment_id'])['uuid'] ?? null) : null) ?></select>
                            <label class="form-label mb-0">Gravedad potencial</label><select class="form-select form-select-sm mb-2" name="potential_severity"><?= $opt(array_column($severities, 'name', 'uuid'), $uuidOf($i['potential_severity_id'] ? (int) $i['potential_severity_id'] : null)) ?></select>
                            <label class="form-label mb-0">Fecha y hora del hecho</label><input class="form-control form-control-sm mb-2" type="datetime-local" name="occurred_at" value="<?= e(fecha($i['occurred_at'], 'Y-m-d\TH:i')) ?>">
                            <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" required minlength="5" placeholder="Motivo de la corrección"></textarea>
                            <button class="btn btn-primary btn-sm">Guardar corrección</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm" id="linea-de-tiempo">
            <div class="card-header"><strong>Línea de tiempo</strong></div>
            <ul class="list-group list-group-flush small">
                <?php foreach (array_reverse($events) as $ev): $data = $ev['data'] ? json_decode($ev['data'], true) : null; ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between"><strong><?= e($eventLabels[$ev['type']] ?? $ev['type']) ?>
                            <?= $ev['to_status'] && $ev['type'] === 'status' ? $stateBadge($ev['to_status']) : '' ?></strong>
                            <span class="text-body-secondary text-nowrap"><?= e(fecha($ev['created_at'], 'd/m/Y H:i')) ?></span></div>
                        <div class="text-body-secondary"><?= e($ev['actor_name'] ?? '') ?></div>
                        <?php if ($ev['comment']): ?><div style="white-space: pre-wrap"><?= e($ev['comment']) ?></div><?php endif; ?>
                        <?php if ($data && isset($data['despues'])): ?>
                            <?php if (!empty($data['persona'])): ?><div><?= e($data['persona']) ?></div><?php endif; ?>
                            <?php foreach ($data['despues'] as $label => $value): ?>
                                <div><?= e($label) ?>: <s class="text-body-secondary"><?= e($data['antes'][$label] ?? '—') ?></s> → <strong><?= e($value ?? '—') ?></strong></div>
                            <?php endforeach; ?>
                        <?php elseif ($data && isset($data['persona'])): ?><div><?= e($data['persona'] . ' (' . $data['rol'] . ')') ?></div>
                        <?php elseif ($data && isset($data['archivos'])): ?><div><?= e($data['archivos']) ?> archivo(s)</div>
                        <?php elseif ($data && isset($data['accion'])): ?><div><?= e($data['accion'] . ' · ' . $data['titulo']) ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form class="card-body border-top d-flex gap-2" method="post" action="<?= e(url($base . '/comentario')) ?>">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm" name="comment" placeholder="Agregar un comentario…" maxlength="5000" required>
                <button class="btn btn-outline-primary btn-sm">Comentar</button>
            </form>
        </div>
    </div>
</div>
