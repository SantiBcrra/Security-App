<?php
/** @var array $old @var array $errors @var array $requirements @var bool $canInstall @var array $timezones */
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
<h1 class="h4 mb-3">Instalación</h1>

<div class="card shadow-sm mb-3">
    <div class="card-header"><strong>1. Requisitos del servidor</strong></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($requirements as $req): ?>
            <li class="list-group-item d-flex justify-content-between py-1 small">
                <span><?= e($req['name']) ?></span>
                <span class="<?= $req['ok'] ? 'text-success' : 'text-danger fw-semibold' ?>"><?= $req['ok'] ? '✓' : '✗' ?> <?= e($req['detail']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
    <div class="card-footer small">
        Diagnóstico completo: <a href="<?= e(url('install/check.php')) ?>" target="_blank" rel="noopener">install/check.php</a>
    </div>
</div>

<?php if (!$canInstall): ?>
    <div class="alert alert-danger">Hay requisitos sin cumplir. Resolvelos y recargá esta página para continuar.</div>
<?php else: ?>
<form method="post" action="<?= e(url('/install')) ?>" autocomplete="off"
      x-data="dbTest('<?= e(url('/install/test-db')) ?>', '<?= e(csrf_token()) ?>')" @submit="installing = true">
    <?= csrf_field() ?>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><strong>2. Base de datos maestra</strong></div>
        <div class="card-body">
            <p class="small text-body-secondary">Creá primero una base vacía desde el panel del hosting (o phpMyAdmin en local). Cada empresa tendrá después su propia base.</p>
            <div class="row g-3">
                <div class="col-md-8"><?= $field('db_host', 'Servidor', 'text', ['required' => true]) ?></div>
                <div class="col-md-4"><?= $field('db_port', 'Puerto', 'text', ['required' => true, 'inputmode' => 'numeric']) ?></div>
                <div class="col-md-4"><?= $field('db_name', 'Base de datos', 'text', ['required' => true]) ?></div>
                <div class="col-md-4"><?= $field('db_user', 'Usuario', 'text', ['required' => true]) ?></div>
                <div class="col-md-4"><?= $field('db_pass', 'Contraseña', 'password') ?></div>
            </div>
            <div class="mt-3 d-flex align-items-center gap-3">
                <button type="button" class="btn btn-outline-primary btn-sm" @click="test()" :disabled="testing">
                    <span x-show="testing" class="spinner-border spinner-border-sm me-1"></span>Probar conexión
                </button>
                <span class="small" :class="ok ? 'text-success' : 'text-danger'" x-text="message"></span>
            </div>
            <div class="small text-warning-emphasis mt-2" x-show="warning" x-text="warning"></div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><strong>3. Super-admin de la plataforma</strong></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6"><?= $field('admin_name', 'Nombre', 'text', ['required' => true, 'maxlength' => '120']) ?></div>
                <div class="col-md-6"><?= $field('admin_email', 'Email', 'email', ['required' => true, 'autocomplete' => 'username']) ?></div>
                <div class="col-md-6"><?= $field('admin_password', 'Contraseña (mín. 10 caracteres)', 'password', ['required' => true, 'minlength' => '10', 'autocomplete' => 'new-password']) ?></div>
                <div class="col-md-6"><?= $field('admin_password_confirmation', 'Repetir contraseña', 'password', ['required' => true, 'minlength' => '10', 'autocomplete' => 'new-password']) ?></div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-3">
        <div class="card-header"><strong>4. Entorno</strong></div>
        <div class="card-body row g-3">
            <div class="col-md-6">
                <label class="form-label" for="f_env">Entorno</label>
                <select class="form-select" id="f_env" name="env">
                    <option value="local" <?= $old['env'] === 'local' ? 'selected' : '' ?>>Local (XAMPP) — muestra errores detallados</option>
                    <option value="production" <?= $old['env'] === 'production' ? 'selected' : '' ?>>Producción — errores ocultos</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="f_timezone">Zona horaria por defecto</label>
                <select class="form-select" id="f_timezone" name="timezone">
                    <?php foreach ($timezones as $tz): ?>
                        <option value="<?= e($tz) ?>" <?= $old['timezone'] === $tz ? 'selected' : '' ?>><?= e($tz) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <button class="btn btn-primary btn-lg w-100" :disabled="installing">
        <span x-show="installing" class="spinner-border spinner-border-sm me-1"></span>Instalar
    </button>
</form>

<script>
function dbTest(url, csrf) {
    return {
        testing: false, installing: false, ok: false, message: '', warning: '',
        async test() {
            this.testing = true; this.message = ''; this.warning = '';
            const f = this.$root;
            const body = new URLSearchParams({
                db_host: f.db_host.value, db_port: f.db_port.value, db_name: f.db_name.value,
                db_user: f.db_user.value, db_pass: f.db_pass.value,
            });
            try {
                const res = await fetch(url, { method: 'POST', body, headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' } });
                const json = await res.json();
                this.ok = json.ok;
                this.message = json.ok ? '✓ Conexión correcta · ' + json.data.version : '✗ ' + json.error.message;
                this.warning = json.ok && json.data.warning ? json.data.warning : '';
            } catch (e) {
                this.ok = false; this.message = '✗ No se pudo probar la conexión.';
            } finally {
                this.testing = false;
            }
        },
    };
}
</script>
<?php endif; ?>
