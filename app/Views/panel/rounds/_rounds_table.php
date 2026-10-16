<?php
/** Tabla de rondas (Hoy y Rondas realizadas). @var array $rows (Patrols::searchRounds) @var string $empty */
$state = ['completa' => ['Completa', 'success'], 'incompleta' => ['Incompleta', 'danger'], 'en_curso' => ['En curso', 'warning']];
$dur = function (array $r): string {
    $end = $r['finished_at'] ? strtotime($r['finished_at'] . ' UTC') : time();
    $m = intdiv(max(0, $end - strtotime($r['started_at'] . ' UTC')), 60);
    return $m >= 60 ? intdiv($m, 60) . ' h ' . ($m % 60) . ' min' : $m . ' min';
};
?>
<div class="table-responsive"><table class="table table-hover align-middle">
    <thead class="small text-body-secondary"><tr><th>Fecha</th><th>Horario</th><th>Duración</th><th>Guardia</th><th>Ruta</th><th>Estado</th><th>Puntos</th><th>Ubicación</th><th>Alertas</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): [$label, $class] = $state[$r['status']] ?? [$r['status'], 'secondary']; $late = $r['expected_minutes'] && $r['finished_at']
        && (strtotime($r['finished_at'] . ' UTC') - strtotime($r['started_at'] . ' UTC')) / 60 > (int) $r['expected_minutes']; ?>
        <tr role="button" onclick="if (!event.target.closest('a')) location.href='<?= e(url('/panel/rondas/ronda/' . $r['uuid'])) ?>'">
            <td class="text-nowrap"><?= e(fecha($r['started_at'], 'd/m/Y')) ?></td>
            <td class="text-nowrap"><?= e(fecha($r['started_at'], 'H:i')) ?> – <?= $r['finished_at'] ? e(fecha($r['finished_at'], 'H:i')) : '…' ?></td>
            <td class="text-nowrap <?= $late ? 'text-danger' : '' ?>"><?= e($dur($r)) ?><?= $late ? ' <span class="small">(esperados ' . (int) $r['expected_minutes'] . ')</span>' : '' ?></td>
            <td><?= e($r['user_name']) ?></td>
            <td><?= $r['route_name'] ? e($r['route_name']) : '<span class="text-body-secondary">Ronda libre</span>' ?></td>
            <td><span class="badge text-bg-<?= e($class) ?>"><?= e($label) ?></span></td>
            <td class="text-nowrap"><?= (int) $r['scans_count'] ?><?= $r['route_id'] ? ' / ' . (int) $r['route_points'] : '' ?><?= (int) ($r['out_of_order_count'] ?? 0) ? ' <span class="badge text-bg-warning" title="Marcas fuera de orden">' . (int) $r['out_of_order_count'] . ' orden</span>' : '' ?></td>
            <td class="text-nowrap"><?php if ((int) $r['outside_count']): ?><span class="badge text-bg-danger"><?= (int) $r['outside_count'] ?> fuera</span><?php endif; ?>
                <?php if ((int) $r['doubtful_count']): ?><span class="badge text-bg-warning"><?= (int) $r['doubtful_count'] ?> dudosa(s)</span><?php endif; ?>
                <?php if (!(int) $r['outside_count'] && !(int) $r['doubtful_count']): ?><?= (int) $r['scans_count'] ? '<span class="text-success">OK</span>' : '—' ?><?php endif; ?></td>
            <td><?= (int) ($r['alerts_count'] ?? 0) ? '<span class="badge text-bg-danger">' . (int) $r['alerts_count'] . '</span>' : '<span class="text-body-secondary">—</span>' ?></td>
            <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/rondas/ronda/' . $r['uuid'])) ?>">Ver detalle</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="10" class="text-body-secondary py-4 text-center"><?= e($empty ?? 'No hay rondas.') ?></td></tr><?php endif; ?>
    </tbody>
</table></div>
