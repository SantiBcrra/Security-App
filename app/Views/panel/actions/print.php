<?php
use App\Models\Actions;
use App\Services\ActionWorkflow;
$tenant = App\Core\Tenant::current();
$logo = App\Controllers\Web\App\HomeController::logoUrl($tenant);
$num = Actions::format((int) $a['number']);
$labels = ['created' => 'Creada', 'started' => 'Tomada', 'updated' => 'Modificada', 'comment' => 'Comentario', 'evidence' => 'Evidencia agregada',
    'closed' => 'Cerrada', 'verified' => 'Verificada', 'rejected' => 'Cierre rechazado', 'cancelled' => 'Cancelada', 'migrated' => 'Migrada'];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title><?= e($num) ?> · <?= e($tenant['name']) ?></title>
    <style>
        body { font: 10.5pt/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #212529; margin: 0; background: #f1f3f5; }
        .page { max-width: 190mm; margin: 0 auto; background: #fff; padding: 14mm; }
        .toolbar { text-align: center; padding: 10px; } .toolbar button { font: inherit; padding: 6px 16px; border: 0; border-radius: 6px; background: #0d6efd; color: #fff; cursor: pointer; }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #212529; padding-bottom: 6px; margin-bottom: 10px; }
        h1 { font-size: 15pt; margin: 0; } h2 { font-size: 11pt; margin: 14px 0 6px; border-bottom: 1px solid #dee2e6; }
        table { width: 100%; border-collapse: collapse; } td { padding: 3px 6px; vertical-align: top; border-bottom: 1px solid #f1f3f5; } td:first-child { width: 32%; color: #6c757d; }
        .late { color: #dc3545; font-weight: 700; }
        .photos { display: flex; flex-wrap: wrap; gap: 6px; } .photos img { height: 45mm; border: 1px solid #dee2e6; }
        .hash { font-family: monospace; font-size: 7.5pt; color: #6c757d; word-break: break-all; }
        @media print { body { background: #fff; } .toolbar { display: none; } .page { padding: 0; } @page { size: A4; margin: 14mm; } }
    </style>
</head>
<body>
<div class="toolbar"><button onclick="window.print()">Imprimir o guardar como PDF</button></div>
<div class="page">
    <header>
        <div><h1>Acción <?= e($num) ?></h1><div><?= e($tenant['name']) ?></div></div>
        <?php if ($logo): ?><img src="<?= e($logo) ?>" alt="" style="height: 40px"><?php endif; ?>
    </header>
    <table>
        <tr><td>Acción</td><td><strong><?= e($a['title']) ?></strong></td></tr>
        <?php if ($a['description']): ?><tr><td>Detalle</td><td style="white-space: pre-wrap"><?= e($a['description']) ?></td></tr><?php endif; ?>
        <tr><td>Estado</td><td><?= e(ActionWorkflow::label($a['status'])) ?><?= ActionWorkflow::isOverdue($a, $today) ? ' <span class="late">· VENCIDA</span>' : '' ?></td></tr>
        <tr><td>Tipo · prioridad</td><td><?= e(ActionWorkflow::TYPES[$a['type']] ?? $a['type']) ?> · <?= e(ActionWorkflow::PRIORITIES[$a['priority']] ?? $a['priority']) ?></td></tr>
        <tr><td>Origen</td><td><?= e($origin['label']) ?></td></tr>
        <tr><td>Sector</td><td><?= e($a['sector_name'] ?? '—') ?></td></tr>
        <tr><td>Responsable</td><td><?= e($a['responsible_name']) ?></td></tr>
        <tr><td>Fecha límite</td><td><?= e(date('d/m/Y', strtotime($a['due_on']))) ?></td></tr>
        <tr><td>Creada</td><td><?= e(fecha($a['created_at'], 'd/m/Y')) ?> · <?= e($a['created_by_name'] ?? 'Sistema') ?></td></tr>
        <?php if ($a['closed_at']): ?>
            <tr><td>Cierre</td><td><?= e(fecha($a['closed_at'], 'd/m/Y')) ?> · <?= e($a['closed_by_name'] ?? '') ?><br><span style="white-space: pre-wrap"><?= e($a['closure_text']) ?></span></td></tr>
        <?php endif; ?>
        <?php if ($a['verified_at']): ?>
            <tr><td>Verificación de eficacia</td><td>Eficaz · <?= e(fecha($a['verified_at'], 'd/m/Y')) ?> · <?= e($a['verified_by_name'] ?? '') ?><?= $a['verification_text'] ? '<br>' . e($a['verification_text']) : '' ?></td></tr>
        <?php endif; ?>
    </table>
    <?php $images = array_filter($files, fn ($f) => str_starts_with($f['mime'], 'image/')); ?>
    <?php if ($files): ?>
        <h2>Evidencia</h2>
        <div class="photos"><?php foreach ($images as $f): ?><img src="<?= e(url('/panel/acciones/' . $a['uuid'] . '/archivos/' . $f['uuid'] . '?t=1')) ?>" alt=""><?php endforeach; ?></div>
        <?php foreach ($files as $f): ?>
            <div class="hash"><?= e(($f['kind'] === 'referencia' ? 'Referencia' : 'Evidencia intento ' . $f['cycle']) . ' · ' . ($f['original_name'] ?? $f['mime'])) ?> · SHA-256 <?= e($f['sha256']) ?></div>
        <?php endforeach; ?>
    <?php endif; ?>
    <h2>Línea de tiempo</h2>
    <table>
        <?php foreach ($events as $ev): ?>
            <tr><td><?= e(fecha($ev['created_at'], 'd/m/Y H:i')) ?> · <?= e($ev['actor_name']) ?></td>
                <td><?= e($labels[$ev['type']] ?? $ev['type']) ?><?= $ev['comment'] ? ': ' . e($ev['comment']) : '' ?></td></tr>
        <?php endforeach; ?>
    </table>
    <p class="hash">Impreso <?= e(fecha(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i')) ?></p>
</div>
</body>
</html>
