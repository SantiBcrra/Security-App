<?php $me = App\Services\UserAuth::user(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0"><?= $me ? 'Hola, ' . e(explode(' ', $me['name'])[0]) : 'Inicio' ?></h1>
    <?php if (App\Services\UserAuth::can('observaciones', 'crear')): ?>
        <a class="btn btn-primary" href="<?= e(url('/panel/observaciones/nueva')) ?>">+ Reportar observación</a>
    <?php endif; ?>
</div>
<?php if ($obs !== null): require BASE_PATH . '/app/Views/panel/observations/_badges.php'; ?>
    <div class="row g-3 mb-3">
        <?php foreach ([
            ['Pendientes', $obs['pending'], '/panel/observaciones?estado=pendientes', 'primary'],
            ['Riesgo inminente sin cerrar', $obs['imminent'], '/panel/observaciones?estado=pendientes&inminente=1', $obs['imminent'] ? 'danger' : 'secondary'],
            ['Asignadas a mí', $obs['assigned'], '/panel/observaciones?estado=pendientes&asignadas=1', 'warning'],
            ['Mis reportes', $obs['mine'], '/panel/observaciones', 'success'],
        ] as [$label, $n, $href, $class]): ?>
            <div class="col-6 col-lg-3">
                <a class="card shadow-sm text-decoration-none border-<?= e($class) ?> h-100" href="<?= e(url($href)) ?>">
                    <div class="card-body"><div class="display-6 fw-semibold text-<?= e($class) ?>"><?= e($n) ?></div><div class="small text-body-secondary"><?= e($label) ?></div></div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($obs['latest']): ?>
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between"><strong>Últimas observaciones</strong><a class="small" href="<?= e(url('/panel/observaciones')) ?>">Ver todas</a></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($obs['latest'] as $r): ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2" href="<?= e(url('/panel/observaciones/' . $r['uuid'])) ?>">
                        <span><span class="fw-semibold"><?= e(App\Models\Observations::format((int) $r['number'])) ?></span>
                            <?= $r['imminent_risk'] ? $imminentBadge : '' ?>
                            <span class="small text-body-secondary"><?= e(mb_strimwidth($r['description'], 0, 80, '…')) ?></span></span>
                        <span class="text-nowrap"><?= $severityBadge($r['severity_name'], $r['severity_color']) ?> <?= $statusBadge($r['status']) ?></span>
                    </a>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
<?php endif; ?>
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
