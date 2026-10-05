<?php /** Tabla genérica. @var App\Resources\Resource $res @var array $rows */ ?>
<div class="card shadow-sm">
    <?php if (!$rows): ?>
        <p class="text-body-secondary text-center py-5 mb-0">No hay registros<?= $q !== '' ? ' para esa búsqueda' : ' todavía' ?>.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead><tr><?php foreach ($res->columns() as $col): ?><th><?= e($col['label']) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($rows as $row): $href = url('/panel/datos/' . $res->key() . '/' . $row['uuid']); ?>
                    <tr class="<?= (int) $row['is_active'] !== 1 ? 'text-body-tertiary' : '' ?>" style="cursor:pointer" onclick="location.href='<?= e($href) ?>'">
                        <?php foreach ($res->columns() as $i => $col): ?>
                            <td><?php if ($i === 0): ?><a class="text-decoration-none fw-semibold" href="<?= e($href) ?>"><?= ($col['value'])($row) ?></a><?php if ((int) $row['is_active'] !== 1): ?> <span class="badge text-bg-secondary">inactivo</span><?php endif; ?><?php else: ?><?= ($col['value'])($row) ?><?php endif; ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-footer small text-body-secondary"><?= e(count($rows)) ?> registro(s)</div>
    <?php endif; ?>
</div>
