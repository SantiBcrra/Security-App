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
        assert_true(str_ends_with($m['url'], '/api/v1/app/android/apk'));
        // Retirar la última: vuelve a ofrecer la anterior, pero la mínima obligatoria activa se recalcula
        AppReleases::setActive((int) AppReleases::latest()['id'], false);
        $m = AppDistribution::manifest();
        assert_same([1, 0], [$m['version_code'], $m['min_version_code']]);
        AppReleases::setActive((int) AppReleases::all()[0]['id'], true);
    },

    'publicar APK por partes (internet lento / límite del hosting), retomar, archivo dañado y bandeja FTP' => function () use ($setup, $fakeApk) {
        $setup();
        $apk = $fakeApk();
        $bytes = (string) file_get_contents($apk['tmp_name']);
        $sha = hash('sha256', $bytes);
        $start = AppDistribution::chunkStart('app-release.apk', strlen($bytes), $sha);
        assert_same(0, $start['received']);
        $half = intdiv(strlen($bytes), 2);
        assert_same($half, AppDistribution::chunkAppend($start['id'], 0, substr($bytes, 0, $half)));
        assert_same($half, AppDistribution::chunkAppend($start['id'], 0, substr($bytes, 0, $half)), 'parte repetida (reintento): no se duplica');
        assert_same($half, AppDistribution::chunkStart('app-release.apk', strlen($bytes), $sha)['received'], 'retoma desde lo recibido');
        assert_same(AppDistribution::chunkFile($start['id'])['error'], UPLOAD_ERR_PARTIAL, 'incompleto no se publica');
        assert_same(strlen($bytes), AppDistribution::chunkAppend($start['id'], $half, substr($bytes, $half)));
        $code = AppReleases::maxCode() + 1;
        $r = AppDistribution::upload(['version_code' => (string) $code, 'version_name' => '1.5.0'], AppDistribution::chunkFile($start['id']), null);
        assert_same([], $r['errors']);
        assert_same($sha, $r['release']['sha256']);
        assert_true(!is_file(App\Core\Storage::path('apps/android/partes/' . $start['id'] . '.part')), 'se borra la parte temporal');
        // Dañado: la huella no coincide
        $bad = AppDistribution::chunkStart('otra.apk', 10, str_repeat('a', 64));
        AppDistribution::chunkAppend($bad['id'], 0, '0123456789');
        try { AppDistribution::chunkFile($bad['id']); assert_true(false, 'tenía que rechazarlo'); } catch (App\Core\UserError $e) { assert_true(str_contains($e->getMessage(), 'dañado')); }
        try { AppDistribution::chunkStart('virus.exe', 10, $sha); assert_true(false); } catch (App\Core\UserError) {}
        // Bandeja FTP
        $inbox = App\Core\Storage::path(AppDistribution::INBOX);
        @mkdir($inbox, 0777, true);
        copy($fakeApk()['tmp_name'], $inbox . '/SecurityApp-1.6.apk');
        assert_same(['SecurityApp-1.6.apk'], array_column(AppDistribution::inbox(), 'name'));
        assert_same(null, AppDistribution::inboxFile('../../config/config.php'), 'no sale de la carpeta');
        $r = AppDistribution::upload(['version_code' => (string) ($code + 1), 'version_name' => '1.6.0'], AppDistribution::inboxFile('SecurityApp-1.6.apk'), null);
        assert_same([], $r['errors']);
        assert_same([], AppDistribution::inbox(), 'se mueve a las versiones publicadas');
        foreach (AppReleases::all() as $row) if ((int) $row['version_code'] >= $code) AppReleases::setActive((int) $row['id'], false); // el test siguiente espera la 1.1.0
    },

    'HTTP: la descarga pide sesión (usuario de la empresa o super-admin); la app se actualiza con su token' => function () use ($setup, &$st) {
        $setup();
        $router = new \App\Core\Router();
        (require BASE_PATH . '/app/routes.php')($router);
        $_SESSION = [];
        UserAuth::setCurrent(null);
        $page = $router->dispatch(new \App\Core\Request('GET', '/descargas/android'));
        assert_same([302, '/descargas/android'], [$page->status, $_SESSION['intended'] ?? null], 'sin sesión: al login y vuelve');
        assert_same(302, $router->dispatch(new \App\Core\Request('GET', '/descargas/android/2.apk'))->status, 'el APK tampoco se baja sin sesión');
        // Con sesión de un usuario de la empresa
        $_SESSION = [\App\Services\Impersonation::TENANT_KEY => $st['a']['uuid'], UserAuth::USER_KEY => $st['user']['uuid']];
        $page = $router->dispatch(new \App\Core\Request('GET', '/descargas/android'));
        assert_true($page->status === 200 && str_contains($page->body, 'Versión 1.1.0') && str_contains($page->body, 'Instalar de todas formas'));
        $apk = $router->dispatch(new \App\Core\Request('GET', '/descargas/android/2.apk'));
        assert_same([200, 'application/vnd.android.package-archive'], [$apk->status, $apk->headers['Content-Type'] ?? null]);
        assert_same(AppReleases::latest()['sha256'], hash('sha256', $apk->body));
        assert_same(404, $router->dispatch(new \App\Core\Request('GET', '/descargas/android/9.apk'))->status);
        $_SESSION = [];
        // La consulta de versión sigue sin sesión (pantalla de ingreso), pero el APK se baja por la API con token.
        $api = json_decode($router->dispatch(new \App\Core\Request('GET', '/api/v1/app/android'))->body, true);
        assert_same([true, 2], [$api['ok'], $api['data']['version_code']]);
        assert_true(str_ends_with($api['data']['url'], '/api/v1/app/android/apk'));
        assert_same(401, $router->dispatch(new \App\Core\Request('GET', '/api/v1/app/android/apk'))->status);
        Tenant::activate($st['a']);
        $tokens = \App\Services\ApiAuth::login('apk-a', 'g@apk.test', 'clave-larga-123', \App\Core\Uuid::v4(), 'Moto', null, '10.0.0.1');
        Tenant::deactivate();
        $res = $router->dispatch(new \App\Core\Request('GET', '/api/v1/app/android/apk', [], [], ['authorization' => 'Bearer ' . $tokens['access_token']]));
        assert_same([200, AppReleases::latest()['sha256']], [$res->status, hash('sha256', $res->body)]);
        $admin = $router->dispatch(new \App\Core\Request('GET', '/admin/app-android'));
        assert_true($admin->status === 302, 'el admin requiere super-admin');
    },

    'consentimiento: texto versionado, se acepta una vez y queda con hash' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['user']);
        $ctl = new App\Controllers\Api\AppController();
        $c = json_decode($ctl->consent(new \App\Core\Request('GET', '/'))->body, true)['data'];
        assert_true($c['version'] === Consent::VERSION && !$c['aceptado'] && str_contains($c['texto'], 'SOLO mientras estás en tu turno'));
        $old = $ctl->accept(new \App\Core\Request('POST', '/', [], ['version' => '2020-01']));
        assert_same(409, $old->status, 'un texto viejo no vale');
        assert_same(200, $ctl->accept(new \App\Core\Request('POST', '/', [], ['version' => Consent::VERSION]))->status);
        assert_same(200, $ctl->accept(new \App\Core\Request('POST', '/', [], ['version' => Consent::VERSION]))->status, 'reenvío: no duplica');
        $rows = DB::tenant()->query('SELECT * FROM user_consents')->fetchAll();
        assert_same([1, Consent::hash()], [count($rows), $rows[0]['text_sha256']]);
        assert_true(Consent::accepted((int) $st['user']['id']));
    },

    'Firebase: configuración, JWT de la cuenta de servicio, envío, token vencido y registro del token del celular' => function () use ($setup, &$st) {
        $setup();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $sa = ['type' => 'service_account', 'project_id' => 'secapp-test', 'client_email' => 'fcm@secapp-test.iam.gserviceaccount.com', 'private_key' => $pem];
        $gs = ['project_info' => ['project_id' => 'secapp-test', 'project_number' => '123456'], 'client' => [
            ['client_info' => ['mobilesdk_app_id' => '1:123456:android:aaa', 'android_client_info' => ['package_name' => 'ar.com.securityapp.campo']], 'api_key' => [['current_key' => 'AIzaPublica']]],
            ['client_info' => ['mobilesdk_app_id' => '1:123456:android:bbb', 'android_client_info' => ['package_name' => 'ar.com.securityapp.campo.debug']], 'api_key' => [['current_key' => 'AIzaPublica']]],
        ]];
        assert_true(str_contains((string) App\Services\Notify\Fcm::validateConfig('{"x":1}', ''), 'google-services'));
        assert_true(str_contains((string) App\Services\Notify\Fcm::validateConfig('', '{"type":"service_account","private_key":"no"}'), 'cuenta de servicio'));
        assert_same(null, App\Services\Notify\Fcm::validateConfig(json_encode($gs), json_encode($sa)));
        assert_same(null, App\Services\Notify\Fcm::clientConfig('ar.com.securityapp.campo'), 'sin configurar no hay datos');
        App\Models\PlatformSettings::set('push.fcm_google_services', json_encode($gs));
        App\Models\PlatformSettings::setSecret('push.fcm_service_account', json_encode($sa));
        assert_same(['123456', '1:123456:android:bbb'], array_values(array_intersect_key(App\Services\Notify\Fcm::clientConfig('ar.com.securityapp.campo.debug'), ['application_id' => 1, 'sender_id' => 1])));
        // JWT RS256 verificable con la clave pública
        $jwt = App\Services\Notify\Fcm::jwt($sa, 1_800_000_000);
        [$h, $c, $sig] = explode('.', $jwt);
        $pub = openssl_pkey_get_details($key)['key'];
        assert_same(1, openssl_verify("$h.$c", base64_decode(strtr($sig, '-_', '+/')), $pub, OPENSSL_ALGO_SHA256));
        assert_same('https://www.googleapis.com/auth/firebase.messaging', json_decode(base64_decode(strtr($c, '-_', '+/')), true)['scope']);
        // Envío: un token OAuth (cacheado) + el mensaje con data y prioridad alta si es crítico
        $calls = [];
        App\Services\Notify\Fcm::$transport = function (string $url, string $body, array $headers) use (&$calls) {
            $calls[] = [$url, $body];
            if (str_contains($url, 'oauth2')) return ['status' => 200, 'body' => '{"access_token":"ya29.x","expires_in":3600}', 'error' => null];
            if (str_contains($body, 'token-viejo')) return ['status' => 404, 'body' => '{"error":{"status":"NOT_FOUND","details":[{"errorCode":"UNREGISTERED"}]}}', 'error' => null];
            return ['status' => 200, 'body' => '{"name":"projects/x/messages/1"}', 'error' => null];
        };
        App\Services\Notify\Fcm::send('token-ok', ['title' => '⚠ PÁNICO', 'body' => 'x', 'critical' => '1'], true);
        App\Services\Notify\Fcm::send('token-ok', ['title' => 'Aviso', 'body' => 'y'], false);
        assert_same(3, count($calls), 'el token OAuth se pide una sola vez');
        $msg = json_decode($calls[1][1], true)['message'];
        assert_same(['token-ok', 'HIGH', '1'], [$msg['token'], $msg['android']['priority'], $msg['data']['critical']]);
        assert_true(str_contains($calls[1][0], '/projects/secapp-test/messages:send'));
        // Token vencido: el canal lo borra del dispositivo
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['user']);
        $deviceUuid = App\Models\UserDevices::upsert((int) $st['user']['id'], App\Core\Uuid::v4(), 'Moto G', hash('sha256', 'x'), gmdate('Y-m-d H:i:s', time() + 86400), null);
        $device = App\Models\UserDevices::findByUuid($deviceUuid);
        $ref = new ReflectionProperty(App\Services\ApiAuth::class, 'device');
        $ref->setAccessible(true);
        $ref->setValue(null, $device);
        $api = new App\Controllers\Api\DevicesController();
        assert_same(422, $api->fcmToken(new App\Core\Request('POST', '/', [], ['token' => 'mal token con espacios']))->status);
        assert_same(200, $api->fcmToken(new App\Core\Request('POST', '/', [], ['token' => 'token-viejo']))->status);
        assert_same('fcm:token-viejo', App\Models\UserDevices::findByUuid($deviceUuid)['push_token']);
        $cfg = json_decode($api->fcmConfig(new App\Core\Request('GET', '/', ['package' => 'ar.com.securityapp.campo']))->body, true)['data']['fcm'];
        assert_same(['secapp-test', 'AIzaPublica'], [$cfg['project_id'], $cfg['api_key']]);
        App\Services\Notify\Channels::$fakeExternal = null;
        App\Services\Notify\Channels::send(['channel' => 'push', 'to_address' => 'fcm:token-viejo', 'subject' => 'x', 'body_text' => 'y', 'is_critical' => 0,
            'payload' => '{}', 'entity_uuid' => null]);
        assert_same(null, App\Models\UserDevices::findByUuid($deviceUuid)['push_token'], 'token desinstalado: se borra');
        App\Services\Notify\Fcm::$transport = null;
        $ref->setValue(null, null);
        App\Models\PlatformSettings::setSecret('push.fcm_service_account', null);
        App\Models\PlatformSettings::set('push.fcm_google_services', null);
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
