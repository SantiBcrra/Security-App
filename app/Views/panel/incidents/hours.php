<?php
$months = array_map(fn ($m) => sprintf('%d-%02d', $year, $m), range(1, 12));
$monthName = ['01' => 'Ene', '02' => 'Feb', '03' => 'Mar', '04' => 'Abr', '05' => 'May', '06' => 'Jun', '07' => 'Jul', '08' => 'Ago', '09' => 'Sep', '10' => 'Oct', '11' => 'Nov', '12' => 'Dic'];
$fmt = fn ($v, int $dec = 0) => $v === null ? '—' : number_format((float) $v, $dec, ',', '.');
$t = $indicators['total'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/incidentes')) ?>">←</a>
        <h1 class="h4 m-0">Horas trabajadas e índices</h1>
    </div>
    <form class="d-flex gap-2" method="get">
        <select class="form-select form-select-sm" name="anio" onchange="this.form.submit()">
            <?php foreach (range((int) date('Y') + 1, (int) date('Y') - 5) as $y): ?><option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endforeach; ?>
        </select>
        <select class="form-select form-select-sm" name="planta" onchange="this.form.submit()"><option value="">Todas las plantas</option>
            <?php foreach ($sites as $s): ?><option value="<?= e($s['uuid']) ?>" <?= ($site['uuid'] ?? '') === $s['uuid'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
    </form>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Índice de frecuencia', $fmt($t['if'], 2), 'accidentes con baja por millón de horas'],
        ['Índice de gravedad', $fmt($t['ig'], 3), 'días perdidos cada mil horas' . ($t['provisional'] ? ' (incluye bajas abiertas)' : '')],
        ['Índice de incidencia', $fmt($t['ii'], 2), 'accidentes con baja cada mil trabajadores'],
        ['Accidentes con baja', $t['accidents'], $t['lost_days'] . ' días perdidos · ' . $t['itinere'] . ' in itinere aparte'],
    ] as [$label, $value, $help]): ?>
        <div class="col-6 col-lg-3"><div class="card shadow-sm h-100"><div class="card-body">
            <div class="h3 mb-0"><?= e($value) ?></div><div class="fw-semibold small"><?= e($label) ?></div><div class="small text-body-secondary"><?= e($help) ?></div>
        </div></div></div>
    <?php endforeach; ?>
</div>

<div class="card shadow-sm mb-3">
    <div class="card-header"><strong>Índices por mes</strong> <span class="small text-body-secondary">· <?= e($site['name'] ?? 'todas las plantas') ?></span></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0 text-end small">
        <thead><tr><th class="text-start">Mes</th><th>Horas</th><th>Dotación</th><th>Accid. con baja</th><th>Días perdidos</th><th>In itinere</th><th>IF</th><th>IG</th><th>II</th></tr></thead>
        <tbody>
        <?php foreach ($indicators['months'] as $m => $r): ?>
            <tr><td class="text-start"><?= e($monthName[substr($m, 5)]) ?></td><td><?= e($fmt($r['hours'] ?: null)) ?></td><td><?= e($fmt($r['headcount'])) ?></td>
                <td><?= e($r['accidents']) ?></td><td><?= e($r['lost_days']) ?><?= $r['provisional'] ? '*' : '' ?></td><td><?= e($r['itinere']) ?></td>
                <td><?= e($fmt($r['if'], 2)) ?></td><td><?= e($fmt($r['ig'], 3)) ?></td><td><?= e($fmt($r['ii'], 2)) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="card-footer small text-body-secondary">IF = accidentes con baja × 1.000.000 / horas · IG = días perdidos × 1.000 / horas · II = accidentes con baja × 1.000 / dotación.
        Los días perdidos se imputan al mes del accidente; * incluye bajas que siguen abiertas. Los in itinere se informan aparte.</div>
</div>

<form method="post" action="<?= e(url('/panel/incidentes/horas')) ?>" class="card shadow-sm">
    <?= csrf_field() ?>
    <input type="hidden" name="anio" value="<?= e($year) ?>">
    <div class="card-header"><strong>Carga de horas-hombre y dotación promedio</strong> <span class="small text-body-secondary">· por planta y mes (de liquidación de sueldos o fichadas)</span></div>
    <fieldset class="table-responsive" <?= $canEdit ? '' : 'disabled' ?>><table class="table table-sm align-middle mb-0 small">
        <thead><tr><th>Planta</th><?php foreach ($months as $m): ?><th class="text-center"><?= e($monthName[substr($m, 5)]) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($sites as $s): $h = $hours[(int) $s['id']] ?? []; ?>
            <tr><td class="text-nowrap"><?= e($s['name']) ?><div class="text-body-secondary">horas / dotación</div></td>
                <?php foreach ($months as $m): ?>
                    <td style="min-width: 86px">
                        <input class="form-control form-control-sm mb-1" name="h[<?= e($s['uuid']) ?>][<?= e($m) ?>][hours]" inputmode="decimal" value="<?= isset($h[$m]) ? e($fmt($h[$m]['hours'], 1)) : '' ?>" placeholder="horas">
                        <input class="form-control form-control-sm" name="h[<?= e($s['uuid']) ?>][<?= e($m) ?>][headcount]" inputmode="numeric" value="<?= e($h[$m]['headcount'] ?? '') ?>" placeholder="pers.">
                    </td>
                <?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$sites): ?><tr><td colspan="13" class="text-body-secondary">Cargá las plantas en Datos maestros.</td></tr><?php endif; ?>
        </tbody>
    </table></fieldset>
    <?php if ($canEdit && $sites): ?><div class="card-footer"><button class="btn btn-primary btn-sm">Guardar</button></div><?php endif; ?>
</form>
