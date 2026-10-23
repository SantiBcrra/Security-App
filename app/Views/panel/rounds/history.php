<?php
/** Pestaña "Rondas realizadas". @var array $rows @var int $total @var array $filters @var array $routes @var array $users */
?>
<?= App\Core\View::render('panel/rounds/_nav', ['tab' => 'rondas', 'subtitle' => 'Todas las rondas hechas: filtrá por fecha, guardia, ruta o estado y entrá al detalle'], null) ?>
<form method="get" class="card card-body mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2"><label class="form-label small">Desde</label><input type="date" name="desde" value="<?= e($filters['desde']) ?>" class="form-control"></div>
        <div class="col-6 col-md-2"><label class="form-label small">Hasta</label><input type="date" name="hasta" value="<?= e($filters['hasta']) ?>" class="form-control"></div>
        <?php if ($users): ?><div class="col-md-2"><label class="form-label small">Guardia</label><select name="guardia" class="form-select"><option value="">Todos</option>
            <?php foreach ($users as $u): ?><option value="<?= e($u['uuid']) ?>" <?= $filters['guardia'] === $u['uuid'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="col-md-2"><label class="form-label small">Ruta</label><select name="ruta" class="form-select"><option value="">Todas</option><option value="libre" <?= $filters['ruta'] === 'libre' ? 'selected' : '' ?>>Rondas libres</option>
            <?php foreach ($routes as $rt): ?><option value="<?= e($rt['uuid']) ?>" <?= $filters['ruta'] === $rt['uuid'] ? 'selected' : '' ?>><?= e($rt['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small">Estado</label><select name="estado" class="form-select">
            <?php foreach (['' => 'Todos', 'completa' => 'Completas', 'incompleta' => 'Incompletas', 'en_curso' => 'En curso', 'con_problemas' => 'Con problemas (fuera de lugar / alertas)', 'anulada' => 'Anuladas'] as $k => $l): ?>
                <option value="<?= e($k) ?>" <?= $filters['estado'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1">Filtrar</button><a class="btn btn-outline-secondary" href="<?= e(url('/panel/rondas/historial')) ?>" title="Limpiar filtros">✕</a></div>
    </div>
</form>
<div class="small text-body-secondary mb-2"><?= $total ?> ronda(s)<?= $total > count($rows) ? ' — se muestran las ' . count($rows) . ' más recientes; acotá las fechas para ver el resto' : '' ?>. Tocá una fila para ver el detalle con el mapa.</div>
<?= App\Core\View::render('panel/rounds/_rounds_table', ['rows' => $rows, 'empty' => 'No hay rondas con estos filtros.'], null) ?>
