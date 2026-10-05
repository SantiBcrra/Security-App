<?php
/**
 * Security App — Diagnóstico del servidor.
 *
 * Standalone a propósito: NO usa el core, así funciona aunque el resto falle.
 * Correrlo en el hosting real ANTES de instalar. Se bloquea solo cuando existe
 * storage/installed.lock (lo crea el instalador en la Etapa 1).
 */
declare(strict_types=1);

$basePath = dirname(__DIR__);
$minPhp = '8.2.0';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

if (is_file($basePath . '/storage/installed.lock')) {
    http_response_code(403);
    exit('El sistema ya está instalado: el diagnóstico está bloqueado.');
}

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function iniBytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $num = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g' => $num * 1024 ** 3,
        'm' => $num * 1024 ** 2,
        'k' => $num * 1024,
        default => $num,
    };
}

/** @return array{0:string,1:string,2:string,3:string} [grupo, nombre, estado ok|warn|fail, detalle] */
function check(string $group, string $name, string $status, string $detail): array
{
    return [$group, $name, $status, $detail];
}

$checks = [];

// ── PHP ────────────────────────────────────────────────────────────────
$checks[] = check('PHP', 'Versión de PHP', version_compare(PHP_VERSION, $minPhp, '>=') ? 'ok' : 'fail',
    PHP_VERSION . " (mínimo {$minPhp})");

foreach (['pdo_mysql', 'mbstring', 'openssl', 'curl', 'gd', 'fileinfo', 'zip', 'json'] as $ext) {
    $checks[] = check('Extensiones', $ext, extension_loaded($ext) ? 'ok' : 'fail',
        extension_loaded($ext) ? 'cargada' : 'FALTA — pedir al hosting que la habilite');
}

// ── Carpetas escribibles (prueba real de escritura y borrado) ─────────
foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/cache', 'storage/tenants', 'config'] as $dir) {
    $full = $basePath . '/' . $dir;
    if (!is_dir($full)) {
        @mkdir($full, 0755, true);
    }
    $probe = $full . '/.write-test-' . bin2hex(random_bytes(4));
    $ok = @file_put_contents($probe, 'ok') === 2 && @unlink($probe);
    $hint = $dir === 'config' ? 'el instalador necesita escribir config.local.php' : 'necesario para logs, sesiones y archivos';
    $checks[] = check('Permisos', $dir . '/', $ok ? 'ok' : 'fail', $ok ? 'escribible' : "NO escribible — {$hint}");
}

// ── Límites ────────────────────────────────────────────────────────────
$upload = ini_get('upload_max_filesize');
$post = ini_get('post_max_size');
$exec = (int) ini_get('max_execution_time');
$memory = ini_get('memory_limit');
$checks[] = check('Límites', 'upload_max_filesize', iniBytes($upload) >= 2 * 1024 ** 2 ? 'ok' : 'warn',
    $upload . ' (las fotos se suben en partes de ~1 MB; alcanza con 2M)');
$checks[] = check('Límites', 'post_max_size', iniBytes($post) >= 2 * 1024 ** 2 ? 'ok' : 'warn', $post);
$checks[] = check('Límites', 'max_execution_time', ($exec === 0 || $exec >= 30) ? 'ok' : 'warn',
    $exec . ' s (importaciones y cron trabajan en lotes cortos)');
$checks[] = check('Límites', 'memory_limit', (iniBytes($memory) === -1 || iniBytes($memory) >= 128 * 1024 ** 2) ? 'ok' : 'warn',
    $memory . ' (recomendado 128M o más para PDFs)');

$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
$checks[] = check('Límites', 'set_time_limit', in_array('set_time_limit', $disabled, true) ? 'warn' : 'ok',
    in_array('set_time_limit', $disabled, true) ? 'deshabilitada por el hosting' : 'disponible');

// ── Servidor ───────────────────────────────────────────────────────────
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$checks[] = check('Servidor', 'HTTPS', $https ? 'ok' : 'warn',
    $https ? 'activo' : 'sin HTTPS (normal en XAMPP; obligatorio en producción)');
$checks[] = check('Servidor', 'Zona horaria de PHP', 'ok', date_default_timezone_get() . ' (la app fuerza UTC internamente)');
$apacheMods = function_exists('apache_get_modules') ? apache_get_modules() : null;
if ($apacheMods !== null) {
    $checks[] = check('Servidor', 'mod_rewrite (módulo)', in_array('mod_rewrite', $apacheMods, true) ? 'ok' : 'fail',
        in_array('mod_rewrite', $apacheMods, true) ? 'cargado' : 'no cargado');
}

