<?php
use App\Models\Observations;
use App\Services\ObservationWorkflow;
$tenant = App\Core\Tenant::current();
$logo = App\Controllers\Web\App\HomeController::logoUrl($tenant);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= e(Observations::format((int) $obs['number'])) ?> · <?= e($tenant['name']) ?></title>
    <style>
        body { font: 10.5pt/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #212529; margin: 0; background: #f1f3f5; }
        .page { max-width: 190mm; margin: 0 auto; background: #fff; padding: 14mm; }
        .toolbar { text-align: center; padding: 10px; } .toolbar button { font: inherit; padding: 6px 16px; border: 0; border-radius: 6px; background: #0d6efd; color: #fff; cursor: pointer; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #212529; padding-bottom: 6px; margin-bottom: 10px; }
        h1 { font-size: 15pt; margin: 0; } h2 { font-size: 11pt; margin: 14px 0 6px; border-bottom: 1px solid #dee2e6; }
        table { width: 100%; border-collapse: collapse; } td { padding: 3px 6px; vertical-align: top; border-bottom: 1px solid #f1f3f5; } td:first-child { width: 32%; color: #6c757d; }
        .imminent { background: #dc3545; color: #fff; padding: 4px 8px; font-weight: 700; display: inline-block; margin-bottom: 6px; }
        .photos { display: flex; flex-wrap: wrap; gap: 6px; } .photos img { height: 45mm; border: 1px solid #dee2e6; }
        .hash { font-family: monospace; font-size: 7.5pt; color: #6c757d; word-break: break-all; }
        @media print { body { background: #fff; } .toolbar { display: none; } .page { padding: 0; } @page { size: A4; margin: 14mm; } }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Imprimir o guardar como PDF</button></div>
<div class="page">
    <header>
        <div><h1>Observación <?= e(Observations::format((int) $obs['number'])) ?></h1><div><?= e($tenant['name']) ?></div></div>
        <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" style="height: 40px"><?php endif; ?>
    </header>
    <?php if ($obs['imminent_risk']): ?><div class="imminent">RIESGO INMINENTE</div><?php endif; ?>
    <table>
        <tr><td>Estado</td><td><strong><?= e(ObservationWorkflow::label($obs['status'])) ?></strong></td></tr>
        <tr><td>Categoría</td><td><?= e($obs['category_name']) ?></td></tr>
        <tr><td>Severidad</td><td><?= e($obs['severity_name']) ?></td></tr>
        <tr><td>Tipo de riesgo</td><td><?= e($obs['risk_name'] ?? '—') ?></td></tr>
        <tr><td>Sector</td><td><?= e(($obs['site_name'] ? $obs['site_name'] . ' › ' : '') . $obs['sector_name']) ?></td></tr>
        <tr><td>Equipo</td><td><?= $obs['equipment_code'] ? e($obs['equipment_code'] . ' · ' . $obs['equipment_name']) : '—' ?></td></tr>
        <tr><td>Fecha del hecho</td><td><?= e(fecha($obs['created_at_device'], 'd/m/Y H:i')) ?></td></tr>
        <tr><td>Reportado por</td><td><?= $obs['is_anonymous'] ? 'Anónimo' : e($obs['reporter_name'] ?? '—') ?></td></tr>
        <?php if ($obs['assigned_name']): ?>
            <tr><td>Responsable de la acción</td><td><?= e($obs['assigned_name']) ?><?= $obs['action_due_on'] ? ' · compromiso ' . e(date('d/m/Y', strtotime($obs['action_due_on']))) : '' ?></td></tr>
            <tr><td>Acción</td><td><?= e($obs['action_text']) ?></td></tr>
        <?php endif; ?>
        <?php if ($people): ?><tr><td>Involucrados</td><td><?= e(implode(', ', array_column($people, 'name'))) ?></td></tr><?php endif; ?>
    </table>
    <h2>Descripción (reporte original)</h2>
    <p style="white-space: pre-wrap"><?= e($original['descripcion'] ?? $obs['description']) ?></p>
    <?php if (!empty($original['lugar'])): ?><p>Lugar: <?= e($original['lugar']) ?></p><?php endif; ?>
    <?php if ($photos): ?>
        <h2>Fotos</h2>
        <div class="photos"><?php foreach ($photos as $ph): ?><img src="<?= e(url('/panel/observaciones/' . $obs['uuid'] . '/fotos/' . $ph['uuid'] . '?t=1')) ?>" alt=""><?php endforeach; ?></div>
    <?php endif; ?>
    <h2>Línea de tiempo</h2>
    <table>
        <?php foreach ($events as $ev): ?>
            <tr><td><?= e(fecha($ev['created_at'], 'd/m/Y H:i')) ?> · <?= e($ev['actor_name']) ?></td>
                <td><?= e($ev['to_status'] ? ObservationWorkflow::label($ev['to_status']) : ucfirst($ev['type'])) ?><?= $ev['comment'] ? ': ' . e($ev['comment']) : '' ?></td></tr>
        <?php endforeach; ?>
    </table>
    <p class="hash">Integridad del reporte original (SHA-256): <?= e($obs['original_hash']) ?> · Recibido <?= e(fecha($obs['received_at'], 'd/m/Y H:i:s')) ?> · Impreso <?= e(fecha(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i')) ?></p>
</div>
</body>
</html>
