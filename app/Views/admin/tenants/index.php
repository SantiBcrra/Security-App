<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 m-0">Empresas</h1>
    <a class="btn btn-primary" href="<?= e(url('/admin/empresas/nueva')) ?>">Nueva empresa</a>
</div>

<?php if (!$tenants): ?>
    <div class="card shadow-sm"><div class="card-body text-center py-5 text-body-secondary">
        Todavía no hay empresas. Cada empresa tiene su propia base de datos.
    </div></div>
<?php else: ?>
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><th>Empresa</th><th>Estado</th><th>Base de datos</th><th>Migraciones</th><th>Alta</th></tr></thead>
                <tbody>
                <?php foreach ($tenants as $t):
                    $p = $pending['Empresa: ' . $t['name'] . ' (' . $t['db_name'] . ')'] ?? 0; ?>
                    <tr>
                        <td>
                            <a class="fw-semibold text-decoration-none" href="<?= e(url('/admin/empresas/' . $t['uuid'])) ?>"><?= e($t['name']) ?></a>
                            <div class="small text-body-secondary"><?= e($t['slug']) ?><?= $t['cuit'] ? ' · CUIT ' . e(App\Core\Cuit::format($t['cuit'])) : '' ?></div>
                        </td>
                        <td><?php $status = $t['status']; require BASE_PATH . '/app/Views/partials/tenant_status.php'; ?></td>
                        <td><code><?= e($t['db_name']) ?></code></td>
                        <td>
                            <?php if ($p === 'error'): ?><span class="badge text-bg-danger">sin conexión</span>
                            <?php elseif ($p > 0): ?><a class="badge text-bg-warning text-decoration-none" href="<?= e(url('/admin/migraciones')) ?>"><?= e($p) ?> pendiente(s)</a>
                            <?php else: ?><span class="badge text-bg-success">al día</span><?php endif; ?>
                        </td>
                        <td class="small"><?= e(fecha($t['created_at'], 'd/m/Y')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
