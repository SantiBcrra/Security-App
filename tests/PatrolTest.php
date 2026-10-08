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
        assert_same('ok', Push::run([$op('round.start', ['uuid' => $round])])[0]['status']);
        assert_same('ok', Push::run([$op('round.start', ['uuid' => $round])])[0]['status'], 'otro op_id, misma ronda');
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
        $r = Push::run([$op('round.finish', ['round_uuid' => $st['round']])])[0];
        assert_same('completa', $r['data']['status']);
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
