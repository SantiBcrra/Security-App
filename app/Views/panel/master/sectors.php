<?php
/** Árbol planta > nave > sector. @var array $rows (ordenados por planta y path) */
require __DIR__ . '/_nav.php';
$bySite = [];
foreach ($rows as $r) { $bySite[$r['site_name']][] = $r; }
?>
<h1 class="h4 mb-3">Sectores</h1>
<?php require __DIR__ . '/_toolbar.php'; ?>
<?php if (!$rows): ?>
    <div class="card shadow-sm"><p class="text-body-secondary text-center py-5 mb-0">
        No hay sectores<?= $q !== '' ? ' para esa búsqueda' : '' ?>. Primero creá una planta y después sus naves y sectores.
    </p></div>
<?php endif; ?>
<?php foreach ($bySite as $site => $sectors): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header"><strong><?= e($site) ?></strong></div>
        <ul class="list-group list-group-flush">
            <?php foreach ($sectors as $s): ?>
                <li class="list-group-item py-2 <?= (int) $s['is_active'] !== 1 ? 'text-body-tertiary' : '' ?>" style="padding-left: <?= e(1 + ((int) $s['depth'] - 1) * 1.75) ?>rem">
                    <?= (int) $s['depth'] > 1 ? '<span class="text-body-tertiary">└</span> ' : '' ?>
                    <a class="text-decoration-none <?= (int) $s['depth'] === 1 ? 'fw-semibold' : '' ?>" href="<?= e(url('/panel/datos/sectores/' . $s['uuid'])) ?>"><?= e($s['name']) ?></a>
                    <?php if ($s['code']): ?><span class="small text-body-secondary"><?= e($s['code']) ?></span><?php endif; ?>
                    <?php if ((int) $s['is_active'] !== 1): ?><span class="badge text-bg-secondary">inactivo</span><?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endforeach; ?>
