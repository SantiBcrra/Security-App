<div class="text-center py-5">
    <h1 class="display-6">Módulo no disponible</h1>
    <p class="lead">Este módulo todavía no está habilitado en esta base de datos.</p>
    <?php if ($admin): ?>
        <p>Aplicá las migraciones pendientes desde el panel de administración.</p>
        <a class="btn btn-primary" href="<?= e(url('/admin/migraciones')) ?>">Ir a migraciones</a>
    <?php else: ?>
        <p>Contactá al administrador para habilitarlo.</p>
        <a class="btn btn-outline-secondary" href="<?= e(url('/panel')) ?>">Volver al inicio</a>
    <?php endif; ?>
    <p class="small text-body-secondary mt-3">Referencia: <code><?= e($errorId) ?></code></p>
</div>
