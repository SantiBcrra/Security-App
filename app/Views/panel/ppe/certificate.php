<?php
use App\Core\Cuit;
use App\Models\Ppe;
$docTitle = 'Constancia de entrega de EPP · ' . $e['name'];
$v = fn ($x) => ($x === null || $x === '') ? '<span class="empty">(completar)</span>' : e((string) $x);
require __DIR__ . '/../incidents/_print_head.php';
$rows = [];
foreach ($deliveries as $d) {
    foreach ($d['items'] as $i) {
        $rows[] = [$d, $i];
    }
}
?>
<style>
    .grid th, .grid td { border: 1px solid #adb5bd; padding: 3px 4px; font-size: 8.5pt; vertical-align: middle; }
    .grid th { background: #f1f3f5; font-weight: 600; text-align: center; }
    .grid img { height: 28px; max-width: 100%; display: block; margin: 0 auto; }
    .grid .c { text-align: center; }
</style>
<div class="page">
    <header><div><h1>Constancia de entrega de ropa de trabajo y elementos de protección personal</h1><div>Resolución SRT N° 299/11</div></div></header>
    <table>
        <tr><td class="k">Razón social</td><td><?= $v($tenant['legal_name'] ?: $tenant['name']) ?></td><td class="k">CUIT</td><td><?= $v($tenant['cuit'] ? Cuit::format($tenant['cuit']) : null) ?></td></tr>
        <tr><td class="k">Dirección</td><td colspan="3"><?= $v($company['domicilio']) ?></td></tr>
        <tr><td class="k">Nombre y apellido del trabajador</td><td><?= e($e['first_name'] . ' ' . $e['last_name']) ?></td><td class="k">DNI</td><td><?= e($e['dni']) ?></td></tr>
        <tr><td class="k">Descripción breve del puesto</td><td colspan="3"><?= $v($e['position_name']) ?><?= $e['sector_name'] ? ' · ' . e($e['sector_name']) : '' ?></td></tr>
        <tr><td class="k">Elementos de protección personal necesarios para el trabajador, según el puesto</td>
            <td colspan="3"><?= $required ? e(implode(', ', array_map(fn ($r) => $r['item']['name'] . ($r['mandatory'] ? '' : ' (según tarea)'), $required))) : $v(null) ?></td></tr>
    </table>
    <table class="grid" style="margin-top: 8px">
        <thead><tr><th>#</th><th>Producto</th><th>Tipo / modelo</th><th>Marca</th><th>Posee certificación (sí / no)</th><th>Cantidad</th><th>Fecha de entrega</th><th style="width: 28mm">Firma del trabajador</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $n => [$d, $i]): ?>
            <tr>
                <td class="c"><?= $n + 1 ?></td>
                <td><?= e($i['item_name']) ?><?= $i['size'] ? ' (talle ' . e($i['size']) . ')' : '' ?></td>
                <td><?= e($i['model'] ?? '') ?></td>
                <td><?= e($i['brand'] ?? '') ?></td>
                <td class="c"><?= (int) $i['certified'] ? 'Sí' . ($i['certification'] ? ' · ' . e($i['certification']) : '') : 'No' ?></td>
                <td class="c"><?= e($i['quantity']) ?></td>
                <td class="c"><?= e(fecha($d['delivered_at'], 'd/m/Y')) ?></td>
                <td class="c"><?php if ($d['signature_mode'] === 'pantalla'): ?><img src="<?= e(url('/panel/epp/entregas/' . $d['uuid'] . '/firma')) ?>" alt="Firma"><?php else: ?><span style="font-size: 7.5pt">Planilla en papel (<?= e(Ppe::format((int) $d['number'])) ?>)</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="c empty">Sin entregas en el período.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <h2>Información adicional</h2>
    <p class="note">El trabajador declara haber recibido los elementos indicados, en buen estado, y haber sido capacitado en su uso y conservación.
        Entregas registradas en el sistema con firma del trabajador (SHA-256 de cada firma guardado) por: <?= e(implode(', ', array_unique(array_filter(array_column($deliveries, 'delivered_by_name'))))) ?: '—' ?>.</p>
    <div class="sign"><div>Firma y aclaración del responsable de la empresa</div><div>Firma del trabajador</div></div>
    <p class="hash">Impreso <?= e(fecha(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i')) ?> · entregas: <?= e(implode(', ', array_map(fn ($d) => Ppe::format((int) $d['number']), $deliveries))) ?: '—' ?> (anuladas excluidas)</p>
</div>
</body>
</html>
