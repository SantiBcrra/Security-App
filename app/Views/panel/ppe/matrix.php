<?php
use App\Services\PpeService;
require __DIR__ . '/_badges.php';
$positionOptions = array_column($positions, 'name', 'uuid');
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/epp')) ?>">←</a>
    <h1 class="h4 m-0">EPP · matriz por puesto</h1>
    <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= e(url('/panel/epp/catalogo')) ?>">Catálogo</a>
</div>
<?php if (!$items || !$positions): ?>
    <div class="alert alert-info small">Para armar la matriz hacen falta elementos en el <a href="<?= e(url('/panel/epp/catalogo')) ?>">catálogo</a> y puestos en Datos maestros.</div>
<?php else: ?>
<div class="card shadow-sm mb-3">
    <div class="table-responsive">
        <table class="table table-sm table-bordered small mb-0 text-center align-middle">
            <thead><tr><th class="text-start">Puesto</th><?php foreach ($items as $i): ?><th style="writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap; font-weight: 500"><?= e($i['name']) ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php foreach ($positions as $p): ?>
                <tr><td class="text-start text-nowrap"><a href="#p-<?= e($p['uuid']) ?>"><?= e($p['name']) ?></a></td>
                    <?php foreach ($items as $i): $c = $cells[(int) $p['id']][(int) $i['id']] ?? null; ?>
                        <td title="<?= $c ? e($c['quantity'] . ' × ' . $i['name'] . ($c['mandatory'] ? '' : ' (según tarea)')) : '' ?>"><?= $c ? ($c['mandatory'] ? '<strong>' . ((int) $c['quantity'] > 1 ? e($c['quantity']) : '✓') . '</strong>' : '<span class="text-body-secondary">○</span>') : '' ?></td>
                    <?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-body-secondary">✓ obligatorio (un número = cantidad por entrega) · ○ según tarea (se entrega cuando hace falta, no cuenta como faltante).</div>
</div>

<?php if ($canManage): ?>
    <form class="card card-body shadow-sm mb-3" method="post" action="<?= e(url('/panel/epp/matriz/copiar')) ?>">
        <?= csrf_field() ?>
        <div class="row g-2 align-items-end">
            <div class="col-md-4"><label class="form-label small mb-0">Copiar lo de</label><select class="form-select form-select-sm" name="from"><?php foreach ($positionOptions as $uuid => $name): ?><option value="<?= e($uuid) ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label small mb-0">al puesto</label><select class="form-select form-select-sm" name="to"><?php foreach ($positionOptions as $uuid => $name): ?><option value="<?= e($uuid) ?>"><?= e($name) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><button class="btn btn-sm btn-outline-primary">Copiar (agrega, no saca nada)</button></div>
        </div>
    </form>
<?php endif; ?>

<?php foreach ($positions as $p): $rows = $cells[(int) $p['id']] ?? []; ?>
    <div class="card shadow-sm mb-3" id="p-<?= e($p['uuid']) ?>">
        <div class="card-header"><strong><?= e($p['name']) ?></strong> <span class="small text-body-secondary">· <?= count($rows) ?> elemento(s)</span></div>
        <ul class="list-group list-group-flush small">
            <?php foreach ($items as $i): $c = $rows[(int) $i['id']] ?? null; if (!$c) { continue; } ?>
                <li class="list-group-item">
                    <?php if ($canManage): ?>
                        <form method="post" action="<?= e(url('/panel/epp/matriz')) ?>" class="row g-2 align-items-center">
                            <?= csrf_field() ?><input type="hidden" name="position" value="<?= e($p['uuid']) ?>"><input type="hidden" name="item" value="<?= e($i['uuid']) ?>"><input type="hidden" name="active" value="1">
                            <div class="col-md-4"><?= e($i['name']) ?></div>
                            <div class="col-4 col-md-1"><input class="form-control form-control-sm" name="quantity" value="<?= e($c['quantity']) ?>" title="Cantidad"></div>
                            <div class="col-4 col-md-2"><input class="form-control form-control-sm" name="life_days" value="<?= e($c['life_days'] ?? '') ?>" placeholder="<?= e($ppeLife($i['life_days'] !== null ? (int) $i['life_days'] : null)) ?>" title="Vida útil en días (vacío = la del catálogo)"></div>
                            <div class="col-4 col-md-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="mandatory" value="1" id="m<?= e($c['id']) ?>" <?= $c['mandatory'] ? 'checked' : '' ?>><label class="form-check-label" for="m<?= e($c['id']) ?>">Obligatorio</label></div></div>
                            <div class="col-md-3 text-end"><button class="btn btn-sm btn-outline-primary">Guardar</button>
                                <button class="btn btn-sm btn-link text-danger" name="active" value="0">Quitar</button></div>
                        </form>
                    <?php else: ?>
                        <?= e($c['quantity']) ?> × <?= e($i['name']) ?> · <?= e($ppeLife($c['life_days'] !== null ? (int) $c['life_days'] : ($i['life_days'] !== null ? (int) $i['life_days'] : null))) ?><?= $c['mandatory'] ? '' : ' · según tarea' ?>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php $missing = array_filter($items, fn ($i) => !isset($rows[(int) $i['id']])); ?>
        <?php if ($canManage && $missing): ?>
            <form class="card-body border-top row g-2 align-items-center" method="post" action="<?= e(url('/panel/epp/matriz')) ?>">
                <?= csrf_field() ?><input type="hidden" name="position" value="<?= e($p['uuid']) ?>"><input type="hidden" name="active" value="1">
                <div class="col-md-4"><select class="form-select form-select-sm" name="item" required><option value="">Agregar elemento…</option>
                    <?php foreach ($missing as $i): ?><option value="<?= e($i['uuid']) ?>"><?= e($i['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-4 col-md-1"><input class="form-control form-control-sm" name="quantity" value="1"></div>
                <div class="col-4 col-md-2"><input class="form-control form-control-sm" name="life_days" placeholder="Vida útil (días)"></div>
                <div class="col-4 col-md-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="mandatory" value="1" id="nm<?= e($p['id']) ?>" checked><label class="form-check-label" for="nm<?= e($p['id']) ?>">Obligatorio</label></div></div>
                <div class="col-md-3 text-end"><button class="btn btn-sm btn-primary">Agregar</button></div>
            </form>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
<?php endif; ?>