// ── Prueba opcional de conexión MySQL (no se guarda nada) ─────────────
session_name('secapp_check');
session_start();
if (empty($_SESSION['check_csrf'])) {
    $_SESSION['check_csrf'] = bin2hex(random_bytes(16));
}
$dbResult = null;
$form = ['host' => 'localhost', 'port' => '3306', 'database' => '', 'username' => ''];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($form as $k => $_) {
        $form[$k] = trim((string) ($_POST[$k] ?? ''));
    }
    if (!hash_equals($_SESSION['check_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $dbResult = ['fail', 'Formulario vencido, recargá la página.', []];
    } elseif (!extension_loaded('pdo_mysql')) {
        $dbResult = ['fail', 'Falta la extensión pdo_mysql.', []];
    } else {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $form['host'], (int) $form['port'], $form['database']),
                $form['username'],
                (string) ($_POST['password'] ?? ''),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            $info = $pdo->query('SELECT VERSION() v, @@character_set_server cs, @@collation_server co, @@sql_mode sm')->fetch(PDO::FETCH_ASSOC);
            $rows = [
                ['Versión', $info['v']],
                ['Charset / collation del servidor', $info['cs'] . ' / ' . $info['co']],
                ['sql_mode del servidor', $info['sm'] !== '' ? $info['sm'] : '(vacío)'],
            ];
            $pdo->exec("SET time_zone = '+00:00'");
            $rows[] = ['SET time_zone UTC', 'ok'];
            $pdo->exec('CREATE TEMPORARY TABLE _secapp_check (id INT AUTO_INCREMENT PRIMARY KEY, txt VARCHAR(191)) '
                . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            $pdo->exec("INSERT INTO _secapp_check (txt) VALUES ('Señal ñandú ✓')");
            $back = $pdo->query('SELECT txt FROM _secapp_check')->fetchColumn();
            $rows[] = ['Tabla InnoDB utf8mb4 (crear/insertar/leer)', $back === 'Señal ñandú ✓' ? 'ok' : 'los acentos no vuelven iguales'];
            $dbResult = ['ok', 'Conexión correcta.', $rows];
        } catch (Throwable $e) {
            $dbResult = ['fail', 'No se pudo conectar: ' . $e->getMessage(), []];
        }
    }
}

