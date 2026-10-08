<?php
use App\Models\WorkPermits;
use App\Services\InspectionStructure;
use App\Services\WorkPermitService;
$tenant = App\Core\Tenant::current();
$num = WorkPermits::format((int) $p['number']);
$name = fn (array $w) => $w['employee_id'] ? $w['last_name'] . ', ' . $w['first_name'] : (string) $w['external_name'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= e($num) ?> · <?= e($tenant['name']) ?></title>
    <style>
        body { font: 10pt/1.35 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #212529; margin: 0; background: #f1f3f5; }
        .page { max-width: 190mm; margin: 0 auto; background: #fff; padding: 12mm; }
        .toolbar { text-align: center; padding: 10px; } .toolbar button { font: inherit; padding: 6px 16px; border: 0; border-radius: 6px; background: #0d6efd; color: #fff; cursor: pointer; }
        header { display: flex; justify-content: space-between; align-items: flex-start; gap: 10mm; border-bottom: 3px solid #212529; padding-bottom: 6px; margin-bottom: 8px; }
        h1 { font-size: 17pt; margin: 0; } h2 { font-size: 10.5pt; margin: 10px 0 4px; background: #f1f3f5; padding: 3px 6px; }
        .types { font-size: 13pt; font-weight: 700; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; } td { padding: 2px 6px; vertical-align: top; border-bottom: 1px solid #eee; } td.k { width: 28%; color: #6c757d; }
        .big { font-size: 12pt; font-weight: 700; } .fail { background: #fdecee; }
        .sigs { display: flex; flex-wrap: wrap; gap: 6mm; } .sigs figure { margin: 0; width: 40mm; text-align: center; font-size: 8pt; } .sigs img { width: 100%; height: 16mm; object-fit: contain; border-bottom: 1px solid #212529; }
        .qr svg { width: 34mm; height: 34mm; } .hash { font-family: monospace; font-size: 7pt; color: #6c757d; word-break: break-all; }
        @media print { body { background: #fff; } .toolbar { display: none; } .page { padding: 0; } @page { size: A4; margin: 10mm; } }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Imprimir</button></div>
<div class="page">
    <header>
        <div><h1>Permiso de trabajo <?= e($num) ?></h1>
            <div class="types"><?= e(implode(' + ', array_map(fn ($t) => WorkPermitService::TYPES[$t]['label'], $p['type_list']))) ?></div>
            <div><?= e($tenant['name']) ?> · Estado: <strong><?= e(WorkPermitService::STATES[$p['status']]['label']) ?></strong></div></div>
        <div class="qr" style="text-align: center"><div id="qr"></div><div style="font-size: 8pt">Escaneá para ver si sigue vigente</div></div>
    </header>
    <table>
        <tr><td class="k">Válido</td><td class="big"><?= e(fecha($p['valid_from'], 'd/m/Y H:i')) ?> → <?= e(fecha($p['ends_at'], 'd/m/Y H:i')) ?></td></tr>
        <tr><td class="k">Lugar</td><td><?= e($p['sector_name'] ?? '') ?><?= $p['equipment_code'] ? ' · ' . e($p['equipment_code'] . ' ' . $p['equipment_name']) : '' ?><?= $p['location_text'] ? ' · ' . e($p['location_text']) : '' ?></td></tr>
        <tr><td class="k">Tarea</td><td><?= e($p['task']) ?></td></tr>
        <tr><td class="k">Contratista</td><td><?= e($p['contractor_name'] ?? 'Personal propio') ?></td></tr>
        <tr><td class="k">Ejecutores</td><td><?= e(implode('; ', array_map(fn ($w) => $name($w) . ($w['role'] === 'vigia' ? ' (vigía)' : ''), $workers))) ?></td></tr>
        <tr><td class="k">Solicitó / autorizó</td><td><?= e($p['requested_by_name'] ?? '—') ?> / <?= e($p['approved_by_name'] ?? '—') ?></td></tr>
    </table>
    <?php foreach ($checklists as $c): ?>
        <h2>Checklist: <?= e(WorkPermitService::TYPES[$c['permit_type']]['label']) ?></h2>
        <table><?php foreach ($c['answers'] as $a): ?>
            <tr class="<?= $a['ok'] === false ? 'fail' : '' ?>"><td><?= $a['critical'] ? '★ ' : '' ?><?= e($a['text']) ?><?= $a['comment'] ? ' — ' . e($a['comment']) : '' ?></td>
                <td style="width: 18%; text-align: right"><?= $a['ok'] === true ? '✓ ' : ($a['ok'] === false ? '✗ ' : '') ?><?= e(InspectionStructure::valueLabel($a['value'])) ?></td></tr>
        <?php endforeach; ?></table>
    <?php endforeach; ?>
    <h2>Firmas</h2>
    <div class="sigs">
        <?php foreach ($signatures as $s): ?>
            <figure><img src="<?= e(url('/panel/permisos/' . $p['uuid'] . '/firmas/' . $s['uuid'])) ?>" alt=""><figcaption><strong><?= e(WorkPermitService::SIGNATURE_ROLES[$s['role']]) ?></strong><br><?= e($s['signer_name']) ?><br><?= e(fecha($s['signed_at'], 'd/m H:i')) ?></figcaption></figure>
        <?php endforeach; ?>
    </div>
    <p class="hash">★ = ítem crítico · Integridad (SHA-256): <?= e($p['original_hash']) ?> · Impreso <?= e(fecha(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i')) ?></p>
</div>
<script src="<?= e(asset('js/qrcode.min.js')) ?>"></script>
<script>var qr = qrcode(0, 'M'); qr.addData(<?= json_encode($verifyUrl) ?>); qr.make(); document.getElementById('qr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 0 });</script>
</body>
</html>
