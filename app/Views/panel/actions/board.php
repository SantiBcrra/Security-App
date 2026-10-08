<?php
use App\Services\ActionWorkflow;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$list = fn (array $q) => url('/panel/acciones?' . http_build_query($q));
$days = fn ($v) => $v === null ? '—' : number_format((float) $v, 0, ',', '.') . ' d';
$totalOpen = array_sum(array_column($b['byPriority'], 'open'));
$totalLate = array_sum(array_column($b['byPriority'], 'late'));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/acciones')) ?>">←</a>
        <h1 class="h4 m-0">Tablero de acciones</h1>
    </div>
    <?php if (UserAuth::can('acciones', 'exportar')): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/acciones/exportar?estado=vencidas')) ?>">Exportar vencidas (CSV)</a>
    <?php endif; ?>
</div>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Abiertas', $totalOpen, $list(['estado' => 'pendientes']), 'primary'],
        ['Vencidas', $totalLate, $list(['estado' => 'vencidas']), $totalLate ? 'danger' : 'secondary'],
        ['Para verificar', (int) ($b['verify']['n'] ?? 0), $list(['estado' => 'cerrada']), 'warning'],
        ['Verificación atrasada', (int) ($b['verify']['late'] ?? 0), $list(['estado' => 'cerrada']), ($b['verify']['late'] ?? 0) ? 'danger' : 'secondary'],
    ] as [$label, $n, $href, $class]): ?>
        <div class="col-6 col-lg-3">
            <a class="card shadow-sm text-decoration-none border-<?= e($class) ?> h-100" href="<?= e($href) ?>">
                <div class="card-body"><div class="display-6 fw-semibold text-<?= e($class) ?>"><?= e($n) ?></div><div class="small text-body-secondary"><?= e($label) ?></div></div>
            </a>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header"><strong>Por sector</strong> <span class="small text-body-secondary">· acciones abiertas</span></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>Sector</th><th class="text-end">Abiertas</th><th class="text-end">Vencidas</th><th class="text-end">Atraso máx.</th><th class="text-end">Promedio</th></tr></thead>
                <tbody>
                <?php foreach ($b['bySector'] as $r): $s = $r['k'] !== null ? ($sectors[(int) $r['k']] ?? null) : null; ?>
                    <tr>
                        <td><?php if ($s): ?><a href="<?= e($list(['estado' => 'vencidas', 'sector' => $s['uuid']])) ?>"><?= e($s['label']) ?></a><?php else: ?><span class="text-body-secondary">Sin sector</span><?php endif; ?></td>
                        <td class="text-end"><?= e($r['open']) ?></td>
                        <td class="text-end <?= (int) $r['late'] ? 'text-danger fw-semibold' : '' ?>"><?= e((int) $r['late']) ?></td>
                        <td class="text-end"><?= e($days($r['max_late'])) ?></td>
                        <td class="text-end"><?= e($days($r['avg_late'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$b['bySector']): ?><tr><td colspan="5" class="text-body-secondary small">No hay acciones abiertas.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header"><strong>Por responsable</strong> <span class="small text-body-secondary">· acciones abiertas</span></div>
            <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>Responsable</th><th class="text-end">Abiertas</th><th class="text-end">Vencidas</th><th class="text-end">Atraso máx.</th><th class="text-end">Promedio</th></tr></thead>
                <tbody>
                <?php foreach ($b['byResponsible'] as $r): ?>
                    <tr>
                        <td><a href="<?= e($list(['estado' => 'pendientes', 'responsable' => $r['uuid']])) ?>"><?= e($r['name']) ?></a></td>
                        <td class="text-end"><?= e($r['open']) ?></td>
                        <td class="text-end <?= (int) $r['late'] ? 'text-danger fw-semibold' : '' ?>"><?= e((int) $r['late']) ?></td>
                        <td class="text-end"><?= e($days($r['max_late'])) ?></td>
                        <td class="text-end"><?= e($days($r['avg_late'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$b['byResponsible']): ?><tr><td colspan="5" class="text-body-secondary small">No hay acciones abiertas.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header"><strong>Abiertas por prioridad</strong></div>
            <ul class="list-group list-group-flush">
                <?php foreach (array_reverse(ActionWorkflow::PRIORITIES, true) as $k => $label): $r = $b['byPriority'][$k] ?? ['open' => 0, 'late' => 0]; ?>
                    <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= e($list(['estado' => 'pendientes', 'prioridad' => $k])) ?>">
                        <span><?= $priorityBadge($k) ?></span>
                        <span><?= e($r['open']) ?> abiertas<?= (int) $r['late'] ? ' · <span class="text-danger fw-semibold">' . e((int) $r['late']) . ' vencidas</span>' : '' ?></span>
                    </a>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header"><strong>Cierres de los últimos 90 días</strong></div>
            <div class="card-body">
                <?php $n = (int) ($b['closing']['n'] ?? 0); ?>
                <?php if ($n === 0): ?>
                    <p class="text-body-secondary small mb-0">Todavía no hay acciones cerradas en este período.</p>
                <?php else: ?>
                    <div class="row text-center">
                        <div class="col"><div class="h3 mb-0"><?= e($n) ?></div><div class="small text-body-secondary">cerradas</div></div>
                        <div class="col"><div class="h3 mb-0"><?= e($days($b['closing']['avg_days'])) ?></div><div class="small text-body-secondary">promedio hasta el cierre</div></div>
                        <div class="col"><div class="h3 mb-0"><?= e((int) round(100 * (int) $b['closing']['on_time'] / $n)) ?>%</div><div class="small text-body-secondary">cerradas a tiempo</div></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
