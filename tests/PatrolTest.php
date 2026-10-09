<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Storage;
use App\Core\Tenant;
use App\Core\Uuid;
use App\Models\Patrols;
use App\Models\PlatformSettings;
use App\Models\Roles;
use App\Models\Tenants;
use App\Models\Users;
use App\Policies\Permissions;
use App\Services\Sync\Pull;
use App\Services\Sync\Push;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Etapa 9: rondas de guardias. Maestra `securityapp_test_patrol` + empresa pa-a.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_patrol';
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
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Pa A', 'slug' => 'pa-a'] + $company, null));
    Tenant::activate($st['a']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    $st['hys'] = $mk('responsable_hys', 'hys@pa.test');
    $st['g1'] = $mk('reportante', 'guardia1@pa.test');
    $st['g2'] = $mk('reportante', 'guardia2@pa.test');
    $st['p1'] = Patrols::createPoint(['name' => 'Portón', 'code' => 'P1', 'description' => '', 'site_id' => null, 'sector_id' => null,
        'lat' => -34.6037, 'lng' => -58.3816, 'radius_m' => 30, 'is_critical' => true]);
    $st['p2'] = Patrols::createPoint(['name' => 'Depósito', 'code' => 'P2', 'description' => '', 'site_id' => null, 'sector_id' => null,
        'lat' => -34.6050, 'lng' => -58.3816, 'radius_m' => 30, 'is_critical' => false]);
    $st['uuid'] = fn (int $id) => (string) DB::tenant()->query('SELECT uuid FROM patrol_points WHERE id = ' . $id)->fetchColumn();
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

$op = fn (string $type, array $data) => ['op_id' => Uuid::v4(), 'type' => $type, 'data' => $data];

return [
    'rondas: calcula distancia GPS y respeta un radio' => function (): void {
        assert_same(0.0, Patrols::distance(-34.6037, -58.3816, -34.6037, -58.3816));
        $d = Patrols::distance(-34.6037, -58.3816, -34.6040, -58.3816);
        assert_true($d > 30 && $d < 40, 'distancia aproximada');
    },

    'rondas: los roles operativos tienen permisos' => function (): void {
        $roles = Permissions::baseRoles();
        assert_true(in_array('crear', $roles['reportante']['permissions']['rondas']['acciones'], true));
        assert_true(in_array('cerrar', $roles['supervisor']['permissions']['rondas']['acciones'], true));
        assert_true(in_array('exportar', $roles['admin_empresa']['permissions']['rondas']['acciones'], true));
    },

    'rondas: inicio idempotente por uuid del celular y ajeno rechazado' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $round = Uuid::v4();
        $startedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 300);
        assert_same('ok', Push::run([$op('round.start', ['uuid' => $round, 'started_at_device' => $startedAt])])[0]['status']);
        assert_same('ok', Push::run([$op('round.start', ['uuid' => $round])])[0]['status'], 'otro op_id, misma ronda');
        assert_same(gmdate('Y-m-d H:i:s', strtotime($startedAt)), DB::tenant()->query('SELECT started_at FROM patrol_rounds')->fetchColumn(),
            'la ronda empezada sin señal guarda la hora del celular');
        assert_same(1, (int) DB::tenant()->query('SELECT COUNT(*) FROM patrol_rounds')->fetchColumn());
        UserAuth::setCurrent($st['g2']);
        assert_same('error', Push::run([$op('round.start', ['uuid' => $round])])[0]['status'], 'no puede tomar la ronda de otro');
        assert_same('error', Push::run([$op('round.start', ['uuid' => 'no-uuid'])])[0]['status']);
        $st['round'] = $round;
    },

    'rondas: escaneo dentro/fuera del radio, duplicado y validaciones' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $scan = fn (int $point, array $extra = []) => Push::run([$op('round.scan', $extra + ['uuid' => Uuid::v4(), 'round_uuid' => $st['round'],
            'point_uuid' => ($st['uuid'])($point), 'lat' => -34.6037, 'lng' => -58.3816, 'accuracy_m' => 8,
            'scanned_at_device' => gmdate('Y-m-d\TH:i:s\Z', time() - 120)])])[0];
        $r = $scan($st['p1']);
        assert_same('ok', $r['status'], json_encode($r));
        assert_same(true, $r['data']['within_radius']);
        assert_same(true, $scan($st['p1'])['data']['duplicate'], 'mismo punto en la misma ronda: no duplica');
        assert_true(str_contains($scan($st['p2'], ['scanned_at_device' => 'ayer'])['error'], 'Hora'), 'hora inválida');
        assert_same('error', $scan($st['p2'], ['uuid' => 'x'])['status'], 'uuid inválido');
        $far = $scan($st['p2']);
        assert_same(false, $far['data']['within_radius'], 'a ~150 m del depósito queda fuera del radio');
        $p3 = Patrols::createPoint(['name' => 'Garita', 'code' => 'P3', 'description' => '', 'site_id' => null, 'sector_id' => null,
            'lat' => -34.6037, 'lng' => -58.3816, 'radius_m' => 50, 'is_critical' => 0]);
        $mars = Push::run([$op('round.scan', ['uuid' => Uuid::v4(), 'round_uuid' => $st['round'], 'point_uuid' => ($st['uuid'])($p3),
            'lat' => 37.42, 'lng' => -122.08, 'accuracy_m' => 5, 'scanned_at_device' => gmdate('Y-m-d\TH:i:s\Z', time() - 60)])])[0];
        assert_same('ok', $mars['status'], 'un GPS a 10.000 km no hace fallar el escaneo: ' . json_encode($mars));
        assert_same([false, false], [$mars['data']['within_radius'], $mars['data']['duplicate']]);
        DB::tenant()->exec('DELETE FROM patrol_scans WHERE point_id = ' . (int) $p3);
        DB::tenant()->exec('UPDATE patrol_points SET is_active = 0 WHERE id = ' . (int) $p3);
        $saved = DB::tenant()->query('SELECT scanned_at_device, accuracy_m FROM patrol_scans ORDER BY id LIMIT 1')->fetch();
        assert_same(gmdate('Y-m-d H:i:s', time() - 120), $saved['scanned_at_device'], 'conserva la hora del celular en UTC');
        DB::tenant()->exec('UPDATE patrol_points SET is_active = 0 WHERE id = ' . $st['p2']);
        DB::tenant()->exec('DELETE FROM patrol_scans WHERE point_id = ' . $st['p2']);
        assert_true(str_contains($scan($st['p2'])['error'], 'activo'), 'punto desactivado rechazado');
        DB::tenant()->exec('UPDATE patrol_points SET is_active = 1 WHERE id = ' . $st['p2']);
        UserAuth::setCurrent($st['g2']);
        assert_same('error', $scan($st['p2'])['status'], 'no puede escanear en la ronda de otro');
    },

    'rondas: finalizar y alcance "propios" en la sincronización' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $finishedAt = gmdate('Y-m-d\TH:i:s\Z', time() - 60);
        // Escaneo hecho sin señal que llega después del fin (reintento): vale si fue antes de terminar.
        $late = Patrols::createPoint(['name' => 'Tardío', 'code' => 'P9', 'description' => '', 'site_id' => null, 'sector_id' => null,
            'lat' => -34.6037, 'lng' => -58.3816, 'radius_m' => 30, 'is_critical' => false]);
        $r = Push::run([$op('round.finish', ['round_uuid' => $st['round'], 'finished_at_device' => $finishedAt])])[0];
        assert_same('completa', $r['data']['status']);
        $lateScan = fn (int $secondsAgo) => Push::run([$op('round.scan', ['uuid' => Uuid::v4(), 'round_uuid' => $st['round'], 'point_uuid' => ($st['uuid'])($late),
            'lat' => -34.6037, 'lng' => -58.3816, 'scanned_at_device' => gmdate('Y-m-d\TH:i:s\Z', time() - $secondsAgo)])])[0];
        assert_same('error', $lateScan(10)['status'], 'después del fin no vale');
        assert_same('ok', $lateScan(90)['status'], 'antes del fin, llegado tarde, vale');
        DB::tenant()->exec('UPDATE patrol_points SET is_active = 0 WHERE id = ' . (int) $late);
        assert_same(gmdate('Y-m-d H:i:s', strtotime($finishedAt)), DB::tenant()->query("SELECT finished_at FROM patrol_rounds WHERE uuid = '{$st['round']}'")->fetchColumn());
        UserAuth::setCurrent($st['g2']);
        Push::run([$op('round.start', ['uuid' => Uuid::v4()])]);
        $rounds = fn () => (array) (Pull::run(null, 1000)['changes']->patrol_rounds ?? []);
        $mine = $rounds();
        assert_same(1, count($mine), 'el guardia ve solo sus rondas');
        assert_same(true, $mine[0]['mine']);
        assert_true(!array_key_exists('user_id', $mine[0]), 'no expone ids internos');
        UserAuth::setCurrent($st['hys']);
        $all = $rounds();
        assert_same(2, count($all), 'SyH ve todas');
        assert_same([false, false], array_column($all, 'mine'));
    },

    'rutas: solo las asignadas llegan al celular, con los puntos en orden' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $st['route'] = Uuid::v4();
        Patrols::createRoute(['uuid' => $st['route'], 'name' => 'Nocturna', 'description' => '', 'frequency' => 'diaria', 'expected_minutes' => 30,
            'point_ids' => [$st['p2'], $st['p1']], 'user_ids' => [(int) $st['g1']['id']]]);
        $pull = function (array $user) {
            UserAuth::setCurrent($user);
            $page = Pull::run(null, 1000);
            return [(array) ($page['changes']->patrol_routes ?? []), (array) ($page['deleted']->patrol_routes ?? [])];
        };
        [$g1] = $pull($st['g1']);
        assert_same(1, count($g1));
        assert_same([($st['uuid'])($st['p2']), ($st['uuid'])($st['p1'])], $g1[0]['points'], 'en el orden de la ruta');
        [$g2, $g2Deleted] = $pull($st['g2']);
        assert_same([], $g2, 'el guardia sin asignación no la recibe');
        assert_same([$st['route']], $g2Deleted, 'le llega como baja (por si se la sacaron)');
        [$hys] = $pull($st['hys']);
        assert_same(1, count($hys), 'SyH (alcance todo) ve todas las rutas');
    },

    'rutas: no se puede iniciar una ruta no asignada; salteados = incompleta' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g2']);
        $r = Push::run([$op('round.start', ['uuid' => Uuid::v4(), 'route_uuid' => $st['route']])])[0];
        assert_true(str_contains((string) ($r['error'] ?? ''), 'asignada'), json_encode($r));
        UserAuth::setCurrent($st['g1']);
        $scan = fn (string $round, int $point) => Push::run([$op('round.scan', ['uuid' => Uuid::v4(), 'round_uuid' => $round,
            'point_uuid' => ($st['uuid'])($point), 'lat' => -34.6037, 'lng' => -58.3816])])[0];
        // Ronda que saltea un punto
        $partial = Uuid::v4();
        assert_same('ok', Push::run([$op('round.start', ['uuid' => $partial, 'route_uuid' => $st['route']])])[0]['status']);
        assert_same('ok', $scan($partial, $st['p1'])['status']);
        assert_same('incompleta', Push::run([$op('round.finish', ['round_uuid' => $partial])])[0]['data']['status']);
        $detail = Patrols::roundDetail($partial);
        assert_same(['P2', 'P1'], array_column($detail['route_points'], 'code'));
        assert_same(null, $detail['route_points'][0]['scanned_at_device'], 'P2 salteado');
        assert_true($detail['route_points'][1]['scanned_at_device'] !== null);
        // Ronda completa
        $full = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $full, 'route_uuid' => $st['route']])]);
        $scan($full, $st['p2']);
        $scan($full, $st['p1']);
        assert_same('completa', Push::run([$op('round.finish', ['round_uuid' => $full])])[0]['data']['status']);
        // Historial del panel por alcance
        assert_same(3, count(Patrols::rounds(100, (int) $st['g1']['id'])), 'el guardia ve solo sus 3 rondas');
        assert_same(4, count(Patrols::rounds(100)), 'sin filtro, todas');
        $row = array_values(array_filter(Patrols::rounds(100), fn ($x) => $x['uuid'] === $partial))[0];
        assert_same([1, 2], [(int) $row['scans_count'], (int) $row['route_points']]);
    },

    'guardias: posiciones en lote, pánico (directo y en cola), re-aviso, atendido y "sin señal"' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        \App\Services\Notify\MailTransport::$fake = fn () => null;
        \App\Services\Notify\Channels::$fakeExternal = fn () => null;
        $sup = Users::findById(Users::create(['name' => 'Supervisor Uno', 'email' => 'sup@pa.test', 'role_id' => (int) Roles::findBySlug('supervisor')['id'],
            'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
        UserAuth::setCurrent($st['g1']);
        $round = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $round, 'started_at_device' => gmdate('Y-m-d\TH:i:s\Z', time() - 1800)])]);
        $pt = fn (int $ago, float $lat) => ['uuid' => Uuid::v4(), 'at' => gmdate('Y-m-d\TH:i:s\Z', time() - $ago), 'lat' => $lat, 'lng' => -58.38, 'accuracy_m' => 12, 'battery' => 80];
        $points = [$pt(1500, -34.600), $pt(1440, -34.601), $pt(1380, -34.602), ['uuid' => 'malo', 'lat' => 'x']];
        $r = Push::run([$op('round.track', ['round_uuid' => $round, 'points' => $points])])[0];
        assert_same(['ok', 3], [$r['status'], $r['data']['saved']]);
        assert_same(0, Push::run([$op('round.track', ['round_uuid' => $round, 'points' => $points])])[0]['data']['saved'], 'reenvío: no duplica');
        $roundRow = Patrols::round($round);
        assert_same(gmdate('Y-m-d H:i:s', time() - 1380), DB::tenant()->query("SELECT last_track_at FROM patrol_rounds WHERE uuid = '{$round}'")->fetchColumn());
        assert_same(3, count(\App\Services\GuardSafety::tracks((int) $roundRow['id'])));
        UserAuth::setCurrent($st['g2']);
        assert_same('error', Push::run([$op('round.track', ['round_uuid' => $round, 'points' => [$pt(60, -34.6)]])])[0]['status'], 'la ronda de otro no');
        // Guardia sin señal: última posición hace 23 min (> 10) → un aviso por hueco
        UserAuth::setCurrent(null);
        $res = \App\Services\GuardSafety::run();
        assert_same(1, $res['silent']);
        assert_same(0, \App\Services\GuardSafety::run()['silent'], 'no se repite por el mismo hueco');
        $inbox = fn (array $u) => array_column(DB::tenant()->query('SELECT title FROM notifications WHERE user_id = ' . (int) $u['id'])->fetchAll(), 'title');
        assert_true(in_array('Guardia sin señal · Guardia1', $inbox($sup), true), 'aviso al supervisor');
        // Pánico: directo + el mismo en la cola = una sola alerta, crítica, a SyH y supervisores (no al guardia)
        UserAuth::setCurrent($st['g1']);
        $panicUuid = Uuid::v4();
        $data = ['uuid' => $panicUuid, 'at' => gmdate('Y-m-d\TH:i:s\Z', time() - 30), 'lat' => -34.6031, 'lng' => -58.3815, 'accuracy_m' => 9, 'round_uuid' => $round];
        $first = \App\Services\GuardSafety::panic($data, 'datos');
        assert_same(false, $first['duplicate']);
        $queued = Push::run([$op('guard.panic', $data + ['sms_sent' => 1])])[0];
        assert_same([true, 1], [$queued['data']['duplicate'], (int) \App\Services\GuardSafety::findPanic($panicUuid)['sms_sent']]);
        assert_same(1, (int) DB::tenant()->query('SELECT COUNT(*) FROM panic_alerts')->fetchColumn());
        assert_true(in_array('⚠ PÁNICO · Guardia1', $inbox($st['hys']), true) && in_array('⚠ PÁNICO · Guardia1', $inbox($sup), true));
        assert_true(!in_array('⚠ PÁNICO · Guardia1', $inbox($st['g1']), true), 'el guardia no se avisa a sí mismo');
        assert_same(1, (int) DB::tenant()->query("SELECT is_critical FROM notifications WHERE title = '⚠ PÁNICO · Guardia1' LIMIT 1")->fetchColumn());
        // Sin atender a los N minutos → re-aviso a los responsables
        UserAuth::setCurrent(null);
        assert_same(1, \App\Services\GuardSafety::run(time() + 20 * 60)['escalated']);
        assert_true(in_array('⚠ PÁNICO SIN ATENDER (nivel 1) · Guardia1', $inbox($st['hys']), true));
        UserAuth::setCurrent($st['hys']);
        $p = \App\Services\GuardSafety::findPanic($panicUuid);
        assert_true(str_contains((string) \App\Services\GuardSafety::ack($p, ''), 'Contá'));
        assert_same(null, \App\Services\GuardSafety::ack($p, 'Se lo llamó: está bien, se tropezó'));
        UserAuth::setCurrent(null);
        assert_same(0, \App\Services\GuardSafety::run(time() + 60 * 60)['escalated'], 'atendida: no se re-avisa');
        // Ajustes que bajan al celular y pantallas
        \App\Models\Settings::set('guardias.panico_telefonos', '+5491122334455, 11-2233 (malo), +5491166778899');
        UserAuth::setCurrent($st['g1']);
        $meta = Pull::run(null, 50)['meta']['guardias'];
        assert_same([['+5491122334455', '+5491166778899'], 60, 10], [$meta['panico_telefonos'], $meta['track_segundos'], $meta['minutos_sin_senal']]);
        UserAuth::setCurrent($st['hys']);
        $p = \App\Services\GuardSafety::findPanic($panicUuid);
        $html = \App\Core\View::render('panel/rounds/panic', ['p' => $p, 'tracks' => \App\Services\GuardSafety::tracks((int) $roundRow['id'])], null);
        assert_true(str_contains($html, 'Se lo llamó') && str_contains($html, 'maps.google.com/?q=-34.6031'));
        $html = \App\Core\View::render('panel/rounds/round', ['round' => Patrols::roundDetail($round), 'tracks' => \App\Services\GuardSafety::tracks((int) $roundRow['id'])], null);
        assert_true(str_contains($html, 'track-map') && str_contains($html, '3 posición(es)'));
        Push::run([$op('round.finish', ['round_uuid' => $round])]);
        \App\Services\Notify\MailTransport::$fake = null;
        \App\Services\Notify\Channels::$fakeExternal = null;
    },

    'panel: el historial y el detalle de ronda se renderizan' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $rounds = Patrols::rounds();
        $html = \App\Core\View::render('panel/rounds/index', ['points' => Patrols::points(), 'routes' => Patrols::routes(), 'rounds' => $rounds], null);
        assert_true(str_contains($html, 'incompleta') && str_contains($html, '1 / 2'), 'estado y avance en el historial');
        $partial = array_values(array_filter($rounds, fn ($r) => $r['status'] === 'incompleta'))[0];
        $html = \App\Core\View::render('panel/rounds/round', ['round' => Patrols::roundDetail($partial['uuid']), 'tracks' => []], null);
        assert_true(str_contains($html, 'Salteado') && str_contains($html, 'Se saltearon 1 punto'), 'marca el punto salteado');
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
