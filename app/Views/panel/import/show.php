<?php
/** @var array $import @var App\Services\Import\EntityImporter $importer @var ?array $preview */
$actionLabel = ['create' => ['nuevo', 'success'], 'update' => ['actualiza', 'info'], 'unchanged' => ['ya existe', 'secondary'], 'error' => ['error', 'danger']];
$columns = $importer->columns();
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/panel/importar')) ?>">←</a>
    <h1 class="h4 m-0"><?= e($title) ?></h1>
    <span class="text-body-secondary small"><?= e($import['original_name']) ?> · <?= e($import['total_rows']) ?> fila(s)</span>
</div>

<?php if ($editable): ?>
    <form class="card shadow-sm mb-3" method="post" action="<?= e(url('/panel/importar/' . $import['uuid'] . '/mapeo')) ?>">
        <?= csrf_field() ?>
        <div class="card-header"><strong>3. ¿Qué columna del archivo es cada dato?</strong> <span class="small text-body-secondary">Lo sugerimos según los títulos; corregí si hace falta.</span></div>
        <div class="card-body row g-2">
            <?php foreach ($columns as $field => $def): ?>
                <div class="col-sm-6 col-lg-4">
                    <label class="form-label small mb-0" for="map_<?= e($field) ?>"><?= e($def['label']) ?><?= !empty($def['required']) ? ' <span class="text-danger">*</span>' : '' ?></label>
                    <select class="form-select form-select-sm" id="map_<?= e($field) ?>" name="map[<?= e($field) ?>]">
                        <option value="">— no está en el archivo —</option>
                        <?php foreach ($headers as $i => $h): ?>
                            <option value="<?= e($i) ?>" <?= ($mapping[$field] ?? null) === $i ? 'selected' : '' ?>><?= e($h !== '' ? $h : 'Columna ' . ($i + 1)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!empty($def['help'])): ?><div class="form-text small"><?= e($def['help']) ?></div><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="col-12"><button class="btn btn-outline-primary btn-sm">Aplicar columnas y revisar</button></div>
        </div>
    </form>

    <?php if ($mappingErrors): ?>
        <div class="alert alert-warning"><?= implode('<br>', array_map('e', $mappingErrors)) ?></div>
    <?php elseif ($preview): $c = $preview['counts']; ?>
        <div class="card shadow-sm mb-3" id="vista-previa" x-data="importRunner('<?= e(url('/panel/importar/' . $import['uuid'] . '/lote')) ?>', '<?= e(csrf_token()) ?>', <?= (int) $import['total_rows'] ?>)">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center">
                <strong>4. Vista previa</strong>
                <span class="badge text-bg-success"><?= e($c['create']) ?> nuevos</span>
                <span class="badge text-bg-info"><?= e($c['update']) ?> se actualizan</span>
                <?php if ($c['unchanged']): ?><span class="badge text-bg-secondary"><?= e($c['unchanged']) ?> ya existen</span><?php endif; ?>
                <span class="badge text-bg-danger"><?= e($c['error']) ?> con error (no se importan)</span>
            </div>
            <div class="table-responsive" style="max-height: 420px">
                <table class="table table-sm small mb-0 align-middle">
                    <thead class="sticky-top bg-body"><tr><th>Fila</th><th></th>
                        <?php foreach ($columns as $field => $def): if (($mapping[$field] ?? null) === null) continue; ?><th><?= e($def['label']) ?></th><?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php
                    // Primero los errores (lo que hay que mirar), después hasta 100 filas de muestra.
                    $shown = array_merge(array_filter($preview['rows'], fn ($r) => $r['action'] === 'error'), array_slice(array_filter($preview['rows'], fn ($r) => $r['action'] !== 'error'), 0, 100));
                    foreach ($shown as $r): [$text, $class] = $actionLabel[$r['action']]; ?>
                        <tr class="<?= $r['action'] === 'error' ? 'table-danger' : '' ?>">
                            <td><?= e($r['line']) ?></td>
                            <td><span class="badge text-bg-<?= e($class) ?>"><?= e($text) ?></span>
                                <?php if ($r['errors']): ?><div class="text-danger"><?= e(implode(' ', $r['errors'])) ?></div><?php endif; ?></td>
                            <?php foreach ($columns as $field => $def): if (($mapping[$field] ?? null) === null) continue; ?><td><?= e($r['values'][$field]) ?></td><?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-body border-top">
                <template x-if="!running && !done">
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <button class="btn btn-primary" @click="run()" <?= $c['create'] + $c['update'] + $c['unchanged'] === 0 ? 'disabled' : '' ?>>
                            Importar <?= e($c['create'] + $c['update'] + $c['unchanged']) ?> fila(s)
                        </button>
                        <span class="small text-body-secondary">Las filas con error se saltean. Podés corregirlas en el archivo y volver a importarlo: no se duplica nada.</span>
                    </div>
                </template>
                <div x-show="running || done" x-cloak>
                    <div class="progress mb-2" role="progressbar"><div class="progress-bar" :style="'width:' + pct() + '%'" x-text="pct() + '%'"></div></div>
                    <div class="small" x-text="status"></div>
                </div>
                <div class="text-danger small mt-2" x-show="error" x-text="error"></div>
            </div>
        </div>
    <?php endif; ?>
