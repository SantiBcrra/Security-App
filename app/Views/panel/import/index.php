<?php /** @var array<string, App\Services\Import\EntityImporter> $importers */ ?>
<h1 class="h4 mb-3">Importar desde Excel o CSV</h1>
<div class="row g-3">
    <div class="col-lg-6">
        <form class="card shadow-sm" method="post" enctype="multipart/form-data" action="<?= e(url('/panel/importar')) ?>"
              x-data="{ entity: '<?= e($selected) ?>', sending: false }" @submit="sending = true">
            <?= csrf_field() ?>
            <div class="card-body">
                <label class="form-label fw-semibold" for="entity">1. ¿Qué vas a importar?</label>
                <select class="form-select mb-3" id="entity" name="entity" x-model="entity">
                    <?php foreach ($importers as $key => $imp): ?>
                        <option value="<?= e($key) ?>"><?= e($imp->label()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php foreach ($importers as $key => $imp): ?>
                    <div class="small mb-3" x-show="entity === '<?= e($key) ?>'" x-cloak>
                        Columnas que reconoce:
                        <?php foreach ($imp->columns() as $def): ?>
                            <span class="badge <?= !empty($def['required']) ? 'text-bg-primary' : 'text-bg-light' ?>"><?= e($def['label']) ?></span>
                        <?php endforeach; ?>
                        <div class="mt-1"><a href="<?= e(url('/panel/importar/plantilla/' . $key)) ?>">Descargar planilla de ejemplo (.csv)</a></div>
                    </div>
                <?php endforeach; ?>

                <label class="form-label fw-semibold" for="file">2. Elegí el archivo</label>
                <input class="form-control mb-1" type="file" id="file" name="file" accept=".xlsx,.csv" required>
                <div class="form-text mb-3">Excel (.xlsx) o CSV, hasta 5 MB. La primera fila tiene que tener los títulos de las columnas.</div>
                <button class="btn btn-primary" :disabled="sending"><span x-show="sending" class="spinner-border spinner-border-sm me-1"></span>Subir y revisar</button>
                <div class="form-text">Todavía no se guarda nada: primero vas a ver una vista previa.</div>
            </div>
        </form>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Últimas importaciones</strong></div>
            <?php if (!$recent): ?>
                <p class="small text-body-secondary p-3 mb-0">Todavía no hay importaciones.</p>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($recent as $imp): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <a class="text-decoration-none" href="<?= e(url('/panel/importar/' . $imp['uuid'])) ?>">
                                <?= e(isset($importers[$imp['entity']]) ? $importers[$imp['entity']]->label() : $imp['entity']) ?> · <?= e($imp['original_name']) ?>
                            </a>
                            <span class="text-nowrap text-body-secondary">
                                <?= $imp['status'] === 'done' ? e($imp['created_count'] . ' nuevos, ' . $imp['updated_count'] . ' act., ' . $imp['error_count'] . ' err.') : 'sin terminar' ?>
                                · <?= e(fecha($imp['created_at'], 'd/m H:i')) ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
