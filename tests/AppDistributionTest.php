<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Storage;
use App\Core\Tenant;
use App\Models\AppReleases;
use App\Models\PlatformSettings;
use App\Models\Roles;
use App\Models\Tenants;
use App\Models\Users;
use App\Services\AppDistribution;
use App\Services\Consent;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * App Android (entrega 1): distribución propia del APK (sin Google Play) y consentimiento del empleado.
 * Maestra `securityapp_test_apk`.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_apk';
$st = ['ready' => false];

$root = function () use ($server): PDO {
    try {
        return new PDO("mysql:host={$server['host']};charset=utf8mb4", $server['username'], $server['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
    } catch (PDOException) {
        throw new SkipTest('sin MySQL local');
    }
};
$dropAll = function (PDO $root) use ($master): void {
    foreach ($root->query("SHOW DATABASES LIKE '{$master}%'")->fetchAll(PDO::FETCH_COLUMN) as $db) {
        $root->exec("DROP DATABASE `{$db}`");
    }
};
$setup = function () use (&$st, $root, $dropAll, $server, $master): void {
    if ($st['ready']) {
        return;
    }
    $pdo = $root();
    $dropAll($pdo);
    $pdo->exec("CREATE DATABASE `{$master}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    Config::set('app.key', base64_encode(random_bytes(32)));
    Config::set('db.master', $server + ['database' => $master]);
    DB::reset();
    PlatformSettings::forget();
    $_SESSION = [];
    assert_same(null, Migrator::run(DB::master(), BASE_PATH . Migrator::MASTER_DIR)['error']);
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Apk A', 'slug' => 'apk-a', 'legal_name' => null, 'cuit' => null,
        'timezone' => 'America/Argentina/Buenos_Aires', 'status' => 'active', 'plan' => null], null));
    Tenant::activate($st['a']);
    $st['user'] = Users::findById(Users::create(['name' => 'Guardia Uno', 'email' => 'g@apk.test', 'role_id' => (int) Roles::findBySlug('reportante')['id'],
        'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};
/** APK de mentira: un ZIP con AndroidManifest.xml (lo mínimo que valida el servidor). */
$fakeApk = function (bool $valid = true): array {
    $path = tempnam(sys_get_temp_dir(), 'apk');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    $zip->addFromString($valid ? 'AndroidManifest.xml' : 'leeme.txt', str_repeat('x', 2000));
    $zip->addFromString('classes.dex', random_bytes(4000));
    $zip->close();
    return ['tmp_name' => $path, 'name' => 'app-release.apk', 'error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'upload' => false];
};

return [
    'publicar APK: validaciones, versión creciente, hash y versión mínima obligatoria' => function () use ($setup, $fakeApk) {
        $setup();
        assert_same(null, AppDistribution::manifest(), 'sin versiones publicadas');
        $bad = AppDistribution::upload(['version_code' => '1', 'version_name' => '1.0.0'], $fakeApk(false), null);
        assert_true(isset($bad['errors']['apk']), 'un ZIP sin AndroidManifest no es un APK');
        $bad = AppDistribution::upload(['version_code' => '1', 'version_name' => 'uno'], $fakeApk(), null);
        assert_true(isset($bad['errors']['version_name']));
        $one = AppDistribution::upload(['version_code' => '1', 'version_name' => '1.0.0', 'notes' => 'Primera versión'], $fakeApk(), null);
        assert_same([], $one['errors']);
        $file = AppDistribution::filePath($one['release']);
        assert_same(hash_file('sha256', $file), $one['release']['sha256']);
        assert_true(isset(AppDistribution::upload(['version_code' => '1', 'version_name' => '1.0.1'], $fakeApk(), null)['errors']['version_code']), 'el código tiene que crecer');
        assert_true(isset(AppDistribution::upload(['version_code' => '3', 'version_name' => '1.0.3', 'min_version_code' => '4'], $fakeApk(), null)['errors']['min_version_code']));
        assert_same([], AppDistribution::upload(['version_code' => '2', 'version_name' => '1.1.0', 'min_version_code' => '2'], $fakeApk(), null)['errors']);
        $m = AppDistribution::manifest();
        assert_same([2, '1.1.0', 2], [$m['version_code'], $m['version_name'], $m['min_version_code']]);
        assert_true(str_ends_with($m['url'], '/descargas/android/2.apk'));
        // Retirar la última: vuelve a ofrecer la anterior, pero la mínima obligatoria activa se recalcula
        AppReleases::setActive((int) AppReleases::latest()['id'], false);
        $m = AppDistribution::manifest();
        assert_same([1, 0], [$m['version_code'], $m['min_version_code']]);
        AppReleases::setActive((int) AppReleases::all()[0]['id'], true);
    },

    'HTTP: página de descarga, APK, versión para la app (sin sesión) y admin' => function () use ($setup) {
        $setup();
        $router = new \App\Core\Router();
        (require BASE_PATH . '/app/routes.php')($router);
        $_SESSION = [];
        $page = $router->dispatch(new \App\Core\Request('GET', '/descargas/android'));
        assert_true($page->status === 200 && str_contains($page->body, 'Versión 1.1.0') && str_contains($page->body, 'Instalar de todas formas'));
        $apk = $router->dispatch(new \App\Core\Request('GET', '/descargas/android/2.apk'));
        assert_same([200, 'application/vnd.android.package-archive'], [$apk->status, $apk->headers['Content-Type'] ?? null]);
        assert_same(AppReleases::latest()['sha256'], hash('sha256', $apk->body));
        assert_same(404, $router->dispatch(new \App\Core\Request('GET', '/descargas/android/9.apk'))->status);
        $api = json_decode($router->dispatch(new \App\Core\Request('GET', '/api/v1/app/android'))->body, true);
        assert_same([true, 2], [$api['ok'], $api['data']['version_code']]);
        $admin = $router->dispatch(new \App\Core\Request('GET', '/admin/app-android'));
        assert_true($admin->status === 302, 'el admin requiere super-admin');
    },

    'consentimiento: texto versionado, se acepta una vez y queda con hash' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['user']);
        $ctl = new App\Controllers\Api\AppController();
        $c = json_decode($ctl->consent(new \App\Core\Request('GET', '/'))->body, true)['data'];
        assert_true($c['version'] === Consent::VERSION && !$c['aceptado'] && str_contains($c['texto'], 'SOLO mientras hacés una ronda'));
        $old = $ctl->accept(new \App\Core\Request('POST', '/', [], ['version' => '2020-01']));
        assert_same(409, $old->status, 'un texto viejo no vale');
        assert_same(200, $ctl->accept(new \App\Core\Request('POST', '/', [], ['version' => Consent::VERSION]))->status);
        assert_same(200, $ctl->accept(new \App\Core\Request('POST', '/', [], ['version' => Consent::VERSION]))->status, 'reenvío: no duplica');
        $rows = DB::tenant()->query('SELECT * FROM user_consents')->fetchAll();
        assert_same([1, Consent::hash()], [count($rows), $rows[0]['text_sha256']]);
        assert_true(Consent::accepted((int) $st['user']['id']));
    },

    'limpieza: se borran las bases de prueba' => function () use ($root, $dropAll, &$st, $master) {
        if (!$st['ready']) {
            throw new SkipTest('no hubo setup');
        }
        Tenant::deactivate();
        UserAuth::setCurrent(null);
        DB::reset();
        PlatformSettings::forget();
        @unlink(Storage::path('installed.lock'));
        foreach (glob(Storage::path('apps/android') . '/*.apk') ?: [] as $f) {
            @unlink($f);
        }
        $pdo = $root();
        $dropAll($pdo);
        Config::load(BASE_PATH . '/config');
        $_SESSION = [];
        assert_same([], $pdo->query("SHOW DATABASES LIKE '{$master}%'")->fetchAll(PDO::FETCH_COLUMN));
    },
];
