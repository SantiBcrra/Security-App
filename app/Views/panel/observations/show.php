<?php
use App\Models\Observations;
use App\Services\ObservationWorkflow;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$base = '/panel/observaciones/' . $obs['uuid'];
$canEdit = UserAuth::can('observaciones', 'editar');
$canAdd = UserAuth::can('observaciones', 'crear');
$eventLabels = ['created' => 'Reporte creado', 'status' => 'Cambio de estado', 'comment' => 'Comentario', 'correction' => 'Corrección de clasificación',
    'assignment' => 'Acción asignada', 'attachment' => 'Fotos agregadas', 'imminent_alert' => 'Alerta de riesgo inminente',
    'escalation' => 'Alerta escalada', 'alert_ack' => 'Alerta confirmada (Recibido)'];
$eventIcons = ['created' => '●', 'status' => '↻', 'comment' => '✎', 'correction' => '✱', 'assignment' => '➜', 'attachment' => '▣', 'imminent_alert' => '⚠', 'escalation' => '⇈', 'alert_ack' => '✓'];
$openAction = $old['action'] ?? null;
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/observaciones')) ?>">←</a>
    <h1 class="h4 m-0"><?= e(Observations::format((int) $obs['number'])) ?></h1>
    <?= $statusBadge($obs['status']) ?>
    <?= $severityBadge($obs['severity_name'], $obs['severity_color']) ?>
    <?php if ($obs['imminent_risk']): ?><?= $imminentBadge ?><?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary ms-auto" target="_blank" href="<?= e(url($base . '/imprimir')) ?>">Imprimir / PDF</a>
</div>

