<?php
use App\Models\Inspections;
use App\Services\InspectionService;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$query = fn (array $over) => '?' . http_build_query(array_filter(array_merge($form, $over), fn ($v) => $v !== '' && $v !== null));
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0">Inspecciones</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/programadas')) ?>">Programadas</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/cumplimiento')) ?>">Cumplimiento</a>
        <?php if (UserAuth::can('inspecciones', 'editar')): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/plantillas')) ?>">Plantillas</a>
        <?php endif; ?>
        <?php if (UserAuth::can('inspecciones', 'exportar')): ?>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/inspecciones/exportar' . $query([]))) ?>">CSV</a>
        <?php endif; ?>
        <a class="btn btn-sm <?= $form['mias'] === '1' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= e($query(['mias' => $form['mias'] === '1' ? '' : '1'])) ?>">Mis inspecciones</a>
        <?php if (UserAuth::can('inspecciones', 'crear')): ?>
            <a class="btn btn-primary btn-sm" href="<?= e(url('/panel/inspecciones/nueva')) ?>">+ Nueva inspección</a>
        <?php endif; ?>
    </div>
</div>

<ul class="nav nav-pills small mb-3 flex-nowrap overflow-auto">
    <li class="nav-item"><a class="nav-link py-1 <?= $form['resultado'] === '' && $form['anuladas'] !== '1' ? 'active' : '' ?>" href="<?= e($query(['resultado' => '', 'anuladas' => ''])) ?>">Todas <span class="badge text-bg-light"><?= e(array_sum($byResult)) ?></span></a></li>
    <?php foreach (InspectionService::RESULTS as $key => $r): ?>
        <li class="nav-item"><a class="nav-link py-1 <?= $form['resultado'] === $key ? 'active' : '' ?>" href="<?= e($query(['resultado' => $key, 'anuladas' => ''])) ?>"><?= e($r['label']) ?> <span class="badge text-bg-light"><?= e($byResult[$key] ?? 0) ?></span></a></li>
    <?php endforeach; ?>
    <li class="nav-item"><a class="nav-link py-1 <?= $form['anuladas'] === '1' ? 'active' : '' ?>" href="<?= e($query(['anuladas' => '1', 'resultado' => ''])) ?>">Anuladas</a></li>
</ul>

<form class="row g-2 align-items-end mb-3" method="get">
    <input type="hidden" name="resultado" value="<?= e($form['resultado']) ?>"><input type="hidden" name="mias" value="<?= e($form['mias']) ?>"><input type="hidden" name="anuladas" value="<?= e($form['anuladas']) ?>">
    <div class="col-12 col-md-3"><input class="form-control form-control-sm" type="search" name="q" value="<?= e($form['q']) ?>" placeholder="Número (INS-…), equipo o notas"></div>
    <div class="col-6 col-md-3">
        <select class="form-select form-select-sm" name="plantilla"><option value="">Checklist</option>
            <?php foreach ($templates as $t): ?><option value="<?= e($t['uuid']) ?>" <?= $form['plantilla'] === $t['uuid'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-3">
        <select class="form-select form-select-sm" name="sector"><option value="">Sector (incluye los de abajo)</option>
            <?php foreach ($sectors as $u => $n): ?><option value="<?= e($u) ?>" <?= $form['sector'] === $u ? 'selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-auto"><label class="small text-body-secondary">Desde</label><input class="form-control form-control-sm" type="date" name="desde" value="<?= e($form['desde']) ?>"></div>
    <div class="col-6 col-md-auto"><label class="small text-body-secondary">Hasta</label><input class="form-control form-control-sm" type="date" name="hasta" value="<?= e($form['hasta']) ?>"></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Filtrar</button> <a class="btn btn-sm btn-link" href="?">Limpiar</a></div>
</form>

<div class="card shadow-sm">
    <div class="list-group list-group-flush">
        <?php foreach ($rows as $r): ?>
            <a class="list-group-item list-group-item-action <?= $r['result'] === 'no_conforme_critico' ? 'border-start border-danger border-4' : '' ?>" href="<?= e(url('/panel/inspecciones/' . $r['uuid'])) ?>">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div><span class="small text-body-secondary me-1"><?= e(Inspections::format((int) $r['number'])) ?></span>
                        <strong><?= e($r['template_name']) ?></strong>
                        <?php if ($r['equipment_code']): ?> · <?= e($r['equipment_code'] . ' ' . $r['equipment_name']) ?><?php endif; ?></div>
                    <div class="d-flex gap-2 align-items-start"><span class="small"><?= $scoreText($r['score']) ?></span> <?= $r['status'] === 'anulada' ? '<span class="badge text-bg-dark">Anulada</span>' : $resultBadge($r['result']) ?></div>
                </div>
                <div class="small text-body-secondary d-flex flex-wrap gap-3 mt-1">
                    <span><?= e(fecha($r['done_at_device'], 'd/m/Y H:i')) ?></span>
                    <span>👤 <?= e($r['inspector_name'] ?? '—') ?></span>
                    <?php if ($r['sector_name']): ?><span><?= e($r['sector_name']) ?></span><?php endif; ?>
                    <?php if ((int) $r['items_fail'] > 0): ?><span class="text-danger"><?= e($r['items_fail']) ?> no cumple(n)</span><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
        <?php if (!$rows): ?><div class="list-group-item text-body-secondary small">No hay inspecciones con estos filtros.</div><?php endif; ?>
    </div>
</div>
<?php if ($total > $perPage): ?>
    <nav class="mt-3 d-flex justify-content-between small"><span class="text-body-secondary"><?= e($total) ?> inspecciones</span><span>
        <?php if ($page > 1): ?><a href="<?= e($query(['pagina' => $page - 1])) ?>">← Anteriores</a><?php endif; ?>
        <?php if ($page * $perPage < $total): ?><a class="ms-3" href="<?= e($query(['pagina' => $page + 1])) ?>">Siguientes →</a><?php endif; ?>
    </span></nav>
<?php endif; ?>
