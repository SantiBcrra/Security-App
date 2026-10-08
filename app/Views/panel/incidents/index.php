<?php
use App\Models\Incidents;
use App\Services\IncidentService;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$query = fn (array $over) => '?' . http_build_query(array_filter(array_merge($form, $over), fn ($v) => $v !== '' && $v !== null));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0">Incidentes y accidentes</h1>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/incidentes/horas')) ?>">Horas trabajadas e índices</a>
        <?php if (UserAuth::can('incidentes', 'exportar')): ?><a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/incidentes/exportar' . $query([]))) ?>">CSV</a><?php endif; ?>
        <?php if (UserAuth::can('incidentes', 'crear')): ?>
            <a class="btn btn-danger btn-sm" href="<?= e(url('/panel/incidentes/nuevo')) ?>">+ Reportar incidente / accidente</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card shadow-sm h-100 border-<?= $noLost['days'] === null || $noLost['days'] >= 30 ? 'success' : 'warning' ?>"><div class="card-body">
            <div class="display-6 fw-semibold"><?= $noLost['days'] === null ? '—' : e($noLost['days']) ?></div>
            <div class="small text-body-secondary">días sin accidentes con baja<?= $noLost['last'] ? ' (último: ' . e(fecha($noLost['last'], 'd/m/Y')) . ')' : '' ?></div>
        </div></div>
    </div>
    <div class="col-md-8">
        <div class="d-flex flex-wrap gap-2 h-100 align-items-center">
            <?php foreach (IncidentService::TYPES as $k => $t): ?>
                <a class="btn btn-sm <?= $form['tipo'] === $k ? 'btn-dark' : 'btn-outline-secondary' ?>" href="<?= e($query(['tipo' => $form['tipo'] === $k ? '' : $k])) ?>">
                    <?= e($t['label']) ?> <span class="badge text-bg-light"><?= e($byType[$k] ?? 0) ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<form class="row g-2 align-items-end mb-3" method="get">
    <input type="hidden" name="tipo" value="<?= e($form['tipo']) ?>">
    <div class="col-12 col-md-3"><input class="form-control form-control-sm" type="search" name="q" value="<?= e($form['q']) ?>" placeholder="Número (INC-…), texto o persona"></div>
    <div class="col-6 col-md-2"><select class="form-select form-select-sm" name="estado">
        <option value="">Todos (sin anulados)</option><option value="abiertos" <?= $form['estado'] === 'abiertos' ? 'selected' : '' ?>>Abiertos</option>
        <?php foreach (IncidentService::STATES as $k => $s): ?><option value="<?= e($k) ?>" <?= $form['estado'] === $k ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-3"><select class="form-select form-select-sm" name="sector"><option value="">Sector (incluye los de abajo)</option>
        <?php foreach ($sectors as $u => $n): ?><option value="<?= e($u) ?>" <?= $form['sector'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-auto"><label class="small text-body-secondary">Desde</label><input class="form-control form-control-sm" type="date" name="desde" value="<?= e($form['desde']) ?>"></div>
    <div class="col-6 col-md-auto"><label class="small text-body-secondary">Hasta</label><input class="form-control form-control-sm" type="date" name="hasta" value="<?= e($form['hasta']) ?>"></div>
    <div class="col-auto form-check ms-2"><input class="form-check-input" type="checkbox" name="bajas" value="1" id="f_bajas" <?= $form['bajas'] === '1' ? 'checked' : '' ?>><label class="form-check-label small" for="f_bajas">Con baja abierta</label></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Filtrar</button> <a class="btn btn-sm btn-link" href="?">Limpiar</a></div>
</form>

<div class="card shadow-sm">
    <div class="list-group list-group-flush">
        <?php foreach ($rows as $r): ?>
            <a class="list-group-item list-group-item-action <?= IncidentService::TYPES[$r['type']]['serious'] ? 'border-start border-danger border-4' : '' ?>" href="<?= e(url('/panel/incidentes/' . $r['uuid'])) ?>">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div><span class="small text-body-secondary me-1"><?= e(Incidents::format((int) $r['number'])) ?></span> <?= $typeBadge($r['type']) ?>
                        <span class="ms-1"><?= e(mb_strimwidth($r['description'], 0, 90, '…')) ?></span></div>
                    <div class="text-nowrap"><?= $stateBadge($r['status']) ?></div>
                </div>
                <div class="small text-body-secondary d-flex flex-wrap gap-3 mt-1">
                    <span><?= e(fecha($r['occurred_at'], 'd/m/Y H:i')) ?></span>
                    <?php if ($r['sector_name']): ?><span><?= e($r['sector_name']) ?></span><?php endif; ?>
                    <?php if ((int) $r['injured_count']): ?><span><?= e($r['injured_count']) ?> lesionado(s)</span><?php endif; ?>
                    <?php if ((int) $r['open_leaves']): ?><span class="text-danger fw-semibold">baja abierta</span><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="list-group-item small text-body-secondary">No hay incidentes con estos filtros.</div><?php endif; ?>
    </div>
</div>
<?php if ($total > $perPage): ?>
    <nav class="mt-3 d-flex justify-content-between small"><span class="text-body-secondary"><?= e($total) ?> incidentes</span><span>
        <?php if ($page > 1): ?><a href="<?= e($query(['pagina' => $page - 1])) ?>">← Anteriores</a><?php endif; ?>
        <?php if ($page * $perPage < $total): ?><a class="ms-3" href="<?= e($query(['pagina' => $page + 1])) ?>">Siguientes →</a><?php endif; ?>
    </span></nav>
<?php endif; ?>
