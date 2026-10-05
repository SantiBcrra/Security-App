<?php
/** @var array $old @var array $errors */
$field = function (string $name, string $label, string $type = 'text', array $attrs = []) use ($old, $errors): string {
    $id = 'f_' . $name;
    $invalid = isset($errors[$name]) ? ' is-invalid' : '';
    $value = $type === 'password' ? '' : ($old[$name] ?? '');
    $extra = '';
    foreach ($attrs as $k => $v) {
        $extra .= ' ' . e($k) . ($v === true ? '' : '="' . e($v) . '"');
    }
    return '<label class="form-label" for="' . e($id) . '">' . e($label) . '</label>'
        . '<input class="form-control' . $invalid . '" type="' . e($type) . '" id="' . e($id) . '" name="' . e($name) . '" value="' . e($value) . '"' . $extra . '>'
        . (isset($errors[$name]) ? '<div class="invalid-feedback">' . e($errors[$name]) . '</div>' : '');
};
?>
<div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/admin/empresas')) ?>">←</a>
    <h1 class="h4 m-0">Nueva empresa</h1>
</div>

<form method="post" action="<?= e(url('/admin/empresas')) ?>" autocomplete="off"
      x-data="tenantForm(<?= e(json_encode(['mode' => $old['db_mode'], 'slug' => $old['slug']])) ?>, '<?= e(url('/admin/empresas/probar-base')) ?>', '<?= e(csrf_token()) ?>', '<?= e(config('db.master.database')) ?>')"
      @submit="saving = true">
    <?= csrf_field() ?>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><strong>Datos de la empresa</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-6"><?= $field('name', 'Nombre', 'text', ['required' => true, 'maxlength' => '120', '@input' => 'suggestSlug($event.target.value)']) ?></div>
            <div class="col-md-6">
                <?= $field('slug', 'Identificador (sin espacios)', 'text', ['maxlength' => '40', 'x-model' => 'slug', '@input' => 'slugTouched = true']) ?>
                <div class="form-text">Se usa en la base: <code x-text="dbName()"></code></div>
            </div>
            <div class="col-md-6"><?= $field('legal_name', 'Razón social', 'text', ['maxlength' => '191']) ?></div>
            <div class="col-md-6"><?= $field('cuit', 'CUIT', 'text', ['placeholder' => '30-12345678-9', 'inputmode' => 'numeric']) ?></div>
            <div class="col-md-4">
                <label class="form-label" for="f_timezone">Zona horaria</label>
                <select class="form-select" id="f_timezone" name="timezone">
                    <?php foreach (App\Core\Tenant::TIMEZONES as $tz): ?>
                        <option value="<?= e($tz) ?>" <?= $old['timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="f_status">Estado inicial</label>
                <select class="form-select" id="f_status" name="status">
                    <option value="trial" <?= $old['status'] === 'trial' ? 'selected' : '' ?>>Prueba</option>
                    <option value="active" <?= $old['status'] === 'active' ? 'selected' : '' ?>>Activa</option>
                </select>
            </div>
            <div class="col-md-4"><?= $field('plan', 'Plan (opcional)', 'text', ['maxlength' => '40']) ?></div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><strong>Base de datos de la empresa</strong></div>
        <div class="card-body">
            <p class="small text-body-secondary">Cada empresa tiene su propia base, totalmente independiente de las demás.</p>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="db_mode" id="mode_auto" value="auto" x-model="mode">
                <label class="form-check-label" for="mode_auto">Crearla automáticamente (<code x-text="dbName()"></code>)</label>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="db_mode" id="mode_existing" value="existing" x-model="mode">
                <label class="form-check-label" for="mode_existing">Usar una base existente (la creaste en el panel del hosting)</label>
            </div>
            <div class="row g-3 mt-1" x-show="mode === 'existing'" x-cloak>
                <div class="col-md-8"><?= $field('db_host', 'Servidor') ?></div>
                <div class="col-md-4"><?= $field('db_port', 'Puerto', 'text', ['inputmode' => 'numeric']) ?></div>
                <div class="col-md-4"><?= $field('db_name', 'Base de datos') ?></div>
                <div class="col-md-4"><?= $field('db_user', 'Usuario') ?></div>
                <div class="col-md-4"><?= $field('db_pass', 'Contraseña', 'password', ['autocomplete' => 'new-password']) ?></div>
                <div class="col-12 d-flex align-items-center gap-3">
                    <button type="button" class="btn btn-outline-primary btn-sm" @click="test()" :disabled="testing">
                        <span x-show="testing" class="spinner-border spinner-border-sm me-1"></span>Probar conexión
                    </button>
                    <span class="small" :class="ok ? 'text-success' : 'text-danger'" x-text="message"></span>
                </div>
            </div>
        </div>
    </div>

    <button class="btn btn-primary" :disabled="saving">
        <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>Crear empresa
    </button>
</form>

<script>
function tenantForm(init, testUrl, csrf, prefix) {
    return {
        mode: init.mode, slug: init.slug, slugTouched: init.slug !== '',
        testing: false, saving: false, ok: false, message: '',
        suggestSlug(name) {
            if (this.slugTouched) return;
            this.slug = name.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
                .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
        },
        dbName() { return (prefix + '_' + (this.slug || '…').replace(/-/g, '_')).slice(0, 64); },
        async test() {
            this.testing = true; this.message = '';
            const f = this.$root;
            const body = new URLSearchParams({ db_host: f.db_host.value, db_port: f.db_port.value,
                db_name: f.db_name.value, db_user: f.db_user.value, db_pass: f.db_pass.value });
            try {
                const res = await fetch(testUrl, { method: 'POST', body, headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' } });
                const json = await res.json();
                this.ok = json.ok;
                this.message = json.ok ? '✓ Conexión correcta · ' + json.data.version : '✗ ' + json.error.message;
            } catch (e) {
                this.ok = false; this.message = '✗ No se pudo probar la conexión.';
            } finally {
                this.testing = false;
            }
        },
    };
}
</script>
