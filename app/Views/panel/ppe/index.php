<?php
use App\Services\PpeService;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 m-0">EPP</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-primary btn-sm" href="<?= e(url('/panel/epp/entrega-lote')) ?>">Entrega por lote</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/epp/exportar')) ?>">CSV estado</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/epp/exportar?tipo=entregas')) ?>">CSV entregas</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/epp/matriz')) ?>">Matriz por puesto</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/epp/catalogo')) ?>">Catálogo</a>
    </div>
</div>
<div class="row g-2 mb-3">
<?php foreach ([['empleados','Empleados'],['al_dia','Al día'],['por_vencer','Por vencer'],['vencido','Vencidos'],['nunca','Nunca entregado']] as [$k,$label]): ?>
<div class="col-6 col-md"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-body-secondary"><?= e($label) ?></div><strong class="fs-5"><?= (int)($compliance['totals'][$k] ?? 0) ?></strong></div></div></div>
<?php endforeach; ?>
</div>
<div class="card shadow-sm mb-3"><div class="card-header"><strong>Cumplimiento por sector</strong></div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Sector</th><th>Empleados</th><th>Al día</th><th>Por vencer</th><th>Vencidos</th><th>Cumplimiento</th></tr></thead><tbody><?php foreach ($compliance['sectors'] as $s): ?><tr><td><?= e($s['name']) ?></td><td><?= (int)$s['empleados'] ?></td><td><?= (int)$s['al_dia'] ?></td><td><?= (int)$s['por_vencer'] ?></td><td><?= (int)($s['vencido']+$s['nunca']) ?></td><td><?= e((string)$s['porcentaje']) ?>%</td></tr><?php endforeach; ?></tbody></table></div></div>
<?php if ($noMatrix): ?>
    <div class="alert alert-info small">Todavía no hay matriz de EPP: cargá el catálogo (o el del rubro) y definí qué corresponde a cada puesto.
        <a href="<?= e(url('/panel/epp/catalogo')) ?>">Ir al catálogo</a></div>
<?php endif; ?>
<form method="get" class="card card-body shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><input class="form-control form-control-sm" name="q" value="<?= e($form['q']) ?>" placeholder="Buscar: apellido, nombre, DNI o legajo"></div>
        <div class="col-md-4"><select class="form-select form-select-sm" name="sector"><option value="">Todos los sectores</option>
            <?php foreach ($sectors as $uuid => $label): ?><option value="<?= e($uuid) ?>" <?= $form['sector'] === $uuid ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><select class="form-select form-select-sm" name="estado"><option value="">Cualquier estado</option>
            <?php foreach (PpeService::STATES as $k => $st): ?><option value="<?= e($k) ?>" <?= $form['estado'] === $k ? 'selected' : '' ?>><?= e($st['label']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filtrar</button></div>
    </div>
</form>
<div class="small text-body-secondary mb-2"><?= count($employees) ?> empleado(s) propio(s). Los de contratistas no se gestionan acá: les entrega su empleador.</div>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover small mb-0 align-middle">
            <thead><tr><th>Empleado</th><th>Puesto</th><th>Sector</th><th>Estado</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($employees as $emp): $s = $summaries[(int) $emp['id']]; ?>
                <tr>
                    <td><a href="<?= e(url('/panel/epp/empleado/' . $emp['uuid'])) ?>"><?= e($emp['name']) ?></a> <span class="text-body-secondary">DNI <?= e($emp['dni']) ?></span></td>
                    <td><?= e($emp['position_name'] ?? '') ?: '<span class="text-danger">sin puesto</span>' ?></td>
                    <td><?= e($emp['sector_name'] ?? '') ?></td>
                    <td><?= $ppeState($s['overall']) ?>
                        <?php foreach (['vencido', 'nunca', 'por_vencer'] as $k): ?><?php if ($s['counts'][$k]): ?> <span class="text-body-secondary"><?= e($s['counts'][$k]) ?> <?= e(mb_strtolower(PpeService::STATES[$k]['label'])) ?></span><?php endif; ?><?php endforeach; ?></td>
                    <td class="text-end"><?php if (UserAuth::can('epp', 'crear')): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/panel/epp/empleado/' . $emp['uuid'] . '/entregar')) ?>">Entregar</a><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$employees): ?><tr><td colspan="5" class="text-body-secondary">No hay empleados con esos filtros.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
