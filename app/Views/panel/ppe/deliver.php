<?php
use App\Services\PpeService;
require __DIR__ . '/_badges.php';
$base = '/panel/epp/empleado/' . $e['uuid'];
$required = [];
foreach ($status['rows'] as $r) {
    $required[$r['item']['uuid']] = $r;
}
$others = array_values(array_filter($catalog, fn ($i) => !isset($required[$i['uuid']])));
$sizeOf = fn (?string $type) => $type ? ($e[PpeService::SIZE_TYPES[$type]['column']] ?? '') : '';
$oldItems = [];
foreach ((array) ($old['items'] ?? []) as $row) {
    if (is_array($row) && !empty($row['item'])) {
        $oldItems[$row['item']] = $row;
    }
}
$hasOld = !empty($old['_items']);
$anyDelivered = (bool) array_filter($status['rows'], fn ($r) => $r['last'] !== null);
$reason = $old['reason'] ?? ($anyDelivered ? 'vencimiento' : 'inicial');
$mode = $old['signature_mode'] ?? 'pantalla';
$n = 0;
$row = function (array $item, ?array $req) use (&$n, $oldItems, $hasOld, $sizeOf, $ppeState, $ppeDate): string {
    $i = $n++;
    $old = $oldItems[$item['uuid']] ?? null;
    $checked = $hasOld ? ($old !== null && !empty($old['selected'])) : ($req !== null && in_array($req['state'], ['vencido', 'nunca', 'por_vencer'], true));
    $qty = $old['quantity'] ?? ($req['quantity'] ?? 1);
    $size = $old['size'] ?? ($req['last']['size'] ?? $sizeOf($item['size_type']));
    ob_start(); ?>
    <tr>
        <td><input class="form-check-input" type="checkbox" name="items[<?= $i ?>][selected]" value="1" id="it<?= $i ?>" <?= $checked ? 'checked' : '' ?>>
            <input type="hidden" name="items[<?= $i ?>][item]" value="<?= e($item['uuid']) ?>"></td>
        <td><label for="it<?= $i ?>"><?= e($item['name']) ?></label><?= $item['brand'] || $item['model'] ? '<br><span class="text-body-secondary">' . e(trim($item['brand'] . ' ' . $item['model'])) . '</span>' : '' ?></td>
        <td><?= $req ? $ppeState($req['state']) . ($req['last'] ? '<br><span class="text-body-secondary">reponer ' . e($ppeDate($req['last']['next_due_on'])) . '</span>' : '') : '<span class="text-body-secondary">fuera de la matriz</span>' ?></td>
        <td style="width: 80px"><input class="form-control form-control-sm" name="items[<?= $i ?>][quantity]" value="<?= e($qty) ?>" inputmode="numeric"></td>
        <td style="width: 90px"><?php if ($item['size_type']): ?><input class="form-control form-control-sm" name="items[<?= $i ?>][size]" value="<?= e($size) ?>" maxlength="10" placeholder="<?= e(PpeService::SIZE_TYPES[$item['size_type']]['label']) ?>"><?php endif; ?></td>
        <td style="width: 120px"><select class="form-select form-select-sm" name="items[<?= $i ?>][returned_previous]"><option value="">—</option>
            <option value="1" <?= ($old['returned_previous'] ?? '') === '1' ? 'selected' : '' ?>>Devolvió</option><option value="0" <?= ($old['returned_previous'] ?? '') === '0' ? 'selected' : '' ?>>No devolvió</option></select></td>
    </tr>
    <?php return (string) ob_get_clean();
};
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url($base)) ?>">←</a>
    <h1 class="h4 m-0">Entregar EPP</h1>
    <span class="text-body-secondary"><?= e($e['name']) ?> · DNI <?= e($e['dni']) ?> · <?= e($e['position_name'] ?? 'sin puesto') ?></span>
