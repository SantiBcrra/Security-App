<?php
/** Buscar en el libro. @var array $filters @var array $rows @var bool $searched @var array $sites @var bool $canExport @var bool $canPurge */
use App\Services\Logbook;
$qs = http_build_query(['q' => $filters['q'], 'tipo' => $filters['kind'], 'desde' => $filters['from'], 'hasta' => $filters['to'], 'predio' => $filters['predio']]);
?>
<div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
    <div><h1 class="h4 m-0">Buscar en el libro de guardia</h1>
        <div class="text-body-secondary small">Por patente, DNI, nombre, empresa o texto de la novedad.</div></div>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/panel/libro')) ?>">← Libro del día</a>
</div>

<form method="get" class="card card-body shadow-sm mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label small">Buscar</label><input class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="AB123CD, 30111222, Pérez, portón…" autofocus></div>
        <div class="col-6 col-md-2"><label class="form-label small">Tipo</label><select class="form-select" name="tipo"><option value="">Todos</option>
            <?php foreach (Logbook::KINDS as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <?php if (count($sites) > 1): ?>
        <div class="col-6 col-md-2"><label class="form-label small">Predio</label><select class="form-select" name="predio"><option value="">Todos</option>
            <?php foreach ($sites as $s): ?><option value="<?= e($s['uuid']) ?>" <?= $filters['predio'] === $s['uuid'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-6 col-md-1"><label class="form-label small">Desde</label><input class="form-control" type="date" name="desde" value="<?= e($filters['from']) ?>"></div>
        <div class="col-6 col-md-1"><label class="form-label small">Hasta</label><input class="form-control" type="date" name="hasta" value="<?= e($filters['to']) ?>"></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary">Buscar</button>
            <?php if ($canExport && $searched): ?><a class="btn btn-outline-secondary" href="<?= e(url('/panel/libro/exportar?' . $qs)) ?>">CSV</a><?php endif; ?></div>
    </div>
</form>

<?php if ($searched): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold"><?= count($rows) ?> resultado(s)<?= count($rows) >= 300 ? ' (se muestran los 300 más recientes)' : '' ?></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <thead><tr><th>Fecha</th><th>Predio · folio</th><th>Tipo</th><th>Detalle</th><th>Salida</th><th>Guardia</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="text-nowrap"><a href="<?= e(url('/panel/libro?predio=' . $r['site_uuid'] . '&fecha=' . App\Services\ShiftPlanner::localDay($r['occurred_at']))) ?>"><?= e(fecha($r['occurred_at'], 'd/m/Y H:i')) ?></a></td>
                <td class="small"><?= e($r['site_name']) ?> · <?= (int) $r['folio'] ?></td>
                <td><span class="badge text-bg-light"><?= e(Logbook::KINDS[$r['kind']] ?? $r['kind']) ?></span><?= $r['important'] ? ' <span class="badge text-bg-danger">!</span>' : '' ?></td>
                <td class="small"><?= in_array($r['kind'], ['persona', 'vehiculo'], true) ? '<strong>' . e(Logbook::label($r)) . '</strong>' . ($r['visiting'] ? ' → ' . e($r['visiting']) : '') . '<br>' : '' ?>
                    <?= e(mb_strimwidth((string) $r['text'], 0, 200, '…')) ?></td>
                <td class="small text-nowrap"><?= in_array($r['kind'], ['persona', 'vehiculo'], true) ? ($r['exited_at'] ? e(fecha($r['exited_at'], 'd/m H:i')) : '<span class="text-primary">adentro</span>') : '' ?></td>
                <td class="small"><?= e($r['user_name'] ?? '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="text-body-secondary">Sin resultados.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<?php if ($canPurge): ?>
<details class="card border-danger">
    <summary class="card-header text-danger fw-semibold">Borrar datos personales de visitantes antiguos</summary>
    <form method="post" action="<?= e(url('/panel/libro/purgar')) ?>" class="card-body" onsubmit="return confirm('¿Borrar los datos personales de las anotaciones anteriores a esa fecha? No se puede deshacer.')">
        <?= csrf_field() ?>
        <p class="small">Por defecto el libro guarda todo para siempre. Esto borra nombre, DNI, patente, conductor y a quién visitó de los ingresos <strong>anteriores</strong> a la fecha elegida.
            La anotación (folio, hora, tipo, guardia) queda, y la acción queda en la auditoría. <strong>No se puede deshacer.</strong>
            La Ley 25.326 recomienda no guardar datos personales más de lo necesario: la decisión es de la empresa.</p>
        <div class="row g-2 align-items-end" style="max-width: 560px">
            <div class="col-sm-5"><label class="form-label small">Anteriores al</label><input class="form-control" type="date" name="before" required></div>
            <div class="col-sm-4"><label class="form-label small">Escribí BORRAR</label><input class="form-control" name="confirm" required autocomplete="off"></div>
            <div class="col-sm-3"><button class="btn btn-danger w-100">Borrar</button></div>
        </div>
    </form>
</details>
<?php endif; ?>
