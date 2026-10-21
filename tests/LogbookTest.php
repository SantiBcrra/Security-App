<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Storage;
use App\Core\Tenant;
use App\Core\Uuid;
use App\Models\PlatformSettings;
use App\Models\Roles;
use App\Models\Tenants;
use App\Models\Users;
use App\Services\Logbook;
use App\Services\Sync\Pull;
use App\Services\Sync\Push;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Libro de guardia (docs/design/libro-de-guardia.md). Maestra `securityapp_test_logbook` + empresa lb-a.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_logbook';
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
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Lb A', 'slug' => 'lb-a'] + $company, null));
    Tenant::activate($st['a']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    $st['hys'] = $mk('responsable_hys', 'jefe@lb.test');
    $st['sup'] = $mk('supervisor', 'super@lb.test');
    $st['g1'] = $mk('reportante', 'noche@lb.test');
    $st['g2'] = $mk('reportante', 'dia@lb.test');
    $st['aud'] = $mk('auditor', 'art@lb.test');
    $st['site'] = \App\Models\Sites::findById(\App\Models\Sites::create(['name' => 'Planta Norte', 'code' => 'PN', 'is_active' => 1]));
    $st['site2'] = \App\Models\Sites::findById(\App\Models\Sites::create(['name' => 'Depósito', 'code' => 'DE', 'is_active' => 1]));
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

$op = fn (string $type, array $data) => ['op_id' => Uuid::v4(), 'type' => $type, 'data' => $data];
$count = fn (string $event) => (int) DB::tenant()->query('SELECT COUNT(*) FROM notifications WHERE event = ' . DB::tenant()->quote($event))->fetchColumn();
$sign = function (): string {
    $img = imagecreatetruecolor(400, 140);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    $ink = imagecolorallocate($img, 20, 20, 30);
    imagesetthickness($img, 3);
    for ($i = 0; $i < 6; $i++) imageline($img, 20 + $i * 50, 100 - ($i % 2) * 60, 70 + $i * 50, 40 + ($i % 2) * 60, $ink);
    ob_start();
    imagepng($img);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
};

return [
    'libro: permisos del módulo en los roles base (guardia anota, auditor solo mira)' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        assert_true(UserAuth::can('libro', 'crear') && !UserAuth::can('libro', 'editar'));
        UserAuth::setCurrent($st['aud']);
        assert_true(UserAuth::can('libro', 'ver') && !UserAuth::can('libro', 'crear'));
        try { Logbook::create(['site_uuid' => $st['site']['uuid'], 'kind' => 'novedad', 'text' => 'x']); assert_true(false, 'el auditor no anota'); } catch (App\Core\UserError) {}
    },

    'libro: novedad, persona y vehículo; validaciones, patente normalizada, folios por predio e idempotencia' => function () use ($setup, &$st, $count) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $site = $st['site']['uuid'];
        $n = Logbook::create(['site_uuid' => $site, 'kind' => 'novedad', 'category' => 'rutina', 'text' => 'Recorrida perimetral sin novedad']);
        $p = Logbook::create(['site_uuid' => $site, 'kind' => 'persona', 'person_name' => 'Juan Pérez', 'person_doc' => '30.111.222', 'company' => 'Gas SA', 'visiting' => 'Mantenimiento']);
        $v = Logbook::create(['site_uuid' => $site, 'kind' => 'vehiculo', 'plate' => 'ab 123-cd', 'vehicle_type' => 'camion', 'driver' => 'Luis', 'company' => 'Transportes Sur']);
        assert_same([1, 2, 3], [(int) $n['entry']['folio'], (int) $p['entry']['folio'], (int) $v['entry']['folio']]);
        assert_same(['30111222', 'AB123CD'], [$p['entry']['person_doc'], $v['entry']['plate']]);
        $other = Logbook::create(['site_uuid' => $st['site2']['uuid'], 'kind' => 'novedad', 'text' => 'Otro predio']);
        assert_same(1, (int) $other['entry']['folio'], 'cada predio tiene su numeración');
        $uuid = Uuid::v4();
        Logbook::create(['uuid' => $uuid, 'site_uuid' => $site, 'kind' => 'novedad', 'text' => 'Desde la app sin señal'], 'app');
        assert_true(Logbook::create(['uuid' => $uuid, 'site_uuid' => $site, 'kind' => 'novedad', 'text' => 'otra vez'], 'app')['duplicate'], 'reenvío: no duplica');
        foreach ([['kind' => 'persona', 'reason' => 'nada que identifique'], ['kind' => 'vehiculo', 'driver' => 'sin patente'], ['kind' => 'novedad', 'text' => ' '], ['kind' => 'otra']] as $bad) {
            try { Logbook::create(['site_uuid' => $site] + $bad); assert_true(false, json_encode($bad)); } catch (App\Core\UserError) {}
        }
        $before = $count('logbook.important');
        Logbook::create(['site_uuid' => $site, 'kind' => 'novedad', 'category' => 'incidente', 'text' => 'Portón 2 forzado', 'important' => 1]);
        assert_same($before + 2, $count('logbook.important'), 'avisa a SyH y supervisor');
        assert_same(['AB123CD'], array_column(Logbook::lookup('vehiculo', 'ab1'), 'plate'), 'padrón para autocompletar');
        assert_same('Juan Pérez', Logbook::lookup('persona', '3011')[0]['name']);
    },

    'libro: adentro ahora, salida (una sola vez) y corrección sin tocar la original' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $sid = (int) $st['site']['id'];
        $inside = Logbook::inside($sid);
        assert_same(2, count($inside), 'la persona y el camión');
        $truck = array_values(array_filter($inside, fn ($e) => $e['kind'] === 'vehiculo'))[0];
        $out = Logbook::exit($truck['uuid'], ['load_note' => 'Remito 0001-123']);
        assert_same(['salida', $truck['uuid']], [$out['entry']['kind'], $out['entry']['ref_uuid']]);
        assert_true(Logbook::exit($truck['uuid'])['duplicate'], 'no se marca dos veces');
        assert_same(1, count(Logbook::inside($sid)));
        $orig = Logbook::find($truck['uuid']);
        $c = Logbook::correct($truck['uuid'], ['text' => 'La patente era AB123CE']);
        assert_same(['correccion', (int) $orig['folio']], [$c['entry']['kind'], (int) $c['entry']['ref_folio']]);
        assert_same('AB123CD', Logbook::find($truck['uuid'])['plate'], 'la original no cambia');
        try { Logbook::exit($c['entry']['uuid']); assert_true(false); } catch (App\Core\UserError) {}
    },

    'libro: cadena de huellas — íntegro, alteración detectada, purga de datos personales' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $sid = (int) $st['site']['id'];
        assert_same(true, Logbook::verify($sid)['ok']);
        DB::tenant()->exec("UPDATE logbook_entries SET text = 'Todo tranquilo' WHERE site_id = {$sid} AND folio = 1");
        $v = Logbook::verify($sid);
        assert_same([false, 1], [$v['ok'], $v['broken_folio']], 'alguien tocó la base: se detecta');
        DB::tenant()->exec("UPDATE logbook_entries SET text = 'Recorrida perimetral sin novedad' WHERE site_id = {$sid} AND folio = 1");
        assert_same(true, Logbook::verify($sid)['ok']);
        // Purga (a riesgo del usuario): borra datos personales, el libro sigue verificable.
        UserAuth::setCurrent($st['sup']);
        try { Logbook::purge(date('Y-m-d', strtotime('+1 day'))); assert_true(false, 'el supervisor no purga'); } catch (App\Core\UserError) {}
        UserAuth::setCurrent($st['hys']);
        $n = Logbook::purge(date('Y-m-d', strtotime('+2 days')));
        assert_true($n >= 2);
        $p = Logbook::search(['q' => 'Gas SA']);
        assert_true($p && $p[0]['person_name'] === null && $p[0]['purged_at'] !== null && Logbook::label($p[0]) === '(datos borrados)');
        assert_same(true, Logbook::verify($sid)['ok'], 'la cadena sigue válida');
        assert_same([], Logbook::lookup('vehiculo', 'AB'), 'también se vacía el padrón');
    },

    'libro: cierre de turno con firma (avisa si quedó gente adentro), recepción por otro guardia' => function () use ($setup, &$st, $sign, $count) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $site = $st['site']['uuid'];
        Logbook::create(['site_uuid' => $site, 'kind' => 'persona', 'person_name' => 'Técnico de guardia']);
        try { Logbook::close(['site_uuid' => $site, 'text' => 'Sin firma']); assert_true(false); } catch (App\Core\UserError) {}
        $before = $count('logbook.inside_at_close');
        $close = Logbook::close(['site_uuid' => $site, 'text' => 'Dejo el portón 2 con traba provisoria', 'signature' => $sign()])['entry'];
        assert_true($close['signature_sha256'] !== null && count(json_decode($close['data'], true)['adentro']) >= 1);
        assert_same($before + 2, $count('logbook.inside_at_close'));
        try { Logbook::receive($close['uuid'], ['signature' => $sign()]); assert_true(false, 'no se recibe a sí mismo'); } catch (App\Core\UserError) {}
        UserAuth::setCurrent($st['g2']);
        assert_same($close['uuid'], Logbook::pendingClose((int) $st['site']['id'], (int) $st['g2']['id'])['uuid']);
        $rec = Logbook::receive($close['uuid'], ['text' => 'Recibo con portón 2 trabado', 'signature' => $sign()])['entry'];
        assert_same(['recepcion', (int) $close['folio']], [$rec['kind'], (int) $rec['ref_folio']]);
        assert_same(null, Logbook::pendingClose((int) $st['site']['id'], (int) $st['g2']['id']));
        try { Logbook::receive($close['uuid'], ['signature' => $sign()]); assert_true(false, 'ya recibido'); } catch (App\Core\UserError) {}
        assert_same(true, Logbook::verify((int) $st['site']['id'])['ok']);
    },

    'libro: cron (adentro hace muchas horas, un aviso por día) y app (push idempotente, pull del libro y del padrón)' => function () use ($setup, &$st, $op, $count, $sign) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']); // un ingreso de hace 20 h que nadie cerró (se anota con su hora real, no se toca la base)
        Logbook::create(['site_uuid' => $st['site2']['uuid'], 'kind' => 'persona', 'person_name' => 'Proveedor', 'occurred_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 20 * 3600)]);
        $before = $count('logbook.inside_long');
        UserAuth::setCurrent(null);
        Logbook::run();
        Logbook::run();
        assert_same($before + 2, $count('logbook.inside_long'), 'una vez por predio y día');
        UserAuth::setCurrent($st['g2']);
        $u = Uuid::v4();
        $r = Push::run([$op('logbook.create', ['uuid' => $u, 'site_uuid' => $st['site']['uuid'], 'kind' => 'vehiculo', 'plate' => 'AC 987 ZZ',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 600)])]);
        assert_same(['ok', 'AC987ZZ', false], [$r[0]['status'], $r[0]['data']['plate'], $r[0]['data']['duplicate']]);
        $r = Push::run([$op('logbook.exit', ['uuid' => Uuid::v4(), 'entry_uuid' => $u])]);
        assert_same('salida', $r[0]['data']['kind']);
        $r = Push::run([$op('logbook.close', ['uuid' => Uuid::v4(), 'site_uuid' => $st['site']['uuid'], 'signature' => $sign()])]);
        assert_same('cierre', $r[0]['data']['kind']);
        $page = Pull::run(null, 1000);
        $kinds = array_column((array) ($page['changes']->logbook_entries ?? []), 'kind');
        assert_true(in_array('vehiculo', $kinds, true) && in_array('cierre', $kinds, true));
        assert_true(in_array('AC987ZZ', array_column((array) ($page['changes']->logbook_known ?? []), 'key'), true));
        UserAuth::setCurrent($st['aud']);
        assert_true(empty((array) (Pull::run(null, 1000)['changes']->logbook_known ?? [])), 'el padrón solo a quien anota');
    },

    'panel: libro del día, búsqueda, parte diario imprimible, CSV y purga con confirmación' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $_SESSION = [];
        $ctl = new \App\Controllers\Web\Panel\LogbookController();
        $site = $st['site']['uuid'];
        $res = $ctl->store(new \App\Core\Request('POST', '/', ['predio' => $site], ['kind' => 'novedad', 'category' => 'alarma', 'text' => 'Sonó la alarma del depósito']));
        assert_same(302, $res->status);
        $html = $ctl->index(new \App\Core\Request('GET', '/', ['predio' => $site]))->body;
        assert_true(str_contains($html, 'Sonó la alarma del depósito') && str_contains($html, 'Adentro ahora') && str_contains($html, 'Libro íntegro'), 'libro del día');
        $html = $ctl->search(new \App\Core\Request('GET', '/', ['q' => 'ac987zz']))->body;
        assert_true(str_contains($html, 'AC987ZZ') && str_contains($html, '1 resultado'), 'buscar por patente sin importar el formato');
        $print = $ctl->printable(new \App\Core\Request('GET', '/', ['predio' => $site]))->body;
        assert_true(str_contains($print, 'parte diario') && str_contains($print, 'Libro íntegro'));
        $csv = $ctl->export(new \App\Core\Request('GET', '/', ['q' => 'alarma']))->body;
        assert_true(str_contains($csv, 'Sonó la alarma del depósito') && str_contains($csv, 'Folio'));
        $ctl->purge(new \App\Core\Request('POST', '/', [], ['before' => date('Y-m-d', strtotime('+2 days')), 'confirm' => 'no']));
        assert_true(Logbook::search(['q' => 'AC987ZZ']) !== [], 'sin escribir BORRAR no purga');
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
        $pdo = $root();
        $dropAll($pdo);
        Config::load(BASE_PATH . '/config');
        $_SESSION = [];
        assert_same([], $pdo->query("SHOW DATABASES LIKE '{$master}%'")->fetchAll(PDO::FETCH_COLUMN));
    },
];