$fails = count(array_filter($checks, fn ($c) => $c[2] === 'fail'));
$warns = count(array_filter($checks, fn ($c) => $c[2] === 'warn'));
$labels = ['ok' => 'OK', 'warn' => 'Atención', 'fail' => 'Error'];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Diagnóstico del servidor · Security App</title>
<style>
    :root { --ok:#198754; --warn:#b58105; --fail:#dc3545; --muted:#6c757d; --line:#dee2e6; }
    * { box-sizing: border-box; }
    body { font: 15px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f6f7f9; color: #212529; }
    main { max-width: 860px; margin: 0 auto; padding: 24px 16px 48px; }
    h1 { font-size: 1.5rem; margin: 0 0 4px; }
    .muted { color: var(--muted); }
    .summary { padding: 12px 16px; border-radius: 8px; margin: 16px 0; font-weight: 600; color: #fff; }
    table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
    th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid var(--line); vertical-align: top; }
    th.group { background: #eef0f3; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
    .st { font-weight: 600; white-space: nowrap; }
    .st.ok { color: var(--ok); } .st.warn { color: var(--warn); } .st.fail { color: var(--fail); }
    form { background: #fff; border: 1px solid var(--line); border-radius: 8px; padding: 16px; display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
    label { display: grid; gap: 4px; font-size: .85rem; color: var(--muted); }
    input { font: inherit; padding: 6px 8px; border: 1px solid #ced4da; border-radius: 6px; }
    button { font: inherit; padding: 8px 14px; border: 0; border-radius: 6px; background: #0d6efd; color: #fff; cursor: pointer; align-self: end; }
    h2 { font-size: 1.1rem; margin: 28px 0 10px; }
    .box { padding: 10px 14px; border-radius: 8px; margin-top: 10px; background: #fff; border: 1px solid var(--line); }
</style>
</head>
<body>
<main>
    <h1>Diagnóstico del servidor</h1>
    <div class="muted">Security App · <?= h(gmdate('Y-m-d H:i')) ?> UTC</div>

    <div id="summary" class="summary" style="background: <?= $fails ? 'var(--fail)' : ($warns ? 'var(--warn)' : 'var(--ok)') ?>">
        <?= $fails ? "{$fails} error(es) a resolver" : ($warns ? "Todo funciona, con {$warns} punto(s) a revisar" : 'Todo en verde') ?>
        <span id="summary-js"></span>
    </div>

    <table>
        <?php $last = null; foreach ($checks as [$group, $name, $status, $detail]): ?>
            <?php if ($group !== $last): $last = $group; ?>
                <tr><th class="group" colspan="3"><?= h($group) ?></th></tr>
            <?php endif; ?>
            <tr>
                <td><?= h($name) ?></td>
                <td class="st <?= h($status) ?>"><?= h($labels[$status]) ?></td>
                <td><?= h($detail) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><th class="group" colspan="3">Pruebas desde el navegador</th></tr>
        <tr data-js="rewrite"><td>URLs limpias (mod_rewrite)</td><td class="st">…</td><td>probando</td></tr>
        <tr data-js="auth"><td>Header Authorization llega a PHP</td><td class="st">…</td><td>probando</td></tr>
        <tr data-js="storage"><td>/storage bloqueado por web</td><td class="st">…</td><td>probando</td></tr>
        <tr data-js="config"><td>/config bloqueado por web</td><td class="st">…</td><td>probando</td></tr>
    </table>

    <h2>Prueba de conexión MySQL (opcional)</h2>
    <p class="muted">Prueba los datos de la base creada en el panel. No se guarda nada.</p>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['check_csrf']) ?>">
        <label>Servidor <input name="host" value="<?= h($form['host']) ?>" required></label>
        <label>Puerto <input name="port" value="<?= h($form['port']) ?>" inputmode="numeric" required></label>
        <label>Base de datos <input name="database" value="<?= h($form['database']) ?>" required></label>
        <label>Usuario <input name="username" value="<?= h($form['username']) ?>" required></label>
        <label>Contraseña <input name="password" type="password"></label>
        <button type="submit">Probar conexión</button>
    </form>
    <?php if ($dbResult): [$st, $msg, $rows] = $dbResult; ?>
        <div class="box">
            <div class="st <?= h($st) ?>"><?= h($msg) ?></div>
            <?php if ($rows): ?>
                <table style="margin-top:10px">
                    <?php foreach ($rows as [$k, $v]): ?>
                        <tr><td><?= h($k) ?></td><td><?= h($v) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
<script>
(function () {
    // Base de la app: todo lo que está antes de /install/check.php (subcarpeta o subdominio)
    var base = location.pathname.replace(/\/install\/check\.php.*$/, '');
    var labels = { ok: 'OK', warn: 'Atención', fail: 'Error' };
    var results = [];

    function set(key, status, detail) {
        var row = document.querySelector('tr[data-js="' + key + '"]');
        row.children[1].className = 'st ' + status;
        row.children[1].textContent = labels[status];
        row.children[2].textContent = detail;
        results.push(status);
        if (results.length === 4) {
            var bad = results.filter(function (s) { return s === 'fail'; }).length;
            if (bad) {
                var s = document.getElementById('summary');
                s.style.background = 'var(--fail)';
                document.getElementById('summary-js').textContent = ' · ' + bad + ' prueba(s) del navegador fallaron';
            }
        }
    }

    function getJson(url, headers) {
        return fetch(url, { headers: headers || {}, cache: 'no-store' }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    getJson(base + '/_diag/rewrite').then(function () {
        set('rewrite', 'ok', 'funciona');
    }).catch(function () {
        return getJson(base + '/index.php?r=/_diag/rewrite').then(function () {
            set('rewrite', 'fail', 'el front controller anda pero mod_rewrite/.htaccess no (AllowOverride?)');
        }, function () {
            set('rewrite', 'fail', 'no responde el front controller');
        });
    });

    getJson(base + '/index.php?r=/_diag/auth', { Authorization: 'Bearer diag-test' }).then(function (j) {
        j.data && j.data.authorization
            ? set('auth', 'ok', 'llega correctamente')
            : set('auth', 'fail', 'Apache lo descarta: revisar la regla HTTP_AUTHORIZATION del .htaccess');
    }).catch(function (e) { set('auth', 'fail', 'no se pudo probar (' + e.message + ')'); });

    function blocked(key, path) {
        fetch(base + path, { cache: 'no-store' }).then(function (r) {
            r.status === 200
                ? set(key, 'fail', 'ACCESIBLE por web (HTTP 200): revisar .htaccess')
                : set(key, 'ok', 'bloqueado (HTTP ' + r.status + ')');
        }).catch(function () { set(key, 'ok', 'bloqueado'); });
    }
    blocked('storage', '/storage/.htaccess');
    blocked('config', '/config/config.php');
})();
</script>
</body>
</html>
