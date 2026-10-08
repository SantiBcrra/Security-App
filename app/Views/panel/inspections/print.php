<?php
use App\Models\Actions;
use App\Models\Inspections;
use App\Services\InspectionService;
use App\Services\InspectionStructure;
$tenant = App\Core\Tenant::current();
$logo = App\Controllers\Web\App\HomeController::logoUrl($tenant);
$num = Inspections::format((int) $i['number']);
$section = null;
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= e($num) ?> · <?= e($tenant['name']) ?></title>
    <style>
        body { font: 10pt/1.35 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #212529; margin: 0; background: #f1f3f5; }
        .page { max-width: 190mm; margin: 0 auto; background: #fff; padding: 14mm; }
        .toolbar { text-align: center; padding: 10px; } .toolbar button { font: inherit; padding: 6px 16px; border: 0; border-radius: 6px; background: #0d6efd; color: #fff; cursor: pointer; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #212529; padding-bottom: 6px; margin-bottom: 10px; }
        h1 { font-size: 15pt; margin: 0; } h2 { font-size: 10.5pt; margin: 12px 0 4px; background: #f1f3f5; padding: 3px 6px; }
        table { width: 100%; border-collapse: collapse; } td { padding: 3px 6px; vertical-align: top; border-bottom: 1px solid #eee; }
        .meta td:first-child { width: 28%; color: #6c757d; } .ans td:last-child { width: 22%; text-align: right; white-space: nowrap; }
        .fail { background: #fdecee; } .crit { color: #dc3545; font-weight: 700; } .muted { color: #6c757d; }
        .hash { font-family: monospace; font-size: 7.5pt; color: #6c757d; word-break: break-all; }
        .sign { display: flex; gap: 20mm; margin-top: 18mm; } .sign div { flex: 1; border-top: 1px solid #212529; text-align: center; padding-top: 3px; font-size: 9pt; }
        @media print { body { background: #fff; } .toolbar { display: none; } .page { padding: 0; } @page { size: A4; margin: 12mm; } }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Imprimir o guardar como PDF</button></div>
<div class="page">
    <header>
        <div><h1><?= e($i['template_name']) ?></h1><div><?= e($tenant['name']) ?> · Inspección <?= e($num) ?> (v<?= e($i['template_version']) ?>)</div></div>
        <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" style="height: 40px"><?php endif; ?>
    </header>
    <table class="meta">
        <?php if ($i['equipment_code']): ?><tr><td>Equipo</td><td><?= e($i['equipment_code'] . ' · ' . $i['equipment_name']) ?></td></tr><?php endif; ?>
        <tr><td>Sector</td><td><?= e($i['sector_name'] ?? '—') ?></td></tr>
        <tr><td>Fecha</td><td><?= e(fecha($i['done_at_device'], 'd/m/Y H:i')) ?></td></tr>
        <tr><td>Inspector</td><td><?= e($i['inspector_name'] ?? '—') ?></td></tr>
        <tr><td>Resultado</td><td><strong><?= e($i['status'] === 'anulada' ? 'ANULADA: ' . $i['annul_reason'] : InspectionService::RESULTS[$i['result']]['label']) ?></strong> · cumplimiento <?= e($i['score'] ?? '—') ?>%</td></tr>
    </table>
    <table class="ans">
        <?php foreach ($answers as $a): ?>
            <?php if ($a['section_title'] !== $section): $section = $a['section_title']; ?></table><h2><?= e($section) ?></h2><table class="ans"><?php endif; ?>
            <tr class="<?= $a['ok'] !== null && (int) $a['ok'] === 0 ? 'fail' : '' ?>">
                <td><?= (int) $a['critical'] ? '<span class="crit">★</span> ' : '' ?><?= e($a['item_text']) ?>
                    <?php if ($a['comment']): ?><br><span class="muted"><?= e($a['comment']) ?></span><?php endif; ?>
                    <?php if ($a['action_uuid']): ?><br><span class="muted">Acción <?= e(Actions::format((int) $a['action_number'])) ?></span><?php endif; ?></td>
                <td><?= $a['ok'] === null ? '' : ((int) $a['ok'] === 1 ? '✓ ' : '✗ ') ?><?= e(InspectionStructure::valueLabel($a['value'], $units[$a['item_key']] ?? null)) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <?php if ($i['notes']): ?><h2>Notas</h2><p style="white-space: pre-wrap"><?= e($i['notes']) ?></p><?php endif; ?>
    <div class="sign"><div>Inspector</div><div>Supervisor</div></div>
    <p class="hash">★ = ítem crítico · Integridad (SHA-256): <?= e($i['original_hash']) ?> · Impreso <?= e(fecha(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i')) ?></p>
</div>
</body>
</html>
