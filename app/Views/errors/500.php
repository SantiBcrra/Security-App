<div class="text-center py-5">
    <h1 class="display-5">Algo salió mal</h1>
    <p class="lead">Ocurrió un error inesperado. Si se repite, avisá al administrador con esta referencia:</p>
    <p><code class="fs-5"><?= e($errorId) ?></code></p>
    <a class="btn btn-primary" href="<?= e(url('/')) ?>">Volver al inicio</a>
</div>
<?php if ($debug && $error): ?>
    <div class="alert alert-danger text-start">
        <strong><?= e(get_class($error)) ?>:</strong> <?= e($error->getMessage()) ?><br>
        <small><?= e($error->getFile()) ?>:<?= e($error->getLine()) ?></small>
        <pre class="mt-2 mb-0 small"><?= e($error->getTraceAsString()) ?></pre>
    </div>
<?php endif; ?>
