<div class="text-center py-5">
    <?php if (!empty($moduleOff)): ?>
        <h1 class="display-6">Módulo desactivado</h1>
        <p class="lead">La empresa todavía no usa esta herramienta.</p>
        <?php if (!empty($canConfigure)): ?><p><a class="btn btn-outline-primary" href="<?= e(url('/panel/configuracion#modulos')) ?>">Activarlo en Configuración → Módulos</a></p><?php endif; ?>
    <?php else: ?>
        <h1 class="display-6">Sin permiso</h1>
        <p class="lead">Tu rol no tiene acceso a esta sección.</p>
    <?php endif; ?>
    <a class="btn btn-primary" href="<?= e(url('/panel')) ?>">Volver al inicio</a>
</div>
