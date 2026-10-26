<?php
/** Reportes → Cobertura de puestos. @var array $filters @var array $posts (PostCoverage::report) @var array $sites @var bool $canExport */
$f = $filters;
$h = fn (int $sec) => number_format($sec / 3600, 1, ',', '.') . ' h';
$query = http_build_query(['desde' => $f['desde'], 'hasta' => $f['hasta'], 'predio' => $f['predio']]);
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'cobertura', 'subtitle' => 'Horas que había que cubrir en cada puesto contra las que se cubrieron'], null) ?>
<form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input class="form-control form-control-sm" type="date" name="desde" value="<?= e($f['desde']) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input class="form-control form-control-sm" type="date" name="hasta" value="<?= e($f['hasta']) ?>"></div>
    <div class="col-12 col-md-3"><label class="form-label small">Predio</label>
        <select class="form-select form-select-sm" name="predio"><option value="">Todos</option>
            <?php foreach ($sites as $p): ?><option value="<?= e($p['uuid']) ?>" <?= $f['predio'] === $p['uuid'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Ver</button></div>
    <?php if ($canExport): ?><div class="col-auto ms-auto"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/turnos/cobertura?' . $query . '&exportar=1')) ?>">Exportar CSV</a></div><?php endif; ?>
</form>

<?php foreach ($posts as $p): $pct = $p['scheduled'] > 0 ? (int) round(100 * $p['covered'] / $p['scheduled']) : null; ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between gap-2">
            <span><strong><?= e($p['name'] ?: $p['site_name']) ?></strong> <span class="text-body-secondary small">· <?= e($p['site_name']) ?></span></span>
            <span class="small">Cubierto <strong><?= $pct !== null ? $pct . ' %' : '—' ?></strong> · <?= e($h($p['covered'])) ?> de <?= e($h($p['scheduled'])) ?>
                · <span class="text-danger">sin guardia <?= e($h($p['unjustified'])) ?></span> · sin persona con motivo <?= e($h($p['justified'])) ?>
                <?= $p['sin_cubrir'] ? ' · <span class="text-danger">' . (int) $p['sin_cubrir'] . ' franja(s) sin nadie</span>' : '' ?></span>
        </div>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0">
            <thead><tr><th>Franja</th><th style="min-width: 220px">Cobertura</th><th>Cubierto</th><th>Guardias</th></tr></thead>
            <tbody>
            <?php foreach ($p['rows'] as $c): ?>
                <tr role="button" onclick="location.href='<?= e(url('/panel/rondas/turnos/puesto/' . $c['uuid'])) ?>'">
                    <td class="text-nowrap small"><?= e(App\Services\ShiftPlanner::DAYS[(int) fecha($c['start_at'], 'N')] . ' ' . fecha($c['start_at'], 'd/m H:i')) ?> a <?= e(fecha($c['end_at'], 'd/m H:i')) ?></td>
                    <td><?= App\Core\View::render('panel/shifts/_coverage_bar', ['c' => $c], null) ?></td>
                    <td><?= $c['percent'] !== null ? (int) $c['percent'] . ' %' : '—' ?></td>
                    <td class="small"><?= e(implode(', ', array_unique(array_map(fn ($s) => $s['user_name'], $c['segments']))) ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
<?php endforeach; ?>
<?php if (!$posts): ?>
    <div class="alert alert-light">No hay puestos con franjas en este período. Los puestos se cargan en Programación de turnos → "Un puesto que cubre un grupo".</div>
<?php endif; ?>
<p class="small text-body-secondary">Las horas de cada guardia (para liquidar o controlar a la empresa de seguridad) están en <a href="<?= e(url('/panel/rondas/turnos/horas?desde=' . $f['desde'] . '&hasta=' . $f['hasta'])) ?>">Horas trabajadas</a>.</p>
