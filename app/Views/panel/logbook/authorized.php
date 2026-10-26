<?php
/** Libro → Lista de autorizados. @var array $rows @var array $filters @var array $sites @var bool $canManage */
use App\Services\LogbookAuthorized;
$f = $filters;
$today = App\Services\ShiftPlanner::localDay(gmdate('Y-m-d H:i:s'));
?>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
    <div>
        <a class="small d-inline-block mb-1" href="<?= e(url('/panel/libro')) ?>">← Libro de guardia</a>
        <h1 class="h4 m-0">Lista de autorizados</h1>
        <div class="text-body-secondary small">Personas y vehículos que pueden ingresar. Al anotar un ingreso, el guardia ve si está autorizado (también sin señal).
            Si no lo está se anota igual y se avisa al supervisor. Si un predio no tiene a nadie en la lista, no se controla.</div>
    </div>
    <?php if ($canManage): ?>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-primary btn-sm" href="<?= e(url('/panel/libro/autorizados/nuevo')) ?>">+ Persona</a>
        <a class="btn btn-primary btn-sm" href="<?= e(url('/panel/libro/autorizados/nuevo?tipo=vehiculo')) ?>">+ Vehículo</a>
        <button class="btn btn-outline-secondary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#importar">Importar Excel</button>
    </div>
    <?php endif; ?>
</div>

<?php if ($canManage): ?>
<div class="collapse mb-3" id="importar"><form class="card card-body" method="post" enctype="multipart/form-data" action="<?= e(url('/panel/libro/autorizados/importar')) ?>">
    <?= csrf_field() ?>
    <p class="small mb-2">Un archivo Excel (.xlsx) o CSV con títulos en la primera fila: <code>tipo, nombre, dni, empresa, patente, predio, desde, hasta, dias, hora_desde, hora_hasta, motivo, autorizo</code>.
        Fechas dd/mm/aaaa; días como "lun a vie" o "lun, mie"; predio vacío = todos. Si la persona (DNI) o el vehículo (patente) ya está, se actualiza.
        <a href="<?= e(url('/panel/libro/autorizados/planilla')) ?>">Bajar planilla de ejemplo</a>.</p>
    <div class="d-flex flex-wrap gap-2"><input class="form-control form-control-sm" type="file" name="archivo" accept=".xlsx,.csv" required style="max-width: 360px">
        <button class="btn btn-sm btn-primary">Importar</button></div>
</form></div>
<?php endif; ?>

<form class="row g-2 align-items-end mb-3" method="get">
    <div class="col-12 col-md-4"><label class="form-label small">Buscar</label><input class="form-control form-control-sm" name="q" value="<?= e($f['q']) ?>" placeholder="Nombre, DNI, empresa o patente"></div>
    <div class="col-6 col-md-2"><label class="form-label small">Tipo</label><select class="form-select form-select-sm" name="tipo">
        <?php foreach (['' => 'Todos', 'persona' => 'Personas', 'vehiculo' => 'Vehículos'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small">Predio</label><select class="form-select form-select-sm" name="predio"><option value="">Todos</option>
        <?php foreach ($sites as $s): ?><option value="<?= e($s['uuid']) ?>" <?= $f['predio'] === $s['uuid'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small">Estado</label><select class="form-select form-select-sm" name="estado">
        <?php foreach (['vigentes' => 'Vigentes', 'vencidas' => 'Vencidas', 'todas' => 'Todas'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['estado'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-primary">Filtrar</button></div>
</form>

<div class="card shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Quién</th><th>Predio</th><th>Cuándo puede entrar</th><th>Motivo</th><th>Estado</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a):
        $expired = $a['valid_until'] && $a['valid_until'] < $today; ?>
        <tr class="<?= (int) $a['is_active'] !== 1 || $expired ? 'text-body-secondary' : '' ?>">
            <td><?= $a['kind'] === 'vehiculo' ? '🚗' : '👤' ?> <strong><?= e($a['kind'] === 'vehiculo' ? $a['plate'] : ($a['person_name'] ?: '—')) ?></strong>
                <div class="small text-body-secondary"><?= e(implode(' · ', array_filter([$a['person_doc'] ? 'DNI ' . $a['person_doc'] : null, $a['company']]))) ?></div></td>
            <td class="small"><?= e($a['site_name'] ?? 'Todos los predios') ?></td>
            <td class="small"><?= e(LogbookAuthorized::window($a)) ?></td>
            <td class="small"><?= e($a['reason'] ?? '') ?><?= $a['authorized_by'] ? '<div class="text-body-secondary">Autorizó: ' . e($a['authorized_by']) . '</div>' : '' ?></td>
            <td><?= (int) $a['is_active'] !== 1 ? '<span class="badge text-bg-secondary">Suspendido</span>' : ($expired ? '<span class="badge text-bg-warning">Vencido</span>' : '<span class="badge text-bg-success">Vigente</span>') ?></td>
            <td class="text-end text-nowrap"><?php if ($canManage): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/libro/autorizados/' . $a['uuid'])) ?>">Editar</a>
                <form class="d-inline" method="post" action="<?= e(url('/panel/libro/autorizados/' . $a['uuid'] . '/estado')) ?>"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-secondary"><?= (int) $a['is_active'] === 1 ? 'Suspender' : 'Reactivar' ?></button></form>
                <form class="d-inline" method="post" action="<?= e(url('/panel/libro/autorizados/' . $a['uuid'] . '/eliminar')) ?>" onsubmit="return confirm('¿Quitar de la lista?')"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-danger">Quitar</button></form>
            <?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-body-secondary">No hay nadie en la lista<?= $f['q'] !== '' ? ' con esa búsqueda' : '' ?>. Mientras la lista de un predio esté vacía, no se controla quién entra.</td></tr><?php endif; ?>
    </tbody>
</table></div></div>
