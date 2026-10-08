<?php
use App\Models\Actions;
use App\Services\ActionWorkflow;
require __DIR__ . '/_badges.php';
$base = '/panel/acciones/' . $a['uuid'];
$open = $old['transition'] ?? null;
$eventLabels = ['created' => 'Acción creada', 'started' => 'Tomada', 'updated' => 'Datos modificados', 'comment' => 'Comentario',
    'evidence' => 'Archivos agregados', 'closed' => 'Cerrada con evidencia', 'verified' => 'Verificada: eficaz',
    'rejected' => 'Rechazada: no fue eficaz', 'cancelled' => 'Cancelada', 'migrated' => 'Migrada'];
$eventIcons = ['created' => '●', 'started' => '▶', 'updated' => '✱', 'comment' => '✎', 'evidence' => '▣', 'closed' => '✓',
    'verified' => '✔', 'rejected' => '↺', 'cancelled' => '✕', 'migrated' => '⇢'];
$byCycle = [];
foreach ($files as $f) {
    $byCycle[$f['kind'] === 'referencia' ? 'ref' : (int) $f['cycle']][] = $f;
}
$fileThumb = function (array $f) use ($base): string {
    $src = url($base . '/archivos/' . $f['uuid']);
    $title = e(($f['original_name'] ?? '') . ' · SHA-256 ' . $f['sha256']);
    if (str_starts_with($f['mime'], 'image/')) {
        return '<a href="' . e($src) . '" target="_blank" title="' . $title . '"><img src="' . e($src . '?t=1') . '" alt="Evidencia" class="rounded border" style="height: 100px; width: auto" loading="lazy"></a>';
    }
    return '<a class="btn btn-outline-secondary btn-sm" href="' . e($src) . '" target="_blank" title="' . $title . '">📄 ' . e($f['original_name'] ?? 'PDF') . '</a>';
};
$late = ActionWorkflow::isOverdue($a, $today);
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/acciones')) ?>">←</a>
    <h1 class="h4 m-0"><?= e(Actions::format((int) $a['number'])) ?></h1>
    <?= $actionBadge($a['status']) ?> <?= $priorityBadge($a['priority']) ?>
    <?php if ($late): ?><span class="badge text-bg-danger">Vencida</span><?php endif; ?>
    <a class="btn btn-sm btn-outline-secondary ms-auto" target="_blank" href="<?= e(url($base . '/imprimir')) ?>">Imprimir / PDF</a>
