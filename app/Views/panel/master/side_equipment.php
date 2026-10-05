<div class="card shadow-sm text-center">
    <div class="card-header"><strong>Código QR</strong></div>
    <div class="card-body">
        <div x-data x-init="const qr = qrcode(0, 'M'); qr.addData($el.dataset.url); qr.make(); $el.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 2 });"
             data-url="<?= e($qrUrl) ?>" class="d-inline-block bg-white border rounded p-1"></div>
        <div class="fw-semibold mt-2"><?= e($row['code']) ?></div>
        <div class="small text-body-secondary text-break mb-3"><?= e($qrUrl) ?></div>
        <a class="btn btn-outline-primary btn-sm" target="_blank" href="<?= e(url('/panel/datos/equipos/etiquetas?equipos=' . $row['uuid'])) ?>">Imprimir etiqueta</a>
    </div>
</div>
<script src="<?= e(asset('js/qrcode.min.js')) ?>"></script>
