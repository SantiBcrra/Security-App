<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between"><strong>Personal</strong>
        <span class="small"><?= App\Resources\ContractorsResource::artBadge($row['art_expires_on']) ?></span></div>
    <?php if (!$workers): ?>
        <p class="small text-body-secondary p-3 mb-0">Sin personal cargado. Se agrega desde Empleados eligiendo esta contratista.</p>
    <?php else: ?>
        <ul class="list-group list-group-flush small">
            <?php foreach ($workers as $w): ?>
                <li class="list-group-item"><a class="text-decoration-none" href="<?= e(url('/panel/datos/empleados/' . $w['uuid'])) ?>"><?= e($w['name']) ?></a>
                    <span class="text-body-secondary">· DNI <?= e($w['dni']) ?></span></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
