<?php $totalPending = array_sum(array_map(fn ($s) => count($s['pending']), $status)); ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Base de datos</h1>
    <form method="post" action="<?= e(url('/admin/migraciones')) ?>" x-data="{ busy: false }" @submit="busy = true">
        <?= csrf_field() ?>
        <button class="btn <?= $totalPending ? 'btn-warning' : 'btn-outline-secondary' ?>" :disabled="busy">
            <span x-show="busy" class="spinner-border spinner-border-sm me-1"></span>
            Actualizar base de datos<?= $totalPending ? ' (' . e($totalPending) . ')' : '' ?>
        </button>
    </form>
</div>
<p class="text-body-secondary small">Después de cada deploy, este botón aplica las migraciones nuevas en la base maestra y en la base de cada empresa.</p>

<?php foreach ($status as $db): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex justify-content-between">
            <strong><?= e($db['label']) ?></strong>
            <span><?= count($db['pending']) ? '<span class="badge text-bg-warning">' . count($db['pending']) . ' pendiente(s)</span>' : '<span class="badge text-bg-success">al día</span>' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Migración</th><th>Lote</th><th>Duración</th><th>Aplicada (hora local)</th></tr></thead>
                <tbody>
                <?php foreach ($db['pending'] as $file): ?>
                    <tr class="table-warning"><td><code><?= e($file) ?></code></td><td colspan="3">pendiente</td></tr>
                <?php endforeach; ?>
                <?php foreach (array_reverse($db['applied']) as $row): ?>
                    <tr>
                        <td><code><?= e($row['migration']) ?></code></td>
                        <td><?= e($row['batch']) ?></td>
                        <td><?= e($row['duration_ms']) ?> ms</td>
                        <td><?= e(fecha($row['applied_at'], 'd/m/Y H:i:s')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>
