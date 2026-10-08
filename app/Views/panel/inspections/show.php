<?php
use App\Models\Actions;
use App\Models\Inspections;
use App\Services\InspectionStructure;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$base = '/panel/inspecciones/' . $i['uuid'];
$thumbs = function (array $list) use ($base): string {
    $html = '';
    foreach ($list as $p) {
        $src = url($base . '/fotos/' . $p['uuid']);
        $html .= '<a href="' . e($src) . '" target="_blank" title="SHA-256 ' . e($p['sha256']) . '"><img src="' . e($src . '?t=1') . '" alt="Foto" class="rounded border me-1 mb-1" style="height: 70px" loading="lazy"></a>';
    }
    return $html;
};
$section = null;
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones')) ?>">←</a>
    <h1 class="h4 m-0"><?= e(Inspections::format((int) $i['number'])) ?></h1>
    <?= $i['status'] === 'anulada' ? '<span class="badge text-bg-dark">Anulada</span>' : $resultBadge($i['result']) ?>
    <span class="badge text-bg-light border">Cumplimiento <?= $scoreText($i['score']) ?></span>
    <a class="btn btn-sm btn-outline-secondary ms-auto" target="_blank" href="<?= e(url($base . '/imprimir')) ?>">Imprimir / PDF</a>
</div>
<h2 class="h5 mb-3"><?= e($i['template_name']) ?> <span class="small text-body-secondary">v<?= e($i['template_version']) ?></span>
    <?php if ($i['equipment_code']): ?> · <a href="<?= e(url('/panel/equipo/' . $i['equipment_uuid'])) ?>"><?= e($i['equipment_code'] . ' ' . $i['equipment_name']) ?></a><?php endif; ?></h2>

<?php if ($i['status'] === 'anulada'): ?>
    <div class="alert alert-dark">Anulada por <?= e($i['annulled_by_name'] ?? '—') ?> el <?= e(fecha($i['annulled_at'], 'd/m/Y H:i')) ?>: <?= e($i['annul_reason']) ?></div>
<?php elseif ((int) $i['items_critical_fail'] > 0): ?>
    <div class="alert alert-danger">Falló <?= e($i['items_critical_fail']) ?> ítem(s) crítico(s): se avisó al supervisor del sector y a Seguridad e Higiene. Verificá que el equipo no se use hasta resolverlo.</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-3">
            <ul class="list-group list-group-flush small">
                <?php foreach ($answers as $a): ?>
                    <?php if ($a['section_title'] !== $section): $section = $a['section_title']; ?>
                        <li class="list-group-item bg-body-tertiary fw-semibold"><?= e($section) ?></li>
                    <?php endif; ?>
                    <li class="list-group-item <?= $a['ok'] !== null && (int) $a['ok'] === 0 ? 'list-group-item-danger' : '' ?>">
                        <div class="d-flex justify-content-between gap-2">
                            <span><?= (int) $a['critical'] ? '<span class="badge text-bg-danger me-1">Crítico</span>' : '' ?><?= e($a['item_text']) ?></span>
                            <span class="text-nowrap fw-semibold">
                                <?= $a['ok'] === null ? '' : ((int) $a['ok'] === 1 ? '<span class="text-success">✓</span>' : '<span class="text-danger">✗</span>') ?>
                                <?= e(InspectionStructure::valueLabel($a['value'], $units[$a['item_key']] ?? null)) ?>
                            </span>
                        </div>
                        <?php if ($a['comment']): ?><div class="mt-1" style="white-space: pre-wrap"><?= e($a['comment']) ?></div><?php endif; ?>
                        <?php if (!empty($photos[$a['item_key']])): ?><div class="mt-1"><?= $thumbs($photos[$a['item_key']]) ?></div><?php endif; ?>
                        <?php if ($a['action_uuid']): ?>
                            <div class="mt-1"><a href="<?= e(url('/panel/acciones/' . $a['action_uuid'])) ?>">➜ Acción <?= e(Actions::format((int) $a['action_number'])) ?></a>
                                <span class="text-body-secondary">(<?= e(App\Services\ActionWorkflow::label($a['action_status'])) ?>)</span></div>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php if (!empty($photos[''])): ?><div class="card shadow-sm mb-3"><div class="card-body"><?= $thumbs($photos['']) ?></div></div><?php endif; ?>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <ul class="list-group list-group-flush small">
                <li class="list-group-item d-flex justify-content-between"><span>Fecha</span><span><?= e(fecha($i['done_at_device'], 'd/m/Y H:i')) ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Inspector</span><span><?= e($i['inspector_name'] ?? '—') ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Sector</span><span><?= e($i['sector_name'] ?? '—') ?></span></li>
                <li class="list-group-item d-flex justify-content-between"><span>Cumplen / no cumplen</span><span><?= e($i['items_ok']) ?> / <?= e($i['items_fail']) ?></span></li>
                <?php if ($i['lat'] !== null): ?><li class="list-group-item d-flex justify-content-between"><span>GPS</span><span><?= e($i['lat'] . ', ' . $i['lng']) ?></span></li><?php endif; ?>
                <li class="list-group-item d-flex justify-content-between"><span>Recibida</span><span><?= e(fecha($i['received_at'], 'd/m/Y H:i')) ?></span></li>
                <?php if ($i['notes']): ?><li class="list-group-item" style="white-space: pre-wrap"><?= e($i['notes']) ?></li><?php endif; ?>
                <li class="list-group-item <?= $hashOk ? 'text-success' : 'text-danger fw-semibold' ?>" title="SHA-256 <?= e($i['original_hash']) ?>"><?= $hashOk ? '✓ Íntegra (no se modificó)' : '✗ La verificación de integridad falló' ?></li>
            </ul>
        </div>
        <?php if ($i['status'] === 'completa' && UserAuth::can('inspecciones', 'cerrar')): ?>
            <form class="card shadow-sm mb-3" method="post" action="<?= e(url($base . '/anular')) ?>" x-data="{ open: false }">
                <?= csrf_field() ?>
                <div class="card-body">
                    <button type="button" class="btn btn-sm btn-outline-danger" @click="open = !open">Anular inspección</button>
                    <div x-show="open" x-cloak class="mt-2">
                        <textarea class="form-control form-control-sm mb-2" name="reason" rows="2" required minlength="5" placeholder="Motivo (ej. se cargó en el equipo equivocado)"></textarea>
                        <button class="btn btn-sm btn-danger">Confirmar anulación</button>
                        <div class="small text-body-secondary mt-1">Las respuestas no se borran: queda registrada como anulada.</div>
                    </div>
                </div>
            </form>
        <?php endif; ?>
        <div class="card shadow-sm">
            <div class="card-header small fw-semibold">Línea de tiempo</div>
            <ul class="list-group list-group-flush small">
                <?php foreach (array_reverse($events) as $ev): $data = $ev['data'] ? json_decode($ev['data'], true) : []; ?>
                    <li class="list-group-item">
                        <div class="d-flex justify-content-between"><strong><?= e(['created' => 'Inspección registrada', 'action_created' => 'Acciones creadas', 'annulled' => 'Anulada'][$ev['type']] ?? $ev['type']) ?></strong>
                            <span class="text-body-secondary"><?= e(fecha($ev['created_at'], 'd/m H:i')) ?></span></div>
                        <div class="text-body-secondary"><?= e($ev['actor_name'] ?? '') ?></div>
                        <?php if ($ev['comment']): ?><div><?= e($ev['comment']) ?></div><?php endif; ?>
                        <?php if (!empty($data['acciones'])): ?><div><?= e(implode(', ', $data['acciones'])) ?></div><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