</div>
<form method="post" action="<?= e(url($base . '/entregar')) ?>" enctype="multipart/form-data" x-data="{ mode: <?= e(json_encode($mode)) ?> }">
    <?= csrf_field() ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header"><strong>Qué se entrega</strong> <span class="small text-body-secondary">· vencidos, nunca entregados y por vencer vienen tildados</span></div>
        <div class="table-responsive">
            <table class="table table-sm small mb-0 align-middle">
                <thead><tr><th></th><th>Elemento</th><th>Estado</th><th>Cant.</th><th>Talle</th><th>Anterior</th></tr></thead>
                <tbody>
                <?php foreach ($status['rows'] as $r): ?><?= $row($r['item'], $r) ?><?php endforeach; ?>
                <?php if (!$status['rows']): ?><tr><td colspan="6" class="text-body-secondary">Su puesto no tiene EPP en la matriz: elegí del catálogo abajo.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($others): ?>
            <details class="card-body border-top" <?= $hasOld && array_intersect(array_column($others, 'uuid'), array_keys(array_filter($oldItems, fn ($o) => !empty($o['selected'])))) ? 'open' : '' ?>>
                <summary class="small">Otro elemento del catálogo (fuera de la matriz)</summary>
                <table class="table table-sm small mb-0 mt-2 align-middle"><tbody>
                    <?php foreach ($others as $item): ?><?= $row($item, null) ?><?php endforeach; ?>
                </tbody></table>
            </details>
        <?php endif; ?>
        <?php if (isset($errors['items'])): ?><div class="card-body text-danger small pt-0"><?= e($errors['items']) ?></div><?php endif; ?>
    </div>

    <div class="card shadow-sm mb-3"><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label">Motivo</label>
            <select class="form-select" name="reason"><?php foreach (PpeService::REASONS as $k => $label): ?><option value="<?= e($k) ?>" <?= $reason === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Fecha y hora de entrega</label>
            <input class="form-control" type="datetime-local" name="delivered_at" value="<?= e($old['delivered_at'] ?? fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d\TH:i')) ?>">
            <div class="form-text">Para cargar una planilla atrasada (hasta 60 días).</div></div>
        <div class="col-md-4"><label class="form-label">Observaciones</label><input class="form-control" name="notes" maxlength="500" value="<?= e($old['notes'] ?? '') ?>"></div>
    </div></div>

    <div class="card shadow-sm mb-3" style="max-width: 640px">
        <div class="card-header d-flex gap-3 align-items-center"><strong>Firma del empleado</strong>
            <div class="btn-group btn-group-sm ms-auto" role="group">
                <input type="radio" class="btn-check" name="signature_mode" id="m1" value="pantalla" x-model="mode"><label class="btn btn-outline-secondary" for="m1">En pantalla</label>
                <input type="radio" class="btn-check" name="signature_mode" id="m2" value="papel" x-model="mode"><label class="btn btn-outline-secondary" for="m2">Planilla en papel</label>
            </div></div>
        <div class="card-body">
            <div x-show="mode === 'pantalla'">
                <p class="small text-body-secondary mb-2"><?= e($e['name']) ?> firma que recibió los elementos tildados y que fue capacitado en su uso.</p>
                <?php $sigName = 'signature'; $sigLabel = 'Firma de ' . $e['name']; require __DIR__ . '/../permits/_signature.php'; ?>
                <?php if (isset($errors['signature'])): ?><div class="text-danger small"><?= e($errors['signature']) ?></div><?php endif; ?>
            </div>
            <div x-show="mode === 'papel'" x-cloak>
                <p class="small text-body-secondary mb-2">Cuando no hay pantalla a mano: subí la foto o el PDF de la planilla firmada por el empleado. Queda guardada tal cual, como evidencia.</p>
                <input class="form-control mb-2" type="file" name="paper" accept="image/jpeg,image/png,image/webp,application/pdf">
                <input class="form-control" name="paper_reason" maxlength="191" value="<?= e($old['paper_reason'] ?? '') ?>" placeholder="Por qué se firmó en papel (ej. entrega en obra sin tablet)">
                <?php foreach (['paper', 'paper_reason'] as $k): ?><?php if (isset($errors[$k])): ?><div class="text-danger small"><?= e($errors[$k]) ?></div><?php endif; ?><?php endforeach; ?>
            </div>
        </div>
    </div>
    <button class="btn btn-primary">Registrar entrega</button>
</form>
<script src="<?= e(asset('js/signature-pad.js')) ?>"></script>
