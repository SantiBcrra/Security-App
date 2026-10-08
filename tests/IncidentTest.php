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

    'investigación: árbol válido (sin ciclos; huérfanos quedan como raíz)' => function () {
        [$d, $err] = App\Services\IncidentInvestigation::normalize(['cause_tree' => [
            ['id' => 'a', 'parent' => null, 'text' => 'Corte en la mano', 'type' => 'hecho'],
            ['id' => 'b', 'parent' => 'a', 'text' => 'Chapa sin guantes', 'type' => 'causa_inmediata'],
            ['id' => 'c', 'parent' => 'zz', 'text' => 'Huérfano', 'type' => 'raro'],
            ['id' => 'd', 'parent' => 'a', 'text' => '', 'type' => 'hecho'],
        ], 'whys' => ['uno', '', 'dos']]);
        assert_same(null, $err);
        assert_same([3, null, 'causa_inmediata'], [count($d['cause_tree']), $d['cause_tree'][2]['parent'], $d['cause_tree'][2]['type']]);
        assert_same(['uno', 'dos'], $d['five_whys']['whys']);
        assert_same([0, 1, 0], array_column(App\Services\IncidentInvestigation::flatten($d['cause_tree']), 'depth'));
        [, $err] = App\Services\IncidentInvestigation::normalize(['cause_tree' => [['id' => 'x', 'parent' => 'y', 'text' => 'X'], ['id' => 'y', 'parent' => 'x', 'text' => 'Y']]]);
        assert_true(str_contains((string) $err, 'ciclo'));
    },

    'investigación: empezar, guardar, terminar (con requisitos), acción derivada, cerrar y reabrir' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['sup']); // el supervisor investiga en su nave
        $i = Incidents::findById((int) $st['acc']['id']);
        assert_same(null, App\Services\IncidentInvestigation::start($i));
        $i = Incidents::findById((int) $i['id']);
        assert_same('en_investigacion', $i['status']);
        assert_true(str_contains((string) App\Services\IncidentInvestigation::complete($i), 'causa raíz'), 'faltan requisitos');
        $cause = CatalogItems::findByName('causa', 'Falta de uso de EPP')['uuid'];
        $in = ['team' => [$st['sup']['uuid'], $st['hys']['uuid']], 'problem' => 'Se cortó la mano', 'whys' => ['No usaba guantes', 'No había guantes del talle'],
            'cause_tree' => json_encode([['id' => 'h1', 'parent' => null, 'text' => 'Corte en la palma', 'type' => 'hecho'],
                ['id' => 'c1', 'parent' => 'h1', 'text' => 'Manipuló chapa sin guantes', 'type' => 'causa_inmediata'],
                ['id' => 'c2', 'parent' => 'c1', 'text' => 'Faltan talles en el pañol', 'type' => 'causa_basica']]),
            'root_causes' => [$cause], 'conclusions' => 'Falta de control de stock de EPP en el pañol', 'lessons' => 'Revisar talles al ingreso'];
        assert_same(null, App\Services\IncidentInvestigation::save($i, $in));
        [$action, $errors] = App\Services\IncidentInvestigation::createAction($i, ['title' => 'Comprar guantes anticorte talle S y M', 'responsible' => $st['hys']['uuid'],
            'due_on' => (new DateTimeImmutable(IncidentService::today()))->modify('+5 days')->format('Y-m-d'), 'priority' => 'alta', 'cause' => 'Faltan talles en el pañol']);
        assert_same([], $errors);
        assert_same(['incidente', (int) $st['nave1']], [$action['origin_type'], (int) $action['sector_id']]);
        assert_true(str_contains((string) $action['description'], 'Faltan talles'));
        assert_same(null, App\Services\IncidentInvestigation::complete(Incidents::findById((int) $i['id'])));
        $i = Incidents::findById((int) $i['id']);
        assert_same('investigado', $i['status']);
        $v = Incidents::investigation((int) $i['id']);
        assert_same([3, 2, true], [count($v['cause_tree']), count($v['team']), $v['completed_at'] !== null]);
        assert_same(null, IncidentService::transition($i, 'cerrar', ['comment' => 'Investigación y acciones en curso']));
        UserAuth::setCurrent($st['hys']);
        assert_same(null, IncidentService::transition(Incidents::findById((int) $i['id']), 'reabrir', ['comment' => 'Apareció un dato nuevo']));
        $i = Incidents::findById((int) $i['id']);
        assert_same(['en_investigacion', null], [$i['status'], Incidents::investigation((int) $i['id'])['completed_at']], 'vuelve a la investigación');
        $st['accAction'] = $action;
    },

    'recordatorios: investigación sin empezar, falta N° ART y resumen semanal de bajas (una sola vez)' => function () use ($setup, &$st, $report, $inbox) {
        $setup();
        $new = $report($st['rep'], 'accidente_con_baja')['incident'];
        DB::tenant()->prepare('UPDATE incidents SET occurred_at = UTC_TIMESTAMP() - INTERVAL 5 DAY WHERE id = ?')->execute([(int) $new['id']]);
        DB::tenant()->prepare('UPDATE incident_people SET leave_start = CURDATE() - INTERVAL 4 DAY WHERE incident_id = ? AND lost_time = 1')->execute([(int) $new['id']]);
        UserAuth::setCurrent(null);
        $monday = new DateTimeImmutable('next monday 10:00', new DateTimeZone('America/Argentina/Buenos_Aires'));
        $r = App\Services\Notify\IncidentReminders::run($monday->setTimezone(new DateTimeZone('UTC')));
        assert_true($r['investigation'] >= 1 && $r['art'] >= 1 && $r['leaves'] >= 1, json_encode($r));
        assert_same(['investigation' => 0, 'art' => 0, 'leaves' => 0], App\Services\Notify\IncidentReminders::run($monday->modify('+1 hour')->setTimezone(new DateTimeZone('UTC'))));
        $titles = array_keys($inbox($st['hys']));
        $num = Incidents::format((int) $new['number']);
        assert_true(in_array("Investigación pendiente · {$num}", $titles, true) && in_array("Falta el N° de siniestro ART · {$num}", $titles, true));
        assert_true((bool) array_filter($titles, fn ($t) => str_starts_with($t, 'Bajas abiertas: ')));
        assert_true(!array_filter(array_keys($inbox($st['sup'])), fn ($t) => str_starts_with($t, 'Bajas abiertas')), 'el resumen de bajas solo a SyH');
    },

    'índices: frecuencia, gravedad e incidencia con las horas cargadas' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $tz = new DateTimeZone('America/Argentina/Buenos_Aires');
        $month = (new DateTimeImmutable('now', $tz))->modify('-5 days')->format('Y-m'); // mes del accidente con baja del test anterior
        $year = (int) substr($month, 0, 4);
        $site = (int) Sectors::findById($st['nave1'])['site_id'];
        App\Models\WorkedHours::save($site, $month, 20000.0, 120, null);
        $ind = App\Services\IncidentIndicators::year($year);
        $m = $ind['months'][$month];
        assert_true($m['accidents'] >= 1 && $m['hours'] === 20000.0 && $m['headcount'] === 120);
        assert_same(round($m['accidents'] * 1_000_000 / 20000, 2), $m['if']);
        assert_same(round($m['lost_days'] * 1000 / 20000, 3), $m['ig']);
        assert_same(round($m['accidents'] * 1000 / 120, 2), $m['ii']);
        assert_true($m['lost_days'] >= 4 && $m['provisional'], 'baja abierta: días provisorios');
        App\Models\WorkedHours::save($site, $month, null, null, null);
        assert_same(0.0, App\Services\IncidentIndicators::year($year)['months'][$month]['hours'], 'vaciar la celda la borra');
    },

    'HTTP: investigación, horas (formato argentino) y CSV' => function () use ($setup, &$st) {
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
            [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
            parse_str($q, $query);
            $router = new \App\Core\Router();
            (require BASE_PATH . '/app/routes.php')($router);
            return $router->dispatch(new \App\Core\Request($method, $p, $query, $post));
        };
        $show = $call($st['hys'], '/panel/incidentes/' . $st['acc']['uuid']);
        assert_true($show->status === 200 && str_contains($show->body, 'Faltan talles en el pañol') && str_contains($show->body, 'investigationEditor'), 'editor de la investigación');
        $print = $call($st['sup'], '/panel/incidentes/' . $st['acc']['uuid'] . '/imprimir');
        assert_true(str_contains($print->body, 'Árbol de causas') && str_contains($print->body, 'Comprar guantes'));
        assert_same(200, $call($st['sup'], '/panel/incidentes/horas')->status);
        Tenant::activate($st['a']);
        $site = App\Models\Sites::findById((int) Sectors::findById($st['nave1'])['site_id']);
        $year = (int) date('Y');
        $res = $call($st['hys'], '/panel/incidentes/horas', 'POST', ['anio' => $year, 'h' => [$site['uuid'] => [$year . '-01' => ['hours' => '12.345,5', 'headcount' => '80']]]]);
        assert_same(302, $res->status);
        Tenant::activate($st['a']);
        assert_same(['hours' => 12345.5, 'headcount' => 80], App\Models\WorkedHours::forYear($year)[(int) $site['id']][$year . '-01']);
        assert_same(302, $call($st['sup'], '/panel/incidentes/horas', 'POST', ['anio' => $year])->status, 'el supervisor tiene "editar" en incidentes');
        assert_same(403, $call($st['rep'], '/panel/incidentes/horas', 'POST', ['anio' => $year])->status, 'el operario no carga horas');
        $csv = $call($st['hys'], '/panel/incidentes/exportar');
        assert_true(str_contains($csv->body, 'Número;Fecha;Tipo') && str_contains($csv->body, 'Días perdidos'));
        $_SESSION = [];
    },

    'app de campo: reporte offline por sync con foto por partes, solo lo propio y sin datos de salud' => function () use ($setup, &$st, $inbox) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep2']);
        $uuid = Uuid::v4();
        $data = ['uuid' => $uuid, 'type' => 'accidente_con_baja', 'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 1800), 'sector' => Sectors::findById($st['nave2'])['uuid'],
            'description' => 'Se golpeó la rodilla al bajar de la plataforma', 'immediate_actions' => 'Hielo y traslado',
            'people' => [['employee' => $st['emp2']['uuid'], 'role' => 'lesionado', 'injury_description' => 'Golpe en la rodilla derecha']]];
        $op = fn () => ['op_id' => Uuid::v4(), 'type' => 'incident.create', 'data' => $data];
        $r = App\Services\Sync\Push::run([$op()])[0];
        assert_same('ok', $r['status'], json_encode($r));
        assert_true(str_starts_with($r['data']['code'], 'INC-'));
        assert_same(true, App\Services\Sync\Push::run([$op()])[0]['data']['duplicate'], 'reenvío con otro op_id: no duplica');
        $bad = App\Services\Sync\Push::run([['op_id' => Uuid::v4(), 'type' => 'incident.create', 'data' => ['uuid' => Uuid::v4(), 'type' => 'accidente_con_baja',
            'description' => 'corto', 'people' => []]]])[0];
        assert_same('error', $bad['status']);
        $title = '⚠ Accidente con baja · ' . $r['data']['code'];
        assert_true(isset($inbox($st['sup2'])[$title]), 'aviso crítico al supervisor de la nave 2');
        // Foto por partes
        $img = imagecreatetruecolor(200, 150);
        $tmp = tempnam(sys_get_temp_dir(), 'ic');
        imagejpeg($img, $tmp, 80);
        $bytes = file_get_contents($tmp);
        $up = Uuid::v4();
        $init = App\Services\Uploads::init(['upload_uuid' => $up, 'incident_uuid' => $uuid, 'name' => 'lugar.jpg', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        assert_same('receiving', $init['status'] ?? null, json_encode($init));
        App\Services\Uploads::chunk($up, 0, $bytes);
        assert_same('completed', App\Services\Uploads::complete($up)['status'] ?? null);
        $i = Incidents::findByUuid($uuid);
        assert_same(1, count(Incidents::attachments((int) $i['id'], false)));
        UserAuth::setCurrent($st['rep']);
        assert_same(404, App\Services\Uploads::init(['upload_uuid' => Uuid::v4(), 'incident_uuid' => $uuid, 'size' => 10, 'sha256' => str_repeat('a', 64)])['code'] ?? null,
            'otro no sube fotos a un incidente ajeno');
        // Pull: solo los míos
        DB::tenant()->exec('UPDATE incidents SET updated_at = updated_at - INTERVAL 60 SECOND');
        $mine = function (array $u) {
            UserAuth::setCurrent($u);
            return array_column((array) (App\Services\Sync\Pull::run(null, 1000)['changes']->incidents ?? []), null, 'uuid');
        };
        $rep2 = $mine($st['rep2']);
        assert_true(isset($rep2[$uuid]) && count($rep2) === 1);
        assert_true(!isset($rep2[$uuid]['injury_description']) && !str_contains(json_encode($rep2[$uuid], JSON_UNESCAPED_UNICODE), 'rodilla derecha'), 'sin datos de salud');
        assert_true(!isset($mine($st['hys'])[$uuid]), 'al celular de SyH no le llegan los ajenos (se gestionan en la web)');
        // Detalle por API: sin datos de salud, aunque el que mira sea SyH
        UserAuth::setCurrent($st['hys']);
        $api = json_decode((new App\Controllers\Api\IncidentsController())->show(new App\Core\Request('GET', '/x'), $uuid)->body, true)['data'];
        assert_same(['Accidente con baja', 'Lesionado'], [$api['type_label'], $api['people'][0]['role']]);
        assert_true(!str_contains(json_encode($api, JSON_UNESCAPED_UNICODE), 'rodilla derecha'));
        UserAuth::setCurrent($st['sup']);
        assert_same(404, (new App\Controllers\Api\IncidentsController())->show(new App\Core\Request('GET', '/x'), $uuid)->status, 'fuera de su alcance');
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
