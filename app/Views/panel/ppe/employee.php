<?php
use App\Models\Ppe;
use App\Services\PpeService;
use App\Services\UserAuth;
require __DIR__ . '/_badges.php';
$base = '/panel/epp/empleado/' . $e['uuid'];
$canDeliver = UserAuth::can('epp', 'crear');
$reasons = PpeService::REASONS;
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/epp')) ?>">←</a>
    <h1 class="h4 m-0"><?= e($e['name']) ?></h1>
    <span class="text-body-secondary">DNI <?= e($e['dni']) ?> · <?= e($e['position_name'] ?? 'sin puesto') ?> · <?= e($e['sector_name'] ?? 'sin sector') ?></span>
    <?= $ppeState($status['overall']) ?>
    <div class="ms-auto d-flex gap-2">
        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= e(url($base . '/constancia')) ?>">Constancia SRT 299/11</a>
        <?php if ($canDeliver && (int) $e['is_active']): ?><a class="btn btn-sm btn-primary" href="<?= e(url($base . '/entregar')) ?>">Entregar EPP</a><?php endif; ?>
    </div>
</div>
<?php if (!$e['position_name']): ?><div class="alert alert-warning small">El empleado no tiene puesto asignado: no se sabe qué EPP le corresponde. Asignalo en Datos maestros → Empleados.</div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Lo que le corresponde</strong> <span class="small text-body-secondary">· matriz de su puesto + extras</span></div>
            <div class="table-responsive">
                <table class="table table-sm small mb-0 align-middle">
                    <thead><tr><th>Elemento</th><th>Cant.</th><th>Vida útil</th><th>Última entrega</th><th>Reponer</th><th>Estado</th></tr></thead>
                    <tbody>
                    <?php foreach ($status['rows'] as $r): ?>
                        <tr>
                            <td><?= e($r['item']['name']) ?><?= $r['source'] === 'extra' ? ' <span class="badge text-bg-light border">extra</span>' : '' ?>
                                <?= !$r['mandatory'] ? ' <span class="badge text-bg-light border">según tarea</span>' : '' ?>
                                <?= $r['notes'] ? '<br><span class="text-body-secondary">' . e($r['notes']) . '</span>' : '' ?></td>
                            <td><?= e($r['quantity']) ?></td>
                            <td><?= e($ppeLife($r['life_days'])) ?></td>
                            <td><?= $r['last'] ? e(fecha($r['last']['delivered_at'], 'd/m/Y')) . ($r['last']['size'] ? ' · talle ' . e($r['last']['size']) : '') : '—' ?></td>
                            <td><?= e($ppeDate($r['last']['next_due_on'] ?? null)) ?></td>
                            <td><?= $ppeState($r['state']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$status['rows']): ?><tr><td colspan="6" class="text-body-secondary">Su puesto no tiene EPP en la matriz.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm mb-3">
            <div class="card-header"><strong>Entregas</strong></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($deliveries as $d): ?>
                    <li class="list-group-item <?= $d['voided_at'] ? 'text-body-secondary' : '' ?>" x-data="{ voiding: false }">
                        <div class="d-flex justify-content-between gap-2 flex-wrap">
                            <span><strong class="<?= $d['voided_at'] ? 'text-decoration-line-through' : '' ?>"><?= e(Ppe::format((int) $d['number'])) ?></strong>
                                · <?= e(fecha($d['delivered_at'], 'd/m/Y H:i')) ?> · <?= e($reasons[$d['reason']] ?? $d['reason']) ?> · entregó <?= e($d['delivered_by_name'] ?? '—') ?>
                                <?= $d['voided_at'] ? '<span class="badge text-bg-secondary">Anulada</span>' : '' ?></span>
                            <span>
                                <a target="_blank" href="<?= e(url('/panel/epp/entregas/' . $d['uuid'] . '/firma')) ?>"><?= $d['signature_mode'] === 'papel' ? 'Planilla en papel' : 'Firma' ?></a>
                                <?php if (!$d['voided_at'] && UserAuth::can('epp', 'cerrar')): ?> · <a href="#" @click.prevent="voiding = !voiding" class="text-danger">Anular</a><?php endif; ?>
                            </span>
                        </div>
                        <div><?= e(implode(' · ', array_map(fn ($i) => $i['quantity'] . ' × ' . $i['item_name'] . ($i['size'] ? ' (talle ' . $i['size'] . ')' : ''), $d['items']))) ?></div>
                        <?php if ($d['signature_mode'] === 'pantalla'): ?>
                            <img src="<?= e(url('/panel/epp/entregas/' . $d['uuid'] . '/firma')) ?>" alt="Firma" class="border rounded bg-white mt-1" style="height: 46px" title="SHA-256 <?= e($d['signature_sha256']) ?>">
                        <?php else: ?>
                            <div class="text-body-secondary">Firmada en papel: <?= e($d['paper_reason']) ?></div>
                        <?php endif; ?>
                        <?php if ($d['notes']): ?><div class="text-body-secondary"><?= e($d['notes']) ?></div><?php endif; ?>
                        <?php if ($d['voided_at']): ?><div>Anulada por <?= e($d['voided_by_name'] ?? '—') ?> el <?= e(fecha($d['voided_at'], 'd/m/Y')) ?>: <?= e($d['void_reason']) ?></div><?php endif; ?>
                        <form method="post" action="<?= e(url('/panel/epp/entregas/' . $d['uuid'] . '/anular')) ?>" class="mt-2 d-flex gap-2" x-show="voiding" x-cloak>
                            <?= csrf_field() ?>
                            <input class="form-control form-control-sm" name="reason" required minlength="5" placeholder="Motivo (ej. se cargó al empleado equivocado)">
                            <button class="btn btn-sm btn-danger">Anular</button>
                        </form>
                    </li>
                <?php endforeach; ?>
                <?php if (!$deliveries): ?><li class="list-group-item text-body-secondary">Sin entregas registradas.</li><?php endif; ?>
            </ul>
        </div>
    </div>

    <div class="col-lg-4">
        <form class="card shadow-sm mb-3" method="post" action="<?= e(url($base . '/talles')) ?>">
            <?= csrf_field() ?>
            <div class="card-header"><strong>Talles</strong> <span class="small text-body-secondary">· se actualizan con cada entrega</span></div>
            <fieldset class="card-body row g-2" <?= $canDeliver ? '' : 'disabled' ?>>
                <?php foreach (PpeService::SIZE_TYPES as $key => $t): ?>
                    <div class="col-4"><label class="form-label small mb-0"><?= e($t['label']) ?></label><input class="form-control form-control-sm" name="<?= e($key) ?>" maxlength="10" value="<?= e($e[$t['column']] ?? '') ?>"></div>
                <?php endforeach; ?>
                <?php if ($canDeliver): ?><div class="col-12"><button class="btn btn-sm btn-outline-primary">Guardar talles</button></div><?php endif; ?>
            </fieldset>
        </form>

        <div class="card shadow-sm">
            <div class="card-header"><strong>Extras de este empleado</strong></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($extras as $x): ?>
                    <li class="list-group-item d-flex justify-content-between gap-2"><span><?= e($x['quantity']) ?> × <?= e($x['item_name']) ?><br><span class="text-body-secondary"><?= e($x['reason']) ?></span></span>
                        <?php if (PpeService::canManage()): ?>
                            <form method="post" action="<?= e(url($base . '/extras')) ?>"><?= csrf_field() ?><input type="hidden" name="item" value="<?= e($x['item_uuid']) ?>"><input type="hidden" name="remove" value="1">
                                <button class="btn btn-sm btn-link text-danger p-0">Quitar</button></form>
                        <?php endif; ?></li>
                <?php endforeach; ?>
                <?php if (!$extras): ?><li class="list-group-item text-body-secondary">Ninguno: recibe lo de su puesto.</li><?php endif; ?>
            </ul>
            <?php if (PpeService::canManage()): ?>
                <form class="card-body border-top" method="post" action="<?= e(url($base . '/extras')) ?>">
                    <?= csrf_field() ?>
                    <select class="form-select form-select-sm mb-2" name="item" required><option value="">Elemento…</option>
                        <?php foreach ($items as $uuid => $name): ?><option value="<?= e($uuid) ?>"><?= e($name) ?></option><?php endforeach; ?></select>
                    <div class="row g-2 mb-2">
                        <div class="col-4"><input class="form-control form-control-sm" name="quantity" value="1" inputmode="numeric" title="Cantidad"></div>
                        <div class="col-8"><input class="form-control form-control-sm" name="life_days" inputmode="numeric" placeholder="Vida útil (días, opcional)"></div>
                    </div>
                    <input class="form-control form-control-sm mb-2" name="reason" required minlength="3" placeholder="Motivo (ej. anteojos con graduación)">
                    <button class="btn btn-sm btn-outline-primary">Agregar extra</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