</div>
<h2 class="h5 mb-3"><?= e($a['title']) ?></h2>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm mb-3">
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between"><span>Responsable</span><strong><?= e($a['responsible_name']) ?></strong></li>
                <li class="list-group-item d-flex justify-content-between"><span>Fecha límite</span><?= $dueLabel($a, $today) ?></li>
                <li class="list-group-item d-flex justify-content-between"><span>Tipo</span><span><?= e(ActionWorkflow::TYPES[$a['type']] ?? $a['type']) ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Origen</span>
                    <span><?php if ($origin['url']): ?><a href="<?= e(url($origin['url'])) ?>"><?= e($origin['label']) ?></a><?php else: ?><?= e($origin['label']) ?><?php endif; ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Sector</span><span><?= e($a['sector_name'] ?? '—') ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Creada</span><span><?= e(fecha($a['created_at'], 'd/m/Y H:i')) ?> · <?= e($a['created_by_name'] ?? 'Sistema') ?></span></li>
                <?php if ($a['description']): ?><li class="list-group-item" style="white-space: pre-wrap"><?= e($a['description']) ?></li><?php endif; ?>
            </ul>
        </div>

        <?php if ($a['closed_at']): ?>
            <div class="card shadow-sm mb-3 border-warning">
                <div class="card-header"><strong>Cierre</strong> <span class="small text-body-secondary">· <?= e($a['closed_by_name'] ?? '') ?>, <?= e(fecha($a['closed_at'], 'd/m/Y H:i')) ?></span></div>
                <div class="card-body small">
                    <div style="white-space: pre-wrap"><?= e($a['closure_text']) ?></div>
                    <?php if ($a['status'] === 'cerrada' && $a['verify_due_on']): ?>
                        <div class="mt-2 <?= $a['verify_due_on'] < $today ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">Verificar antes del <?= e(date('d/m/Y', strtotime($a['verify_due_on']))) ?></div>
                    <?php endif; ?>
                    <?php if ($a['verified_at']): ?>
                        <div class="mt-2 text-success">✔ Verificada como eficaz por <?= e($a['verified_by_name'] ?? '—') ?> el <?= e(fecha($a['verified_at'], 'd/m/Y')) ?>
                            <?php if ($a['verification_text']): ?><br><span class="text-body"><?= e($a['verification_text']) ?></span><?php endif; ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Evidencia y archivos (<?= count($files) ?>)</strong></div>
            <div class="card-body">
                <?php if (!$files): ?><p class="small text-body-secondary">Todavía no hay archivos. Para cerrar la acción hace falta al menos una foto o PDF de evidencia.</p><?php endif; ?>
                <?php foreach ($byCycle as $cycle => $list): ?>
                    <div class="small text-body-secondary mb-1">
                        <?= $cycle === 'ref' ? 'Referencia' : ((int) $cycle === (int) $a['cycle'] ? 'Evidencia' . ((int) $a['cycle'] > 1 ? ' (intento ' . e($cycle) . ')' : '') : 'Evidencia del intento ' . e($cycle) . ' (cierre rechazado)') ?></div>
                    <div class="d-flex flex-wrap gap-2 mb-3"><?php foreach ($list as $f) { echo $fileThumb($f); } ?></div>
                <?php endforeach; ?>
                <?php if ($canEvidence): ?>
                    <form method="post" enctype="multipart/form-data" action="<?= e(url($base . '/evidencia')) ?>" class="d-flex gap-2" x-data="{ resizing: false }">
                        <?= csrf_field() ?>
                        <input class="form-control form-control-sm" type="file" name="files[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple data-resize
                               @resize-start="resizing = true" @resize-done="resizing = false" required>
                        <button class="btn btn-outline-primary btn-sm text-nowrap" :disabled="resizing">Agregar evidencia</button>
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
                <div class="card-body d-grid gap-2" x-data="{ open: <?= json_encode($open) ?> }">
                    <?php foreach ($transitions as $key => $t): ?>
                        <button type="button" class="btn btn-sm <?= in_array($key, ['cerrar', 'verificar'], true) ? 'btn-success' : ($key === 'cancelar' || $key === 'rechazar' ? 'btn-outline-danger' : 'btn-outline-primary') ?>"
                                @click="open = open === '<?= e($key) ?>' ? null : '<?= e($key) ?>'"><?= e($t['label']) ?></button>
                        <form method="post" enctype="multipart/form-data" action="<?= e(url($base . '/accion/' . $key)) ?>" class="border rounded p-2" x-show="open === '<?= e($key) ?>'" x-cloak x-data="{ resizing: false }">
                            <?= csrf_field() ?>
                            <?php if ($key === 'cerrar'): ?>
                                <label class="form-label small mb-0">¿Qué se hizo? (obligatorio)</label>
                                <textarea class="form-control form-control-sm mb-2" name="closure_text" rows="3" required minlength="10"><?= e(($old['transition'] ?? '') === 'cerrar' ? ($old['closure_text'] ?? '') : '') ?></textarea>
                                <label class="form-label small mb-0">Evidencia: fotos o PDF <?= $files && array_filter($files, fn ($f) => $f['kind'] === 'evidencia' && (int) $f['cycle'] === (int) $a['cycle']) ? '(ya hay; podés sumar más)' : '(obligatoria)' ?></label>
                                <input class="form-control form-control-sm mb-2" type="file" name="files[]" accept="image/jpeg,image/png,image/webp,application/pdf" multiple data-resize
                                       @resize-start="resizing = true" @resize-done="resizing = false">
                                <div class="small text-body-secondary mb-2">Después del cierre, otra persona verifica si la acción fue eficaz.</div>
                            <?php elseif ($key === 'rechazar'): ?>
                                <label class="form-label small mb-0">Nueva fecha límite (opcional)</label>
                                <input class="form-control form-control-sm mb-2" type="date" name="due_on" min="<?= e($today) ?>">
                            <?php endif; ?>
                            <?php if ($key !== 'cerrar'): ?>
                                <label class="form-label small mb-0"><?= $t['comment'] ? 'Motivo (obligatorio)' : 'Comentario (opcional)' ?></label>
                                <textarea class="form-control form-control-sm mb-2" name="comment" rows="2" <?= $t['comment'] ? 'required minlength="5"' : '' ?>><?= e(($old['transition'] ?? '') === $key ? ($old['comment'] ?? '') : '') ?></textarea>
                            <?php endif; ?>
                            <button class="btn btn-primary btn-sm" :disabled="resizing">Confirmar: <?= e(mb_strtolower($t['label'])) ?></button>
                        </form>
                    <?php endforeach; ?>

                    <?php if ($canEdit): $v = fn (string $k, $default) => ($old['transition'] ?? '') === 'editar' ? ($old[$k] ?? $default) : $default; ?>
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="open = open === 'editar' ? null : 'editar'">Reasignar / cambiar fecha o datos</button>
                        <form method="post" action="<?= e(url($base . '/editar')) ?>" class="border rounded p-2" x-show="open === 'editar'" x-cloak>
                            <?= csrf_field() ?>
                            <label class="form-label small mb-0">Acción</label>
                            <input class="form-control form-control-sm mb-2" name="title" maxlength="191" required value="<?= e($v('title', $a['title'])) ?>">
                            <label class="form-label small mb-0">Detalle</label>
                            <textarea class="form-control form-control-sm mb-2" name="description" rows="2"><?= e($v('description', $a['description'] ?? '')) ?></textarea>
                            <label class="form-label small mb-0">Responsable</label>
                            <select class="form-select form-select-sm mb-2" name="responsible">
                                <?php foreach ($users as $u): ?><option value="<?= e($u['uuid']) ?>" <?= $v('responsible', $a['responsible_uuid']) === $u['uuid'] ? 'selected' : '' ?>><?= e($u['name'] . ' · ' . $u['role_name']) ?></option><?php endforeach; ?>
                            </select>
                            <div class="row g-2 mb-2">
                                <div class="col-6"><label class="form-label small mb-0">Fecha límite</label><input class="form-control form-control-sm" type="date" name="due_on" value="<?= e($v('due_on', $a['due_on'])) ?>"></div>
                                <div class="col-6"><label class="form-label small mb-0">Prioridad</label>
                                    <select class="form-select form-select-sm" name="priority"><?php foreach (ActionWorkflow::PRIORITIES as $k => $n): ?><option value="<?= e($k) ?>" <?= $v('priority', $a['priority']) === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
                            </div>
                            <input type="hidden" name="type" value="<?= e($a['type']) ?>">
                            <label class="form-label small mb-0">Motivo (obligatorio si cambiás responsable o fecha)</label>
                            <textarea class="form-control form-control-sm mb-2" name="comment" rows="2"><?= e($v('comment', '')) ?></textarea>
                            <button class="btn btn-primary btn-sm">Guardar cambios</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <script src="<?= e(asset('js/image-resize.js')) ?>"></script>
        <?php endif; ?>

        <div class="card shadow-sm" id="linea-de-tiempo">
            <div class="card-header"><strong>Línea de tiempo</strong></div>
            <ul class="list-group list-group-flush small">
                <?php foreach (array_reverse($events) as $ev): $data = $ev['data'] ? json_decode($ev['data'], true) : null; ?>
                    <li class="list-group-item <?= $ev['type'] === 'rejected' ? 'list-group-item-danger' : ($ev['type'] === 'verified' ? 'list-group-item-success' : '') ?>">
                        <div class="d-flex justify-content-between">
                            <span><span class="text-body-secondary me-1"><?= e($eventIcons[$ev['type']] ?? '•') ?></span><strong><?= e($eventLabels[$ev['type']] ?? $ev['type']) ?></strong>
                                <?php if ($ev['to_status'] && $ev['type'] !== 'created'): ?> <?= $actionBadge($ev['to_status']) ?><?php endif; ?></span>
                            <span class="text-body-secondary text-nowrap"><?= e(fecha($ev['created_at'], 'd/m/Y H:i')) ?></span>
                        </div>
                        <div class="text-body-secondary"><?= e($ev['actor_name'] ?? '') ?></div>
                        <?php if ($ev['comment']): ?><div class="mt-1" style="white-space: pre-wrap"><?= e($ev['comment']) ?></div><?php endif; ?>
                        <?php if ($ev['type'] === 'created' && $data): ?>
                            <div class="mt-1">Responsable: <strong><?= e($data['responsable'] ?? '—') ?></strong> · límite <?= e(date('d/m/Y', strtotime((string) $data['fecha_limite']))) ?></div>
                        <?php elseif ($ev['type'] === 'updated' && $data): ?>
                            <?php foreach ($data['despues'] as $label => $value): ?>
                                <div class="mt-1"><?= e($label) ?>: <s class="text-body-secondary"><?= e($data['antes'][$label] ?? '—') ?></s> → <strong><?= e($value ?? '—') ?></strong></div>
                            <?php endforeach; ?>
                        <?php elseif ($ev['type'] === 'rejected' && $data): ?>
                            <div class="mt-1 text-body-secondary">Cierre rechazado: <?= e($data['cierre_rechazado'] ?? '') ?><?= !empty($data['nueva_fecha']) ? ' · nueva fecha límite ' . e(date('d/m/Y', strtotime($data['nueva_fecha']))) : '' ?></div>
                        <?php elseif ($ev['type'] === 'evidence' && $data): ?>
                            <div class="mt-1"><?= e($data['archivos']) ?> archivo(s) de <?= e($data['tipo']) ?></div>
                        <?php endif; ?>
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
