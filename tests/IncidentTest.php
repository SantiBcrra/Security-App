<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Storage;
use App\Core\Tenant;
use App\Core\Uuid;
use App\Models\CatalogItems;
use App\Models\Employees;
use App\Models\Incidents;
use App\Models\PlatformSettings;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\Tenants;
use App\Models\Users;
use App\Models\UserSectors;
use App\Policies\Permissions;
use App\Services\IncidentService;
use App\Services\IndustryTemplates;
use App\Services\Notify\Channels;
use App\Services\Notify\MailTransport;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Etapa 12 (entrega 1): incidentes y accidentes, personas, datos de salud. Maestra `securityapp_test_inc`.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_inc';
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
    $company = ['legal_name' => 'Metalúrgica Prueba SA', 'cuit' => '30711111118', 'timezone' => 'America/Argentina/Buenos_Aires', 'status' => 'active', 'plan' => null];
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Ic A', 'slug' => 'ic-a'] + $company, null));
    $st['b'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Ic B', 'slug' => 'ic-b'] + $company, null));
    MailTransport::$fake = fn () => null;
    Channels::$fakeExternal = fn () => null;
    Tenant::activate($st['a']);
    $site = Sites::create(['name' => 'Planta']);
    $st['nave1'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 1']);
    $st['nave2'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 2']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    $st['admin'] = $mk('admin_empresa', 'admin@ic.test');
    $st['hys'] = $mk('responsable_hys', 'hys@ic.test');
    $st['sup'] = $mk('supervisor', 'sup@ic.test');
    $st['sup2'] = $mk('supervisor', 'sup2@ic.test');
    $st['rep'] = $mk('reportante', 'rep@ic.test');
    $st['rep2'] = $mk('reportante', 'rep2@ic.test');
    UserSectors::replace((int) $st['sup']['id'], [$st['nave1']]);
    UserSectors::replace((int) $st['sup2']['id'], [$st['nave2']]);
    UserAuth::setCurrent($st['hys']);
    IndustryTemplates::apply('metalurgica');
    $st['emp'] = Employees::findById(Employees::create(['dni' => '30111222', 'last_name' => 'Pérez', 'first_name' => 'Juan', 'sector_id' => $st['nave1'],
        'cuil' => '20301112220', 'birth_date' => '1985-05-02', 'gender' => 'M', 'address' => 'Calle 1', 'hire_date' => '2020-03-01']));
    $st['emp2'] = Employees::findById(Employees::create(['dni' => '28999888', 'last_name' => 'Gómez', 'first_name' => 'Ana', 'sector_id' => $st['nave1']]));
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

$inbox = fn (array $u) => array_column(DB::tenant()->query('SELECT title, body FROM notifications WHERE user_id = ' . (int) $u['id'] . ' ORDER BY id')->fetchAll(), 'body', 'title');

/** Reporte como $user. */
$report = function (array $user, string $type, array $extra = []) use (&$st): array {
    Tenant::activate($st['a']);
    UserAuth::setCurrent($user);
    return IncidentService::create($extra + [
        'type' => $type, 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600), 'sector' => Sectors::findById($st['nave1'])['uuid'],
        'description' => 'Se cortó la mano con una chapa al descargar el camión',
        'immediate_actions' => 'Primeros auxilios y traslado a la ART',
        'people' => [['role' => 'lesionado', 'employee' => $st['emp']['uuid'], 'injury_description' => 'Corte profundo palma izquierda'],
            ['role' => 'testigo', 'employee' => $st['emp2']['uuid'], 'statement' => 'Vi que la chapa se resbaló']],
    ]);
};

return [
    'permisos: "datos de salud" solo en incidentes, para SyH y admin; el operario reporta' => function () {
        assert_true(in_array('datos_salud', Permissions::actionsFor('incidentes'), true));
        assert_true(!in_array('datos_salud', Permissions::actionsFor('observaciones'), true));
        $roles = Permissions::baseRoles();
        $can = fn (string $r) => in_array('datos_salud', $roles[$r]['permissions']['incidentes']['acciones'] ?? [], true);
        assert_same([true, true, false, false, false], [$can('admin_empresa'), $can('responsable_hys'), $can('supervisor'), $can('reportante'), $can('auditor')]);
        assert_same(['ver', 'crear'], $roles['reportante']['permissions']['incidentes']['acciones']);
    },

    'días perdidos: con alta, sin alta (provisorios) y sin baja' => function () {
        assert_same(['days' => 5, 'provisional' => false], IncidentService::lostDays(['lost_time' => 1, 'leave_start' => '2026-10-10', 'discharge_date' => '2026-10-15'], '2026-10-20'));
        assert_same(['days' => 11, 'provisional' => true], IncidentService::lostDays(['lost_time' => 1, 'leave_start' => '2026-10-10', 'discharge_date' => null], '2026-10-20'),
            'hasta hoy inclusive');
        assert_same(['days' => 0, 'provisional' => false], IncidentService::lostDays(['lost_time' => 0, 'leave_start' => null, 'discharge_date' => null], '2026-10-20'));
    },

    'alta: validaciones (tipo, descripción, lesionado en accidentes, fecha)' => function () use ($setup, &$st, $report) {
        $setup();
        $r = $report($st['rep'], 'accidente_con_baja', ['people' => [], 'description' => 'corto']);
        assert_true(isset($r['errors']['people'], $r['errors']['description']));
        $r = $report($st['rep'], 'inventado');
        assert_true(isset($r['errors']['type']));
        $r = $report($st['rep'], 'casi_accidente', ['occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400)]);
        assert_true(isset($r['errors']['occurred_at']), 'no puede ser futura');
        $r = $report($st['rep'], 'in_itinere', ['sector' => '']);
        assert_same([], $r['errors'], 'in itinere sin sector');
        $st['itinere'] = $r['incident'];
    },

    'alta: accidente con baja → número, hash, baja automática y aviso crítico sin datos de salud' => function () use ($setup, &$st, $report, $inbox) {
        $setup();
        $r = $report($st['rep'], 'accidente_con_baja');
        assert_same([], $r['errors']);
        $i = $r['incident'];
        assert_same(['reportado', (int) $st['rep']['id']], [$i['status'], (int) $i['reported_by']]);
        assert_same(hash('sha256', $i['original_data']), $i['original_hash']);
        $people = Incidents::people((int) $i['id']);
        $injured = array_values(array_filter($people, fn ($p) => $p['role'] === 'lesionado'))[0];
        $nextDay = (new DateTimeImmutable(fecha($i['occurred_at'], 'Y-m-d')))->modify('+1 day')->format('Y-m-d');
        assert_same([1, $nextDay, 'en_tratamiento'], [(int) $injured['lost_time'], $injured['leave_start'], $injured['follow_up_status']]);
        $title = '⚠ Accidente con baja · ' . Incidents::format((int) $i['number']);
        foreach (['sup', 'hys', 'admin'] as $who) {
            assert_true(isset($inbox($st[$who])[$title]), "aviso crítico a {$who}");
        }
        assert_true(!isset($inbox($st['sup2'])[$title]), 'no al supervisor de otra nave');
        assert_true(!str_contains($inbox($st['sup'])[$title], 'palma'), 'el aviso no lleva la lesión');
        $st['acc'] = $i;
        $casi = $report($st['rep'], 'casi_accidente', ['people' => []])['incident'];
        assert_true(isset($inbox($st['hys'])['Casi-accidente · ' . Incidents::format((int) $casi['number'])]), 'aviso normal');
        $st['casi'] = $casi;
    },

    'datos de salud: el supervisor no los ve ni los carga; SyH sí, con eventos reservados' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $i = $st['acc'];
        $p = array_values(array_filter(Incidents::people((int) $i['id']), fn ($x) => $x['role'] === 'lesionado'))[0];
        UserAuth::setCurrent($st['sup']);
        assert_true(str_contains((string) IncidentService::updatePerson($i, $p, ['lost_time' => '1', 'leave_start' => $p['leave_start']]), 'datos de salud'));
        UserAuth::setCurrent($st['hys']);
        $in = ['role' => 'lesionado', 'injury_type' => CatalogItems::findByName('lesion', 'Herida cortante')['uuid'],
            'body_part' => CatalogItems::findByName('parte_cuerpo', 'Mano')['uuid'], 'accident_form' => CatalogItems::findByName('forma_accidente', 'Golpe / corte con herramienta')['uuid'],
            'medical_attention' => 'art', 'lost_time' => '1', 'leave_start' => $p['leave_start'], 'art_case_number' => 'SIN-123', 'follow_up_status' => 'en_tratamiento'];
        assert_true(str_contains((string) IncidentService::updatePerson($i, $p, $in + ['discharge_date' => '2000-01-01']), 'anterior'));
        assert_true(str_contains((string) IncidentService::updatePerson($i, $p, $in + ['discharge_date' => '2099-01-01']), 'futura'));
        assert_same(null, IncidentService::updatePerson($i, $p, $in));
        $p = Incidents::person((int) $i['id'], $p['uuid']);
        assert_same(['Herida cortante', 'Mano', 'SIN-123'], [$p['injury_type_name'], $p['body_part_name'], $p['art_case_number']]);
        $withHealth = array_column(Incidents::events((int) $i['id'], true), 'type');
        $without = array_column(Incidents::events((int) $i['id'], false), 'type');
        assert_true(in_array('follow_up', $withHealth, true) && !in_array('follow_up', $without, true), 'el evento de salud queda reservado');
        assert_same(1, count(Incidents::openLeaves()));
    },

    'alcance, corrección (original intacto) y cierre con investigación obligatoria' => function () use ($setup, &$st, $inbox) {
        $setup();
        Tenant::activate($st['a']);
        $count = function (array $u) {
            UserAuth::setCurrent($u);
            return Incidents::count([], IncidentService::scope());
        };
        assert_same([3, 3, 2, 0, 0], [$count($st['hys']), $count($st['rep']), $count($st['sup']), $count($st['sup2']), $count($st['rep2'])],
            'SyH todo; el operario los suyos; el supervisor los de su nave (el in itinere no tiene sector)');
        UserAuth::setCurrent($st['hys']);
        $i = Incidents::findById((int) $st['acc']['id']);
        assert_same(null, IncidentService::correct($i, ['type' => 'accidente_sin_baja', 'comment' => 'No tuvo baja finalmente']));
        $fresh = Incidents::findById((int) $i['id']);
        assert_same(['accidente_sin_baja', $i['original_hash']], [$fresh['type'], $fresh['original_hash']]);
        assert_true(str_contains((string) IncidentService::transition($fresh, 'cerrar', ['comment' => 'Listo, todo ok']), 'investigación'), 'accidente: falta investigar');
        UserAuth::setCurrent($st['rep']);
        assert_true(str_contains((string) IncidentService::transition(Incidents::findById((int) $st['casi']['id']), 'cerrar', ['comment' => 'Se ordenó el sector']), 'permiso'),
            'el operario no cierra');
    },

    'cierre de un casi-accidente y aviso a quien reportó; días sin accidentes con baja' => function () use ($setup, &$st, $inbox) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $casi = Incidents::findById((int) $st['casi']['id']);
        assert_same(null, IncidentService::transition($casi, 'cerrar', ['comment' => 'Se colocó una baranda']));
        assert_true(isset($inbox($st['rep'])['Incidente ' . Incidents::format((int) $casi['number']) . ' cerrado']), json_encode(array_keys($inbox($st['rep'])), JSON_UNESCAPED_UNICODE));
        $tz = 'America/Argentina/Buenos_Aires';
        $today = IncidentService::today();
        $d = Incidents::daysWithoutLostTime($today, $tz);
        assert_true(in_array($d['days'], [0, 1], true), 'el in itinere de hace una hora cuenta (0, o 1 si fue antes de la medianoche)');
        DB::tenant()->exec("UPDATE incidents SET occurred_at = UTC_TIMESTAMP() - INTERVAL 10 DAY WHERE type = 'in_itinere'");
        assert_true(in_array(Incidents::daysWithoutLostTime($today, $tz)['days'], [9, 10, 11], true));
    },

    'HTTP: páginas, datos de salud ocultos sin permiso, imprimible ART y auditoría de acceso' => function () use ($setup, &$st) {
        $setup();
        $call = function (array $user, string $path, string $method = 'GET', array $post = []) use (&$st) {
            UserAuth::setCurrent(null);
            Tenant::deactivate();
            $_SESSION = [];
            \App\Core\Session::put(\App\Services\Impersonation::TENANT_KEY, $st['a']['uuid']);
            \App\Core\Session::put(UserAuth::USER_KEY, $user['uuid']);
            if ($method === 'POST') {
                $post['csrf_token'] = csrf_token();
            }
            $router = new \App\Core\Router();
            (require BASE_PATH . '/app/routes.php')($router);
            return $router->dispatch(new \App\Core\Request($method, $path, [], $post));
        };
        $uuid = $st['acc']['uuid'];
        Tenant::activate($st['a']);
        $person = array_values(array_filter(Incidents::people((int) $st['acc']['id']), fn ($p) => $p['role'] === 'lesionado'))[0]['uuid'];
        assert_same(200, $call($st['sup'], '/panel/incidentes')->status);
        assert_same(200, $call($st['rep'], '/panel/incidentes/nuevo')->status);
        $sup = $call($st['sup'], '/panel/incidentes/' . $uuid);
        assert_same(200, $sup->status);
        assert_true(!str_contains($sup->body, 'Herida cortante') && !str_contains($sup->body, 'SIN-123') && str_contains($sup->body, 'reservados'),
            'el supervisor no ve datos de salud');
        $hys = $call($st['hys'], '/panel/incidentes/' . $uuid);
        assert_true(str_contains($hys->body, 'Herida cortante') && str_contains($hys->body, 'Datos para la ART'));
        Tenant::activate($st['a']);
        assert_true((int) DB::tenant()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'incident.health_view'")->fetchColumn() >= 1, 'acceso auditado');
        $art = $call($st['hys'], '/panel/incidentes/' . $uuid . '/art/' . $person);
        assert_same(200, $art->status);
        assert_true(str_contains($art->body, '20-30111222-0') && str_contains($art->body, 'Metalúrgica Prueba SA') && str_contains($art->body, 'Herida cortante'));
        assert_same(403, $call($st['sup'], '/panel/incidentes/' . $uuid . '/art/' . $person)->status);
        $print = $call($st['sup'], '/panel/incidentes/' . $uuid . '/imprimir');
        assert_true($print->status === 200 && !str_contains($print->body, 'Herida cortante'));
        assert_same(404, $call($st['sup2'], '/panel/incidentes/' . $uuid)->status, 'fuera de su alcance');
        $post = ['type' => 'incidente', 'occurred_at' => fecha(gmdate('Y-m-d H:i:s'), 'Y-m-d\TH:i'), 'sector' => '', 'equipment' => '',
            'description' => 'El autoelevador golpeó una estantería', 'people' => []];
        Tenant::activate($st['a']);
        $post['sector'] = Sectors::findById($st['nave2'])['uuid'];
        $res = $call($st['rep'], '/panel/incidentes', 'POST', $post);
        assert_same(302, $res->status);
        assert_true(str_contains($res->headers['Location'], '/panel/incidentes/'));
        $home = $call($st['hys'], '/panel');
        assert_true(str_contains($home->body, 'días sin accidentes con baja') && str_contains($home->body, 'de baja sin alta'));
        $_SESSION = [];
    },

    'empleados: campos para la ART en la base y en la importación' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $e = Employees::findById((int) $st['emp']['id']);
        assert_same(['20301112220', '1985-05-02', 'M'], [$e['cuil'], $e['birth_date'], $e['gender']]);
        $imp = new App\Services\Import\EmployeesImporter();
        $ctx = new App\Services\Import\ImportContext();
        $bad = $imp->validate(['dni' => '33444555', 'apellido' => 'Ruiz', 'nombre' => 'Eva', 'cuil' => '20-33444555-9', 'sexo' => 'Z'], $ctx);
        assert_true(str_contains(json_encode($bad, JSON_UNESCAPED_UNICODE), 'CUIL inválido') && str_contains(json_encode($bad, JSON_UNESCAPED_UNICODE), 'Sexo'));
    },

    'aislamiento: la empresa B no ve incidentes de A' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['b']);
        UserAuth::setCurrent(null);
        assert_same(0, Incidents::count([], null));
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
