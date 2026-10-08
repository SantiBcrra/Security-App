<?php
use App\Models\Actions;
use App\Services\ActionWorkflow;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$query = fn (array $over) => '?' . http_build_query(array_filter(array_merge($form, $over), fn ($v) => $v !== null) + ['estado' => '']);
$tabs = ['pendientes' => ['Pendientes', ($byStatus['abierta'] ?? 0) + ($byStatus['en_curso'] ?? 0)], 'vencidas' => ['Vencidas', $byStatus['vencidas']],
    'cerrada' => ['A verificar', $byStatus['cerrada'] ?? 0], 'verificada' => ['Verificadas', $byStatus['verificada'] ?? 0],
    'cancelada' => ['Canceladas', $byStatus['cancelada'] ?? 0], '' => ['Todas', array_sum(array_diff_key($byStatus, ['vencidas' => 1]))]];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0">Acciones correctivas y preventivas</h1>
    <div class="d-flex gap-2">
        <?php if (UserAuth::can('acciones', 'ver')): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/acciones/tablero')) ?>">Tablero</a>
        <?php endif; ?>
        <?php if (UserAuth::can('acciones', 'exportar')): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/acciones/exportar' . $query([]))) ?>">Exportar CSV</a>
        <?php endif; ?>
        <a class="btn btn-sm <?= $form['mias'] === '1' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e($query(['mias' => $form['mias'] === '1' ? '' : '1'])) ?>">Mis acciones</a>
        <?php if (UserAuth::can('acciones', 'crear')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/panel/acciones/nueva')) ?>">+ Nueva acción</a>
        <?php endif; ?>
    </div>
</div>

<ul class="nav nav-pills small mb-3 flex-nowrap overflow-auto">
    <?php foreach ($tabs as $key => [$label, $n]): ?>
        <li class="nav-item"><a class="nav-link py-1 <?= $form['estado'] === (string) $key ? 'active' : '' ?> <?= $key === 'vencidas' && $n > 0 && $form['estado'] !== 'vencidas' ? 'text-danger' : '' ?>"
            href="<?= e($query(['estado' => (string) $key])) ?>"><?= e($label) ?> <span class="badge text-bg-light"><?= e($n) ?></span></a></li>
    <?php endforeach; ?>
</ul>

<form class="row g-2 align-items-end mb-3" method="get">
    <input type="hidden" name="estado" value="<?= e($form['estado']) ?>">
    <input type="hidden" name="mias" value="<?= e($form['mias']) ?>">
    <div class="col-12 col-md-3"><input class="form-control form-control-sm" type="search" name="q" value="<?= e($form['q']) ?>" placeholder="Buscar texto o número (ACC-…)"></div>
    <?php if ($form['mias'] !== '1'): ?>
    <div class="col-6 col-md-2">
        <select class="form-select form-select-sm" name="responsable"><option value="">Responsable</option>
            <?php foreach ($users as $u): ?><option value="<?= e($u['uuid']) ?>" <?= $form['responsable'] === $u['uuid'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="col-6 col-md-3">
        <select class="form-select form-select-sm" name="sector"><option value="">Sector (incluye los de abajo)</option>
            <?php foreach ($sectors as $u => $n): ?><option value="<?= e($u) ?>" <?= $form['sector'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <select class="form-select form-select-sm" name="origen"><option value="">Origen</option>
            <?php foreach (ActionWorkflow::ORIGINS as $k => $n): ?><option value="<?= e($k) ?>" <?= $form['origen'] === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <select class="form-select form-select-sm" name="prioridad"><option value="">Prioridad</option>
            <?php foreach (ActionWorkflow::PRIORITIES as $k => $n): ?><option value="<?= e($k) ?>" <?= $form['prioridad'] === $k ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Filtrar</button> <a class="btn btn-sm btn-link" href="?">Limpiar</a></div>
</form>

<div class="card shadow-sm">
    <div class="list-group list-group-flush">
        <?php foreach ($rows as $r): $late = ActionWorkflow::isOverdue($r, $today); ?>
            <a class="list-group-item list-group-item-action <?= $late ? 'border-start border-danger border-4' : '' ?>" href="<?= e(url('/panel/acciones/' . $r['uuid'])) ?>">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <span class="text-body-secondary small me-1"><?= e(Actions::format((int) $r['number'])) ?></span>
                        <strong><?= e($r['title']) ?></strong>
                    </div>
                    <div class="d-flex gap-1 align-items-start"><?= $priorityBadge($r['priority']) ?> <?= $actionBadge($r['status']) ?></div>
                </div>
                <div class="small d-flex flex-wrap gap-3 mt-1">
                    <span>👤 <?= e($r['responsible_name']) ?></span>
                    <?= $dueLabel($r, $today) ?>
                    <?php if ($r['sector_name']): ?><span class="text-body-secondary"><?= e($r['sector_name']) ?></span><?php endif; ?>
                    <span class="text-body-secondary"><?= e(ActionWorkflow::ORIGINS[$r['origin_type']] ?? $r['origin_type']) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="list-group-item text-body-secondary small">No hay acciones con estos filtros.</div><?php endif; ?>
    </div>
</div>
<?php if ($total > $perPage): ?>
    <nav class="mt-3 d-flex justify-content-between small">
        <span class="text-body-secondary"><?= e($total) ?> acciones</span>
        <span>
            <?php if ($page > 1): ?><a href="<?= e($query(['pagina' => $page - 1])) ?>">← Anteriores</a><?php endif; ?>
            <?php if ($page * $perPage < $total): ?><a class="ms-3" href="<?= e($query(['pagina' => $page + 1])) ?>">Siguientes →</a><?php endif; ?>
        </span>
    </nav>
<?php endif; ?>
