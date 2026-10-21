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
use App\Models\Settings;
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
        $r = $scan($st['p1'], ['method' => 'nfc']);
        assert_same('ok', $r['status'], json_encode($r));
        assert_same(true, $r['data']['within_radius']);
        assert_same('nfc', DB::tenant()->query("SELECT method FROM patrol_scans ORDER BY id DESC LIMIT 1")->fetchColumn(), 'se guarda cómo se marcó');
        assert_same(true, $scan($st['p1'])['data']['duplicate'], 'mismo punto en la misma ronda: no duplica');
        assert_true(str_contains($scan($st['p2'], ['scanned_at_device' => 'ayer'])['error'], 'Hora'), 'hora inválida');
        assert_same('error', $scan($st['p2'], ['uuid' => 'x'])['status'], 'uuid inválido');
        $far = $scan($st['p2'], ['method' => 'cualquiera']);
        assert_same(null, DB::tenant()->query("SELECT method FROM patrol_scans ORDER BY id DESC LIMIT 1")->fetchColumn(), 'método desconocido = sin dato');
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
        assert_same([$panicUuid => null], \App\Services\GuardSafety::ackState([$panicUuid, null, $panicUuid]));
        assert_true(str_contains((string) \App\Services\GuardSafety::ack($p, ''), 'Contá'));
        assert_same(null, \App\Services\GuardSafety::ack($p, 'Se lo llamó: está bien, se tropezó'));
        assert_same([$panicUuid => $st['hys']['name']], \App\Services\GuardSafety::ackState([$panicUuid]), 'la app ve quién la atendió');
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

    'rondas: estado de la ubicación (lejos, impreciso, sin GPS, GPS falso) y aviso una vez por ronda' => function () use ($setup, &$st, $op) {
        assert_same(['ok', 'impreciso', 'lejos', 'sin_gps', 'simulada'], [Patrols::locationStatus(40.0, 5.0, 50, false),
            Patrols::locationStatus(120.0, 80.0, 50, false), Patrols::locationStatus(120.0, 10.0, 50, false),
            Patrols::locationStatus(null, null, 50, false), Patrols::locationStatus(5.0, 3.0, 50, true)]);
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $roundUuid = Uuid::v4();
        assert_same('ok', Push::run([$op('round.start', ['uuid' => $roundUuid, 'route_uuid' => null,
            'started_at_device' => gmdate('Y-m-d\TH:i:s\Z', time() - 300)])])[0]['status']);
        // Puntos a ~150 m al norte del lugar desde donde marca el guardia (-34.6037, -58.3816)
        $pt = fn (string $code) => Patrols::createPoint(['name' => 'Punto ' . $code, 'code' => $code, 'description' => '', 'site_id' => null,
            'sector_id' => null, 'lat' => -34.60235, 'lng' => -58.3816, 'radius_m' => 50, 'is_critical' => 0]);
        $scan = fn (int $point, array $gps) => Push::run([$op('round.scan', $gps + ['uuid' => Uuid::v4(), 'round_uuid' => $roundUuid,
            'point_uuid' => ($st['uuid'])($point), 'scanned_at_device' => gmdate('Y-m-d\TH:i:s\Z', time() - 30)])])[0];
        $here = ['lat' => -34.6037, 'lng' => -58.3816];
        $status = fn () => DB::tenant()->query('SELECT location_status FROM patrol_scans ORDER BY id DESC LIMIT 1')->fetchColumn();
        $count = fn (string $title) => (int) DB::tenant()->query('SELECT COUNT(*) FROM notifications WHERE user_id = ' . (int) $st['hys']['id']
            . ' AND title = ' . DB::tenant()->quote($title))->fetchColumn();
        $before = [];
        $inbox = function (string $title) use ($count, &$before): int { // avisos nuevos en esta ronda (otros tests ya marcaron lejos)
            $before[$title] ??= $count($title);
            return $count($title) - $before[$title];
        };
        $inbox('⚠ Punto marcado lejos del lugar · Guardia1');
        $inbox('⚠ GPS falso en una ronda · Guardia1');
        $far = '⚠ Punto marcado lejos del lugar · Guardia1';
        $first = $scan($pt('L1'), $here + ['accuracy_m' => 300]); assert_same('ok', $first['status'], json_encode($first, JSON_UNESCAPED_UNICODE));
        assert_same('impreciso', $status(), 'a 150 m con ±300 m de error: puede haber estado ahí, no se acusa');
        assert_same(0, $inbox($far));
        $scan($pt('L2'), $here + ['accuracy_m' => 10]);
        assert_same(['lejos', 1], [$status(), $inbox($far)], 'lejos: aviso a SyH');
        $scan($pt('L3'), $here + ['accuracy_m' => 10]);
        assert_same(['lejos', 1], [$status(), $inbox($far)], 'el aviso no se repite en la misma ronda');
        $scan($pt('L4'), ['accuracy_m' => null]);
        assert_same('sin_gps', $status());
        $scan($pt('L5'), ['lat' => -34.60235, 'lng' => -58.3816, 'accuracy_m' => 3, 'mock' => 1]);
        assert_same(['simulada', 0], [$status(), (int) DB::tenant()->query('SELECT within_radius FROM patrol_scans ORDER BY id DESC LIMIT 1')->fetchColumn()],
            'GPS falso: aunque diga que está en el punto, no vale');
        assert_same(1, $inbox('⚠ GPS falso en una ronda · Guardia1'));
        assert_same([], DB::tenant()->query('SELECT title FROM notifications WHERE user_id = ' . (int) $st['g1']['id'] . " AND event = 'guard.off_site'")->fetchAll(PDO::FETCH_COLUMN),
            'el guardia no recibe estos avisos');
        $row = array_values(array_filter(Patrols::rounds(), fn ($r) => $r['uuid'] === $roundUuid))[0];
        assert_same([3, 2], [(int) $row['outside_count'], (int) $row['doubtful_count']], 'lista de rondas: 3 fuera (2 lejos + 1 GPS falso), 2 dudosas');
    },

    'panel: el historial y el detalle de ronda se renderizan' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $rounds = Patrols::rounds();
        $found = Patrols::searchRounds([]);
        $html = \App\Core\View::render('panel/rounds/history', ['rows' => $found['rows'], 'total' => $found['total'], 'routes' => Patrols::routes(), 'users' => [],
            'filters' => ['desde' => gmdate('Y-m-d'), 'hasta' => gmdate('Y-m-d'), 'guardia' => '', 'ruta' => '', 'estado' => '']], null);
        assert_true(str_contains($html, 'incompleta') && str_contains($html, '1 / 2'), 'estado y avance en el historial');
        assert_true(str_contains($html, 'Rondas realizadas') && str_contains($html, '/panel/rondas/ronda/'), 'pestañas y acceso al detalle');
        $incomplete = Patrols::searchRounds(['status' => 'incompleta'])['rows'];
        assert_true($incomplete && !array_filter($incomplete, fn ($r) => $r['status'] !== 'incompleta'), 'filtro por estado');
        $free = Patrols::searchRounds(['route_id' => 0])['rows'];
        assert_true(!array_filter($free, fn ($r) => $r['route_name'] !== null), 'filtro de rondas libres');
        assert_same(0, Patrols::searchRounds(['from' => '2000-01-01 00:00:00', 'to' => '2000-01-02 00:00:00'])['total'], 'filtro por fecha');
        $hoy = \App\Core\View::render('panel/rounds/index', ['today' => gmdate('Y-m-d'), 'todayRounds' => [], 'inProgress' => [], 'slots' => [], 'alerts' => [], 'last' => [], 'devices' => []], null);
        assert_true(str_contains($hoy, 'En curso ahora') && str_contains($hoy, 'Reportes') && str_contains($hoy, '+ Nuevo'), 'pestaña Hoy con la barra del módulo');
        $withMap = \App\Core\View::render('panel/rounds/points', ['points' => Patrols::points(), 'canCreate' => true, 'canEdit' => true], null);
        assert_true(str_contains($withMap, 'assets/js/maps.js') && !str_contains($withMap, 'src=\\"'), 'el mapa carga maps.js con una URL válida');
        $partial = array_values(array_filter($rounds, fn ($r) => $r['status'] === 'incompleta'))[0];
        $html = \App\Core\View::render('panel/rounds/round', ['round' => Patrols::roundDetail($partial['uuid']), 'tracks' => []], null);
        assert_true(str_contains($html, 'Salteado') && str_contains($html, 'Se saltearon 1 punto'), 'marca el punto salteado');
    },

    'rondas: ubicar un punto desde Android es idempotente y auditable' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $uuid = ($st['uuid'])($st['p1']);
        $operation = $op('patrol.point_locate', ['point_uuid' => $uuid, 'lat' => -34.6042, 'lng' => -58.3821, 'accuracy_m' => 6]);
        $first = Push::run([$operation])[0];
        assert_same('ok', $first['status'], json_encode($first));
        assert_same(-34.6042, (float) Patrols::point($uuid)['lat']);
        assert_same($first, Push::run([$operation])[0], 'repetir el mismo op_id no vuelve a escribir');
        assert_same(1, (int) DB::tenant()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'patrol_point.locate'")->fetchColumn());
        $fake = Push::run([$op('patrol.point_locate', ['point_uuid' => $uuid, 'lat' => -34.5, 'lng' => -58.4, 'accuracy_m' => 3, 'mock' => 1])])[0];
        assert_true($fake['status'] === 'error' && str_contains($fake['error'], 'simulada'), 'GPS falso: no mueve el punto');
        $vague = Push::run([$op('patrol.point_locate', ['point_uuid' => $uuid, 'lat' => -34.5, 'lng' => -58.4, 'accuracy_m' => 80])])[0];
        assert_true($vague['status'] === 'error' && str_contains($vague['error'], '±80 m'), 'GPS impreciso: no mueve el punto');
        assert_same('error', Push::run([$op('patrol.point_locate', ['point_uuid' => $uuid, 'lat' => -34.5, 'lng' => -58.4])])[0]['status'], 'sin precisión');
        assert_same(-34.6042, (float) Patrols::point($uuid)['lat'], 'las coordenadas siguen siendo las buenas');
        UserAuth::setCurrent($st['g1']);
        $bad = Push::run([$op('patrol.point_locate', ['point_uuid' => $uuid, 'lat' => -34.6, 'lng' => -58.3, 'accuracy_m' => 5])])[0];
        assert_same('error', $bad['status'], 'el guardia no puede modificar coordenadas');
    },

    'rondas: el reporte devuelve solo marcas fuera y respeta filtros' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $round = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $round])]);
        $point = ($st['uuid'])($st['p1']);
        Push::run([$op('round.scan', ['uuid' => Uuid::v4(), 'round_uuid' => $round, 'point_uuid' => $point, 'lat' => -34.9, 'lng' => -58.9, 'accuracy_m' => 5])]);
        $rows = Patrols::outsideScans($st['g1']['uuid'], $point);
        assert_same(1, count($rows));
        assert_same('lejos', $rows[0]['location_status']);
        assert_same([], Patrols::outsideScans($st['g2']['uuid'], $point));
    },

    'automatizaciones: configuración por empresa llega en el pull' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        Settings::set('rondas.automatizaciones', '0');
        Settings::set('rondas.sin_progreso_minutos', '22');
        $meta = Pull::run(null, 1000)['meta'];
        assert_same(false, $meta['automatizaciones_rondas']['activas']);
        assert_same(22, $meta['automatizaciones_rondas']['sin_progreso_minutos']);
        assert_same(10, $meta['automatizaciones_rondas']['inicio_gracia_minutos'], 'valor por defecto');
        // Los dos formularios de /panel/configuracion/notificaciones guardan cada uno lo suyo (antes uno pisaba al otro).
        $ctrl = new \App\Controllers\Web\Panel\NotificationRulesController();
        Settings::set('notif.digest_daily', '1');
        Settings::set('acciones.aviso_dias', '9');
        $ctrl->saveSettings(new \App\Core\Request('POST', '/panel/configuracion/notificaciones/ajustes', [], ['section' => 'rondas',
            'round_automation' => '1', 'round_no_progress' => '15', 'round_start_grace' => '5', 'round_overdue' => '20', 'round_battery' => '25', 'round_sync' => '30']));
        assert_same(['1', '15', '0', '1', '9'], [Settings::get('rondas.automatizaciones'), Settings::get('rondas.sin_progreso_minutos'),
            Settings::get('rondas.guia_android'), Settings::get('notif.digest_daily'), Settings::get('acciones.aviso_dias')], 'rondas no toca los demás ajustes');
        $ctrl->saveSettings(new \App\Core\Request('POST', '/panel/configuracion/notificaciones/ajustes', [], ['escalation' => '20', 'daily' => '1',
            'soon_days' => '4', 'escalate_days' => '3', 'verify_days' => '15', 'inv_days' => '3', 'art_hours' => '48']));
        assert_same(['20', '4', '1', '15'], [Settings::get('notif.escalation_minutes'), Settings::get('acciones.aviso_dias'),
            Settings::get('rondas.automatizaciones'), Settings::get('rondas.sin_progreso_minutos')], 'el general no toca rondas');
    },

    'rondas: el primer escaneo puede iniciar y registrar la ronda en una sola operación' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $round = Uuid::v4();
        $scan = Uuid::v4();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $operation = $op('round.scan_auto', ['round_uuid' => $round, 'uuid' => $scan, 'point_uuid' => ($st['uuid'])($st['p1']),
            'lat' => -34.6037, 'lng' => -58.3816, 'accuracy_m' => 5, 'method' => 'nfc', 'started_at_device' => $now, 'scanned_at_device' => $now]);
        $result = Push::run([$operation])[0];
        assert_same('ok', $result['status'], json_encode($result));
        assert_same(true, $result['data']['auto_started']);
        assert_same('en_curso', Patrols::round($round)['status']);
        assert_same('nfc', DB::tenant()->query('SELECT method FROM patrol_scans WHERE uuid = ' . DB::tenant()->quote($scan))->fetchColumn());
        assert_same($result, Push::run([$operation])[0], 'reintentar el op_id no duplica');
    },

    'automatizaciones: sin progreso, atrasada y abandonada (primero la app, después SyH; una vez cada una)' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        foreach (['rondas.automatizaciones' => '1', 'rondas.sin_progreso_minutos' => '10', 'rondas.atraso_minutos' => '10', 'rondas.minutos_sin_senal' => '10'] as $k => $v) {
            Settings::set($k, $v);
        }
        $route = Uuid::v4();
        Patrols::createRoute(['uuid' => $route, 'name' => 'Perímetro', 'description' => '', 'frequency' => 'diaria', 'expected_minutes' => 30,
            'point_ids' => [$st['p1'], $st['p2']], 'user_ids' => [(int) $st['g1']['id']]]);
        UserAuth::setCurrent($st['g1']);
        $now = time();
        $round = Uuid::v4();
        assert_same('ok', Push::run([$op('round.start', ['uuid' => $round, 'route_uuid' => $route, 'started_at_device' => gmdate('Y-m-d\TH:i:s\Z', $now - 25 * 60)])])[0]['status']);
        Push::run([$op('round.scan', ['uuid' => Uuid::v4(), 'round_uuid' => $round, 'point_uuid' => ($st['uuid'])($st['p1']), 'lat' => -34.6037, 'lng' => -58.3816,
            'accuracy_m' => 5, 'scanned_at_device' => gmdate('Y-m-d\TH:i:s\Z', $now - 24 * 60)])]);
        $id = (int) Patrols::round($round)['id'];
        $alerts = fn () => array_column(\App\Services\RoundAutomation::alerts($id), 'type');
        $inbox = fn (string $title) => (int) DB::tenant()->query('SELECT COUNT(*) FROM notifications WHERE user_id = ' . (int) $st['hys']['id']
            . ' AND title = ' . DB::tenant()->quote($title))->fetchColumn();
        // 24 min sin marcar (≥ 2×10) pero el celular no manda posiciones: eso lo cubre "guardia sin señal", no se duplica.
        \App\Services\RoundAutomation::run($now);
        assert_same([], $alerts());
        DB::tenant()->exec("UPDATE patrol_rounds SET last_track_at = '" . gmdate('Y-m-d H:i:s', $now - 120) . "' WHERE id = {$id}");
        \App\Services\RoundAutomation::run($now);
        assert_same(['no_progress'], $alerts(), 'manda posición pero no marca: aviso');
        assert_same(1, $inbox('Ronda sin marcar puntos · Guardia1'));
        \App\Services\RoundAutomation::run($now + 60);
        assert_same(['no_progress'], $alerts(), 'mismo hueco: no se repite');
        // Atrasada: esperados 30 + 2×10 de tolerancia → a los 55 min avisa (falta un punto)
        DB::tenant()->exec("UPDATE patrol_rounds SET last_track_at = '" . gmdate('Y-m-d H:i:s', $now + 30 * 60) . "' WHERE id = {$id}");
        \App\Services\RoundAutomation::run($now + 30 * 60);
        assert_same(['no_progress', 'late'], $alerts());
        assert_same(1, \App\Services\RoundAutomation::alerts($id)[1]['data']['faltan']);
        assert_same(1, $inbox('Ronda atrasada · Guardia1'));
        \App\Services\RoundAutomation::run($now + 31 * 60);
        assert_same(2, count($alerts()), 'atrasada: una sola vez');
        $html = \App\Core\View::render('panel/rounds/round', ['round' => Patrols::roundDetail($round), 'tracks' => [], 'alerts' => \App\Services\RoundAutomation::alerts($id)], null);
        assert_true(str_contains($html, 'Ronda atrasada') && str_contains($html, 'esperados 30'), 'el detalle muestra alertas y duración esperada');
        // Abandonada: a las 12 h la cierra el sistema con la última actividad (incompleta: faltó un punto)
        \App\Services\RoundAutomation::run($now + 13 * 3600);
        $closed = Patrols::round($round);
        assert_same(['incompleta', gmdate('Y-m-d H:i:s', $now + 30 * 60)], [$closed['status'], $closed['finished_at']]);
        assert_same('abandoned', $alerts()[2]);
        // Desactivadas: no hace nada
        Settings::set('rondas.automatizaciones', '0');
        assert_same(['no_progress' => 0, 'late' => 0, 'abandoned' => 0, 'device' => 0], \App\Services\RoundAutomation::run($now + 99 * 3600), 'desactivadas: no hace nada');
        Settings::set('rondas.automatizaciones', '1');
    },

    'automatizaciones: estado del celular (batería, GPS, sin enviar) y aviso a SyH durante la ronda' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        Settings::set('rondas.automatizaciones', '1');
        Settings::set('rondas.bateria_baja_porcentaje', '20');
        UserAuth::setCurrent($st['g1']);
        $dev = \App\Models\UserDevices::findByUuid(\App\Models\UserDevices::upsert((int) $st['g1']['id'], Uuid::v4(), 'Moto G', hash('sha256', 'r-' . Uuid::v4()),
            gmdate('Y-m-d H:i:s', time() + 86400), null));
        \App\Services\ApiAuth::setDevice($dev);
        $now = time();
        $round = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $round, 'started_at_device' => gmdate('Y-m-d\TH:i:s\Z', $now - 600)])]);
        $id = (int) Patrols::round($round)['id'];
        $status = fn (array $s) => Push::run([$op('device.status', $s + ['at' => gmdate('Y-m-d\TH:i:s\Z', $now)])])[0];
        // Batería 15 % (bajo el umbral 20): el guardia ya fue avisado por la app; SyH todavía no (avisa a la mitad, 10 %)
        assert_same('ok', $status(['battery' => 15, 'charging' => false, 'gps' => true, 'permission' => true, 'pending' => 0, 'app_version' => '1.0.0'])['status']);
        \App\Services\RoundAutomation::run($now);
        assert_same([], array_column(\App\Services\RoundAutomation::alerts($id), 'type'));
        assert_same(['Batería 15 %'], \App\Services\DeviceStatus::problems(\App\Services\DeviceStatus::latestForUser((int) $st['g1']['id']), $now));
        $status(['battery' => 8, 'charging' => false, 'gps' => false, 'gps_off_since' => gmdate('Y-m-d\TH:i:s\Z', $now - 360), 'pending' => 4,
            'oldest_pending_at' => gmdate('Y-m-d\TH:i:s\Z', $now - 1800), 'battery_extra' => 'x']);
        \App\Services\RoundAutomation::run($now);
        assert_same(['battery', 'gps_off'], array_column(\App\Services\RoundAutomation::alerts($id), 'type'), 'batería 8 % sin cargar y GPS apagado hace 6 min');
        \App\Services\RoundAutomation::run($now + 60);
        assert_same(2, count(\App\Services\RoundAutomation::alerts($id)), 'una vez por ronda y problema');
        $inbox = array_column(DB::tenant()->query('SELECT title FROM notifications WHERE user_id = ' . (int) $st['hys']['id'])->fetchAll(), 'title');
        assert_true(in_array('Batería por terminarse · Guardia1', $inbox, true) && in_array('GPS apagado · Guardia1', $inbox, true));
        $problems = \App\Services\DeviceStatus::problems(\App\Services\DeviceStatus::latestForUser((int) $st['g1']['id']), $now);
        assert_same(['Batería 8 %', 'GPS apagado', '4 registro(s) sin enviar'], $problems);
        $recent = \App\Services\DeviceStatus::recent();
        assert_same(['Guardia1', 'Moto G'], [$recent[0]['user_name'], $recent[0]['device_name']]);
        \App\Services\ApiAuth::setDevice(null);
        assert_same('error', $status(['battery' => 50])['status'], 'sin dispositivo identificado no se guarda');
        $html = \App\Core\View::render('panel/rounds/index', ['today' => gmdate('Y-m-d'), 'todayRounds' => [], 'inProgress' => [], 'slots' => [], 'alerts' => [], 'last' => [], 'devices' => $recent], null);
        assert_true(str_contains($html, 'Celulares de los guardias') && str_contains($html, 'Batería 8 %'));
    },

    'programación: horarios fijos e intervalos (medianoche, días, feriados, vigencia) en hora de la empresa' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']); // America/Argentina/Buenos_Aires (UTC−3)
        $P = \App\Services\PatrolPlanner::class;
        $fixed = ['mode' => 'fixed', 'times' => json_encode(['06:00', '22:00']), 'days' => '1,2,3,4,5', 'except_dates' => json_encode(['2026-10-16']),
            'valid_from' => null, 'valid_until' => '2026-10-20'];
        // jue 15, vie 16 (feriado), sáb 17 y dom 18 (no), lun 19, mar 20, mié 21 (fuera de vigencia)
        assert_same(['2026-10-15 09:00:00', '2026-10-16 01:00:00', '2026-10-19 09:00:00', '2026-10-20 01:00:00', '2026-10-20 09:00:00', '2026-10-21 01:00:00'],
            $P::occurrences($fixed, '2026-10-15', '2026-10-21'), '22:00 local = 01:00 UTC del día siguiente');
        $night = ['mode' => 'interval', 'window_start' => '22:00', 'window_end' => '06:00', 'every_minutes' => 120, 'days' => '5', 'except_dates' => null,
            'valid_from' => null, 'valid_until' => null];
        assert_same(['2026-10-17 01:00:00', '2026-10-17 03:00:00', '2026-10-17 05:00:00', '2026-10-17 07:00:00', '2026-10-17 09:00:00'],
            $P::occurrences($night, '2026-10-12', '2026-10-18'), 'viernes 22 a sábado 6: 22, 0, 2, 4 y 6 (cuenta el día en que empieza)');
        // Validaciones
        UserAuth::setCurrent($st['hys']);
        $route = Uuid::v4();
        Patrols::createRoute(['uuid' => $route, 'name' => 'Programada', 'description' => '', 'frequency' => 'manual', 'expected_minutes' => 20,
            'point_ids' => [$st['p1']], 'user_ids' => [(int) $st['g1']['id']]]);
        $st['sched_route'] = $route;
        [, $e] = $P::validate(['route' => $route, 'mode' => 'fixed', 'times' => '25:00', 'days' => [1]]);
        assert_true(isset($e['times']));
        [, $e] = $P::validate(['route' => $route, 'mode' => 'interval', 'window_start' => '08:00', 'window_end' => '12:00', 'every_minutes' => 30, 'days' => [1], 'max_delay_minutes' => 60]);
        assert_true(isset($e['max_delay_minutes']), 'con intervalo la demora no puede pisar a la siguiente');
        [, $e] = $P::validate(['route' => $route, 'mode' => 'fixed', 'times' => '08:00', 'days' => [], 'users' => [$st['g2']['uuid']]]);
        assert_true(isset($e['days']) && isset($e['users']), 'sin días / guardia no asignado a la ruta');
        [$data, $e] = $P::validate(['route' => $route, 'mode' => 'fixed', 'times' => '8:00, 08:00 20:30', 'days' => ['1', '9', '3'], 'except_dates' => '25/12/2026',
            'grace_minutes' => '5', 'max_delay_minutes' => '30']);
        assert_same([[], '["08:00","20:30"]', '1,3', '["2026-12-25"]', 5], [$e, $data['times'], $data['days'], $data['except_dates'], $data['grace_minutes']]);
    },

    'programación: a tiempo, tarde, no realizada, avisos y lo que baja al celular' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        Settings::set('rondas.automatizaciones', '1');
        $P = \App\Services\PatrolPlanner::class;
        UserAuth::setCurrent($st['hys']);
        $tz = new DateTimeZone('America/Argentina/Buenos_Aires');
        // Dos horarios: dentro de 30 min y hace 2 min (gracia 5, demora máxima 20)
        $t1 = (new DateTimeImmutable('+30 minutes', $tz))->format('H:i');
        $t2 = (new DateTimeImmutable('-2 minutes', $tz))->format('H:i');
        [$data, $e] = $P::validate(['route' => $st['sched_route'], 'name' => 'Noche', 'mode' => 'fixed', 'times' => "{$t1}, {$t2}", 'days' => [1, 2, 3, 4, 5, 6, 7],
            'grace_minutes' => 5, 'max_delay_minutes' => 20]);
        assert_same([], $e);
        $uuid = $P::save(null, $data);
        $sched = $P::find($uuid);
        $slots = fn () => DB::tenant()->query('SELECT * FROM patrol_slots WHERE schedule_id = ' . (int) $sched['id'] . " AND status <> 'anulada' ORDER BY due_at")->fetchAll();
        $n = count($slots());
        assert_true($n >= 4, 'genera ayer, hoy, mañana y pasado: ' . $n);
        assert_same(0, $P::generate(), 'idempotente');
        $first = array_values(array_filter($slots(), fn ($x) => strtotime($x['due_at'] . ' UTC') > time()))[0];
        $due = strtotime($first['due_at'] . ' UTC');
        // Lo que baja al celular: solo al guardia a cargo
        UserAuth::setCurrent($st['g1']);
        $mine = array_column((array) (Pull::run(null, 1000)['changes']->patrol_slots ?? []), 'uuid');
        assert_true(in_array($first['uuid'], $mine, true), 'el guardia asignado recibe la ronda programada');
        UserAuth::setCurrent($st['g2']);
        assert_true(!in_array($first['uuid'], array_column((array) (Pull::run(null, 1000)['changes']->patrol_slots ?? []), 'uuid'), true), 'otro guardia no');
        // El horario de hace 2 min: el guardia empieza ahora (dentro de la gracia) → realizada a tiempo
        $past = array_values(array_filter($slots(), fn ($x) => strtotime($x['due_at'] . ' UTC') <= time() && $x['status'] === 'pendiente'));
        $second = end($past);
        UserAuth::setCurrent($st['g1']);
        $round = Uuid::v4();
        $res = Push::run([$op('round.start', ['uuid' => $round, 'route_uuid' => $st['sched_route'], 'started_at_device' => gmdate('Y-m-d\TH:i:s\Z')])])[0];
        assert_same('ok', $res['status'], json_encode($res));
        $row = DB::tenant()->query("SELECT status, on_time, round_id FROM patrol_slots WHERE id = {$second['id']}")->fetch();
        assert_same(['realizada', 1, (int) Patrols::round($round)['id']], [$row['status'], (int) $row['on_time'], (int) $row['round_id']]);
        // Sin empezar a la hora + 2×gracia: aviso a SyH (una vez); a la demora máxima: no realizada + aviso
        $inbox = fn (string $t) => (int) DB::tenant()->query('SELECT COUNT(*) FROM notifications WHERE user_id = ' . (int) $st['hys']['id'] . ' AND title = ' . DB::tenant()->quote($t))->fetchColumn();
        $marked = fn (string $key) => (int) DB::tenant()->query('SELECT COUNT(*) FROM notification_marks WHERE mark_key = ' . DB::tenant()->quote($key))->fetchColumn();
        $P::run($due + 9 * 60);
        assert_same(0, $marked("slot_late:{$first['id']}"), 'a la hora + 9 min (< 2×gracia) todavía no');
        $P::run($due + 11 * 60);
        assert_same(1, $marked("slot_late:{$first['id']}"));
        $before = $inbox('Ronda sin empezar · Programada');
        assert_true($before >= 1);
        $P::run($due + 12 * 60);
        assert_same($before, $inbox('Ronda sin empezar · Programada'), 'no se repite');
        $P::run($due + 21 * 60);
        assert_same('no_realizada', DB::tenant()->query("SELECT status FROM patrol_slots WHERE id = {$first['id']}")->fetchColumn());
        assert_same(1, $marked("slot_missed:{$first['id']}"));
        assert_true($inbox('Ronda no realizada · Programada') >= 1);
        // Editar la programación: lo pasado queda, lo futuro pendiente se recalcula (y no se duplica)
        UserAuth::setCurrent($st['hys']);
        [$data2] = $P::validate(['route' => $st['sched_route'], 'name' => 'Noche', 'mode' => 'fixed', 'times' => "{$t1}, {$t2}", 'days' => [1, 2, 3, 4, 5, 6, 7],
            'grace_minutes' => 8, 'max_delay_minutes' => 20]);
        $P::save($sched, $data2);
        assert_same('no_realizada', DB::tenant()->query("SELECT status FROM patrol_slots WHERE id = {$first['id']}")->fetchColumn(), 'el registro pasado no se toca');
        $future = array_values(array_filter($slots(), fn ($x) => $x['status'] === 'pendiente' && strtotime($x['due_at'] . ' UTC') > time()));
        assert_true($future && (int) $future[0]['grace_minutes'] === 8, 'las futuras con la gracia nueva');
        assert_same(count(array_unique(array_column($slots(), 'due_at'))), count($slots()), 'sin duplicados');
        // Panel
        $day = (new DateTimeImmutable('@' . $due))->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'))->format('Y-m-d');
        $html = \App\Core\View::render('panel/rounds/schedules', ['day' => $day, 'today' => $day, 'slots' => $P::day($day), 'schedules' => $P::all(), 'canEdit' => true], null);
        assert_true(str_contains($html, 'No realizada') && str_contains($html, 'Programada'), 'el día muestra el estado');
        $form = \App\Core\View::render('panel/rounds/schedule_form', ['schedule' => $P::find($uuid), 'routes' => Patrols::routes(), 'assignees' => [],
            'userUuids' => [], 'defaultGrace' => 10, 'old' => null], null);
        assert_true(str_contains($form, 'Horarios fijos'));
    },

    'métricas: rondas, marcas, puntos que fallan, guardias sin actividad, alertas, CSV y resumen diario' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        Settings::set('rondas.automatizaciones', '1');
        UserAuth::setCurrent($st['hys']);
        $route = Uuid::v4();
        Patrols::createRoute(['uuid' => $route, 'name' => 'Métricas', 'description' => '', 'frequency' => 'manual', 'expected_minutes' => 10,
            'point_ids' => [$st['p1'], $st['p2']], 'user_ids' => [(int) $st['g1']['id'], (int) $st['g2']['id']]]);
        $routeId = (int) Patrols::route($route)['id'];
        UserAuth::setCurrent($st['g1']);
        $iso = fn (int $ago) => gmdate('Y-m-d\TH:i:s\Z', time() - $ago);
        // Marca en las coordenadas actuales del punto (otro test pudo haberlo movido) + desplazamiento en latitud
        $scan = function (string $round, int $point, float $offset, int $ago) use ($op, $st, $iso) {
            $pt = DB::tenant()->query('SELECT lat, lng FROM patrol_points WHERE id = ' . $point)->fetch();
            return Push::run([$op('round.scan', ['uuid' => Uuid::v4(), 'round_uuid' => $round, 'point_uuid' => ($st['uuid'])($point),
                'lat' => (float) $pt['lat'] + $offset, 'lng' => (float) $pt['lng'], 'accuracy_m' => 5, 'scanned_at_device' => $iso($ago)])])[0];
        };
        // Ronda A: completa pero de 20 min (esperados 10), con P2 marcado a ~2 km
        $a = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $a, 'route_uuid' => $route, 'started_at_device' => $iso(3 * 3600)])]);
        $scan($a, $st['p1'], 0.0, 3 * 3600 - 60);
        $scan($a, $st['p2'], -0.018, 3 * 3600 - 600); // ~2 km
        Push::run([$op('round.finish', ['round_uuid' => $a, 'finished_at_device' => $iso(3 * 3600 - 1200)])]);
        // Ronda B: salteó P2
        $b = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $b, 'route_uuid' => $route, 'started_at_device' => $iso(2 * 3600)])]);
        $scan($b, $st['p1'], 0.0, 2 * 3600 - 60);
        Push::run([$op('round.finish', ['round_uuid' => $b, 'finished_at_device' => $iso(2 * 3600 - 300)])]);
        UserAuth::setCurrent($st['hys']);
        $tz = new DateTimeZone('America/Argentina/Buenos_Aires');
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $yesterday = (new DateTimeImmutable('now', $tz))->modify('-1 day')->format('Y-m-d');
        $r = \App\Services\RoundMetrics::report($yesterday, $today, ['route_id' => $routeId]);
        assert_same([2, 1, 1, 1], [$r['rounds']['rounds'], $r['rounds']['completas'], $r['rounds']['incompletas'], $r['rounds']['atrasadas']]);
        assert_same([3, 2, 1], [$r['scans']['total'], $r['scans']['ok'], $r['scans']['fuera']]);
        assert_same(['P2', 1, 1, 2], [$r['points'][0]['code'], $r['points'][0]['salteados'], $r['points'][0]['fuera'], $r['points'][0]['problemas']], 'el punto que más falla primero');
        assert_same(['Guardia2'], array_column($r['idle'], 'name'), 'asignado a la ruta y sin rondas');
        assert_same([2, 1, 13], [$r['guards'][0]['rounds'], $r['guards'][0]['fuera'], $r['guards'][0]['duracion_media']]);
        assert_true(in_array('off_site', array_column($r['alerts'], 'type'), true), 'la marca fuera de lugar entra al historial');
        // Por guardia: con el filtro de guardia no aparecen los sin actividad
        assert_same([], \App\Services\RoundMetrics::report($yesterday, $today, ['route_id' => $routeId, 'user_id' => (int) $st['g1']['id']])['idle']);
        $csv = \App\Services\RoundMetrics::csv($r, 'guardias');
        assert_true(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'Guardia1;2;1;1;1') && str_contains($csv, 'Guardia2;0'), $csv);
        assert_true(str_contains(\App\Services\RoundMetrics::csv($r, 'puntos'), 'P2;Depósito;1;0;1;0;1'));
        $html = \App\Core\View::render('panel/rounds/metrics', ['r' => $r, 'filters' => ['desde' => $yesterday, 'hasta' => $today, 'ruta' => $route, 'guardia' => ''],
            'routes' => Patrols::routes(), 'users' => [], 'canExport' => true], null);
        assert_true(str_contains($html, 'Por guardia') && str_contains($html, 'Marca fuera de lugar') && str_contains($html, 'Sin rondas en el período'));
        // Resumen diario: mañana después de las 8 h, del día de hoy; una sola vez
        $tomorrow9 = (new DateTimeImmutable($today . ' 09:00:00', $tz))->modify('+1 day')->getTimestamp();
        assert_same(false, \App\Services\RoundMetrics::dailySummary($tomorrow9 - 2 * 3600), 'antes de las 8 h no');
        assert_same(true, \App\Services\RoundMetrics::dailySummary($tomorrow9));
        assert_same(false, \App\Services\RoundMetrics::dailySummary($tomorrow9 + 600), 'una vez por día');
        $title = 'Rondas de ayer · ' . date('d/m', strtotime($today));
        $body = DB::tenant()->query('SELECT body FROM notifications WHERE user_id = ' . (int) $st['hys']['id'] . ' AND title = ' . DB::tenant()->quote($title))->fetchColumn();
        assert_true(is_string($body) && str_contains($body, 'Rondas:') && str_contains($body, 'fuera de lugar'), (string) $body);
    },

    'puntos: eliminar lo saca de la lista, de las rutas y del celular, y conserva las marcas viejas' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $pid = Patrols::createPoint(['name' => 'Para borrar', 'code' => 'DEL1', 'description' => '', 'site_id' => null, 'sector_id' => null,
            'lat' => -34.61, 'lng' => -58.38, 'radius_m' => 40, 'is_critical' => 0]);
        $uuid = ($st['uuid'])($pid);
        $route = Uuid::v4();
        Patrols::createRoute(['uuid' => $route, 'name' => 'Con punto a borrar', 'description' => '', 'frequency' => 'manual', 'expected_minutes' => 10,
            'point_ids' => [$st['p1'], $pid], 'user_ids' => [(int) $st['g1']['id']]]);
        UserAuth::setCurrent($st['g1']);
        $round = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $round, 'route_uuid' => $route])]);
        Push::run([$op('round.scan', ['uuid' => Uuid::v4(), 'round_uuid' => $round, 'point_uuid' => $uuid, 'lat' => -34.61, 'lng' => -58.38, 'accuracy_m' => 5])]);
        $cursor = Pull::run(null, 1000)['cursor'];
        UserAuth::setCurrent($st['hys']);
        DB::tenant()->exec("UPDATE patrol_routes SET updated_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE uuid = '{$route}'");
        assert_same(1, Patrols::deletePoint(Patrols::point($uuid)), 'se sacó de una ruta');
        assert_true(!in_array($uuid, array_column(Patrols::points(), 'uuid'), true), 'no aparece en la lista');
        assert_same([($st['uuid'])($st['p1'])], Patrols::routePointUuids((int) Patrols::route($route)['id']), 'la ruta queda sin ese punto');
        assert_same(1, (int) DB::tenant()->query('SELECT COUNT(*) FROM patrol_scans s JOIN patrol_points p ON p.id = s.point_id WHERE p.uuid = ' . DB::tenant()->quote($uuid))->fetchColumn(), 'la marca vieja queda');
        UserAuth::setCurrent($st['g1']);
        sleep(1);
        $page = Pull::run($cursor, 1000);
        assert_true(in_array($uuid, (array) ($page['deleted']->patrol_points ?? []), true), 'al celular le llega como baja');
        $routes = array_values(array_filter((array) ($page['changes']->patrol_routes ?? []), fn ($r) => $r['uuid'] === $route));
        assert_true($routes && !in_array($uuid, $routes[0]['points'], true), 'y la ruta le llega sin el punto');
        assert_same('error', Push::run([$op('round.scan', ['uuid' => Uuid::v4(), 'round_uuid' => $round, 'point_uuid' => $uuid])])[0]['status'], 'ya no se puede marcar');
        $html = \App\Core\View::render('panel/rounds/points', ['points' => Patrols::points(), 'canCreate' => true, 'canEdit' => true], null);
        assert_true(str_contains($html, 'Doble clic en el mapa') && str_contains($html, '/eliminar'));
    },

    'rutas: editar el orden, modo libre / secuencial / al azar, fuera de orden y vistas' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $mk = fn (string $code, float $lat) => Patrols::createPoint(['name' => 'Orden ' . $code, 'code' => $code, 'description' => '', 'site_id' => null,
            'sector_id' => null, 'lat' => $lat, 'lng' => -58.38, 'radius_m' => 40, 'is_critical' => 0]);
        [$a, $b, $c] = [$mk('OA', -34.600), $mk('OB', -34.601), $mk('OC', -34.602)];
        $u = fn (int $id) => ($st['uuid'])($id);
        $route = Uuid::v4();
        $rid = Patrols::createRoute(['uuid' => $route, 'name' => 'Orden', 'description' => '', 'expected_minutes' => 15, 'order_mode' => 'secuencial',
            'point_ids' => [$a, $b, $c], 'user_ids' => [(int) $st['g1']['id']]]);
        // Editar: nuevo orden C, A, B y sumar un guardia
        Patrols::updateRoute($rid, ['name' => 'Orden', 'description' => 'x', 'expected_minutes' => 15, 'order_mode' => 'secuencial',
            'point_ids' => [$c, $a, $b], 'user_ids' => [(int) $st['g1']['id'], (int) $st['g2']['id']]]);
        assert_same([$u($c), $u($a), $u($b)], Patrols::routePointUuids($rid));
        assert_same(2, count(Patrols::assignments($rid)));
        Patrols::updateRoute($rid, ['name' => 'Orden', 'description' => 'x', 'expected_minutes' => 15, 'order_mode' => 'secuencial',
            'point_ids' => [$c, $a, $b], 'user_ids' => [(int) $st['g1']['id']]]);
        assert_same(1, count(Patrols::assignments($rid)), 'sacar un guardia lo desasigna');
        // Secuencial: el orden es C, A, B. Marcar B antes que A = fuera de orden (se registra, no se rechaza)
        UserAuth::setCurrent($st['g1']);
        $round = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $round, 'route_uuid' => $route, 'started_at_device' => gmdate('Y-m-d\TH:i:s\Z', time() - 600)])]);
        $scan = function (string $round, int $point, int $ago) use ($op, $u) {
            $pt = Patrols::point($u($point));
            return Push::run([$op('round.scan', ['uuid' => Uuid::v4(), 'round_uuid' => $round, 'point_uuid' => $u($point), 'lat' => (float) $pt['lat'],
                'lng' => (float) $pt['lng'], 'accuracy_m' => 5, 'scanned_at_device' => gmdate('Y-m-d\TH:i:s\Z', time() - $ago)])])[0];
        };
        assert_same('ok', $scan($round, $c, 500)['status']);
        assert_same('ok', $scan($round, $b, 400)['status'], 'fuera de orden no se rechaza');
        $scan($round, $a, 300);
        $flags = DB::tenant()->query("SELECT p.code, s.out_of_order FROM patrol_scans s JOIN patrol_points p ON p.id = s.point_id
            JOIN patrol_rounds r ON r.id = s.round_id WHERE r.uuid = '{$round}' ORDER BY s.scanned_at_device")->fetchAll(PDO::FETCH_KEY_PAIR);
        assert_same(['OC' => 0, 'OB' => 1, 'OA' => 0], array_map('intval', $flags), 'C bien, B fuera de orden (tocaba A), A bien');
        // Al azar: vale el orden que armó el celular; uno inválido se reemplaza por uno del servidor
        UserAuth::setCurrent($st['hys']);
        Patrols::updateRoute($rid, ['name' => 'Orden', 'description' => '', 'expected_minutes' => 15, 'order_mode' => 'azar', 'point_ids' => [$a, $b, $c],
            'user_ids' => [(int) $st['g1']['id']]]);
        UserAuth::setCurrent($st['g1']);
        $r2 = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $r2, 'route_uuid' => $route, 'point_order' => [$u($b), $u($c), $u($a)]])]);
        assert_same([$u($b), $u($c), $u($a)], json_decode(Patrols::round($r2)['point_order'], true));
        $r3 = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $r3, 'route_uuid' => $route, 'point_order' => [$u($a), 'basura']])]);
        $o3 = json_decode(Patrols::round($r3)['point_order'], true);
        sort($o3);
        $all = [$u($a), $u($b), $u($c)]; sort($all);
        assert_same($all, $o3, 'orden inválido del celular → uno válido del servidor');
        // Libre: sin orden ni marca
        UserAuth::setCurrent($st['hys']);
        Patrols::updateRoute($rid, ['name' => 'Orden', 'description' => '', 'expected_minutes' => 15, 'order_mode' => 'libre', 'point_ids' => [$a, $b, $c],
            'user_ids' => [(int) $st['g1']['id']]]);
        UserAuth::setCurrent($st['g1']);
        $r4 = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $r4, 'route_uuid' => $route])]);
        assert_same(null, Patrols::round($r4)['point_order']);
        $scan($r4, $c, 60);
        assert_same(null, DB::tenant()->query("SELECT s.out_of_order FROM patrol_scans s JOIN patrol_rounds r ON r.id = s.round_id WHERE r.uuid = '{$r4}'")->fetchColumn());
        // Lo que baja al celular
        $routes = array_values(array_filter((array) (Pull::run(null, 1000)['changes']->patrol_routes ?? []), fn ($r) => $r['uuid'] === $route));
        assert_same('libre', $routes[0]['order_mode']);
        // Vistas
        UserAuth::setCurrent($st['hys']);
        $show = \App\Core\View::render('panel/rounds/route_show', ['route' => Patrols::route($route), 'points' => Patrols::routePoints($rid), 'guards' => Patrols::assignments($rid),
            'schedules' => [], 'rounds' => [], 'canEdit' => true], null);
        assert_true(str_contains($show, 'SAMaps.route') && str_contains($show, 'Editar ruta'));
        $form = \App\Core\View::render('panel/rounds/route', ['route' => Patrols::route($route), 'points' => Patrols::points(), 'users' => [],
            'selected' => Patrols::routePointUuids($rid), 'assigned' => [], 'old' => []], null);
        assert_true(str_contains($form, 'Puntos de la ruta, en orden') && str_contains($form, 'Al azar'));
        $detail = \App\Core\View::render('panel/rounds/round', ['round' => Patrols::roundDetail($round), 'tracks' => [], 'alerts' => []], null);
        assert_true(str_contains($detail, 'Fuera de orden') && str_contains($detail, 'SAMaps.route'));
    },

    'módulos: desactivar uno lo saca de permisos, del menú del celular y de sus páginas; reactivarlo lo devuelve' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        assert_true(UserAuth::can('epp', 'ver') && UserAuth::can('rondas', 'ver'));
        $ctrl = new \App\Controllers\Web\Panel\SettingsController();
        $on = array_values(array_diff(\App\Services\Modules::keys(), ['epp', 'permisos_trabajo']));
        $ctrl->updateModules(new \App\Core\Request('POST', '/panel/configuracion/modulos', [], ['modules' => array_merge($on, ['usuarios', 'inventado'])]));
        assert_same(['permisos_trabajo', 'epp'], \App\Services\Modules::disabled(), 'solo módulos que se pueden desactivar');
        assert_true(!UserAuth::can('epp', 'ver') && !UserAuth::can('permisos_trabajo', 'crear') && UserAuth::can('rondas', 'ver') && UserAuth::can('usuarios', 'ver'));
        assert_same(null, UserAuth::scope('epp'));
        assert_true(!isset(UserAuth::permissions()['epp']) && isset(UserAuth::permissions()['rondas']), 'la app del celular no lo recibe');
        assert_true(!isset(Pull::run(null, 10)['meta']['permisos']['permisos_trabajo']));
        $res = (new \App\Middleware\RequirePermission('epp', 'ver'))(new \App\Core\Request('GET', '/panel/epp'), fn () => new \App\Core\Response('ok'));
        assert_same(403, $res->status);
        assert_true(str_contains($res->body, 'Módulo desactivado'));
        // Reactivar
        $ctrl->updateModules(new \App\Core\Request('POST', '/panel/configuracion/modulos', [], ['modules' => \App\Services\Modules::keys()]));
        assert_same([], \App\Services\Modules::disabled());
        assert_true(UserAuth::can('epp', 'ver'));
        $html = $ctrl->show(new \App\Core\Request('GET', '/panel/configuracion'))->body;
        assert_true(str_contains($html, 'id="modulos"') && str_contains($html, 'mod-rondas'));
    },

    'puntos: editar nombre, código, ubicación y radio sin cambiar el QR; código repetido rechazado' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $_SESSION = [];
        $uuid = $st['uuid']($st['p1']);
        $before = Patrols::point($uuid);
        $ctl = new \App\Controllers\Web\Panel\RoundsController();
        $form = $ctl->pointForm(new \App\Core\Request('GET', '/'), $uuid)->body;
        assert_true(str_contains($form, 'Editar punto P1') && str_contains($form, 'value="Portón"') && str_contains($form, '/panel/rondas/puntos/' . $uuid));
        DB::tenant()->exec("UPDATE patrol_points SET updated_at = '2020-01-01 00:00:00' WHERE id = " . (int) $st['p1']);
        $res = $ctl->pointStore(new \App\Core\Request('POST', '/', [], ['name' => 'Portón norte', 'code' => 'P1N', 'description' => 'Entrada camiones',
            'lat' => '-34.6040', 'lng' => '-58.3816', 'radius_m' => '40', 'is_critical' => '1']), $uuid);
        assert_same(302, $res->status);
        $after = Patrols::point($uuid);
        assert_same([$before['id'], 'Portón norte', 'P1N', 'Entrada camiones', 40, -34.604], [$after['id'], $after['name'], $after['code'], $after['description'], (int) $after['radius_m'], (float) $after['lat']]);
        assert_true($after['updated_at'] > '2020-01-01 00:00:00', 'baja de nuevo a los celulares');
        assert_true((int) DB::tenant()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'patrol_point.update'")->fetchColumn() >= 1);
        // Código de otro punto: no se guarda
        $ctl->pointStore(new \App\Core\Request('POST', '/', [], ['name' => 'X', 'code' => 'P2', 'lat' => '-34.6', 'lng' => '-58.38', 'radius_m' => '30']), $uuid);
        assert_same('P1N', Patrols::point($uuid)['code']);
        // Volver a como estaba (otros tests usan P1)
        Patrols::updatePoint((int) $st['p1'], ['name' => 'Portón', 'code' => 'P1', 'description' => '', 'site_id' => null, 'sector_id' => null,
            'lat' => -34.6037, 'lng' => -58.3816, 'radius_m' => 30, 'is_critical' => true]);
        assert_true(str_contains(\App\Core\View::render('panel/rounds/points', ['points' => Patrols::points(), 'canCreate' => true, 'canEdit' => true], null), '/editar'));
    },

    'panel: ronda en curso en vivo (posición y recorrido cada 15 s; recarga al marcar o terminar)' => function () use ($setup, &$st, $op) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g2']);
        $round = Uuid::v4();
        Push::run([$op('round.start', ['uuid' => $round, 'route_uuid' => null, 'started_at_device' => gmdate('Y-m-d\\TH:i:s\\Z')])]);
        Push::run([$op('round.track', ['round_uuid' => $round, 'points' => [
            ['uuid' => Uuid::v4(), 'at' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 30), 'lat' => -34.6037, 'lng' => -58.3816, 'accuracy_m' => 8],
            ['uuid' => Uuid::v4(), 'at' => gmdate('Y-m-d\\TH:i:s\\Z'), 'lat' => -34.6040, 'lng' => -58.3816, 'accuracy_m' => 6]]])]);
        UserAuth::setCurrent($st['hys']);
        $ctl = new \App\Controllers\Web\Panel\RoundsController();
        $d = json_decode($ctl->roundLive(new \App\Core\Request('GET', '/'), $round)->body, true)['data'];
        assert_same(['en_curso', 0, 2, -34.604, 6], [$d['status'], $d['scans'], count($d['tracks']), $d['tracks'][1][0], $d['last_accuracy']]);
        $html = \App\Core\View::render('panel/rounds/round', ['round' => Patrols::roundDetail($round), 'tracks' => \App\Services\GuardSafety::tracks(
            (int) Patrols::roundDetail($round)['id']), 'alerts' => []], null);
        assert_true(str_contains($html, 'En vivo') && str_contains($html, '/vivo') && str_contains($html, 'Seguir al guardia'));
        UserAuth::setCurrent($st['g2']);
        Push::run([$op('round.finish', ['round_uuid' => $round, 'finished_at_device' => gmdate('Y-m-d\\TH:i:s\\Z')])]);
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
