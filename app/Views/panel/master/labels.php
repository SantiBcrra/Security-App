<?php $tenant = App\Core\Tenant::current(); ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiquetas QR · <?= e($tenant['name']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f1f3f5; }
        .toolbar { padding: 12px 16px; display: flex; gap: 12px; align-items: center; background: #fff; border-bottom: 1px solid #dee2e6; }
        .toolbar button { font: inherit; padding: 6px 14px; border: 0; border-radius: 6px; background: #0d6efd; color: #fff; cursor: pointer; }
        .sheet { display: grid; grid-template-columns: repeat(3, 1fr); gap: 4mm; padding: 10mm; max-width: 210mm; margin: 0 auto; background: #fff; }
        .label { border: 1px dashed #adb5bd; border-radius: 3mm; padding: 3mm; text-align: center; break-inside: avoid; }
        .label .qr svg { width: 100%; height: auto; max-width: 42mm; }
        .label .code { font-weight: 700; font-size: 13pt; margin-top: 1mm; }
        .label .name { font-size: 8.5pt; color: #495057; }
        .label .tenant { font-size: 7pt; color: #868e96; margin-top: 1mm; }
        @media print { .toolbar { display: none; } body { background: #fff; } .sheet { padding: 0; } @page { size: A4; margin: 10mm; } }
    </style>
    <script src="<?= e(asset('js/qrcode.min.js')) ?>"></script>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">Imprimir</button>
    <span><?= e(count($items)) ?> etiqueta(s) · A4, 3 por fila. Escaneá con la cámara del celular para abrir la ficha del equipo.</span>
</div>
<div class="sheet">
    <?php foreach ($items as $item): ?>
        <div class="label">
            <div class="qr" data-url="<?= e($item['url']) ?>"></div>
            <div class="code"><?= e($item['code']) ?></div>
            <div class="name"><?= e($item['name']) ?></div>
            <div class="tenant"><?= e($tenant['name']) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<script>
document.querySelectorAll('.qr').forEach(function (el) {
    var qr = qrcode(0, 'M'); qr.addData(el.dataset.url); qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 1 });
});
</script>
</body>
</html>
