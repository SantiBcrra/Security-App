<?php
/** Franja de un puesto: tramos (quién, desde/hasta, cómo terminó), huecos y cobertura. @var array $c (PostCoverage::detail) */
use App\Services\PostCoverage;
use App\Services\ShiftService;
[$label, $color] = PostCoverage::STATUS[$c['status']] ?? [$c['status'], 'light'];
$dur = fn (int $sec) => intdiv($sec, 3600) . ' h ' . str_pad((string) intdiv($sec % 3600, 60), 2, '0', STR_PAD_LEFT) . ' min';
$endLabel = ['relevo' => 'Lo relevaron', 'sin_persona' => 'Dejó el puesto sin persona', 'auto' => 'Cerrado por el sistema (no marcó el fin)', 'manual' => 'Terminó su turno'];
?>
<?= App\Core\View::render('panel/shifts/_subnav', ['sub' => 'hoy'], null) ?>
<a class="small d-inline-block mb-2" href="<?= e(url('/panel/rondas/turnos?fecha=' . fecha($c['start_at'], 'Y-m-d'))) ?>">← Turnos del día</a>
<div class="d-flex flex-wrap align-items-center gap-2 mb-1">
    <h2 class="h4 m-0"><?= e($c['shift_name'] ?: $c['site_name']) ?></h2>
    <span class="badge text-bg-<?= e($color) ?>"><?= e($label) ?></span>
</div>
<p class="text-body-secondary"><?= e($c['site_name']) ?> · franja del <?= e(fecha($c['start_at'], 'd/m H:i')) ?> al <?= e(fecha($c['end_at'], 'd/m H:i')) ?></p>

<div class="row g-2 mb-3">
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-2"><div class="small text-body-secondary">Cubierto</div>
        <div class="fs-4 fw-semibold"><?= $c['percent'] !== null ? (int) $c['percent'] . ' %' : '—' ?></div><div class="small text-body-secondary"><?= e($dur($c['covered'])) ?> de <?= e($dur($c['elapsed'])) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-2"><div class="small text-body-secondary">Guardias</div>
        <div class="fs-4 fw-semibold"><?= count(array_unique(array_column($c['segments'], 'user_id'))) ?></div><div class="small text-body-secondary"><?= count($c['segments']) ?> tramo(s)</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100 border-danger"><div class="card-body py-2"><div class="small text-body-secondary">Sin guardia</div>
        <div class="fs-4 fw-semibold text-danger"><?= e($dur(array_sum(array_map(fn ($g) => $g['justified'] ? 0 : $g['seconds'], $c['gaps'])))) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body py-2"><div class="small text-body-secondary">Sin persona (con motivo)</div>
        <div class="fs-4 fw-semibold"><?= e($dur(array_sum(array_map(fn ($g) => $g['justified'] ? $g['seconds'] : 0, $c['gaps'])))) ?></div></div></div></div>
</div>

<div class="card shadow-sm mb-3"><div class="card-body"><?= App\Core\View::render('panel/shifts/_coverage_bar', ['c' => $c], null) ?></div></div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold">Quién estuvo</div>
    <ul class="list-group list-group-flush">
        <?php
        // Línea de tiempo: tramos y huecos en orden.
        $items = [];
        foreach ($c['segments'] as $s) $items[] = ['at' => $s['started_at'], 'seg' => $s];
        foreach ($c['gaps'] as $g) $items[] = ['at' => $g['from'], 'gap' => $g];
        usort($items, fn ($a, $b) => strcmp($a['at'], $b['at']));
        foreach ($items as $it): ?>
            <?php if (isset($it['seg'])): $s = $it['seg']; $len = (($s['ended_at'] ? strtotime($s['ended_at'] . ' UTC') : time()) - strtotime($s['started_at'] . ' UTC')); ?>
                <li class="list-group-item d-flex flex-wrap justify-content-between gap-2">
                    <span><span class="badge text-bg-success">Guardia</span> <strong><?= e($s['user_name']) ?></strong>
                        · <?= e(fecha($s['started_at'], 'd/m H:i')) ?> a <?= $s['ended_at'] ? e(fecha($s['ended_at'], 'd/m H:i')) : '<strong>ahora</strong>' ?> (<?= e($dur(max(0, $len))) ?>)
                        <?= $s['start_inside'] !== null && (int) $s['start_inside'] === 0 ? ' <span class="badge text-bg-danger">empezó fuera del predio</span>' : '' ?>
                        <?php if ($s['ended_at']): ?><div class="small text-body-secondary"><?= e($endLabel[$s['end_mode']] ?? 'Terminó') ?><?= $s['end_note'] ? ': «' . e($s['end_note']) . '»' : '' ?></div><?php endif; ?>
                    </span>
                    <a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= e(url('/panel/rondas/turno/' . $s['uuid'])) ?>">Ver turno</a>
                </li>
            <?php else: $g = $it['gap']; ?>
                <li class="list-group-item <?= $g['justified'] ? 'bg-body-tertiary' : 'list-group-item-danger' ?>">
                    <span class="badge <?= $g['justified'] ? 'text-bg-secondary' : 'text-bg-danger' ?>"><?= $g['justified'] ? 'Sin persona' : 'Sin guardia' ?></span>
                    <?= e(fecha($g['from'], 'd/m H:i')) ?> a <?= e(fecha($g['to'], 'd/m H:i')) ?> (<?= e($dur($g['seconds'])) ?>)
                    <?= $g['justified'] ? ' · motivo: «' . e((string) $g['reason']) . '»' : ($g['after'] ? ' · después de ' . e($g['after']) : ' · nadie empezó') ?>
                </li>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$items): ?><li class="list-group-item text-body-secondary">Todavía no empezó la franja.</li><?php endif; ?>
    </ul>
</div>
