<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Ece;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Router;
use App\Core\Storage;
use App\Core\Tenant;
use App\Core\Uuid;
use App\Core\Vapid;
use App\Models\CatalogItems;
use App\Models\ObservationAttachments;
use App\Models\Observations;
use App\Models\PlatformSettings;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\Tenants;
use App\Models\Users;
use App\Models\UserSectors;
use App\Services\ApiAuth;
use App\Services\IndustryTemplates;
use App\Services\Notify\Channels;
use App\Services\Notify\MailTransport;
use App\Services\RateLimiter;
use App\Services\Sync\Pull;
use App\Services\Sync\Push;
use App\Services\TenantProvisioner;
use App\Services\Uploads;
use App\Services\UserAuth;

/**
 * Etapa 5: API de sincronización offline. Maestra `securityapp_test_sync` + empresas sy-a y sy-b.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_sync';
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
    $company = ['legal_name' => null, 'cuit' => null, 'timezone' => 'America/Argentina/Buenos_Aires', 'status' => 'active', 'plan' => null];
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Sy A', 'slug' => 'sy-a'] + $company, null));
    $st['b'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Sy B', 'slug' => 'sy-b'] + $company, null));
    MailTransport::$fake = fn () => null;
    Channels::$fakeExternal = fn () => null;

    Tenant::activate($st['a']);
    IndustryTemplates::apply('metalurgica');
    $site = Sites::create(['name' => 'Planta']);
    $st['nave1'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 1']);
    $st['sold'] = Sectors::create(['site_id' => $site, 'parent_id' => $st['nave1'], 'name' => 'Soldadura']);
    $st['nave2'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 2']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    $st['hys'] = $mk('responsable_hys', 'hys@sy.test');
    $st['sup'] = $mk('supervisor', 'sup@sy.test');
    $st['rep'] = $mk('reportante', 'rep@sy.test');
    UserSectors::replace((int) $st['sup']['id'], [$st['nave1']]);
    // Todo lo creado "hace un minuto": así el cursor no entra en la ventana de solapamiento.
    foreach (['catalog_items', 'sites', 'sectors', 'positions'] as $t) {
        DB::tenant()->exec("UPDATE {$t} SET updated_at = updated_at - INTERVAL 60 SECOND");
    }
    $st['ids'] = fn () => [
        'category' => CatalogItems::findByName('categoria', 'Condición insegura')['uuid'],
        'severity' => CatalogItems::findByName('severidad', 'Alta')['uuid'],
    ];
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

/** Operación de alta como la genera la app. */
$createOp = function (string $sectorUuid, array $ids, ?string $uuid = null): array {
    return ['op_id' => Uuid::v4(), 'type' => 'observation.create', 'data' => [
        'uuid' => $uuid ?? Uuid::v4(), 'category' => $ids['category'], 'severity' => $ids['severity'], 'sector' => $sectorUuid,
        'description' => 'Reporte cargado sin señal en planta', 'created_at_device' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
    ]];
};
$sectorUuid = fn (int $id) => Sectors::findById($id)['uuid'];

