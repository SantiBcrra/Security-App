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
use App\Services\ShiftAbsences;
use App\Services\ShiftPlanner;
use App\Services\ShiftReport;
use App\Services\ShiftService;
use App\Services\Sync\Pull;
use App\Services\Sync\Push;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Turnos de guardias (docs/design/turnos-guardias.md). Maestra `securityapp_test_shift` + empresa sh-a.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_shift';
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
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Sh A', 'slug' => 'sh-a'] + $company, null));
    Tenant::activate($st['a']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    $st['hys'] = $mk('responsable_hys', 'jefe@sh.test');
    $st['sup'] = $mk('supervisor', 'super@sh.test');
    $st['g1'] = $mk('reportante', 'guardia1@sh.test');
    $st['g2'] = $mk('reportante', 'guardia2@sh.test');
    $st['g3'] = $mk('reportante', 'guardia3@sh.test');
    $site = \App\Models\Sites::create(['name' => 'Depósito Norte', 'code' => 'DN', 'lat' => -34.6037, 'lng' => -58.3816, 'geofence_radius_m' => 150, 'is_active' => 1]);
    $st['site'] = \App\Models\Sites::findById($site);
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

$op = fn (string $type, array $data) => ['op_id' => Uuid::v4(), 'type' => $type, 'data' => $data];
$count = fn (string $event) => (int) DB::tenant()->query("SELECT COUNT(*) FROM notifications WHERE event = " . DB::tenant()->quote($event))->fetchColumn();
/** Turno de un guardia que empieza dentro de $startMin minutos (negativo = ya empezó) y dura $minutes. */
$slot = function (array $user, int $startMin, int $minutes = 480) use (&$st): string {
    $uuid = Uuid::v4();
    DB::tenant()->prepare("INSERT INTO guard_shift_slots (uuid, shift_id, user_id, site_id, start_at, end_at, grace_minutes, status, created_at, updated_at)
        VALUES (?, NULL, ?, ?, ?, ?, 15, 'programado', UTC_TIMESTAMP(), UTC_TIMESTAMP())")
        ->execute([$uuid, (int) $user['id'], (int) $st['site']['id'], gmdate('Y-m-d H:i:s', time() + $startMin * 60), gmdate('Y-m-d H:i:s', time() + ($startMin + $minutes) * 60)]);
    return $uuid;
};
$iso = fn (int $offsetMin) => gmdate('Y-m-d\TH:i:s\Z', time() + $offsetMin * 60);
$push = function (array $ops): array {
    return Push::run($ops);
};

return [
    'turnos: semanal que cruza la medianoche y rotativos (12×36, 2×2) en hora de la empresa' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']); // UTC−3
        $weekly = ['pattern' => 'weekly', 'days' => '1,2,3,4,5', 'start_time' => '22:00', 'end_time' => '06:00', 'valid_from' => null, 'valid_until' => null];
        $occ = ShiftPlanner::occurrences($weekly, '2026-10-16', '2026-10-19'); // vie, sáb, dom, lun
        assert_same([['day' => '2026-10-16', 'start' => '2026-10-17 01:00:00', 'end' => '2026-10-17 09:00:00'],
            ['day' => '2026-10-19', 'start' => '2026-10-20 01:00:00', 'end' => '2026-10-20 09:00:00']], $occ, 'vie y lun, el turno nocturno termina al otro día');
        $r1x1 = ['pattern' => 'rotation', 'rotation_on' => 1, 'rotation_off' => 1, 'anchor_date' => '2026-10-01', 'valid_from' => null, 'valid_until' => null];
        assert_same([true, false, true, true], [ShiftPlanner::worksOn($r1x1, '2026-10-01'), ShiftPlanner::worksOn($r1x1, '2026-10-02'),
            ShiftPlanner::worksOn($r1x1, '2026-10-03'), ShiftPlanner::worksOn($r1x1, '2026-09-29')], '12×36: día por medio (también antes de la fecha de referencia)');
        $r2x2 = ['pattern' => 'rotation', 'rotation_on' => 2, 'rotation_off' => 2, 'anchor_date' => '2026-10-01', 'valid_from' => '2026-10-02', 'valid_until' => null];
        assert_same([false, true, false, false, true], array_map(fn ($d) => ShiftPlanner::worksOn($r2x2, $d), ['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05']),
            '2×2 con vigencia desde el 02/10');
        [$data, $errors] = ShiftPlanner::validate(['site' => '', 'pattern' => 'rotation', 'start_time' => '25:00', 'end_time' => '08:00', 'rotation_on' => 0]);
        assert_true(isset($errors['site'], $errors['time'], $errors['rotation'], $errors['anchor_date'], $errors['users']));
    },

    'turnos: horario por día (noches Lun a Vie + sábado 14:00 a lunes 08:00), superposición y máximo de horas' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']); // UTC−3
        $blocks = [];
        foreach ([1, 2, 3, 4, 5] as $d) $blocks[] = ['day' => $d, 'start' => '20:00', 'end_day' => $d % 7 + 1, 'end' => '08:00'];
        $blocks[] = ['day' => 6, 'start' => '14:00', 'end_day' => 1, 'end' => '08:00'];
        [$data, $errors] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'pattern' => 'custom', 'blocks' => json_encode($blocks), 'users' => [$st['g1']['uuid']]]);
        assert_same([], $errors);
        assert_same(['1,2,3,4,5,6', '20:00', '08:00'], [$data['days'], $data['start_time'], $data['end_time']]);
        $occ = ShiftPlanner::occurrences($data + ['valid_from' => null, 'valid_until' => null], '2026-10-16', '2026-10-19'); // vie, sáb, dom, lun
        assert_same([
            ['day' => '2026-10-16', 'start' => '2026-10-16 23:00:00', 'end' => '2026-10-17 11:00:00'],
            ['day' => '2026-10-17', 'start' => '2026-10-17 17:00:00', 'end' => '2026-10-19 11:00:00'],
            ['day' => '2026-10-19', 'start' => '2026-10-19 23:00:00', 'end' => '2026-10-20 11:00:00'],
        ], $occ, 'el sábado entra a las 14 y sale el lunes a las 8 (42 h); el domingo no empieza ninguno');
        assert_same('Lun a Vie 20:00 a 08:00 del día siguiente · Sáb 14:00 a Lun 08:00', ShiftPlanner::describe($data));
        // Sábado 06:00 a lunes 08:00 se pisa con la noche del viernes (sale el sábado 08:00)
        $bad = $blocks;
        $bad[5]['start'] = '06:00';
        [, $e] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'pattern' => 'custom', 'blocks' => $bad, 'users' => [$st['g1']['uuid']]]);
        assert_true(isset($e['blocks']) && str_contains($e['blocks'], 'superponen'), 'superposición');
        // Domingo 20:00 a lunes 08:00 se pisa con la franja del sábado (de una semana a la otra también se controla)
        [, $e] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'pattern' => 'custom', 'blocks' => array_merge($blocks, [['day' => 7, 'start' => '20:00', 'end_day' => 1, 'end' => '08:00']]), 'users' => [$st['g1']['uuid']]]);
        assert_true(isset($e['blocks']));
        [, $e] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'pattern' => 'custom', 'blocks' => [['day' => 5, 'start' => '08:00', 'end_day' => 1, 'end' => '09:00']], 'users' => [$st['g1']['uuid']]]);
        assert_true(isset($e['blocks']) && str_contains($e['blocks'], '72 horas'), 'más de 72 h');
        [, $e] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'pattern' => 'custom', 'blocks' => '[]', 'users' => [$st['g1']['uuid']]]);
        assert_true(isset($e['blocks']), 'sin franjas');
        // 24 h seguidas todos los días: una termina justo cuando empieza la otra (vale)
        $h24 = array_map(fn ($d) => ['day' => $d, 'start' => '08:00', 'end_day' => $d % 7 + 1, 'end' => '08:00'], range(1, 7));
        [$d24, $e] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'pattern' => 'custom', 'blocks' => $h24, 'users' => [$st['g1']['uuid']]]);
        assert_same([], $e);
        assert_same('todos los días 08:00 a 08:00 del día siguiente', ShiftPlanner::describe($d24));
        // Se guarda y el cron genera las franjas; el formulario las muestra
        UserAuth::setCurrent($st['hys']);
        DB::tenant()->exec('DELETE FROM guard_shift_slots');
        $uuid = ShiftPlanner::save(null, $data);
        $slots = DB::tenant()->query("SELECT start_at, end_at FROM guard_shift_slots WHERE status = 'programado' ORDER BY start_at")->fetchAll();
        assert_true(count($slots) >= 5 && count($slots) <= 7, 'una por franja en la semana (' . count($slots) . ')');
        $long = array_filter($slots, fn ($x) => strtotime($x['end_at'] . ' UTC') - strtotime($x['start_at'] . ' UTC') === 42 * 3600);
        assert_true(count($long) >= 1, 'la del fin de semana dura 42 h');
        $html = \App\Core\View::render('panel/shifts/form', ['shift' => ShiftPlanner::find($uuid), 'sites' => [], 'guards' => [], 'userUuids' => [], 'defaultGrace' => 15, 'old' => null], null);
        assert_true(str_contains($html, 'Horario por día') && str_contains($html, '&quot;plus&quot;:2'), 'el formulario carga las franjas');
        ShiftPlanner::delete(ShiftPlanner::find($uuid));
        DB::tenant()->exec('DELETE FROM guard_shift_slots');
    },

    'turnos: el cron genera uno por guardia, idempotente, sin días sin servicio; editar regenera' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        [$data, $errors] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'pattern' => 'weekly', 'days' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => '08:00', 'end_time' => '16:00', 'users' => [$st['g1']['uuid'], $st['g2']['uuid']], 'name' => 'Diurno']);
        assert_same([], $errors);
        DB::tenant()->exec('DELETE FROM guard_shift_slots');
        $uuid = ShiftPlanner::save(null, $data);
        $n = (int) DB::tenant()->query("SELECT COUNT(*) FROM guard_shift_slots WHERE status = 'programado'")->fetchColumn();
        assert_true($n >= 12 && $n % 2 === 0, "dos guardias × ~7 días ({$n})");
        assert_same(0, ShiftPlanner::generate(), 'idempotente');
        $tomorrow = ShiftPlanner::localDay(gmdate('Y-m-d H:i:s', time() + 86400));
        ShiftAbsences::addClosure(null, $tomorrow, 'Feriado');
        [$from, $to] = ShiftPlanner::dayRange($tomorrow);
        $q = DB::tenant()->prepare("SELECT COUNT(*) FROM guard_shift_slots WHERE status = 'programado' AND start_at >= ? AND start_at < ?");
        $q->execute([$from, $to]);
        assert_same(0, (int) $q->fetchColumn(), 'el feriado no tiene turnos');
        ShiftPlanner::generate();
        $q->execute([$from, $to]);
        assert_same(0, (int) $q->fetchColumn(), 'y no vuelven a generarse');
        // Editar a un solo guardia: los futuros del otro se anulan.
        [$data2] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'pattern' => 'weekly', 'days' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => '08:00', 'end_time' => '16:00', 'users' => [$st['g1']['uuid']]]);
        ShiftPlanner::save(ShiftPlanner::find($uuid), $data2);
        $g2 = (int) DB::tenant()->query("SELECT COUNT(*) FROM guard_shift_slots WHERE user_id = {$st['g2']['id']} AND status = 'programado' AND start_at > UTC_TIMESTAMP()")->fetchColumn();
        assert_same(0, $g2, 'el guardia que se sacó ya no tiene turnos futuros');
        assert_true(str_contains(ShiftPlanner::describe(ShiftPlanner::find($uuid)), 'todos los días'));
        ShiftPlanner::setActive(ShiftPlanner::find($uuid), false);
        DB::tenant()->exec('DELETE FROM guard_shift_slots');
        DB::tenant()->exec('DELETE FROM guard_closures');
    },

    'turnos: empezar en el predio, fuera del predio (aviso), temprano, duplicado y no programado' => function () use ($setup, &$st, $op, $slot, $iso, $count) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $early = $slot($st['g1'], 120);
        $r = Push::run([$op('shift.start', ['uuid' => $early, 'started_at_device' => $iso(0), 'lat' => -34.6037, 'lng' => -58.3816, 'accuracy_m' => 10])]);
        assert_true($r[0]['status'] === 'error' && str_contains($r[0]['error'], 'temprano'), 'más de 60 min antes no');
        $s = $slot($st['g1'], -5);
        $before = $count('guard.shift_start_outside');
        $r = Push::run([$op('shift.start', ['uuid' => $s, 'started_at_device' => $iso(0), 'lat' => -34.6040, 'lng' => -58.3816, 'accuracy_m' => 12])]);
        assert_same(['ok', 'en_curso', true], [$r[0]['status'], $r[0]['data']['status'], $r[0]['data']['start_inside']]);
        assert_same($before, $count('guard.shift_start_outside'), 'adentro: sin aviso');
        $r = Push::run([$op('shift.start', ['uuid' => $s, 'started_at_device' => $iso(0)])]);
        assert_true(!empty($r[0]['data']['duplicate']), 'reenviar: duplicado');
        $r = Push::run([$op('shift.start', ['uuid' => Uuid::v4(), 'site_uuid' => $st['site']['uuid'], 'started_at_device' => $iso(0)])]);
        assert_true($r[0]['status'] === 'error' && str_contains($r[0]['error'], 'en curso'), 'uno a la vez');
        Push::run([$op('shift.end', ['slot_uuid' => $s, 'ended_at_device' => $iso(0)])]);
        assert_same('cumplido', ShiftService::find($s)['status']);
        // Fuera del predio (a ~1,1 km) → aviso a SyH y supervisores.
        UserAuth::setCurrent($st['g2']);
        $s2 = $slot($st['g2'], -30);
        $r = Push::run([$op('shift.start', ['uuid' => $s2, 'started_at_device' => $iso(0), 'lat' => -34.5937, 'lng' => -58.3816, 'accuracy_m' => 15])]);
        assert_same(false, $r[0]['data']['start_inside']);
        assert_same($before + 2, $count('guard.shift_start_outside'), 'SyH + supervisor');
        Push::run([$op('shift.end', ['slot_uuid' => $s2, 'ended_at_device' => $iso(0)])]);
        assert_same(15, ShiftReport::measure(ShiftService::find($s2))['late'], 'empezó 30 min tarde con 15 de gracia');
        // No programado (cubre a alguien)
        $u = Uuid::v4();
        $r = Push::run([$op('shift.start', ['uuid' => $u, 'site_uuid' => $st['site']['uuid'], 'started_at_device' => $iso(0), 'lat' => -34.6037, 'lng' => -58.3816])]);
        assert_same([true, 'en_curso'], [$r[0]['data']['unscheduled'], $r[0]['data']['status']]);
        Push::run([$op('shift.end', ['slot_uuid' => $u, 'ended_at_device' => $iso(0)])]);
        // Turno ajeno
        UserAuth::setCurrent($st['g1']);
        $r = Push::run([$op('shift.end', ['slot_uuid' => $u, 'ended_at_device' => $iso(0)])]);
        assert_same('error', $r[0]['status']);
    },

    'turnos: salida con motivo (informa), sin motivo (alarma una vez), vuelta y posiciones' => function () use ($setup, &$st, $op, $slot, $iso, $count) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $s = $slot($st['g1'], -60);
        Push::run([$op('shift.start', ['uuid' => $s, 'started_at_device' => $iso(-50), 'lat' => -34.6037, 'lng' => -58.3816])]);
        $info = $count('guard.shift_exit');
        $alarm = $count('guard.shift_exit_alarm');
        // Con motivo ("Salgo por una emergencia")
        $e1 = Uuid::v4();
        Push::run([$op('shift.exit', ['uuid' => $e1, 'slot_uuid' => $s, 'left_at_device' => $iso(-40), 'lat' => -34.6000, 'lng' => -58.3816, 'reason' => 'comisaria',
            'note' => 'Denuncia por robo', 'announced' => 1])]);
        assert_same($info + 2, $count('guard.shift_exit'));
        Push::run([$op('shift.track', ['slot_uuid' => $s, 'exit_uuid' => $e1, 'points' => [
            ['uuid' => Uuid::v4(), 'at' => $iso(-38), 'lat' => -34.5990, 'lng' => -58.3816, 'accuracy_m' => 8],
            ['uuid' => Uuid::v4(), 'at' => $iso(-36), 'lat' => -34.5980, 'lng' => -58.3816, 'accuracy_m' => 8]]])]);
        Push::run([$op('shift.return', ['uuid' => $e1, 'returned_at_device' => $iso(-30)])]);
        $row = ShiftService::find($s);
        assert_same(600, (int) $row['out_seconds'], '10 min fuera');
        assert_same(2, count(ShiftService::positions((int) $row['id'])));
        assert_true((float) ShiftService::exits((int) $row['id'])[0]['max_distance_m'] > 600, 'distancia máxima');
        // Sin motivo: a los 10 min, alarma (una sola vez); después el motivo informa.
        $e2 = Uuid::v4();
        Push::run([$op('shift.exit', ['uuid' => $e2, 'slot_uuid' => $s, 'left_at_device' => $iso(-12), 'lat' => -34.5990, 'lng' => -58.3816])]);
        UserAuth::setCurrent(null);
        ShiftService::run();
        ShiftService::run();
        assert_same($alarm + 2, $count('guard.shift_exit_alarm'), 'una vez por salida');
        UserAuth::setCurrent($st['g1']);
        Push::run([$op('shift.exit_reason', ['uuid' => $e2, 'reason' => 'robo'])]);
        assert_same($info + 4, $count('guard.shift_exit'));
        assert_same('FUERA del predio', ShiftService::label(ShiftService::find($s))[0]);
        Push::run([$op('shift.end', ['slot_uuid' => $s, 'ended_at_device' => $iso(0)])]);
        assert_true(ShiftService::exits((int) $row['id'])[1]['returned_at'] !== null, 'terminar el turno cierra la salida abierta');
    },

    'turnos: cron — sin empezar (una vez), no vino, cierre automático y sin contacto' => function () use ($setup, &$st, $op, $slot, $iso, $count) {
        $setup();
        Tenant::activate($st['a']);
        $late = $slot($st['g3'], -40, 480);    // 40 min tarde con 15 de gracia → aviso
        $gone = $slot($st['g3'], -600, 480);   // terminó hace 2 h sin empezar → ausente
        UserAuth::setCurrent($st['g2']);
        $open = $slot($st['g2'], -800, 480);   // terminó hace 5 h 20 → se cierra solo
        Push::run([$op('shift.start', ['uuid' => $open, 'started_at_device' => $iso(-799)])]);
        $quiet = Uuid::v4();
        UserAuth::setCurrent($st['g1']);
        Push::run([$op('shift.start', ['uuid' => $quiet, 'site_uuid' => $st['site']['uuid'], 'started_at_device' => $iso(-5)])]);
        DB::tenant()->prepare('UPDATE guard_shift_slots SET last_contact_at = ?, started_received_at = ? WHERE uuid = ?')
            ->execute([gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() - 3600), $quiet]);
        $c = ['ns' => $count('guard.shift_not_started'), 'm' => $count('guard.shift_missed'), 'e' => $count('guard.shift_no_end'), 's' => $count('guard.shift_silent')];
        UserAuth::setCurrent(null);
        ShiftService::run();
        ShiftService::run();
        assert_same($c['ns'] + 2, $count('guard.shift_not_started'), 'sin empezar: una vez');
        assert_same('ausente', ShiftService::find($gone)['status']);
        assert_same($c['m'] + 2, $count('guard.shift_missed'));
        $o = ShiftService::find($open);
        assert_same(['cumplido', 'auto'], [$o['status'], $o['end_mode']]);
        assert_same($o['end_at'], $o['ended_at'], 'con la hora programada de fin');
        assert_same($c['e'] + 2, $count('guard.shift_no_end'));
        assert_same($c['s'] + 2, $count('guard.shift_silent'), 'sin contacto hace 1 h');
        UserAuth::setCurrent($st['g1']);
        Push::run([$op('shift.end', ['slot_uuid' => $quiet, 'ended_at_device' => $iso(0)])]);
    },

    'ausencias: pedido del guardia, aprobación con reemplazo, sin reemplazo (puesto sin cubrir), cancelar' => function () use ($setup, &$st, $op, $slot, $count) {
        $setup();
        Tenant::activate($st['a']);
        $day = ShiftPlanner::localDay(gmdate('Y-m-d H:i:s', time() + 2 * 86400));
        [$from] = ShiftPlanner::dayRange($day);
        $mkSlot = function (array $u) use ($from, &$st) {
            $uuid = Uuid::v4();
            DB::tenant()->prepare("INSERT INTO guard_shift_slots (uuid, shift_id, user_id, site_id, start_at, end_at, grace_minutes, status, created_at, updated_at)
                VALUES (?, NULL, ?, ?, ?, ?, 15, 'programado', UTC_TIMESTAMP(), UTC_TIMESTAMP())")
                ->execute([$uuid, (int) $u['id'], (int) $st['site']['id'], gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') + 10 * 3600), gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') + 18 * 3600)]);
            return $uuid;
        };
        $s1 = $mkSlot($st['g1']);
        UserAuth::setCurrent($st['g1']);
        $req = $count('guard.absence_requested');
        $a = Uuid::v4();
        $r = Push::run([$op('absence.request', ['uuid' => $a, 'date_from' => $day, 'date_to' => $day, 'type' => 'enfermedad', 'reason' => 'Gripe'])]);
        assert_same(['ok', 'solicitada'], [$r[0]['status'], $r[0]['data']['status']]);
        assert_same($req + 2, $count('guard.absence_requested'), 'a SyH y supervisor');
        $r = Push::run([$op('absence.request', ['uuid' => $a, 'date_from' => $day, 'date_to' => $day, 'type' => 'enfermedad'])]);
        assert_same(1, (int) DB::tenant()->query("SELECT COUNT(*) FROM guard_absences WHERE uuid = '{$a}'")->fetchColumn(), 'idempotente');
        try { ShiftAbsences::decide(ShiftAbsences::find($a), true); assert_true(false, 'el guardia no aprueba'); } catch (\App\Core\UserError) {}
        UserAuth::setCurrent($st['sup']);
        $dec = $count('guard.absence_decided');
        ShiftAbsences::decide(ShiftAbsences::find($a), true, 'Que te mejores', $st['g3']['uuid']);
        assert_same('justificado', ShiftService::find($s1)['status']);
        $rep = DB::tenant()->query("SELECT * FROM guard_shift_slots WHERE user_id = {$st['g3']['id']} AND replaces_slot_id IS NOT NULL")->fetchAll();
        assert_same(1, count($rep), 'turno del reemplazo');
        assert_same($dec + 1, $count('guard.absence_decided'), 'le llega al guardia');
        ShiftAbsences::apply(ShiftAbsences::find($a));
        assert_same(1, (int) DB::tenant()->query("SELECT COUNT(*) FROM guard_shift_slots WHERE user_id = {$st['g3']['id']} AND replaces_slot_id IS NOT NULL")->fetchColumn(), 'idempotente');
        // Ronda programada de ese día a cargo solo de g1: queda justificada, sin alarma.
        // Cancelar: vuelve a programado y el reemplazo se anula.
        ShiftAbsences::cancel(ShiftAbsences::find($a));
        assert_same('programado', ShiftService::find($s1)['status']);
        assert_same('anulado', $rep[0]['status'] === 'programado' ? DB::tenant()->query("SELECT status FROM guard_shift_slots WHERE id = {$rep[0]['id']}")->fetchColumn() : 'anulado');
        // Sin reemplazo, cargada por el supervisor: un solo "puesto sin cubrir".
        $unc = $count('guard.shift_uncovered');
        $ab = ShiftAbsences::createApproved(['user' => $st['g1']['uuid'], 'date_from' => $day, 'date_to' => $day, 'type' => 'vacaciones']);
        ShiftAbsences::apply($ab);
        assert_same($unc + 1, $count('guard.shift_uncovered'), 'a SyH (el supervisor que la cargó no se avisa a sí mismo), una vez');
        assert_true(ShiftAbsences::allAbsentAt([(int) $st['g1']['id']], gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') + 12 * 3600)));
        assert_true(!ShiftAbsences::allAbsentAt([(int) $st['g1']['id'], (int) $st['g2']['id']], gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') + 12 * 3600)));
        // Editar la aprobada: se mueve al día siguiente con reemplazo → el turno de ese día vuelve a programado.
        $next = date('Y-m-d', strtotime($day . ' +1 day'));
        $ed = ShiftAbsences::update(ShiftAbsences::find($ab['uuid']), ['user' => $st['g1']['uuid'], 'date_from' => $next, 'date_to' => $next,
            'type' => 'licencia', 'reason' => 'Corrigió la fecha', 'replacement' => $st['g3']['uuid']]);
        assert_same([$next, 'licencia', 'aprobada', $st['g3']['uuid']], [$ed['date_from'], $ed['type'], $ed['status'], $ed['replacement_uuid']]);
        assert_same('programado', ShiftService::find($s1)['status'], 'el turno que ya no cubre vuelve a programado');
        assert_true(!ShiftAbsences::allAbsentAt([(int) $st['g1']['id']], gmdate('Y-m-d H:i:s', strtotime($from . ' UTC') + 12 * 3600)));
        assert_same(1, (int) DB::tenant()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'guard_absence.update' AND entity_uuid = '{$ab['uuid']}'")->fetchColumn());
        try { ShiftAbsences::update(ShiftAbsences::find($ab['uuid']), ['user' => $st['g1']['uuid'], 'date_from' => $next, 'date_to' => $day, 'type' => 'licencia']);
            assert_true(false, 'hasta antes de desde'); } catch (\App\Core\UserError) {}
        UserAuth::setCurrent($st['g1']);
        try { ShiftAbsences::update(ShiftAbsences::find($ab['uuid']), ['user' => $st['g1']['uuid'], 'date_from' => $day, 'type' => 'licencia']);
            assert_true(false, 'el guardia no edita'); } catch (\App\Core\UserError) {}
        UserAuth::setCurrent($st['sup']);
        ShiftAbsences::cancel(ShiftAbsences::find($ab['uuid']));
        try { ShiftAbsences::update(ShiftAbsences::find($ab['uuid']), ['user' => $st['g1']['uuid'], 'date_from' => $day, 'type' => 'licencia']);
            assert_true(false, 'cancelada no se edita'); } catch (\App\Core\UserError) {}
    },

    'turnos: al celular le llegan solo sus turnos y ausencias, con los ajustes; horas y CSV' => function () use ($setup, &$st, $op, $slot, $iso) {
        $setup();
        Tenant::activate($st['a']);
        $mine = $slot($st['g1'], 30);
        $other = $slot($st['g2'], 30);
        UserAuth::setCurrent($st['g1']);
        $page = Pull::run(null, 1000);
        $uuids = array_column((array) ($page['changes']->guard_shift_slots ?? []), 'uuid');
        assert_true(in_array($mine, $uuids, true) && !in_array($other, $uuids, true));
        assert_same(150, (array_values(array_filter((array) $page['changes']->guard_shift_slots, fn ($s) => $s['uuid'] === $mine))[0])['site_radius_m']);
        assert_same(ShiftService::REASONS, $page['meta']['turnos']['motivos']);
        $today = ShiftPlanner::localDay(gmdate('Y-m-d H:i:s'));
        $rep = ShiftReport::summary(date('Y-m-d', strtotime($today . ' -1 day')), $today);
        $names = array_column($rep['rows'], 'name');
        assert_true(in_array('Guardia1', $names, true) && $rep['total']['worked'] > 0);
        assert_true(str_contains(ShiftReport::csv($rep, 'guardias'), 'Horas reales') && str_contains(ShiftReport::csv($rep, 'turnos'), 'Depósito Norte'));
    },

    'turnos: rondas programadas de guardias ausentes quedan justificadas; borrado de posiciones viejas' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $p = \App\Models\Patrols::createPoint(['name' => 'Portón', 'code' => 'P1', 'description' => '', 'site_id' => null, 'sector_id' => null,
            'lat' => -34.6037, 'lng' => -58.3816, 'radius_m' => 30, 'is_critical' => false]);
        $rid = \App\Models\Patrols::createRoute(['name' => 'Perímetro', 'description' => '', 'frequency' => '', 'expected_minutes' => 20,
            'point_ids' => [$p], 'user_ids' => [(int) $st['g1']['id']]]);
        DB::tenant()->exec("INSERT INTO patrol_schedules (uuid, route_id, mode, times, days, max_delay_minutes, is_active, created_at, updated_at)
            VALUES ('" . Uuid::v4() . "', {$rid}, 'fixed', '[\"10:00\"]', '1,2,3,4,5,6,7', 60, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $sid = (int) DB::tenant()->lastInsertId();
        $due = gmdate('Y-m-d H:i:s', time() - 1800);
        DB::tenant()->prepare("INSERT INTO patrol_slots (uuid, schedule_id, route_id, due_at, grace_minutes, deadline_at, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 10, ?, 'pendiente', UTC_TIMESTAMP(), UTC_TIMESTAMP())")->execute([Uuid::v4(), $sid, $rid, $due, gmdate('Y-m-d H:i:s', time() + 1800)]);
        $day = ShiftPlanner::localDay($due);
        $ab = ShiftAbsences::createApproved(['user' => $st['g1']['uuid'], 'date_from' => $day, 'date_to' => $day, 'type' => 'licencia']);
        UserAuth::setCurrent(null);
        $before = (int) DB::tenant()->query("SELECT COUNT(*) FROM notifications WHERE event = 'guard.round_not_started'")->fetchColumn();
        \App\Services\PatrolPlanner::run();
        assert_same('justificada', DB::tenant()->query("SELECT status FROM patrol_slots WHERE schedule_id = {$sid} AND due_at = '{$due}'")->fetchColumn());
        assert_same($before, (int) DB::tenant()->query("SELECT COUNT(*) FROM notifications WHERE event = 'guard.round_not_started'")->fetchColumn(), 'sin alarma');
        UserAuth::setCurrent($st['hys']);
        ShiftAbsences::cancel(ShiftAbsences::find($ab['uuid']));
        // Posiciones de hace 100 días se borran (90 por defecto).
        $slotId = (int) DB::tenant()->query('SELECT id FROM guard_shift_slots LIMIT 1')->fetchColumn();
        DB::tenant()->prepare('INSERT INTO guard_shift_positions (uuid, slot_id, at, lat, lng, created_at) VALUES (?, ?, UTC_TIMESTAMP(), 0, 0, ?)')
            ->execute([Uuid::v4(), $slotId, gmdate('Y-m-d H:i:s', time() - 100 * 86400)]);
        assert_true(ShiftService::purge() >= 1);
    },

    'panel: hoy, programación, formulario, ausencias, horas, detalle, predio y ajustes se renderizan' => function () use ($setup, &$st, $slot, $op, $iso) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $s = $slot($st['g1'], -20);
        Push::run([$op('shift.start', ['uuid' => $s, 'started_at_device' => $iso(-20), 'lat' => -34.6037, 'lng' => -58.3816])]);
        Push::run([$op('shift.exit', ['uuid' => Uuid::v4(), 'slot_uuid' => $s, 'left_at_device' => $iso(-5), 'lat' => -34.60, 'lng' => -58.38, 'reason' => 'medica'])]);
        UserAuth::setCurrent($st['hys']);
        $ctl = new \App\Controllers\Web\Panel\ShiftsController();
        $get = fn (string $method, ...$args) => $ctl->{$method}(new \App\Core\Request('GET', '/'), ...$args);
        $html = $get('today')->body;
        assert_true(str_contains($html, 'FUERA del predio') && str_contains($html, 'Turnos') && str_contains($html, 'Guardia1'), 'hoy');
        assert_true(str_contains($get('schedules')->body, 'Ubicar en el mapa'));
        assert_true(str_contains($get('form')->body, '12×36'));
        assert_true(str_contains($get('absences')->body, 'Días sin servicio'));
        assert_true(str_contains($get('hours')->body, 'Horas reales'));
        $show = $get('show', $s)->body;
        assert_true(str_contains($show, 'Emergencia médica') && str_contains($show, 'shift-map'), 'detalle con salida y mapa');
        assert_true(str_contains($get('site', $st['site']['uuid'])->body, 'site-map'));
        $res = $ctl->saveSite(new \App\Core\Request('POST', '/', [], ['lat' => '-31.45', 'lng' => '-64.11', 'radius' => '300']), $st['site']['uuid']);
        assert_same(302, $res->status);
        assert_same(300, (int) \App\Models\Sites::findById((int) $st['site']['id'])['geofence_radius_m']);
        \App\Models\Sites::update((int) $st['site']['id'], ['lat' => -34.6037, 'lng' => -58.3816, 'geofence_radius_m' => 150]);
        $rules = new \App\Controllers\Web\Panel\NotificationRulesController();
        $rules->saveSettings(new \App\Core\Request('POST', '/', [], ['section' => 'turnos', 'shift_automation' => '1', 'shift_out' => '5', 'shift_tolerance' => '80']));
        assert_same([5, 80], [ShiftService::outMinutes(), ShiftService::toleranceM()]);
        assert_true(str_contains($rules->index(new \App\Core\Request('GET', '/'))->body, 'Turnos de guardias'));
        \App\Models\Settings::set('turnos.minutos_fuera', '3');
        \App\Models\Settings::set('turnos.tolerancia_m', '100');
        UserAuth::setCurrent($st['g1']);
        Push::run([$op('shift.end', ['slot_uuid' => $s, 'ended_at_device' => $iso(0)])]);
    },

    'turnos: el supervisor edita (horario, guardia, corrección de horas reales con motivo), elimina y el generador lo respeta' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        DB::tenant()->exec('DELETE FROM guard_shift_positions'); DB::tenant()->exec('DELETE FROM guard_shift_exits'); DB::tenant()->exec('DELETE FROM guard_shift_slots');
        [$data] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'pattern' => 'weekly', 'days' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => '08:00', 'end_time' => '16:00', 'users' => [$st['g1']['uuid']], 'name' => 'Diurno edición']);
        $shiftUuid = ShiftPlanner::save(null, $data);
        $total = (int) DB::tenant()->query("SELECT COUNT(*) FROM guard_shift_slots WHERE status <> 'anulado'")->fetchColumn();
        $slot = ShiftService::find((string) DB::tenant()->query("SELECT uuid FROM guard_shift_slots WHERE start_at > UTC_TIMESTAMP() ORDER BY start_at LIMIT 1")->fetchColumn());
        $local = fn (string $utc, int $h) => (new DateTimeImmutable($utc . ' UTC'))->setTimezone(ShiftPlanner::tz())->modify("+{$h} hours")->format('Y-m-d\\TH:i');
        // Cambiar guardia y correr la hora: el generador no lo vuelve a crear para el guardia original.
        $edited = ShiftService::adminSave($slot, ['user' => $st['g2']['uuid'], 'site' => $st['site']['uuid'], 'start_at' => $local($slot['start_at'], 1),
            'end_at' => $local($slot['end_at'], 1), 'note' => 'Cambio de cobertura']);
        assert_same(['Guardia2', 'Cambio de cobertura'], [$edited['user_name'], $edited['edit_note']]);
        ShiftPlanner::generate();
        assert_same($total, (int) DB::tenant()->query("SELECT COUNT(*) FROM guard_shift_slots WHERE status <> 'anulado'")->fetchColumn(), 'no se duplica');
        // Corrección de horas reales: exige motivo.
        $past = ShiftService::find((string) DB::tenant()->query("SELECT uuid FROM guard_shift_slots WHERE end_at < UTC_TIMESTAMP() AND status <> 'anulado' ORDER BY start_at DESC LIMIT 1")->fetchColumn());
        if ($past) {
            $in = ['user' => $past['user_uuid'], 'site' => $st['site']['uuid'], 'start_at' => $local($past['start_at'], 0), 'end_at' => $local($past['end_at'], 0),
                'started_at' => $local($past['start_at'], 0), 'ended_at' => $local($past['end_at'], 0)];
            try { ShiftService::adminSave($past, $in); assert_true(false, 'sin motivo no'); } catch (App\Core\UserError) {}
            $fixed = ShiftService::adminSave($past, $in + ['note' => 'No tenía batería, avisó por teléfono']);
            assert_same(['cumplido', 'manual', 480], [$fixed['status'], $fixed['end_mode'], ShiftReport::measure($fixed)['worked']]);
        }
        // Eliminar: anulado con motivo, y el generador no lo reactiva.
        try { ShiftService::adminCancel($edited, ''); assert_true(false); } catch (App\Core\UserError) {}
        ShiftService::adminCancel($edited, 'Cargado por error');
        ShiftPlanner::generate();
        assert_same('anulado', ShiftService::find($edited['uuid'])['status']);
        // Turno suelto
        $one = ShiftService::adminSave(null, ['user' => $st['g3']['uuid'], 'site' => $st['site']['uuid'], 'start_at' => $local(gmdate('Y-m-d H:i:s'), 24),
            'end_at' => $local(gmdate('Y-m-d H:i:s'), 32)]);
        assert_same(['programado', null], [$one['status'], $one['shift_id']]);
        try { ShiftService::adminSave(null, ['user' => $st['g3']['uuid'], 'site' => $st['site']['uuid'], 'start_at' => '2026-10-10T10:00', 'end_at' => '2026-10-10T09:00']); assert_true(false); } catch (App\Core\UserError) {}
        UserAuth::setCurrent($st['g1']);
        try { ShiftService::adminCancel($one, 'x'); assert_true(false, 'el guardia no edita turnos'); } catch (App\Core\UserError) {}
        UserAuth::setCurrent($st['hys']);
        // Eliminar la programación: anula los futuros que no fueron editados ni empezaron.
        ShiftPlanner::delete(ShiftPlanner::find($shiftUuid));
        assert_same(null, ShiftPlanner::find($shiftUuid));
        assert_same(0, (int) DB::tenant()->query("SELECT COUNT(*) FROM guard_shift_slots t JOIN guard_shifts g ON g.id = t.shift_id WHERE g.uuid = '{$shiftUuid}'
            AND t.status = 'programado' AND t.start_at > UTC_TIMESTAMP()")->fetchColumn());
        $ctl = new \App\Controllers\Web\Panel\ShiftsController();
        assert_true(str_contains($ctl->slotForm(new \App\Core\Request('GET', '/'), $one['uuid'])->body, 'Editar turno'));
        assert_true(str_contains($ctl->show(new \App\Core\Request('GET', '/'), $one['uuid'])->body, '/eliminar'));
        DB::tenant()->exec('DELETE FROM guard_shift_positions'); DB::tenant()->exec('DELETE FROM guard_shift_exits'); DB::tenant()->exec('DELETE FROM guard_shift_slots');
    },

    'puesto: grupo con celular compartido y PIN, relevo, sin persona, avisos de cobertura y reporte' => function () use ($setup, &$st, $op, $count, $iso) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        DB::tenant()->exec('DELETE FROM guard_shift_positions');
        DB::tenant()->exec('DELETE FROM guard_shift_exits');
        DB::tenant()->exec('DELETE FROM guard_shift_slots');
        $device = Users::findById(Users::create(['name' => 'Celular portería', 'email' => 'celu@sh.test', 'role_id' => (int) Roles::findBySlug('reportante')['id'],
            'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
        // PIN
        try { \App\Services\ActingGuard::setPin((int) $st['g1']['id'], '12'); assert_true(false, 'PIN corto'); } catch (\App\Core\UserError) {}
        \App\Services\ActingGuard::setPin((int) $st['g1']['id'], '1234');
        \App\Services\ActingGuard::setPin((int) $st['g2']['id'], '5678');
        $g1 = Users::findById((int) $st['g1']['id']);
        assert_true(\App\Services\ActingGuard::verifyPin($g1['pin_hash'], '1234') && !\App\Services\ActingGuard::verifyPin($g1['pin_hash'], '1235'));
        // Programación por puesto: todos los días, de hace 1 h a dentro de 5 h (hora de la empresa).
        $tz = ShiftPlanner::tz();
        $hm = fn (int $min) => (new DateTimeImmutable('@' . (time() + $min * 60)))->setTimezone($tz)->format('H:i');
        [$data, $errors] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'mode' => 'puesto', 'pattern' => 'weekly', 'days' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => $hm(-60), 'end_time' => $hm(300), 'users' => [$st['g1']['uuid'], $st['g2']['uuid']], 'device' => $device['uuid'], 'name' => 'Portería']);
        assert_same([], $errors);
        [, $bad] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'mode' => 'puesto', 'pattern' => 'weekly', 'days' => [1], 'start_time' => '08:00', 'end_time' => '16:00',
            'users' => [$device['uuid']], 'device' => $device['uuid']]);
        assert_true(isset($bad['device']), 'el celular no puede ser guardia del grupo');
        $shiftUuid = ShiftPlanner::save(null, $data);
        $shift = ShiftPlanner::find($shiftUuid);
        assert_same(0, (int) DB::tenant()->query("SELECT COUNT(*) FROM guard_shift_slots WHERE shift_id = {$shift['id']}")->fetchColumn(), 'por puesto no hay turnos por persona');
        $cov = DB::tenant()->query("SELECT * FROM guard_post_coverage WHERE shift_id = {$shift['id']} AND start_at <= UTC_TIMESTAMP() AND end_at > UTC_TIMESTAMP()")->fetch();
        assert_true($cov !== false && $cov['status'] === 'pendiente', 'franja de ahora');
        assert_true(\App\Services\ActingGuard::isDevice((int) $device['id']) && !\App\Services\ActingGuard::isDevice((int) $st['g1']['id']));
        // Nadie empezó (pasó 1 h > 2 × 15 min de tolerancia): aviso una vez.
        $unc = $count('guard.shift_post_uncovered');
        \App\Services\PostCoverage::run();
        \App\Services\PostCoverage::run();
        assert_true($count('guard.shift_post_uncovered') > $unc, 'puesto sin cubrir');
        $afterUnc = $count('guard.shift_post_uncovered');
        \App\Services\PostCoverage::run();
        assert_same($afterUnc, $count('guard.shift_post_uncovered'), 'una sola vez');
        // El celular del puesto baja el grupo con PIN y las franjas.
        UserAuth::setCurrent($device);
        $meta = Pull::run(null, 1000)['meta']['puesto'];
        assert_same(['Celular portería'], [$device['name']]);
        assert_same([$st['g1']['uuid'], $st['g2']['uuid']], array_column($meta['guardias'], 'uuid'));
        assert_true($meta['guardias'][0]['pin']['iterations'] === \App\Services\ActingGuard::PIN_ITERATIONS && strlen($meta['guardias'][0]['pin']['hash']) === 64);
        assert_true(in_array($cov['uuid'], array_column($meta['franjas'], 'uuid'), true) && $meta['de_turno'] === []);
        // g1 empieza desde el celular compartido (as_user); g3 no es del grupo.
        $s1 = Uuid::v4();
        $r = Push::run([$op('shift.start', ['uuid' => $s1, 'site_uuid' => $st['site']['uuid'], 'started_at_device' => $iso(0), 'lat' => -34.6037, 'lng' => -58.3816]) + ['as_user' => $st['g1']['uuid']]]);
        assert_same('ok', $r[0]['status'], json_encode($r));
        $slot1 = ShiftService::find($s1);
        assert_same([(int) $st['g1']['id'], (int) $cov['id'], 'en_curso'], [(int) $slot1['user_id'], (int) $slot1['coverage_id'], $slot1['status']]);
        assert_same('en_curso', DB::tenant()->query("SELECT status FROM guard_post_coverage WHERE id = {$cov['id']}")->fetchColumn());
        $r = Push::run([$op('shift.start', ['uuid' => Uuid::v4(), 'site_uuid' => $st['site']['uuid'], 'started_at_device' => $iso(0)]) + ['as_user' => $st['g3']['uuid']]]);
        assert_true($r[0]['status'] === 'error' && str_contains($r[0]['error'], 'no está en el grupo'));
        assert_same((int) $device['id'], (int) UserAuth::user()['id'], 'vuelve a la cuenta del puesto');
        $meta = Pull::run(null, 1000);
        assert_same([$st['g1']['uuid']], array_column($meta['meta']['puesto']['de_turno'], 'user_uuid'));
        assert_true(in_array($s1, array_column((array) ($meta['changes']->guard_shift_slots ?? []), 'uuid'), true), 'el turno del guardia baja al celular');
        // Relevo: g2 empieza → g1 queda relevado.
        $s2 = Uuid::v4();
        $r = Push::run([$op('shift.start', ['uuid' => $s2, 'site_uuid' => $st['site']['uuid'], 'started_at_device' => $iso(0)]) + ['as_user' => $st['g2']['uuid']]]);
        assert_same('ok', $r[0]['status']);
        assert_same(['cumplido', 'relevo'], [ShiftService::find($s1)['status'], ShiftService::find($s1)['end_mode']]);
        // Dejar el puesto sin persona: exige motivo; no avisa "relevo que no llegó".
        $r = Push::run([$op('shift.end', ['slot_uuid' => $s2, 'ended_at_device' => $iso(0), 'leave_empty' => true]) + ['as_user' => $st['g2']['uuid']]]);
        assert_same('error', $r[0]['status']);
        $r = Push::run([$op('shift.end', ['slot_uuid' => $s2, 'ended_at_device' => $iso(0), 'leave_empty' => true, 'note' => 'Llegó el dueño']) + ['as_user' => $st['g2']['uuid']]]);
        assert_same(['ok', 'sin_persona', 'Llegó el dueño'], [$r[0]['status'] . ($r[0]['error'] ?? ''), ShiftService::find($s2)['end_mode'], ShiftService::find($s2)['end_note']]);
        UserAuth::setCurrent($st['hys']);
        $rel = $count('guard.shift_relief_missing');
        \App\Services\PostCoverage::run(time() + 3600);
        assert_same($rel, $count('guard.shift_relief_missing'), 'sin persona con motivo: no avisa');
        // g1 vuelve y termina normal: si nadie lo releva, avisa una vez.
        UserAuth::setCurrent($device);
        $s3 = Uuid::v4();
        $r = Push::run([$op('shift.start', ['uuid' => $s3, 'site_uuid' => $st['site']['uuid'], 'started_at_device' => $iso(1)]) + ['as_user' => $st['g1']['uuid']]]);
        assert_same('ok', $r[0]['status'], json_encode($r));
        $r = Push::run([$op('shift.end', ['slot_uuid' => $s3, 'ended_at_device' => $iso(2)]) + ['as_user' => $st['g1']['uuid']]]);
        assert_same('ok', $r[0]['status'], json_encode($r));
        UserAuth::setCurrent($st['hys']);
        \App\Services\PostCoverage::run(time() + 3 * 60);
        assert_same($rel, $count('guard.shift_relief_missing'), 'todavía dentro de los minutos de relevo');
        \App\Services\PostCoverage::run(time() + (\App\Services\PostCoverage::reliefMinutes() + 3) * 60);
        \App\Services\PostCoverage::run(time() + (\App\Services\PostCoverage::reliefMinutes() + 4) * 60);
        assert_true($count('guard.shift_relief_missing') > $rel, 'relevo que no llegó');
        $afterRel = $count('guard.shift_relief_missing');
        \App\Services\PostCoverage::run(time() + (\App\Services\PostCoverage::reliefMinutes() + 3) * 60);
        assert_same($afterRel, $count('guard.shift_relief_missing'), 'una vez por hueco');
        // Huecos y cobertura (tiempos armados en la base): g1 de 0 a 30 min, g2 de 30 a 40 (sin persona), g1 de 70 en adelante.
        $base = strtotime($cov['start_at'] . ' UTC');
        $at = fn (int $min) => gmdate('Y-m-d H:i:s', $base + $min * 60);
        DB::tenant()->prepare('UPDATE guard_shift_slots SET started_at = ?, ended_at = ? WHERE uuid = ?')->execute([$at(0), $at(30), $s1]);
        DB::tenant()->prepare('UPDATE guard_shift_slots SET started_at = ?, ended_at = ? WHERE uuid = ?')->execute([$at(30), $at(40), $s2]);
        DB::tenant()->prepare("UPDATE guard_shift_slots SET started_at = ?, ended_at = NULL, status = 'en_curso' WHERE uuid = ?")->execute([$at(70), $s3]);
        $c = \App\Services\PostCoverage::find($cov['uuid']);
        $gaps = \App\Services\PostCoverage::gaps($c, $base + 90 * 60);
        assert_same([[30 * 60, true, 'Llegó el dueño']], array_map(fn ($g) => [$g['seconds'], $g['justified'], $g['reason']], $gaps), 'un hueco justificado de 30 min');
        assert_same(60 * 60, \App\Services\PostCoverage::coveredSeconds($c, $base + 90 * 60), 'cubierto: 30 + 10 + 20 min');
        // Muchas horas seguidas: g1 lleva 2 h y el máximo es 1 h.
        DB::tenant()->prepare('UPDATE guard_shift_slots SET started_at = ? WHERE uuid = ?')->execute([gmdate('Y-m-d H:i:s', time() - 7200), $s3]);
        \App\Models\Settings::set('turnos.horas_maximas', '1');
        $long = $count('guard.shift_long');
        \App\Services\PostCoverage::run();
        \App\Services\PostCoverage::run();
        assert_true($count('guard.shift_long') > $long, 'muchas horas seguidas');
        \App\Models\Settings::set('turnos.horas_maximas', '24');
        DB::tenant()->prepare('UPDATE guard_shift_slots SET started_at = ? WHERE uuid = ?')->execute([$at(70), $s3]);
        // Panel: detalle, hoy y reporte.
        $d = \App\Services\PostCoverage::detail($c);
        $html = \App\Core\View::render('panel/shifts/post', ['c' => $d], null);
        assert_true(str_contains($html, 'Llegó el dueño') && str_contains($html, 'Lo relevaron') && str_contains($html, 'Guardia2'), 'detalle del puesto');
        $today = ShiftPlanner::localDay(gmdate('Y-m-d H:i:s'));
        assert_true(count(\App\Services\PostCoverage::day($today)) >= 1);
        $rep = \App\Services\PostCoverage::report($today, $today);
        assert_true($rep[0]['covered'] > 0 && $rep[0]['justified'] > 0);
        $html = \App\Core\View::render('panel/shifts/coverage', ['filters' => ['desde' => $today, 'hasta' => $today, 'predio' => ''], 'posts' => $rep,
            'sites' => [], 'canExport' => true], null);
        assert_true(str_contains($html, 'Portería') && str_contains($html, 'Exportar CSV'), 'reporte de cobertura');
        $html = \App\Core\View::render('panel/shifts/today', ['day' => $today, 'today' => $today, 'slots' => ShiftService::day($today), 'posts' => \App\Services\PostCoverage::day($today),
            'pending' => [], 'hasShifts' => true, 'canEdit' => true], null);
        assert_true(str_contains($html, 'Puestos (los cubre un grupo de guardias)') && str_contains($html, 'De turno'), 'hoy con puestos');
        $html = \App\Core\View::render('panel/shifts/form', ['shift' => ShiftPlanner::find($shiftUuid), 'sites' => [], 'guards' => [], 'devices' => [$device],
            'userUuids' => [], 'defaultGrace' => 15, 'old' => null], null);
        assert_true(str_contains($html, 'Celular compartido del puesto') && str_contains($html, 'selected'), 'formulario por puesto');
        // Al terminar la franja (y cerrarse el último tramo) queda cubierta en parte.
        // RRHH (casilla de email) recibe el reporte de fin de turno de la franja al cerrarse.
        $rule = array_values(array_filter(\App\Models\NotificationRules::all(), fn ($r) => $r['event'] === 'guard.shift_report'))[0];
        DB::tenant()->prepare('UPDATE notification_rules SET recipients = ? WHERE id = ?')->execute([json_encode([['type' => 'email', 'value' => 'rrhh@sh.test']]), (int) $rule['id']]);
        \App\Services\Logbook::create(['site_uuid' => $st['site']['uuid'], 'kind' => 'persona', 'person_name' => 'Ana Visita', 'company' => 'Gas SA']);
        ShiftService::run(strtotime($c['end_at'] . ' UTC') + (ShiftService::autoEndMinutes() + 5) * 60);
        $mails = DB::tenant()->query("SELECT * FROM notification_queue WHERE event = 'guard.shift_report' AND to_address = 'rrhh@sh.test'")->fetchAll();
        assert_same(1, count($mails), 'un reporte por franja');
        foreach (['Reporte de turno · Portería', 'Guardia1', 'Llegó el dueño', 'Ana Visita', 'SIGUE ADENTRO', 'INCIDENTES Y ACCIDENTES: no hubo'] as $needle) {
            assert_true(str_contains($mails[0]['subject'] . $mails[0]['body_text'], $needle), "el reporte dice «{$needle}»");
        }
        ShiftService::run(strtotime($c['end_at'] . ' UTC') + (ShiftService::autoEndMinutes() + 10) * 60);
        assert_same(1, (int) DB::tenant()->query("SELECT COUNT(*) FROM notification_queue WHERE event = 'guard.shift_report'")->fetchColumn(), 'una sola vez');
        assert_same('parcial', DB::tenant()->query("SELECT status FROM guard_post_coverage WHERE id = {$cov['id']}")->fetchColumn());
        // Editar la programación a "por persona" anula las franjas futuras.
        ShiftPlanner::delete(ShiftPlanner::find($shiftUuid));
        assert_same(0, (int) DB::tenant()->query("SELECT COUNT(*) FROM guard_post_coverage WHERE shift_id = {$shift['id']} AND status = 'pendiente' AND start_at > UTC_TIMESTAMP()")->fetchColumn());
        DB::tenant()->exec('DELETE FROM guard_shift_positions');
        DB::tenant()->exec('DELETE FROM guard_shift_exits');
        DB::tenant()->exec('DELETE FROM guard_shift_slots');
    },

    'puesto: el guardia de turno hace la ronda con la ruta asignada al celular; el siguiente la puede terminar' => function () use ($setup, &$st, $op, $iso) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $device = Users::findByLogin('celu@sh.test');
        [$data] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'mode' => 'puesto', 'pattern' => 'weekly', 'days' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => '00:00', 'end_time' => '23:59', 'users' => [$st['g1']['uuid'], $st['g2']['uuid']], 'device' => $device['uuid']]);
        $shiftUuid = ShiftPlanner::save(null, $data);
        $point = \App\Models\Patrols::createPoint(['name' => 'Portón', 'code' => 'PX', 'description' => '', 'site_id' => null, 'sector_id' => null,
            'lat' => -34.6037, 'lng' => -58.3816, 'radius_m' => 30, 'is_critical' => false]);
        $route = Uuid::v4();
        \App\Models\Patrols::createRoute(['uuid' => $route, 'name' => 'Perímetro puesto', 'description' => '', 'frequency' => 'manual', 'expected_minutes' => 20,
            'point_ids' => [$point], 'user_ids' => [(int) $device['id']]]);
        UserAuth::setCurrent($device);
        $round = Uuid::v4();
        $r = Push::run([$op('round.start', ['uuid' => $round, 'route_uuid' => $route, 'started_at_device' => $iso(-5)]) + ['as_user' => $st['g1']['uuid']]]);
        assert_same('ok', $r[0]['status'], json_encode($r));
        assert_same((int) $st['g1']['id'], (int) \App\Models\Patrols::round($round)['user_id'], 'la ronda queda a nombre del guardia');
        $pointUuid = (string) DB::tenant()->query("SELECT uuid FROM patrol_points WHERE id = {$point}")->fetchColumn();
        $r = Push::run([$op('round.scan', ['uuid' => Uuid::v4(), 'round_uuid' => $round, 'point_uuid' => $pointUuid, 'lat' => -34.6037, 'lng' => -58.3816,
            'accuracy_m' => 5, 'scanned_at_device' => $iso(-3)]) + ['as_user' => $st['g2']['uuid']]]);
        assert_same('ok', $r[0]['status'], 'el siguiente guardia del celular sigue la ronda');
        $r = Push::run([$op('round.finish', ['round_uuid' => $round, 'finished_at_device' => $iso(-1)]) + ['as_user' => $st['g2']['uuid']]]);
        assert_same(['ok', 'completa'], [$r[0]['status'], \App\Models\Patrols::round($round)['status']]);
        $pulled = (array) (Pull::run(null, 1000)['changes']->patrol_rounds ?? []);
        $mine = array_values(array_filter($pulled, fn ($x) => $x['uuid'] === $round));
        assert_true($mine && $mine[0]['mine'] === true, 'el celular la ve como propia');
        // Pánico desde el celular a nombre del guardia
        $res = \App\Services\ActingGuard::run(\App\Services\ActingGuard::resolve($st['g1']['uuid']), fn () => \App\Services\GuardSafety::panic(['uuid' => Uuid::v4()], 'datos'));
        assert_same((int) $st['g1']['id'], (int) $res['panic']['user_id']);
        UserAuth::setCurrent($st['hys']);
        ShiftPlanner::delete(ShiftPlanner::find($shiftUuid));
    },

    'puesto: el reporte sale al terminar el turno en la última hora de la franja (sin cron) y no se repite' => function () use ($setup, &$st, $op, $iso) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $device = Users::findByLogin('celu@sh.test');
        $tz = ShiftPlanner::tz();
        $hm = fn (int $min) => (new DateTimeImmutable('@' . (time() + $min * 60)))->setTimezone($tz)->format('H:i');
        // Franja que termina en 30 min (dentro de la última hora).
        [$data, $errors] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'mode' => 'puesto', 'pattern' => 'weekly', 'days' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => $hm(-120), 'end_time' => $hm(30), 'users' => [$st['g1']['uuid'], $st['g2']['uuid']], 'device' => $device['uuid'], 'name' => 'Noche corta']);
        assert_same([], $errors);
        $shiftUuid = ShiftPlanner::save(null, $data);
        $shift = ShiftPlanner::find($shiftUuid);
        $cov = DB::tenant()->query("SELECT * FROM guard_post_coverage WHERE shift_id = {$shift['id']} AND start_at <= UTC_TIMESTAMP() AND end_at > UTC_TIMESTAMP()")->fetch();
        assert_true($cov !== false);
        $rule = array_values(array_filter(\App\Models\NotificationRules::all(), fn ($r) => $r['event'] === 'guard.shift_report'))[0];
        DB::tenant()->prepare('UPDATE notification_rules SET recipients = ? WHERE id = ?')->execute([json_encode([['type' => 'email', 'value' => 'rrhh@sh.test']]), (int) $rule['id']]);
        $reports = fn () => (int) DB::tenant()->query("SELECT COUNT(*) FROM notification_queue WHERE event = 'guard.shift_report' AND entity_uuid = " . DB::tenant()->quote($cov['uuid']))->fetchColumn();
        UserAuth::setCurrent($device);
        $s = Uuid::v4();
        Push::run([$op('shift.start', ['uuid' => $s, 'site_uuid' => $st['site']['uuid'], 'started_at_device' => $iso(0)]) + ['as_user' => $st['g1']['uuid']]]);
        assert_same(0, $reports(), 'en turno: todavía no');
        // Botón "Enviar reporte ahora" del panel: parcial, no cuenta como el automático.
        UserAuth::setCurrent($st['hys']);
        assert_same(1, \App\Services\ShiftEndReport::sendNow(\App\Services\PostCoverage::findById((int) $cov['id'])));
        $manual = DB::tenant()->query("SELECT subject FROM notification_queue WHERE event = 'guard.shift_report' ORDER BY id DESC LIMIT 1")->fetchColumn();
        assert_true(str_starts_with((string) $manual, '[Parcial'), 'el asunto aclara que es parcial');
        $html = \App\Core\View::render('panel/shifts/post', ['c' => \App\Services\PostCoverage::detail(\App\Services\PostCoverage::findById((int) $cov['id'])),
            'report' => \App\Services\ShiftEndReport::build(\App\Services\PostCoverage::findById((int) $cov['id'])), 'canSend' => true], null);
        assert_true(str_contains($html, 'Enviar reporte ahora (parcial)') && str_contains($html, 'Ver reporte'), 'botones en el detalle');
        UserAuth::setCurrent($device);
        $r = Push::run([$op('shift.end', ['slot_uuid' => $s, 'ended_at_device' => $iso(1)]) + ['as_user' => $st['g1']['uuid']]]);
        assert_same('ok', $r[0]['status'], json_encode($r));
        assert_same(2, $reports(), 'terminó en la última hora: el automático sale igual en el momento');
        assert_true(in_array(DB::tenant()->query("SELECT status FROM guard_post_coverage WHERE id = {$cov['id']}")->fetchColumn(), ['parcial', 'sin_cubrir', 'cubierta'], true));
        UserAuth::setCurrent($st['hys']);
        ShiftService::run(strtotime($cov['end_at'] . ' UTC') + 3 * 3600);
        assert_same(2, $reports(), 'el cron no lo repite');
        // Antes de la última hora no se adelanta
        assert_true(!\App\Services\PostCoverage::closeIfDone((int) $cov['id']), 'ya cerrada');
        ShiftPlanner::delete(ShiftPlanner::find($shiftUuid));
        DB::tenant()->exec('DELETE FROM guard_shift_positions');
        DB::tenant()->exec('DELETE FROM guard_shift_exits');
        DB::tenant()->exec('DELETE FROM guard_shift_slots');
    },

    'notebook del puesto: el libro web va a nombre del guardia de turno; sin nadie, se anota marcado y avisa; la sesión se recuerda' => function () use ($setup, &$st, $op, $iso, $count) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $device = Users::findByLogin('celu@sh.test');
        [$data] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'mode' => 'puesto', 'pattern' => 'weekly', 'days' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => '00:00', 'end_time' => '23:59', 'users' => [$st['g1']['uuid'], $st['g2']['uuid']], 'device' => $device['uuid'], 'name' => 'Notebook']);
        $shiftUuid = ShiftPlanner::save(null, $data);
        DB::tenant()->exec("UPDATE guard_shift_slots SET status = 'cumplido', ended_at = UTC_TIMESTAMP() WHERE status = 'en_curso'");
        UserAuth::setCurrent($device);
        $_SESSION = [];
        $ctl = new \App\Controllers\Web\Panel\LogbookController();
        $req = fn (array $post) => new \App\Core\Request('POST', '/panel/libro/anotar', ['predio' => $st['site']['uuid']], $post);
        $last = fn () => DB::tenant()->query('SELECT * FROM logbook_entries ORDER BY id DESC LIMIT 1')->fetch();
        // Nadie de turno: se anota igual, marcado, y avisa una vez por hora
        $before = $count('logbook.no_duty');
        $ctl->store($req(['kind' => 'novedad', 'category' => 'rutina', 'text' => 'Recorrida sin novedad']));
        $e = $last();
        assert_same([1, (int) $device['id']], [(int) $e['no_duty'], (int) $e['user_id']]);
        assert_true($count('logbook.no_duty') > $before, 'avisa al supervisor');
        $after = $count('logbook.no_duty');
        $ctl->store($req(['kind' => 'novedad', 'category' => 'rutina', 'text' => 'Otra sin turno']));
        assert_same($after, $count('logbook.no_duty'), 'una vez por hora');
        assert_true(str_contains(json_encode($_SESSION), 'SIN GUARDIA DE TURNO'), 'el mensaje pide empezar el turno');
        $html = $ctl->index(new \App\Core\Request('GET', '/panel/libro', ['predio' => $st['site']['uuid']]))->body;
        assert_true(str_contains($html, 'duty-banner') && str_contains($html, 'sin guardia de turno'), 'cartel y marca');
        // Guardia1 empieza en el celular → lo de la notebook va a su nombre
        $s = Uuid::v4();
        Push::run([$op('shift.start', ['uuid' => $s, 'site_uuid' => $st['site']['uuid'], 'started_at_device' => $iso(0)]) + ['as_user' => $st['g1']['uuid']]]);
        $ctl->store($req(['kind' => 'persona', 'person_name' => 'Visita notebook', 'company' => 'Gas SA']));
        $e = $last();
        assert_same([0, (int) $st['g1']['id']], [(int) $e['no_duty'], (int) $e['user_id']]);
        assert_same((int) $device['id'], (int) UserAuth::user()['id'], 'vuelve a la cuenta del puesto');
        $j = json_decode($ctl->duty(new \App\Core\Request('GET', '/panel/libro/de-turno', ['predio' => $st['site']['uuid']]))->body, true);
        assert_same([true, 'Guardia1'], [$j['data']['device'], $j['data']['name']]);
        $ctl->exit(new \App\Core\Request('POST', '/', ['predio' => $st['site']['uuid']], []), $e['uuid']);
        assert_same((int) $st['g1']['id'], (int) $last()['user_id'], 'la salida también');
        assert_true(\App\Services\Logbook::verify((int) $st['site']['id'])['ok'] ?? true, 'la huella del libro sigue íntegra');
        // Recordar la notebook: sin sesión vuelve a entrar sola; "Salir" la olvida
        \App\Services\PostTerminal::issue($st['a'], $device, 'Notebook garita');
        $_SESSION = [];
        assert_true(\App\Services\PostTerminal::restore(), 'vuelve a entrar sola');
        assert_same($device['uuid'], $_SESSION[UserAuth::USER_KEY] ?? null);
        assert_same(1, count(\App\Services\PostTerminal::forUser((int) $device['id'])));
        \App\Services\PostTerminal::revokeCurrent();
        $_SESSION = [];
        assert_true(!\App\Services\PostTerminal::restore(), 'olvidada');
        // Un usuario común no queda recordado
        $_COOKIE = [];
        assert_true(!\App\Services\ActingGuard::isDevice((int) $st['g1']['id']));
        // El guardia con su propia cuenta: con turno empezado anota normal; sin turno, marcado y con el aviso
        UserAuth::setCurrent($st['g1']);
        assert_same('ok', \App\Services\Logbook::dutyState());
        assert_same(0, (int) \App\Services\Logbook::create(['site_uuid' => $st['site']['uuid'], 'kind' => 'novedad', 'text' => 'Con turno'])['entry']['no_duty']);
        Push::run([$op('shift.end', ['slot_uuid' => $s, 'ended_at_device' => $iso(0)]) + ['as_user' => $st['g1']['uuid']]]);
        UserAuth::setCurrent($st['g1']);
        assert_same('none', \App\Services\Logbook::dutyState(), 'guardia de una programación sin turno iniciado');
        assert_same(1, (int) \App\Services\Logbook::create(['site_uuid' => $st['site']['uuid'], 'kind' => 'novedad', 'text' => 'Sin turno'])['entry']['no_duty']);
        $j = json_decode($ctl->duty(new \App\Core\Request('GET', '/panel/libro/de-turno', ['predio' => $st['site']['uuid']]))->body, true);
        assert_same(['guard', null], [$j['data']['mode'], $j['data']['name']], 'el cartel le pide empezar su turno');
        UserAuth::setCurrent($st['hys']);
        assert_same(null, \App\Services\Logbook::dutyState(), 'SyH no es guardia: no se controla');
        ShiftPlanner::delete(ShiftPlanner::find($shiftUuid));
        UserAuth::setCurrent($st['g1']);
        assert_same(null, \App\Services\Logbook::dutyState(), 'sin programaciones activas deja de ser guardia');
        UserAuth::setCurrent($st['hys']);
        $_SESSION = [];
    },

    'puesto: los guardias son empleados (sin usuario): PIN, perfil interno sin acceso, turno desde el celular compartido' => function () use ($setup, &$st, $op, $iso) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $device = Users::findByLogin('celu@sh.test');
        $emp = \App\Models\Employees::findById(\App\Models\Employees::create(['uuid' => Uuid::v4(), 'dni' => '30111222', 'last_name' => 'Gómez', 'first_name' => 'Ana', 'is_active' => 1]));
        [$data, $errors] = ShiftPlanner::validate(['site' => $st['site']['uuid'], 'mode' => 'puesto', 'pattern' => 'weekly', 'days' => [1, 2, 3, 4, 5, 6, 7],
            'start_time' => '00:00', 'end_time' => '23:59', 'employees' => [$emp['uuid']], 'device' => $device['uuid'], 'name' => 'Empleados']);
        assert_same([], $errors, 'alcanza con empleados en el grupo');
        $shiftUuid = ShiftPlanner::save(null, $data);
        $g = \App\Services\PostGuards::linkedUser((int) $emp['id']);
        assert_true($g !== null && (int) $g['no_login'] === 1 && $g['name'] === 'Ana Gómez', 'perfil interno');
        assert_true(!Users::canSignIn($g + ['password_hash' => 'x']), 'nunca puede ingresar');
        assert_true(!in_array($g['uuid'], array_column(Users::accounts(), 'uuid'), true), 'no aparece en Usuarios');
        try { \App\Services\PostGuards::setPin($emp, '12'); assert_true(false, 'PIN corto'); } catch (App\Core\UserError) {}
        \App\Services\PostGuards::setPin($emp, '4321');
        $pool = \App\Services\ActingGuard::poolForDevice((int) $device['id']);
        $mine = array_values(array_filter($pool, fn ($p) => $p['uuid'] === $g['uuid']))[0] ?? null;
        assert_true($mine !== null && $mine['pin'] !== null && $mine['dni_end'] === '222', 'llega al celular con PIN y DNI');
        $rows = \App\Services\PostGuards::list('gómez');
        assert_same([true, ['Empleados']], [(bool) $rows[0]['has_pin'], $rows[0]['posts']]);
        // El formulario marca al empleado y la página de guardias se renderiza
        $html = (new \App\Controllers\Web\Panel\ShiftsController())->form(new \App\Core\Request('GET', '/'), $shiftUuid)->body;
        assert_true(str_contains($html, 'name="employees[]" value="' . $emp['uuid'] . '" id="em' . $emp['uuid'] . '" checked'), 'empleado elegido');
        assert_true(str_contains((new \App\Controllers\Web\Panel\ShiftsController())->guardsPage(new \App\Core\Request('GET', '/'))->body, 'Con PIN'));
        // Desde el celular compartido empieza el turno a su nombre
        UserAuth::setCurrent($device);
        DB::tenant()->exec("UPDATE guard_shift_slots SET status = 'cumplido', ended_at = UTC_TIMESTAMP() WHERE status = 'en_curso'");
        $s = Uuid::v4();
        $r = Push::run([$op('shift.start', ['uuid' => $s, 'site_uuid' => $st['site']['uuid'], 'started_at_device' => $iso(0)]) + ['as_user' => $g['uuid']]]);
        assert_same('ok', $r[0]['status'], json_encode($r));
        assert_true(ShiftService::current((int) $g['id']) !== null, 'turno a nombre del empleado');
        Push::run([$op('shift.end', ['slot_uuid' => $s, 'ended_at_device' => $iso(0)]) + ['as_user' => $g['uuid']]]);
        // Cambia el nombre o se desactiva el empleado → el perfil lo sigue y deja de poder identificarse
        UserAuth::setCurrent($st['hys']);
        \App\Models\Employees::update((int) $emp['id'], ['first_name' => 'Ana María']);
        \App\Services\PostGuards::syncEmployee((int) $emp['id']);
        assert_same('Ana María Gómez', Users::findById((int) $g['id'])['name']);
        \App\Models\Employees::setActive((int) $emp['id'], false);
        \App\Services\PostGuards::syncEmployee((int) $emp['id']);
        UserAuth::setCurrent($device);
        try { \App\Services\ActingGuard::resolve($g['uuid']); assert_true(false, 'desactivado'); } catch (App\Core\UserError) {}
        UserAuth::setCurrent($st['hys']);
        ShiftPlanner::delete(ShiftPlanner::find($shiftUuid));
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
