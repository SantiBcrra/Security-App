<?php /** Descarga pública de la app Android. @var ?array $release */ ?>
<div class="card shadow-sm mx-auto" style="max-width: 560px">
    <div class="card-body">
        <h1 class="h4 mb-1">App de Seguridad e Higiene</h1>
        <p class="text-body-secondary mb-3">Para celulares Android 8 o superior.</p>
        <?php if ($release === null): ?>
            <div class="alert alert-secondary mb-0">Todavía no hay una versión publicada. Consultá con el responsable de Seguridad e Higiene.</div>
        <?php else: ?>
            <div class="d-flex flex-wrap gap-4 align-items-center mb-3">
                <div id="qr" class="border rounded p-2 bg-white" style="width: 168px; height: 168px" data-url="<?= e($page) ?>" title="Escaneá con la cámara del celular"></div>
                <div class="flex-fill">
                    <div class="mb-2"><strong>Versión <?= e($release['version_name']) ?></strong>
                        <span class="text-body-secondary small">· <?= e(number_format((int) $release['size_bytes'] / 1048576, 1, ',', '.')) ?> MB · <?= e(fecha($release['created_at'], 'd/m/Y')) ?></span></div>
                    <a class="btn btn-warning btn-lg w-100 mb-2" href="<?= e(url('/descargas/android/' . $release['version_code'] . '.apk')) ?>">Descargar la app</a>
                    <div class="small text-body-secondary">Desde la computadora: escaneá el código con la cámara del celular.</div>
                </div>
            </div>
            <?php if ($release['notes']): ?><div class="small mb-3" style="white-space: pre-wrap"><strong>Novedades:</strong> <?= e($release['notes']) ?></div><?php endif; ?>
            <h2 class="h6 mt-3">Cómo instalarla</h2>
            <ol class="small mb-3">
                <li>Tocá <strong>Descargar la app</strong> y abrí el archivo cuando termine.</li>
                <li>Si Android avisa que el navegador no puede instalar apps, tocá <strong>Configuración</strong> → activá <strong>"Permitir de esta fuente"</strong> → volvé atrás.</li>
                <li>Tocá <strong>Instalar</strong>. Si aparece "Play Protect" o "app no verificada", tocá <strong>Más detalles → Instalar de todas formas</strong>: la app se distribuye directamente por tu empresa, no por Google Play.</li>
                <li>Abrila e ingresá con la <strong>empresa</strong>, tu <strong>email o DNI</strong> y tu <strong>contraseña</strong>.</li>
            </ol>
            <p class="small text-body-secondary mb-0">Las actualizaciones las avisa la misma app: no hace falta volver a esta página.</p>
        <?php endif; ?>
    </div>
</div>
<?php if ($release !== null): ?>
<script src="<?= e(asset('js/qrcode.min.js')) ?>"></script>
<script>
(function () {
    var el = document.getElementById('qr');
    var qr = qrcode(0, 'M'); qr.addData(el.dataset.url); qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
})();
</script>
<?php endif; ?>
