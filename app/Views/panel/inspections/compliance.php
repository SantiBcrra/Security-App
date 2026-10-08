<?php
use App\Controllers\Web\Panel\InspectionsController as C;
use App\Services\UserAuth;
$pct = C::percent($total);
$labels = ['program' => 'Programa', 'sector' => 'Sector', 'equipment' => 'Equipo', 'inspector' => 'Inspector'];
$q = fn (array $over) => '?' . http_build_query(array_merge(['mes' => $month, 'por' => $group], $over));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/programadas')) ?>">←</a>
        <h1 class="h4 m-0">Cumplimiento de inspecciones</h1>
    </div>
    <form class="d-flex gap-2" method="get">
        <input type="hidden" name="por" value="<?= e($group) ?>">
        <select class="form-select form-select-sm" name="mes" onchange="this.form.submit()">
            <?php foreach ($months as $m): ?><option value="<?= e($m) ?>" <?= $m === $month ? 'selected' : '' ?>><?= e(date('m/Y', strtotime($m . '-01'))) ?></option><?php endforeach; ?>
        </select>
        <?php if (UserAuth::can('inspecciones', 'exportar')): ?><a class="btn btn-sm btn-outline-secondary text-nowrap" href="<?= e($q(['csv' => '1'])) ?>">CSV</a><?php endif; ?>
    </form>
</div>
<div class="row g-3 mb-3">
    <?php foreach ([
        ['Cumplimiento', $pct === null ? '—' : $pct . '%', $pct === null ? 'secondary' : ($pct >= 90 ? 'success' : ($pct >= 70 ? 'warning' : 'danger'))],
        ['Hechas a tiempo', $total['on_time'], 'success'], ['Hechas tarde', $total['late'], 'warning'],
        ['No hechas', $total['missed'], $total['missed'] ? 'danger' : 'secondary'], ['Omitidas', $total['skipped'], 'secondary'],
    ] as [$label, $n, $class]): ?>
        <div class="col-6 col-lg"><div class="card shadow-sm border-<?= e($class) ?> h-100"><div class="card-body">
            <div class="h3 mb-0 text-<?= e($class) ?>"><?= e($n) ?></div><div class="small text-body-secondary"><?= e($label) ?></div></div></div></div>
    <?php endforeach; ?>
</div>
<p class="small text-body-secondary">Cumplimiento = hechas a tiempo / programadas que ya vencieron (las omitidas con motivo no cuentan). Las de días que todavía no llegaron no se cuentan.</p>
<ul class="nav nav-tabs mb-0">
    <?php foreach ($labels as $k => $l): ?><li class="nav-item"><a class="nav-link <?= $group === $k ? 'active' : '' ?>" href="<?= e($q(['por' => $k])) ?>">Por <?= e(mb_strtolower($l)) ?></a></li><?php endforeach; ?>
</ul>
<div class="card shadow-sm border-top-0 rounded-top-0">
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th><?= e($labels[$group]) ?></th><th class="text-end">Programadas</th><th class="text-end">A tiempo</th><th class="text-end">Tarde</th><th class="text-end">No hechas</th><th class="text-end">Omitidas</th><th style="width: 30%">Cumplimiento</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $p = C::percent($r); ?>
            <tr>
                <td><?= e($r['label'] ?? 'Sin dato') ?></td>
                <td class="text-end"><?= e($r['total']) ?></td><td class="text-end"><?= e($r['on_time']) ?></td><td class="text-end"><?= e($r['late']) ?></td>
                <td class="text-end <?= (int) $r['missed'] ? 'text-danger fw-semibold' : '' ?>"><?= e($r['missed']) ?></td><td class="text-end"><?= e($r['skipped']) ?></td>
                <td><?php if ($p !== null): ?><div class="d-flex align-items-center gap-2"><div class="progress flex-grow-1" style="height: 8px">
                    <div class="progress-bar bg-<?= $p >= 90 ? 'success' : ($p >= 70 ? 'warning' : 'danger') ?>" style="width: <?= (int) $p ?>%"></div></div><span class="small"><?= e($p) ?>%</span></div>
                    <?php else: ?><span class="small text-body-secondary">—</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="7" class="small text-body-secondary">No hay inspecciones programadas en este período.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
