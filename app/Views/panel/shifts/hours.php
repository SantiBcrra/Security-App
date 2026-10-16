<?php
/** Reportes → Horas trabajadas. @var array $filters @var array $r @var array $guards @var array $sites @var bool $canExport */
use App\Services\ShiftReport;
use App\Services\ShiftService;
$h = fn (int $m) => ShiftReport::hours($m);
$t = $r['total'];
$qs = http_build_query($filters);
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'horas', 'subtitle' => 'Reportes › Horas trabajadas: inicio y fin real de cada turno'], null) ?>

<form method="get" class="card card-body shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input class="form-control" type="date" name="desde" value="<?= e($filters['desde']) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input class="form-control" type="date" name="hasta" value="<?= e($filters['hasta']) ?>"></div>
        <?php if ($guards): ?>
        <div class="col-md-3"><label class="form-label small">Guardia</label><select class="form-select" name="guardia"><option value="">Todos</option>
            <?php foreach ($guards as $g): ?><option value="<?= e($g['uuid']) ?>" <?= $filters['guardia'] === $g['uuid'] ? 'selected' : '' ?>><?= e($g['name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-md-3"><label class="form-label small">Predio</label><select class="form-select" name="predio"><option value="">Todos</option>
            <?php foreach ($sites as $p): ?><option value="<?= e($p['uuid']) ?>" <?= $filters['predio'] === $p['uuid'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary">Ver</button></div>
    </div>
</form>

<div class="row g-2 mb-3">
    <?php foreach ([['Turnos trabajados', $t['shifts'], ''], ['Horas reales', $h($t['worked']), 'programadas ' . $h($t['planned'])],
        ['Llegadas tarde', $t['late_count'], $t['late'] . ' min en total'], ['Tiempo fuera del predio', $h($t['out']), $t['exits'] . ' salida(s)'],
        ['No vinieron', $t['absent'], $t['justified'] . ' ausencias justificadas']] as [$title, $n, $hint]): ?>
        <div class="col-6 col-lg"><div class="card h-100"><div class="card-body py-2"><div class="small text-body-secondary"><?= e($title) ?></div>
            <div class="fs-4 fw-semibold"><?= e((string) $n) ?></div><div class="small text-body-secondary"><?= e($hint) ?></div></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center"><span class="fw-semibold">Por guardia</span>
        <?php if ($canExport): ?><span class="d-flex gap-2">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/turnos/horas/exportar?tipo=guardias&' . $qs)) ?>">CSV por guardia</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/turnos/horas/exportar?tipo=turnos&' . $qs)) ?>">CSV turno por turno</a></span><?php endif; ?></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Guardia</th><th class="text-end">Turnos</th><th class="text-end">Horas prog.</th><th class="text-end">Horas reales</th><th class="text-end">Tarde</th>
            <th class="text-end">Se fue antes</th><th class="text-end">Fuera del predio</th><th class="text-end">No vino</th><th class="text-end">Justificadas</th><th class="text-end">No prog.</th><th class="text-end">Sin marcar fin</th></tr></thead>
        <tbody>
        <?php foreach ($r['rows'] as $row): ?>
            <tr><td class="fw-semibold"><?= e($row['name']) ?></td><td class="text-end"><?= $row['shifts'] ?></td><td class="text-end"><?= $h($row['planned']) ?></td>
                <td class="text-end fw-semibold"><?= $h($row['worked']) ?></td>
                <td class="text-end"><?= $row['late_count'] ? $row['late_count'] . ' <span class="small text-body-secondary">(' . $row['late'] . ' min)</span>' : '—' ?></td>
                <td class="text-end"><?= $row['early_count'] ? $row['early_count'] . ' <span class="small text-body-secondary">(' . $row['early'] . ' min)</span>' : '—' ?></td>
                <td class="text-end"><?= $row['exits'] ? $h($row['out']) . ' <span class="small text-body-secondary">(' . $row['exits'] . ')</span>' : '—' ?></td>
                <td class="text-end <?= $row['absent'] ? 'text-danger fw-semibold' : '' ?>"><?= $row['absent'] ?: '—' ?></td>
                <td class="text-end"><?= $row['justified'] ?: '—' ?></td><td class="text-end"><?= $row['unscheduled'] ?: '—' ?></td><td class="text-end"><?= $row['no_end'] ?: '—' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$r['rows']): ?><tr><td colspan="11" class="text-body-secondary">No hay turnos en el período.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold">Turno por turno</div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Fecha</th><th>Guardia</th><th>Predio</th><th>Programado</th><th>Real</th><th class="text-end">Horas</th><th>Estado</th></tr></thead>
        <tbody>
        <?php foreach (array_reverse($r['shifts']) as $s): $m = ShiftReport::measure($s); [$label, $color] = ShiftService::label($s); ?>
            <tr role="button" onclick="if (!event.target.closest('a')) location.href='<?= e(url('/panel/rondas/turno/' . $s['uuid'])) ?>'">
                <td class="text-nowrap"><?= e(fecha($s['start_at'], 'd/m')) ?></td><td><?= e($s['user_name']) ?></td><td><?= e($s['site_name']) ?></td>
                <td class="text-nowrap"><?= e(fecha($s['start_at'], 'H:i') . ' – ' . fecha($s['end_at'], 'H:i')) ?></td>
                <td class="text-nowrap"><?= $s['started_at'] ? e(fecha($s['started_at'], 'H:i') . ' – ' . ($s['ended_at'] ? fecha($s['ended_at'], 'H:i') : '…')) : '—' ?>
                    <?= $m['late'] ? '<span class="badge text-bg-warning">+' . $m['late'] . '</span>' : '' ?></td>
                <td class="text-end"><?= $s['started_at'] ? $h($m['worked']) : '—' ?></td>
                <td><span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