<?php if ($obs['imminent_risk'] && ObservationWorkflow::isOpen($obs['status'])): $alert = App\Models\Alerts::latestForObservation((int) $obs['id']); ?>
    <div class="alert alert-danger d-flex flex-wrap justify-content-between align-items-center gap-2">
        <span class="fw-semibold">Riesgo inminente sin cerrar: verificá en el lugar que la tarea esté frenada y el riesgo controlado.
            <?php if ($alert && $alert['acked_at']): ?><br><span class="fw-normal">✓ Alerta confirmada por <?= e($alert['acked_name']) ?> (<?= e(fecha($alert['acked_at'], 'd/m H:i')) ?>).</span>
            <?php elseif ($alert): ?><br><span class="fw-normal">Alerta SIN CONFIRMAR<?= (int) $alert['level'] > 0 ? ' — escalada a nivel ' . e($alert['level']) : '' ?>.</span><?php endif; ?></span>
        <?php if ($alert && !$alert['acked_at'] && UserAuth::user()): ?>
            <form method="post" action="<?= e(url('/panel/alertas/' . $alert['uuid'] . '/recibido')) ?>"><?= csrf_field() ?><button class="btn btn-danger">Recibido</button></form>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between"><strong>Clasificación vigente</strong>
                <span class="small text-body-secondary"><?= e($obs['site_name']) ?></span></div>
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between"><span>Categoría</span><strong><?= e($obs['category_name']) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between"><span>Tipo de riesgo</span><span><?= e($obs['risk_name'] ?? '—') ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Sector</span><span><?= e($obs['sector_name']) ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Equipo</span><span><?= $obs['equipment_code'] ? e($obs['equipment_code'] . ' · ' . $obs['equipment_name']) : '—' ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Fecha del hecho</span><span><?= e(fecha($obs['created_at_device'], 'd/m/Y H:i')) ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Reportado por</span><span><?= $obs['is_anonymous'] ? '<em>Anónimo</em>' : e($obs['reporter_name'] ?? '—') ?></span></li>
            </ul>
        </div>

        <?php if ($obsActions): require __DIR__ . '/../actions/_badges.php'; ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between"><strong>Acciones (<?= count($obsActions) ?>)</strong>
                    <span class="small text-body-secondary">La observación se cierra sola cuando todas quedan verificadas</span></div>
                <div class="list-group list-group-flush small">
                    <?php foreach ($obsActions as $act): ?>
                        <a class="list-group-item list-group-item-action" href="<?= e(url('/panel/acciones/' . $act['uuid'])) ?>">
                            <div class="d-flex justify-content-between gap-2">
                                <span><span class="text-body-secondary"><?= e(App\Models\Actions::format((int) $act['number'])) ?></span> <strong><?= e($act['title']) ?></strong></span>
                                <span class="text-nowrap"><?= $actionBadge($act['status']) ?></span>
                            </div>
                            <div class="d-flex flex-wrap gap-3 mt-1"><span>👤 <?= e($act['responsible_name']) ?></span><?= $dueLabel($act, $today) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><strong>Reporte original</strong>
                <span class="small <?= $hashOk ? 'text-success' : 'text-danger fw-semibold' ?>" title="SHA-256 <?= e($obs['original_hash']) ?>">
                    <?= $hashOk ? '✓ íntegro (no se modificó)' : '✗ la verificación de integridad falló' ?></span></div>
            <div class="card-body">
                <p class="mb-2" style="white-space: pre-wrap"><?= e($original['descripcion'] ?? $obs['description']) ?></p>
                <div class="small text-body-secondary">
                    <?= e(implode(' · ', array_filter([$original['categoria'] ?? null, $original['severidad'] ?? null, $original['sector'] ?? null, $original['equipo'] ?? null]))) ?>
                    <?php if (!empty($original['lugar'])): ?><br>Lugar: <?= e($original['lugar']) ?><?php endif; ?>
                    <?php if (!empty($original['gps'])): ?><br>GPS: <?= e($original['gps']['lat'] . ', ' . $original['gps']['lng']) ?><?= !empty($original['gps']['precision_m']) ? ' (±' . e($original['gps']['precision_m']) . ' m)' : '' ?><?php endif; ?>
                    <br>Recibido: <?= e(fecha($obs['received_at'], 'd/m/Y H:i:s')) ?>
                </div>
                <?php if ($people): ?>
                    <div class="small mt-2">Involucrados: <?= e(implode(', ', array_column($people, 'name'))) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($obs['lat'] !== null): ?>
                <link rel="stylesheet" href="<?= e(asset('vendor/leaflet/leaflet.css')) ?>">
                <script src="<?= e(asset('vendor/leaflet/leaflet.js')) ?>"></script>
                <div id="obs-map" style="height: 220px"></div>
                <script>
                (function () {
                    var p = [<?= (float) $obs['lat'] ?>, <?= (float) $obs['lng'] ?>];
                    var map = L.map('obs-map', { scrollWheelZoom: false }).setView(p, 17);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
                    L.circleMarker(p, { radius: 9, color: '#fff', weight: 2, fillColor: '<?= e($obs['severity_color'] ?: '#dc3545') ?>', fillOpacity: .9 }).addTo(map);
                    <?php if ($obs['gps_accuracy_m']): ?>L.circle(p, { radius: <?= (int) $obs['gps_accuracy_m'] ?>, weight: 1, fillOpacity: .08 }).addTo(map);<?php endif; ?>
                })();
                </script>
            <?php endif; ?>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center"><strong>Fotos (<?= count($photos) ?>)</strong></div>
            <div class="card-body">
                <?php if (!$photos): ?><p class="small text-body-secondary mb-2">Sin fotos.</p><?php endif; ?>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <?php foreach ($photos as $ph): $src = url($base . '/fotos/' . $ph['uuid']); ?>
                        <a href="<?= e($src) ?>" target="_blank" title="<?= e(($ph['original_name'] ?? '') . ' · SHA-256 ' . $ph['sha256']) ?>">
                            <img src="<?= e($src . '?t=1') ?>" alt="Foto" class="rounded border" style="height: 110px; width: auto; object-fit: cover" loading="lazy">
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php if ($canAdd): ?>
                    <form method="post" enctype="multipart/form-data" action="<?= e(url($base . '/fotos')) ?>" class="d-flex gap-2" x-data="{ resizing: false }">
                        <?= csrf_field() ?>
                        <input class="form-control form-control-sm" type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple data-resize
                               @resize-start="resizing = true" @resize-done="resizing = false" required>
                        <button class="btn btn-outline-primary btn-sm text-nowrap" :disabled="resizing">Agregar fotos</button>
                    </form>
                    <script src="<?= e(asset('js/image-resize.js')) ?>"></script>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if ($actions): ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header"><strong>Gestión</strong></div>
                <div class="card-body d-grid gap-2" x-data="{ open: <?= json_encode($openAction) ?> }">
                    <?php foreach ($actions as $key => $t): ?>
                        <button type="button" class="btn btn-sm <?= in_array($key, ['cerrar'], true) ? 'btn-success' : ($key === 'descartar' ? 'btn-outline-dark' : 'btn-outline-primary') ?>"
                                @click="open = open === '<?= e($key) ?>' ? null : '<?= e($key) ?>'"><?= e($key === 'asignar' && $obsActions ? 'Asignar otra acción' : $t['label']) ?></button>
                        <form method="post" action="<?= e(url($base . '/accion/' . $key)) ?>" class="border rounded p-2" x-show="open === '<?= e($key) ?>'" x-cloak>
                            <?= csrf_field() ?>
                            <?php if ($key === 'asignar'): ?>
                                <label class="form-label small mb-0">Responsable</label>
                                <select class="form-select form-select-sm mb-2" name="assigned_user" required>
                                    <option value="">Elegí…</option>
                                    <?php foreach ($users as $u): ?>
                                        <option value="<?= e($u['uuid']) ?>" <?= ($old['assigned_user'] ?? '') === $u['uuid'] ? 'selected' : '' ?>><?= e($u['name'] . ' · ' . $u['role_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="form-label small mb-0">Acción a realizar</label>
                                <input class="form-control form-control-sm mb-2" name="action_text" maxlength="191" required value="<?= e($old['action_text'] ?? '') ?>">
                                <label class="form-label small mb-0">Detalle (opcional)</label>
                                <textarea class="form-control form-control-sm mb-2" name="action_detail" rows="2"><?= e($old['action_detail'] ?? '') ?></textarea>
                                <div class="row g-2 mb-2">
                                    <div class="col-6"><label class="form-label small mb-0">Fecha límite</label>
                                        <input class="form-control form-control-sm" type="date" name="action_due_on" min="<?= e($today) ?>" value="<?= e($old['action_due_on'] ?? '') ?>" required></div>
                                    <div class="col-6"><label class="form-label small mb-0">Prioridad</label>
                                        <select class="form-select form-select-sm" name="priority"><?php foreach (App\Services\ActionWorkflow::PRIORITIES as $pk => $pn): ?><option value="<?= e($pk) ?>" <?= ($old['priority'] ?? 'media') === $pk ? 'selected' : '' ?>><?= e($pn) ?></option><?php endforeach; ?></select></div>
                                </div>
                                <label class="form-label small mb-0">Tipo</label>
                                <select class="form-select form-select-sm mb-2" name="action_type"><?php foreach (App\Services\ActionWorkflow::TYPES as $tk => $tn): ?><option value="<?= e($tk) ?>" <?= ($old['action_type'] ?? 'correctiva') === $tk ? 'selected' : '' ?>><?= e($tn) ?></option><?php endforeach; ?></select>
                                <div class="small text-body-secondary mb-2">Se crea una acción con su propio seguimiento. Podés asignar varias.</div>
                            <?php endif; ?>
                            <label class="form-label small mb-0"><?= $t['comment'] ? 'Comentario / motivo (obligatorio)' : 'Comentario (opcional)' ?></label>
                            <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" <?= $t['comment'] ? 'required minlength="5"' : '' ?>><?= e(($old['action'] ?? '') === $key ? ($old['comment'] ?? '') : '') ?></textarea>
                            <button class="btn btn-primary btn-sm">Confirmar: <?= e(mb_strtolower($t['label'])) ?></button>
                        </form>
                    <?php endforeach; ?>

                    <?php if ($canEdit): ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="open = open === 'corregir' ? null : 'corregir'">Corregir clasificación</button>
                        <form method="post" action="<?= e(url($base . '/correccion')) ?>" class="border rounded p-2" x-show="open === 'corregir'" x-cloak>
                            <?= csrf_field() ?>
                            <div class="small text-body-secondary mb-2">El reporte original queda intacto; la corrección se registra en la línea de tiempo.</div>
                            <?php
                            $sel = function (string $name, string $label, array $opts, ?string $current, bool $required) {
                                echo '<label class="form-label small mb-0">' . e($label) . '</label><select class="form-select form-select-sm mb-2" name="' . e($name) . '">';
                                if (!$required) { echo '<option value="">—</option>'; }
                                foreach ($opts as $v => $l) { echo '<option value="' . e($v) . '"' . ($v === $current ? ' selected' : '') . '>' . e($l) . '</option>'; }
                                echo '</select>';
                            };
                            $uuidOf = function (?string $table, $id) {
                                if (!$id) { return null; }
                                return match ($table) {
                                    'sector' => App\Models\Sectors::findById((int) $id)['uuid'] ?? null,
                                    'equipment' => App\Models\Equipment::findById((int) $id)['uuid'] ?? null,
                                    default => App\Models\CatalogItems::findById((int) $id)['uuid'] ?? null,
                                };
                            };
                            $sel('category', 'Categoría', $options['categories'], $uuidOf(null, $obs['category_id']), true);
                            $sel('severity', 'Severidad', array_column($options['severities'], 'name', 'uuid'), $uuidOf(null, $obs['severity_id']), true);
                            $sel('risk_type', 'Tipo de riesgo', $options['risks'], $uuidOf(null, $obs['risk_type_id']), false);
                            $sel('sector', 'Sector', $options['sectors'], $uuidOf('sector', $obs['sector_id']), true);
                            $sel('equipment', 'Equipo', $options['equipment'], $uuidOf('equipment', $obs['equipment_id']), false);
                            ?>
                            <label class="form-label small mb-0">Motivo de la corrección</label>
                            <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" required minlength="5"></textarea>
                            <button class="btn btn-primary btn-sm">Guardar corrección</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($canEdit): ?>
            <div class="card shadow-sm mb-3"><div class="card-body small text-body-secondary">
                <?= $obs['status'] === 'cerrada' ? 'Observación cerrada.' : 'Sin acciones disponibles para tu rol en este estado.' ?>
            </div></div>
        <?php endif; ?>

        <div class="card shadow-sm" id="linea-de-tiempo">
            <div class="card-header"><strong>Línea de tiempo</strong></div>
            <ul class="list-group list-group-flush small">
                <?php foreach (array_reverse($events) as $ev): $data = $ev['data'] ? json_decode($ev['data'], true) : null; ?>
                    <li class="list-group-item <?= in_array($ev['type'], ['imminent_alert', 'escalation'], true) ? 'list-group-item-danger' : ($ev['type'] === 'alert_ack' ? 'list-group-item-success' : '') ?>">
                        <div class="d-flex justify-content-between">
                            <span><span class="text-body-secondary me-1"><?= e($eventIcons[$ev['type']] ?? '•') ?></span><strong><?= e($eventLabels[$ev['type']] ?? $ev['type']) ?></strong>
                                <?php if ($ev['type'] === 'status' || $ev['type'] === 'assignment'): ?>
                                    <?= $ev['from_status'] ? e(ObservationWorkflow::label($ev['from_status'])) . ' → ' : '' ?><?= $ev['to_status'] ? $statusBadge($ev['to_status']) : '' ?>
                                <?php endif; ?></span>
                            <span class="text-body-secondary text-nowrap"><?= e(fecha($ev['created_at'], 'd/m/Y H:i')) ?></span>
                        </div>
                        <div class="text-body-secondary"><?= e($ev['actor_name'] ?? '') ?></div>
                        <?php if ($ev['comment']): ?><div class="mt-1" style="white-space: pre-wrap"><?= e($ev['comment']) ?></div><?php endif; ?>
                        <?php if ($ev['type'] === 'assignment' && $data): ?>
                            <div class="mt-1"><?php if (!empty($data['accion_uuid'])): ?><a href="<?= e(url('/panel/acciones/' . $data['accion_uuid'])) ?>"><?= e($data['numero']) ?></a> · <?php endif; ?>Responsable: <strong><?= e($data['responsable']) ?></strong> · límite <?= e(date('d/m/Y', strtotime($data['fecha_compromiso']))) ?><br><?= e($data['accion']) ?></div>
                        <?php elseif ($ev['type'] === 'correction' && $data): ?>
                            <?php foreach ($data['despues'] as $label => $value): ?>
                                <div class="mt-1"><?= e($label) ?>: <s class="text-body-secondary"><?= e($data['antes'][$label] ?? '—') ?></s> → <strong><?= e($value ?? '—') ?></strong></div>
                            <?php endforeach; ?>
                        <?php elseif ($ev['type'] === 'attachment' && $data): ?>
                            <div class="mt-1"><?= e($data['fotos']) ?> foto(s)</div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($canAdd): ?>
                <form class="card-body border-top d-flex gap-2" method="post" action="<?= e(url($base . '/comentario')) ?>">
                    <?= csrf_field() ?>
                    <input class="form-control form-control-sm" name="comment" placeholder="Agregar un comentario…" maxlength="5000" required>
                    <button class="btn btn-outline-primary btn-sm">Comentar</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