return [
    'pull: primera carga, cursor y sin repetidos después' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        $first = Pull::run(null, 1000);
        $changes = (array) $first['changes'];
        assert_true(count($changes['catalog_items']) > 50, 'catálogos');
        assert_same(3, count($changes['sectors']));
        $labels = array_column($changes['sectors'], 'label');
        assert_true(in_array('Planta › Nave 1 › Soldadura', $labels, true), 'etiqueta completa del sector');
        assert_same(false, $first['has_more']);
        $again = Pull::run($first['cursor'], 1000);
        assert_same([], (array) $again['changes'], 'con el cursor no vuelve a mandar lo mismo');
        $st['cursor'] = $again['cursor'];
    },

    'pull: paginado por cursor sin perder ni repetir' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        $seen = [];
        $cursor = null;
        for ($i = 0; $i < 50; $i++) {
            $page = Pull::run($cursor, 10);
            foreach ((array) ($page['changes']->catalog_items ?? []) as $c) {
                $seen[] = $c['uuid'];
            }
            $cursor = $page['cursor'];
            if (!$page['has_more']) {
                break;
            }
        }
        assert_same(CatalogItems::count(null, true), count($seen));
        assert_same(count($seen), count(array_unique($seen)), 'sin repetidos entre páginas');
    },

    'pull: las bajas viajan como "deleted"' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        Sectors::setActive($st['nave2'], false);
        $page = Pull::run($st['cursor'], 1000);
        assert_same([Sectors::findById($st['nave2'])['uuid']], (array) ($page['deleted']->sectors ?? []));
        Sectors::setActive($st['nave2'], true);
    },

    'push: alta con uuid del celular, idempotente por op_id y por uuid' => function () use ($setup, &$st, $createOp, $sectorUuid) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        $op = $createOp($sectorUuid($st['sold']), ($st['ids'])());
        $r1 = Push::run([$op])[0];
        assert_same('ok', $r1['status'], json_encode($r1));
        assert_same($op['data']['uuid'], $r1['data']['uuid']);
        $r2 = Push::run([$op])[0];
        assert_same($r1, $r2, 'mismo op_id: mismo resultado');
        $other = $op;
        $other['op_id'] = Uuid::v4();
        $r3 = Push::run([$other])[0];
        assert_same(true, $r3['data']['duplicate'], 'mismo uuid con otro op_id: no duplica');
        assert_same(1, (int) DB::tenant()->query("SELECT COUNT(*) FROM observations WHERE uuid = " . DB::tenant()->quote($op['data']['uuid']))->fetchColumn());
        $obs = Observations::findByUuid($op['data']['uuid']);
        assert_same(gmdate('Y-m-d H:i:s', strtotime($op['data']['created_at_device'])), $obs['created_at_device'], 'conserva la hora del hecho del celular');
        assert_true($obs['received_at'] > $obs['created_at_device'], 'y registra cuándo llegó');
        $st['obs'] = $obs;
    },

    'push: errores explicativos y se repiten igual' => function () use ($setup, &$st, $createOp, $sectorUuid) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        $bad = $createOp($sectorUuid($st['sold']), ['category' => 'x', 'severity' => 'y']);
        $r1 = Push::run([$bad])[0];
        assert_same('error', $r1['status']);
        assert_true(str_contains($r1['error'], 'severidad'));
        assert_same($r1, Push::run([$bad])[0]);
        $t = ['op_id' => Uuid::v4(), 'type' => 'observation.transition', 'data' => ['uuid' => $st['obs']['uuid'], 'action' => 'cerrar', 'input' => ['comment' => 'listo ok']]];
        assert_same('No tenés permiso para esta acción.', Push::run([$t])[0]['error'], 'el reportante no puede cerrar');
        UserAuth::setCurrent($st['hys']);
        $t['op_id'] = Uuid::v4();
        assert_same('ok', Push::run([$t])[0]['status']);
        $t['op_id'] = Uuid::v4();
        assert_true(str_contains(Push::run([$t])[0]['error'], 'ya está'), 'transición inválida con explicación');
        assert_same('error', Push::run([['op_id' => 'no-uuid', 'type' => 'x']])[0]['status']);
    },

    'pull: alcance de observaciones por rol' => function () use ($setup, &$st, $createOp, $sectorUuid) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        Push::run([$createOp($sectorUuid($st['nave2']), ($st['ids'])())]);
        $uuids = function (array $user) use ($st) {
            UserAuth::setCurrent($user);
            return array_column((array) (Pull::run(null, 1000)['changes']->observations ?? []), 'uuid');
        };
        assert_same(1, count($uuids($st['rep'])), 'el reportante solo la suya');
        assert_same(1, count($uuids($st['sup'])), 'el supervisor solo la de su nave (Soldadura)');
        assert_same(2, count($uuids($st['hys'])), 'SyH todas');
    },

    'subida por partes: reanudar, partes repetidas, hash y completar dos veces' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        $img = imagecreatetruecolor(1400, 1000);
        for ($i = 0; $i < 300; $i++) {
            imagefilledellipse($img, random_int(0, 1400), random_int(0, 1000), random_int(10, 200), random_int(10, 200), imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        ob_start();
        imagejpeg($img, null, 95);
        $bytes = (string) ob_get_clean();
        $sha = hash('sha256', $bytes);
        $id = Uuid::v4();
        $init = Uploads::init(['upload_uuid' => $id, 'observation_uuid' => $st['obs']['uuid'], 'name' => 'foto.jpg', 'size' => strlen($bytes), 'sha256' => $sha]);
        assert_same(0, $init['received_bytes']);
        assert_same($init, Uploads::init(['upload_uuid' => $id, 'observation_uuid' => $st['obs']['uuid'], 'name' => 'foto.jpg', 'size' => strlen($bytes), 'sha256' => $sha]), 'alta idempotente');
        $chunk = 100 * 1024;
        $first = Uploads::chunk($id, 0, substr($bytes, 0, $chunk));
        assert_same($chunk, $first['received_bytes']);
        $dup = Uploads::chunk($id, 0, substr($bytes, 0, $chunk));
        assert_same(409, $dup['code'], 'parte repetida: el servidor dice desde dónde seguir');
        assert_same($chunk, $dup['received_bytes']);
        assert_same(409, Uploads::complete($id)['code'], 'faltan partes');
        for ($off = $chunk; $off < strlen($bytes); $off += $chunk) {
            Uploads::chunk($id, $off, substr($bytes, $off, $chunk));
        }
        $done = Uploads::complete($id);
        assert_same('completed', $done['status'], json_encode($done));
        assert_same($done, Uploads::complete($id), 'completar dos veces no duplica');
        $atts = ObservationAttachments::forObservation((int) $st['obs']['id']);
        assert_same(1, count($atts));
        assert_same($sha, $atts[0]['sha256'], 'la evidencia guardada es idéntica a la enviada');

        $id2 = Uuid::v4();
        Uploads::init(['upload_uuid' => $id2, 'observation_uuid' => $st['obs']['uuid'], 'size' => 10, 'sha256' => str_repeat('a', 64)]);
        Uploads::chunk($id2, 0, '0123456789');
        $bad = Uploads::complete($id2);
        assert_true(str_contains($bad['error'], 'hash'), 'hash que no coincide');
        assert_same(0, Uploads::find($id2)['received_bytes'], 'se descarta para reenviar');
    },

    'HTTP: token Bearer, rutas de sync y subida PUT con cuerpo crudo' => function () use ($setup, &$st) {
        $setup();
        $tokens = ApiAuth::login('sy-a', 'rep@sy.test', 'clave-larga-123', Uuid::v4(), 'Chrome Android', null, '10.1.1.1');
        $call = function (string $method, string $path, array $query = [], array $post = [], ?string $body = null) use ($tokens) {
            UserAuth::setCurrent(null);
            Tenant::deactivate();
            $router = new Router();
            (require BASE_PATH . '/app/routes.php')($router);
            $req = new Request($method, $path, $query, $post, ['authorization' => 'Bearer ' . $tokens['access_token'], 'accept' => 'application/json']);
            $req->body = $body;
            return $router->dispatch($req);
        };
        $pull = $call('GET', '/api/v1/sync/pull', ['limit' => '50']);
        assert_same(200, $pull->status);
        assert_true(isset(json_decode($pull->body, true)['data']['cursor']));
        assert_same(422, $call('POST', '/api/v1/sync/push', [], ['operations' => []])->status);
        $id = Uuid::v4();
        $init = $call('POST', '/api/v1/uploads', [], ['upload_uuid' => $id, 'observation_uuid' => $st['obs']['uuid'], 'size' => 4, 'sha256' => hash('sha256', 'abcd')]);
        assert_same(200, $init->status, $init->body);
        assert_same(200, $call('PUT', '/api/v1/uploads/' . $id, ['offset' => '0'], [], 'abcd')->status);
        assert_same(4, json_decode($call('GET', '/api/v1/uploads/' . $id)->body, true)['data']['received_bytes']);
        assert_same(422, $call('POST', '/api/v1/uploads/' . $id . '/complete')->status, 'no es una imagen: se rechaza como evidencia');
        assert_same(200, $call('GET', '/api/v1/observations/' . $st['obs']['uuid'])->status);
        assert_same(401, (function () {
            $router = new Router();
            (require BASE_PATH . '/app/routes.php')($router);
            return $router->dispatch(new Request('GET', '/api/v1/sync/pull'))->status;
        })());
    },

    'rate limit por dispositivo' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $bucket = 'test:' . Uuid::v4();
        assert_true(RateLimiter::hit($bucket, 3) && RateLimiter::hit($bucket, 3) && RateLimiter::hit($bucket, 3));
        assert_same(false, RateLimiter::hit($bucket, 3));
    },

    'Web Push: vector de la RFC 8291 y firma VAPID verificable' => function () {
        $u = fn ($s) => Ece::unb64u($s);
        $body = Ece::encrypt($u('V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24'),
            $u('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'), $u('BTBZMqHH6r4Tts7J_aSIgg'),
            ['private' => $u('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'), 'public' => $u('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8')],
            $u('DGv6ra1nlYgDCS1FRnbzlw'));
        assert_same('DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN', Ece::b64u($body));
        $keys = Vapid::generate();
        $auth = Vapid::authorization('https://fcm.googleapis.com/fcm/send/abc', $keys['public'], $keys['private_pem'], 'mailto:a@b.test');
        preg_match('/t=([^.]+)\.([^.]+)\.([^,]+), k=(.+)$/', $auth, $m);
        assert_same('https://fcm.googleapis.com', json_decode(Ece::unb64u($m[2]), true)['aud']);
        $ok = openssl_verify($m[1] . '.' . $m[2], Vapid::rawToDer(Ece::unb64u($m[3])), Ece::publicPem(Ece::unb64u($m[4])), OPENSSL_ALGO_SHA256);
        assert_same(1, $ok, 'la firma ES256 verifica con la clave pública');
    },

    'aislamiento: un usuario de A no trae datos de B' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['b']);
        $id = Users::create(['name' => 'Otro', 'email' => 'otro@sy.test', 'role_id' => (int) Roles::findBySlug('admin_empresa')['id'], 'password_hash' => 'x']);
        UserAuth::setCurrent(Users::findById($id));
        $page = Pull::run(null, 1000);
        assert_same([], (array) $page['changes'], 'la empresa B no tiene nada cargado');
    },

    'limpieza: se borran las bases de prueba' => function () use ($root, $dropAll, &$st, $master) {
        if (!$st['ready']) {
            throw new SkipTest('no hubo setup');
        }
        MailTransport::$fake = null;
        Channels::$fakeExternal = null;
        Tenant::deactivate();
        UserAuth::setCurrent(null);
        DB::reset();
        PlatformSettings::forget();
        @unlink(Storage::path('installed.lock'));
        $pdo = $root();
        $dropAll($pdo);
        Config::load(BASE_PATH . '/config');
        $_SESSION = [];
        assert_same([], $pdo->query("SHOW DATABASES LIKE '{$master}%'")->fetchAll(PDO::FETCH_COLUMN));
    },
];