<?php else: ?>
    <div class="card shadow-sm mb-3" x-data="importRunner('<?= e(url('/panel/importar/' . $import['uuid'] . '/lote')) ?>', '<?= e(csrf_token()) ?>', <?= (int) $import['total_rows'] ?>, <?= (int) $import['processed_rows'] ?>, <?= $import['status'] === 'done' ? 'true' : 'false' ?>)">
        <div class="card-body">
            <?php if ($import['status'] === 'done'): ?>
                <h2 class="h5">Importación terminada</h2>
                <p class="mb-2">
                    <span class="badge text-bg-success"><?= e($import['created_count']) ?> creados</span>
                    <span class="badge text-bg-info"><?= e($import['updated_count']) ?> actualizados</span>
                    <span class="badge text-bg-danger"><?= e($import['error_count']) ?> con error</span>
                </p>
                <?php if ((int) $import['error_count'] > 0): ?>
                    <a class="btn btn-outline-danger btn-sm" href="<?= e(url('/panel/importar/' . $import['uuid'] . '/errores')) ?>">Descargar filas con error (.csv)</a>
                    <span class="small text-body-secondary ms-2">Corregilas y volvé a importar ese archivo.</span>
                <?php endif; ?>
                <div class="mt-3"><a class="btn btn-primary btn-sm" href="<?= e(url('/panel/datos/' . ($import['entity'] === 'sectores' ? 'sectores' : $import['entity']))) ?>">Ver <?= e(mb_strtolower($importer->label())) ?></a></div>
            <?php else: ?>
                <h2 class="h5">La importación quedó a mitad (<?= e($import['processed_rows']) ?> de <?= e($import['total_rows']) ?>)</h2>
                <button class="btn btn-primary" @click="run()" x-show="!running && !done">Continuar desde donde quedó</button>
                <div x-show="running || done" x-cloak>
                    <div class="progress mb-2"><div class="progress-bar" :style="'width:' + pct() + '%'" x-text="pct() + '%'"></div></div>
                    <div class="small" x-text="status"></div>
                </div>
                <div class="text-danger small mt-2" x-show="error" x-text="error"></div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
function importRunner(url, csrf, total, processed = 0, done = false) {
    return {
        running: false, done, processed, total, status: '', error: '',
        pct() { return this.total ? Math.round(this.processed * 100 / this.total) : 100; },
        async run() {
            this.running = true; this.error = '';
            while (!this.done) {
                try {
                    const res = await fetch(url, { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' } });
                    const json = await res.json();
                    if (!json.ok) { this.error = json.error.message; break; }
                    Object.assign(this, { processed: json.data.processed, done: json.data.done });
                    this.status = `${json.data.processed} de ${json.data.total} filas · ${json.data.created} creados · ${json.data.updated} actualizados · ${json.data.errors} con error`;
                } catch (e) {
                    this.error = 'Se cortó la conexión. Tocá "Continuar" para seguir desde donde quedó.'; break;
                }
            }
            this.running = false;
            if (this.done) setTimeout(() => location.reload(), 800);
        },
    };
}
</script>
