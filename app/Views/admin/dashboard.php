<h1 class="h4 mb-3">Tablero de la plataforma</h1>

<?php if ($pending > 0): ?>
    <div class="alert alert-warning d-flex justify-content-between align-items-center">
        <span>Hay <strong><?= e($pending) ?></strong> migración(es) pendiente(s) de aplicar.</span>
        <a class="btn btn-warning btn-sm" href="<?= e(url('/admin/migraciones')) ?>">Actualizar base de datos</a>
    </div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <table class="table table-sm mb-0">
            <tr><th class="w-50">Versión del sistema</th><td><?= e(config('app.version')) ?></td></tr>
            <tr><th>PHP</th><td><?= e($phpVersion) ?></td></tr>
            <tr><th>Base maestra</th><td><?= e($dbVersion) ?></td></tr>
            <tr><th>Entorno</th><td><?= e($env) ?><?= $debug ? ' <span class="badge text-bg-warning">debug</span>' : '' ?></td></tr>
            <tr><th>Hora del servidor (UTC)</th><td><?= e($nowUtc) ?></td></tr>
            <tr><th>Hora local (<?= e(config('app.timezone')) ?>)</th><td><?= e(fecha($nowUtc, 'd/m/Y H:i:s')) ?></td></tr>
            <tr><th>Migraciones pendientes</th><td><?= $pending > 0 ? '<span class="badge text-bg-warning">' . e($pending) . '</span>' : '<span class="badge text-bg-success">al día</span>' ?></td></tr>
        </table>
    </div>
</div>
