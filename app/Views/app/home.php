<?php $me = App\Services\UserAuth::user(); ?>
<h1 class="h4 mb-3"><?= $me ? 'Hola, ' . e(explode(' ', $me['name'])[0]) : 'Inicio' ?></h1>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th class="w-50">Empresa</th><td><?= e($tenant['name']) ?></td></tr>
                    <?php if ($tenant['legal_name']): ?><tr><th>Razón social</th><td><?= e($tenant['legal_name']) ?></td></tr><?php endif; ?>
                    <?php if ($tenant['cuit']): ?><tr><th>CUIT</th><td><?= e(App\Core\Cuit::format($tenant['cuit'])) ?></td></tr><?php endif; ?>
                    <tr><th>Estado</th><td><?php $status = $tenant['status']; require BASE_PATH . '/app/Views/partials/tenant_status.php'; ?></td></tr>
                    <tr><th>Zona horaria</th><td><?= e($tenant['timezone']) ?></td></tr>
                    <tr><th>Hora local</th><td><?= e(fecha(gmdate('Y-m-d H:i:s'), 'd/m/Y H:i')) ?></td></tr>
                    <?php if ($me): ?><tr><th>Tu rol</th><td><?= e($me['role_name']) ?></td></tr><?php endif; ?>
                    <?php if ($dbName !== null): ?><tr><th>Base conectada</th><td><code><?= e($dbName) ?></code></td></tr><?php endif; ?>
                </table>
            </div>
        </div>
        <p class="small text-body-secondary mt-3">Los módulos (observaciones, inspecciones, etc.) se agregan en las próximas etapas.</p>
    </div>
    <?php if ($events !== null): ?>
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Últimos eventos de la empresa</strong></div>
            <?php require BASE_PATH . '/app/Views/partials/audit_table.php'; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
