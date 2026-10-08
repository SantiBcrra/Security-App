<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Storage;
use App\Core\Tenant;
use App\Core\View;
use App\Models\ActionAttachments;
use App\Models\ActionEvents;
use App\Models\Actions;
use App\Models\CatalogItems;
use App\Models\ObservationEvents;
use App\Models\Observations;
use App\Models\PlatformSettings;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\Tenants;
use App\Models\Users;
use App\Models\UserSectors;
use App\Policies\Permissions;
use App\Services\ActionService;
use App\Services\ActionWorkflow;
use App\Services\IndustryTemplates;
use App\Services\Notify\Channels;
use App\Services\Notify\MailTransport;
use App\Services\ObservationService;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Etapa 10 (entrega 1): acciones CAPA. Maestra `securityapp_test_capa` + empresas ca-a y ca-b.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_capa';
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
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Ca A', 'slug' => 'ca-a'] + $company, null));
    $st['b'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Ca B', 'slug' => 'ca-b'] + $company, null));
    MailTransport::$fake = fn () => null;
    Channels::$fakeExternal = fn () => null;

    Tenant::activate($st['a']);
    IndustryTemplates::apply('metalurgica');
    $site = Sites::create(['name' => 'Planta']);
    $st['nave1'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 1']);
    $st['nave2'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 2']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    $st['hys'] = $mk('responsable_hys', 'hys@ca.test');
    $st['hys2'] = $mk('responsable_hys', 'hys2@ca.test');
    $st['sup'] = $mk('supervisor', 'sup@ca.test');
    $st['rep'] = $mk('reportante', 'rep@ca.test');
    $st['rep2'] = $mk('reportante', 'rep2@ca.test');
    UserSectors::replace((int) $st['sup']['id'], [$st['nave1']]);
    $st['ready'] = true;
    file_put_contents(Storage::path('installed.lock'), 'test');
};

$photo = function (): array {
    $img = imagecreatetruecolor(320, 240);
    imagefilledrectangle($img, 20, 20, 200, 200, imagecolorallocate($img, 200, 30, 30));
    $tmp = tempnam(sys_get_temp_dir(), 'ev');
    imagejpeg($img, $tmp, 85);
    return ['tmp' => $tmp, 'name' => 'evidencia.jpg', 'upload' => false];
};

/** Alta como $user; responsable $resp. */
$newAction = function (array $user, array $resp, ?int $sectorId = null, array $extra = []) use (&$st): array {
    Tenant::activate($st['a']);
    UserAuth::setCurrent($user);
    [$data, $errors] = ActionService::validate($extra + [
        'title' => 'Instalar guarda en amoladora', 'responsible' => $resp['uuid'], 'priority' => 'alta',
        'due_on' => (new DateTimeImmutable(ActionService::today()))->modify('+7 days')->format('Y-m-d'),
        'sector' => $sectorId ? Sectors::findById($sectorId)['uuid'] : '',
    ]);
    assert_same([], $errors);
    return ActionService::create($data, 'manual', null)['action'];
};

$report = function (array $user, int $sectorId) use (&$st): array {
    Tenant::activate($st['a']);
    UserAuth::setCurrent($user);
    return ObservationService::create([
        'category_id' => (int) CatalogItems::findByName('categoria', 'Condición insegura')['id'], 'risk_type_id' => null,
        'severity_id' => (int) CatalogItems::findByName('severidad', 'Alta')['id'], 'site_id' => (int) Sectors::findById($sectorId)['site_id'],
        'sector_id' => $sectorId, 'equipment_id' => null, 'description' => 'Amoladora sin guarda en el puesto 3', 'location_text' => null,
        'lat' => null, 'lng' => null, 'gps_accuracy_m' => null, 'imminent_risk' => 0, 'is_anonymous' => 0, 'created_at_device' => gmdate('Y-m-d H:i:s'),
    ], [], [])['observation'];
};

$fresh = fn (array $a) => Actions::findById((int) $a['id']);

return [
    'permisos: "verificar" existe solo en acciones y los roles base lo tienen' => function () {
        assert_same(['ver', 'crear', 'editar', 'cerrar', 'exportar', 'verificar'], Permissions::actionsFor('acciones'));
        assert_true(!in_array('verificar', Permissions::actionsFor('observaciones'), true));
        $clean = Permissions::normalize(['observaciones' => ['acciones' => ['ver', 'verificar']], 'acciones' => ['acciones' => ['verificar']]]);
        assert_same(['ver'], $clean['observaciones']['acciones'], 'se descarta donde no corresponde');
        assert_same(['ver', 'verificar'], $clean['acciones']['acciones']);
        $roles = Permissions::baseRoles();
        assert_true(in_array('verificar', $roles['responsable_hys']['permissions']['acciones']['acciones'], true));
        assert_true(!in_array('verificar', $roles['supervisor']['permissions']['acciones']['acciones'], true));
        assert_same(['ver'], $roles['reportante']['permissions']['acciones']['acciones'], 'cierra las suyas por ser responsable, no por permiso');
        assert_true(!isset($roles['responsable_hys']['permissions']['observaciones']['acciones']) || !in_array('verificar', $roles['responsable_hys']['permissions']['observaciones']['acciones'], true));
    },

    'alta: numeración ACC correlativa, validación y fecha no pasada' => function () use ($setup, &$st, $newAction) {
        $setup();
        $a1 = $newAction($st['hys'], $st['rep'], $st['nave1']);
        $a2 = $newAction($st['hys'], $st['rep'], $st['nave2']);
        assert_same((int) $a1['number'] + 1, (int) $a2['number']);
        assert_same('ACC-000001', Actions::format(1));
        assert_same('abierta', $a1['status']);
        assert_same((int) $st['nave1'], (int) $a1['sector_id']);
        assert_same((int) Sectors::findById($st['nave1'])['site_id'], (int) $a1['site_id'], 'la planta sale del sector');
        [, $errors] = ActionService::validate(['title' => 'x', 'responsible' => 'nadie', 'due_on' => '2000-01-01']);
        assert_same(['title', 'responsible', 'due_on'], array_keys($errors));
        assert_same('created', ActionEvents::forAction((int) $a1['id'])[0]['type']);
        $st['a1'] = $a1;
        $st['a2'] = $a2;
    },

    'alcance: todo, sectores, propios y el responsable siempre ve la suya' => function () use ($setup, &$st, $newAction) {
        $setup();
        $other = $newAction($st['hys'], $st['hys2'], $st['nave2']); // ni del supervisor ni del reportante
        $count = function (array $user) {
            UserAuth::setCurrent($user);
            return Actions::count([], ActionService::scope());
        };
        assert_same(3, $count($st['hys']), 'SyH ve todas');
        assert_same(1, $count($st['sup']), 'supervisor: solo la de su nave');
        UserAuth::setCurrent($st['sup']);
        assert_true(ActionService::canView($st['a1']) && !ActionService::canView($other));
        assert_same(2, $count($st['rep']), 'reportante: las dos donde es responsable');
        assert_same(0, $count($st['rep2']));
        UserAuth::setCurrent($st['rep2']);
        assert_true(!ActionService::canView($st['a1']));
        $st['other'] = $other;
    },

    'flujo: tomar, cerrar exige evidencia, otro verifica' => function () use ($setup, &$st, $photo, $fresh) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        $a = $st['a1'];
        assert_same(null, ActionService::transition($a, 'tomar', []), 'el responsable puede tomarla');
        $a = $fresh($a);
        assert_same('en_curso', $a['status']);
        assert_true(str_contains((string) ActionService::transition($a, 'cerrar', ['closure_text' => 'corto']), 'mínimo 10'));
        assert_true(str_contains((string) ActionService::transition($a, 'cerrar', ['closure_text' => 'Se instaló la guarda original']), 'evidencia'));
        assert_same(null, ActionService::transition($a, 'cerrar', ['closure_text' => 'Se instaló la guarda original'], [$photo()]));
        $a = $fresh($a);
        assert_same('cerrada', $a['status']);
        assert_same((int) $st['rep']['id'], (int) $a['closed_by']);
        assert_true($a['verify_due_on'] !== null);
        assert_same(1, ActionAttachments::countEvidence((int) $a['id'], 1));
        assert_true(ActionService::transition($a, 'verificar', []) !== null, 'el reportante no verifica (ni tiene permiso ni puede verificar su cierre)');
        UserAuth::setCurrent($st['hys']);
        assert_same(null, ActionService::transition($a, 'verificar', ['comment' => 'Controlado en planta']));
        $a = $fresh($a);
        assert_same(['verificada', 1], [$a['status'], (int) $a['effective']]);
        assert_true(str_contains((string) ActionService::transition($a, 'cancelar', ['comment' => 'ya no aplica']), 'ya está'), 'estado final');
    },

    'flujo: quien cierra no verifica; rechazar abre otra vuelta y conserva la evidencia' => function () use ($setup, &$st, $photo, $fresh) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']); // SyH es responsable de "other" y la cierra él mismo
        $a = $st['other'];
        UserAuth::setCurrent($st['hys2']);
        assert_same(null, ActionService::transition($a, 'cerrar', ['closure_text' => 'Se señalizó el sector completo'], [$photo()]));
        $a = $fresh($a);
        assert_true(str_contains((string) ActionService::transition($a, 'verificar', []), 'otra persona'));
        UserAuth::setCurrent($st['hys']);
        assert_true(str_contains((string) ActionService::transition($a, 'rechazar', []), 'motivo'));
        $newDue = (new DateTimeImmutable(ActionService::today()))->modify('+3 days')->format('Y-m-d');
        assert_same(null, ActionService::transition($a, 'rechazar', ['comment' => 'La señal no se ve de noche', 'due_on' => $newDue]));
        $a = $fresh($a);
        assert_same(['en_curso', 2, $newDue, null], [$a['status'], (int) $a['cycle'], $a['due_on'], $a['closed_at']]);
        assert_same(1, count(ActionAttachments::forAction((int) $a['id'])), 'la evidencia del intento 1 sigue guardada');
        UserAuth::setCurrent($st['hys2']);
        assert_true(str_contains((string) ActionService::transition($a, 'cerrar', ['closure_text' => 'Se agregó cartel reflectivo']), 'evidencia'),
            'el segundo cierre necesita evidencia nueva');
        assert_same(null, ActionService::transition($a, 'cerrar', ['closure_text' => 'Se agregó cartel reflectivo'], [$photo()]));
        UserAuth::setCurrent($st['hys']);
        assert_same(null, ActionService::transition($fresh($a), 'verificar', []));
        $types = array_column(ActionEvents::forAction((int) $a['id']), 'type');
        assert_same(['created', 'evidence', 'closed', 'rejected', 'evidence', 'closed', 'verified'], $types);
    },

    'cancelar pide permiso y motivo; editar fecha o responsable pide motivo' => function () use ($setup, &$st, $fresh) {
        $setup();
        Tenant::activate($st['a']);
        $a = $st['a2'];
        UserAuth::setCurrent($st['rep']);
        assert_true(str_contains((string) ActionService::transition($a, 'cancelar', ['comment' => 'no corresponde']), 'permiso'), 'el responsable no la cancela');
        assert_true(ActionService::update($a, ['due_on' => '2099-01-01']) !== null, 'el reportante no edita');
        UserAuth::setCurrent($st['hys']);
        $in = ['title' => $a['title'], 'responsible' => $st['rep2']['uuid'], 'due_on' => $a['due_on'], 'priority' => 'critica', 'type' => 'correctiva'];
        assert_true(str_contains((string) ActionService::update($a, $in), 'motivo'));
        assert_same(null, ActionService::update($a, $in + ['comment' => 'Rep se fue de vacaciones']));
        $a = $fresh($a);
        assert_same([(int) $st['rep2']['id'], 'critica'], [(int) $a['responsible_user_id'], $a['priority']]);
        $ev = array_values(array_filter(ActionEvents::forAction((int) $a['id']), fn ($e) => $e['type'] === 'updated'))[0];
        assert_same(['Prioridad', 'Responsable'], array_keys(json_decode($ev['data'], true)['despues']));
        assert_same(null, ActionService::transition($a, 'cancelar', ['comment' => 'Se reemplaza por otra acción']));
        assert_same('cancelada', $fresh($a)['status']);
    },

    'observación: asignar crea acciones, resumen vigente y cierre automático' => function () use ($setup, &$st, $report, $photo, $fresh) {
        $setup();
        $obs = $report($st['rep'], $st['nave1']);
        UserAuth::setCurrent($st['hys']);
        $in = fn (string $text, string $resp, int $days) => ['assigned_user' => $resp, 'action_text' => $text, 'priority' => 'alta',
            'action_due_on' => (new DateTimeImmutable(ActionService::today()))->modify("+{$days} days")->format('Y-m-d')];
        assert_same(null, ObservationService::transition($obs, 'asignar', $in('Colocar guarda', $st['rep']['uuid'], 10)));
        $obs = Observations::findById((int) $obs['id']);
        assert_same(null, ObservationService::transition($obs, 'asignar', $in('Capacitar al turno', $st['sup']['uuid'], 5)));
        $actions = Actions::forOrigin('observacion', (int) $obs['id']);
        assert_same(2, count($actions));
        assert_same([(int) $st['nave1'], 'observacion'], [(int) $actions[0]['sector_id'], $actions[0]['origin_type']], 'hereda el sector');
        $obs = Observations::findById((int) $obs['id']);
        assert_same(['accion_asignada', (int) $st['sup']['id'], 'Capacitar al turno'], [$obs['status'], (int) $obs['assigned_user_id'], $obs['action_text']],
            'el resumen muestra la que vence primero');
        assert_true(str_contains((string) json_decode(array_values(array_filter(ObservationEvents::forObservation((int) $obs['id']),
            fn ($e) => $e['type'] === 'assignment'))[0]['data'], true)['numero'], 'ACC-'));

        // Se cierran y verifican las dos → la observación se cierra sola
        foreach ($actions as $act) {
            UserAuth::setCurrent(Users::findById((int) $act['responsible_user_id']));
            assert_same(null, ActionService::transition($fresh($act), 'cerrar', ['closure_text' => 'Hecho y controlado en el lugar'], [$photo()]));
        }
        assert_same(['accion_asignada', null], [Observations::findById((int) $obs['id'])['status'], Observations::findById((int) $obs['id'])['assigned_user_id']],
            'cerradas a verificar: la observación sigue abierta y sin acción vigente');
        UserAuth::setCurrent($st['hys']);
        ActionService::transition($fresh($actions[0]), 'verificar', []);
        assert_same('accion_asignada', Observations::findById((int) $obs['id'])['status'], 'falta una');
        ActionService::transition($fresh($actions[1]), 'verificar', []);
        $obs = Observations::findById((int) $obs['id']);
        assert_same('cerrada', $obs['status']);
        $last = array_values(array_slice(ObservationEvents::forObservation((int) $obs['id']), -1))[0];
        assert_same(['Sistema', 'cerrada'], [$last['actor_name'], $last['to_status']]);
    },

    'migración: las acciones viejas de observaciones pasan a CAPA (idempotente)' => function () use ($setup, &$st, $report) {
        $setup();
        $open = $report($st['rep'], $st['nave1']);
        $closed = $report($st['rep'], $st['nave2']);
        $legacy = ['assigned_user_id' => (int) $st['rep2']['id'], 'action_text' => 'Acción cargada antes de la Etapa 10', 'action_due_on' => '2026-01-15'];
        Observations::update((int) $open['id'], $legacy + ['status' => 'accion_asignada']);
        Observations::update((int) $closed['id'], $legacy + ['status' => 'cerrada', 'closed_at' => '2026-01-20 12:00:00']);
        $migrate = require BASE_PATH . '/database/migrations/tenant/0040_migrate_observation_actions.php';
        $before = Actions::count([], null);
        $migrate(DB::tenant());
        $migrate(DB::tenant());
        assert_same($before + 2, Actions::count([], null), 'dos acciones, una sola vez');
        $a = Actions::forOrigin('observacion', (int) $open['id'])[0];
        assert_same(['abierta', (int) $st['rep2']['id'], '2026-01-15'], [$a['status'], (int) $a['responsible_user_id'], $a['due_on']]);
        $c = Actions::forOrigin('observacion', (int) $closed['id'])[0];
        assert_same(['verificada', 1], [$c['status'], (int) $c['effective']]);
        assert_same('migrated', ActionEvents::forAction((int) $c['id'])[0]['type']);
        UserAuth::setCurrent($st['hys']);
        assert_true(Actions::count(['overdue' => ActionService::today()], null) >= 1, 'la abierta con fecha pasada figura vencida');
    },

    'panel: lista y detalle se renderizan' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $rows = Actions::search([], null, 100);
        $html = View::render('panel/actions/index', ['rows' => $rows, 'total' => count($rows), 'byStatus' => Actions::countByStatus([], null, ActionService::today()),
            'form' => ['estado' => '', 'mias' => '', 'responsable' => '', 'sector' => '', 'origen' => '', 'prioridad' => '', 'q' => ''],
            'page' => 1, 'perPage' => 50, 'today' => ActionService::today(), 'users' => ActionService::assignableUsers(), 'sectors' => Sectors::options()], null);
        assert_true(str_contains($html, 'ACC-000001') && str_contains($html, 'Vencidas'));
        $a = Actions::findByUuid($st['other']['uuid']);
        $html = View::render('panel/actions/show', ['a' => $a, 'origin' => ['label' => 'Manual', 'url' => null], 'events' => ActionEvents::forAction((int) $a['id']),
            'files' => ActionAttachments::forAction((int) $a['id']), 'transitions' => ActionWorkflow::available($a), 'canEdit' => false, 'canEvidence' => false,
            'users' => [], 'today' => ActionService::today(), 'old' => [], 'errors' => []], null);
        assert_true(str_contains($html, 'Evidencia del intento 1 (cierre rechazado)') && str_contains($html, 'Rechazada: no fue eficaz'));
    },

    'HTTP: páginas del panel con sesión, layout y permisos' => function () use ($setup, &$st) {
        $setup();
        $call = function (array $user, string $method, string $path, array $post = [], bool $csrf = true) use (&$st) {
            UserAuth::setCurrent(null);
            Tenant::deactivate();
            $_SESSION = [];
            \App\Core\Session::put(\App\Services\Impersonation::TENANT_KEY, $st['a']['uuid']);
            \App\Core\Session::put(UserAuth::USER_KEY, $user['uuid']);
            if ($method === 'POST' && $csrf) {
                $post['csrf_token'] = csrf_token();
            }
            $router = new \App\Core\Router();
            (require BASE_PATH . '/app/routes.php')($router);
            return $router->dispatch(new \App\Core\Request($method, $path, [], $post));
        };
        $list = $call($st['hys'], 'GET', '/panel/acciones');
        assert_same(200, $list->status);
        assert_true(str_contains($list->body, '>Acciones</a>') || str_contains($list->body, 'Acciones</a>'), 'aparece en el menú');
        Tenant::activate($st['a']);
        $a = Actions::findByUuid($st['other']['uuid']);
        $show = $call($st['hys'], 'GET', '/panel/acciones/' . $a['uuid']);
        assert_same(200, $show->status, substr($show->body, 0, 300));
        assert_same(200, $call($st['hys'], 'GET', '/panel/acciones/nueva')->status);
        assert_same(200, $call($st['hys'], 'GET', '/panel/acciones/tablero')->status, 'la ruta del tablero no la toma {uuid}');
        $csv = $call($st['hys'], 'GET', '/panel/acciones/exportar');
        assert_same([200, 'text/csv; charset=utf-8'], [$csv->status, $csv->headers['Content-Type']]);
        assert_same(403, $call($st['rep'], 'GET', '/panel/acciones/exportar')->status);
        assert_same(200, $call($st['hys'], 'GET', '/panel/acciones/' . $st['other']['uuid'] . '/imprimir')->status);
        $home = $call($st['rep'], 'GET', '/panel');
        assert_true(str_contains($home->body, 'Mis acciones pendientes') || !Actions::count(['status' => Actions::OPEN], ['responsible' => (int) $st['rep']['id']]));
        assert_same(403, $call($st['rep'], 'GET', '/panel/acciones/nueva')->status, 'el reportante no crea acciones sueltas');
        assert_same(404, $call($st['rep2'], 'GET', '/panel/acciones/' . $a['uuid'])->status, 'fuera de su alcance');
        Tenant::activate($st['a']);
        $obs = Observations::findById((int) Actions::search(['origin_type' => 'observacion'], null, 1)[0]['origin_id']);
        $page = $call($st['hys'], 'GET', '/panel/observaciones/' . $obs['uuid']);
        assert_same(200, $page->status);
        assert_true(str_contains($page->body, 'ACC-'), 'la observación lista sus acciones');
        $call($st['hys'], 'POST', '/panel/acciones/' . $a['uuid'] . '/comentario', ['comment' => 'Sin token CSRF'], false);
        Tenant::activate($st['a']);
        assert_true(!in_array('Sin token CSRF', array_column(ActionEvents::forAction((int) $a['id']), 'comment'), true), 'sin CSRF no se guarda');
        $res = $call($st['hys'], 'POST', '/panel/acciones/' . $a['uuid'] . '/comentario', ['comment' => 'Revisado en la recorrida']);
        assert_same(302, $res->status);
        Tenant::activate($st['a']);
        assert_same('comment', array_values(array_slice(ActionEvents::forAction((int) $a['id']), -1))[0]['type']);
        $_SESSION = [];
    },

    'avisos: asignar, cerrar (a quienes verifican) y rechazar' => function () use ($setup, &$st, $newAction, $photo, $fresh) {
        $setup();
        $inbox = fn (array $u) => array_column(DB::tenant()->query('SELECT title FROM notifications WHERE user_id = ' . (int) $u['id'] . ' ORDER BY id')->fetchAll(), 'title');
        $a = $newAction($st['hys'], $st['sup'], $st['nave1']);
        $num = Actions::format((int) $a['number']);
        assert_true(in_array("Te asignaron una acción · {$num}", $inbox($st['sup']), true), 'el responsable recibe la asignación');
        assert_true(!in_array("Te asignaron una acción · {$num}", $inbox($st['hys']), true), 'quien la creó no se avisa a sí mismo');
        UserAuth::setCurrent($st['sup']);
        ActionService::transition($a, 'cerrar', ['closure_text' => 'Se reparó la baranda del entrepiso'], [$photo()]);
        assert_true(in_array("Acción para verificar · {$num}", $inbox($st['hys2']), true), 'les llega a quienes verifican');
        assert_true(!in_array("Acción para verificar · {$num}", $inbox($st['sup']), true), 'el supervisor no verifica');
        UserAuth::setCurrent($st['hys2']);
        ActionService::transition($fresh($a), 'rechazar', ['comment' => 'La baranda sigue floja']);
        assert_true(in_array("Cierre rechazado · {$num}", $inbox($st['sup']), true));
        // Reasignar avisa al nuevo responsable
        UserAuth::setCurrent($st['hys']);
        $cur = $fresh($a);
        ActionService::update(Actions::findByUuid($cur['uuid']), ['title' => $cur['title'], 'responsible' => $st['rep2']['uuid'], 'due_on' => $cur['due_on'],
            'priority' => $cur['priority'], 'type' => $cur['type'], 'comment' => 'Pasa a mantenimiento']);
        assert_true(in_array("Te asignaron una acción · {$num}", $inbox($st['rep2']), true));
        $st['notified'] = $a;
    },

    'recordatorios: por vencer, vencida una vez por día, escalamiento y verificación atrasada' => function () use ($setup, &$st, $newAction, $fresh, $photo) {
        $setup();
        $inbox = fn (array $u, string $prefix) => count(array_filter(array_column(DB::tenant()->query('SELECT title FROM notifications WHERE user_id = '
            . (int) $u['id'])->fetchAll(), 'title'), fn ($t) => str_starts_with($t, $prefix)));
        $today = ActionService::today();
        $soon = $newAction($st['hys'], $st['rep'], $st['nave1'], ['due_on' => (new DateTimeImmutable($today))->modify('+2 days')->format('Y-m-d')]);
        $late = $newAction($st['hys'], $st['rep'], $st['nave1']);
        DB::tenant()->prepare('UPDATE actions SET due_on = ? WHERE id = ?')->execute([(new DateTimeImmutable($today))->modify('-4 days')->format('Y-m-d'), (int) $late['id']]);
        UserAuth::setCurrent(null);
        $r1 = \App\Services\Notify\ActionReminders::run($today);
        assert_true($r1['due_soon'] >= 1 && $r1['overdue'] >= 1 && $r1['escalated'] >= 1, json_encode($r1));
        $r2 = \App\Services\Notify\ActionReminders::run($today);
        assert_same(['due_soon' => 0, 'overdue' => 0, 'escalated' => 0, 'verify_overdue' => 0], $r2, 'el mismo día no se repite nada');
        $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $r3 = \App\Services\Notify\ActionReminders::run($tomorrow);
        assert_true($r3['overdue'] >= 1 && $r3['escalated'] === 0, 'al otro día: vencida otra vez, escalamiento no');
        assert_same(1, $inbox($st['rep'], 'Acción por vencer · ' . Actions::format((int) $soon['number'])), 'por vencer, una vez');
        assert_same(2, $inbox($st['rep'], 'Acción vencida · ' . Actions::format((int) $late['number'])));
        assert_same(1, $inbox($st['sup'], 'Acción vencida hace 4 días · ' . Actions::format((int) $late['number'])), 'escala al supervisor del sector');
        assert_same(1, $inbox($st['hys2'], 'Acción vencida hace 4 días'), 'y a SyH');
        // Verificación atrasada: se cierra una y se vence su plazo de verificación
        UserAuth::setCurrent($st['rep']);
        assert_same(null, ActionService::transition($fresh($soon), 'cerrar', ['closure_text' => 'Se ordenó el depósito completo'], [$photo()]));
        UserAuth::setCurrent(null);
        DB::tenant()->prepare("UPDATE actions SET verify_due_on = '2020-01-01' WHERE id = ?")->execute([(int) $soon['id']]);
        $r4 = \App\Services\Notify\ActionReminders::run($tomorrow);
        assert_true($r4['verify_overdue'] >= 1, 'verificación atrasada ' . json_encode($r4) . ' cerradas: ' . Actions::count(['status' => 'cerrada'], null));
    },

    'tablero y CSV: vencidas por sector y responsable, con alcance' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $today = ActionService::today();
        UserAuth::setCurrent($st['hys']);
        $b = Actions::board(null, $today);
        $late = array_sum(array_column($b['bySector'], 'late'));
        assert_same(Actions::count(['overdue' => $today], null), $late, 'la suma por sector coincide con la lista de vencidas');
        assert_same($late, array_sum(array_column($b['byResponsible'], 'late')));
        $rep = array_values(array_filter($b['byResponsible'], fn ($r) => $r['uuid'] === $st['rep']['uuid']))[0];
        assert_true((int) $rep['max_late'] >= 4);
        UserAuth::setCurrent($st['sup']);
        $mine = Actions::board(ActionService::scope(), $today);
        assert_same([(int) $st['nave1']], array_values(array_unique(array_map(fn ($r) => (int) $r['k'], array_filter($mine['bySector'], fn ($r) => $r['k'] !== null)))),
            'el supervisor solo ve su nave en el tablero');
        UserAuth::setCurrent($st['hys']);
        $csv = ActionService::csv(Actions::search(['overdue' => $today], null, 100), $today);
        assert_true(str_starts_with($csv, "\xEF\xBB\xBF") && str_contains($csv, 'Número;Acción;Estado;Vencida'));
        assert_true(str_contains($csv, ';Sí;'), 'marca las vencidas');
        $html = View::render('panel/actions/board', ['b' => $b, 'sectors' => Sectors::labelMap(), 'today' => $today], null);
        assert_true(str_contains($html, 'Por sector') && str_contains($html, 'Nave 1'));
    },

    'reglas: migración de eventos viejos y reglas nuevas (idempotente)' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $events = fn () => DB::tenant()->query('SELECT event, COUNT(*) n FROM notification_rules GROUP BY event')->fetchAll(PDO::FETCH_KEY_PAIR);
        $e = $events();
        assert_true(!isset($e['observation.assigned']) && !isset($e['observation.overdue']), 'ya no quedan eventos viejos');
        foreach (['action.assigned', 'action.due_soon', 'action.overdue', 'action.overdue_escalated', 'action.closed', 'action.verify_overdue',
            'action.verified', 'action.rejected', 'action.cancelled'] as $ev) {
            assert_same(1, (int) ($e[$ev] ?? 0), $ev);
        }
        (require BASE_PATH . '/database/migrations/tenant/0041_action_notification_rules.php')(DB::tenant());
        assert_same($e, $events(), 'correrla de nuevo no duplica');
    },

    'app de campo: pull de acciones con alcance y baja al reasignar' => function () use ($setup, &$st, $newAction) {
        $setup();
        $a = $newAction($st['hys'], $st['rep2'], $st['nave2'], ['title' => 'Despejar salida de emergencia']);
        $pull = function (array $user) {
            UserAuth::setCurrent($user);
            $page = \App\Services\Sync\Pull::run(null, 1000);
            return [array_column((array) ($page['changes']->actions ?? []), null, 'uuid'), (array) ($page['deleted']->actions ?? [])];
        };
        [$mine] = $pull($st['rep2']);
        assert_true(isset($mine[$a['uuid']]), 'el responsable la recibe');
        $row = $mine[$a['uuid']];
        assert_same([true, true, true, 0], [$row['mine'], $row['can_start'], $row['can_close'], $row['evidence']]);
        assert_true(!isset($row['responsible_user_id']) && !isset($row['id']), 'sin ids internos');
        [$sup] = $pull($st['sup']);
        assert_true(!isset($sup[$a['uuid']]), 'el supervisor de otra nave no la recibe');
        // Se reasigna: al viejo responsable le llega como baja
        UserAuth::setCurrent($st['hys']);
        ActionService::update(Actions::findByUuid($a['uuid']), ['title' => $a['title'], 'responsible' => $st['rep']['uuid'], 'due_on' => $a['due_on'],
            'priority' => $a['priority'], 'type' => $a['type'], 'comment' => 'Cambio de turno']);
        [, $deleted] = $pull($st['rep2']);
        assert_true(in_array($a['uuid'], $deleted, true), 'le llega como baja');
        $st['mobile'] = Actions::findByUuid($a['uuid']);
    },

    'app de campo: tomar y cerrar offline con foto por partes, reenvíos sin duplicar' => function () use ($setup, &$st, $photo) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        $a = $st['mobile'];
        $op = fn (string $type, array $data) => ['op_id' => \App\Core\Uuid::v4(), 'type' => $type, 'data' => $data];
        $start = $op('action.start', ['uuid' => $a['uuid']]);
        assert_same('en_curso', \App\Services\Sync\Push::run([$start])[0]['data']['status']);
        assert_same(true, \App\Services\Sync\Push::run([$op('action.start', ['uuid' => $a['uuid']])])[0]['data']['duplicate'], 'tomar dos veces no falla');
        $closeNoPhoto = \App\Services\Sync\Push::run([$op('action.close', ['uuid' => $a['uuid'], 'closure_text' => 'Salida despejada y señalizada'])])[0];
        assert_true(str_contains((string) ($closeNoPhoto['error'] ?? ''), 'evidencia'), 'sin foto el servidor no la cierra');
        // Foto por partes como evidencia de la acción
        $jpg = $photo()['tmp'];
        $bytes = file_get_contents($jpg);
        $id = \App\Core\Uuid::v4();
        $init = \App\Services\Uploads::init(['upload_uuid' => $id, 'action_uuid' => $a['uuid'], 'name' => 'salida.jpg', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        assert_same('receiving', $init['status'] ?? null, json_encode($init));
        \App\Services\Uploads::chunk($id, 0, $bytes);
        $done = \App\Services\Uploads::complete($id);
        assert_same('completed', $done['status'] ?? null, json_encode($done));
        assert_same($done, \App\Services\Uploads::complete($id), 'completar dos veces es idempotente');
        assert_same(1, ActionAttachments::countEvidence((int) $a['id'], (int) $a['cycle']));
        $close = $op('action.close', ['uuid' => $a['uuid'], 'closure_text' => 'Salida despejada y señalizada']);
        $r1 = \App\Services\Sync\Push::run([$close])[0];
        assert_same('cerrada', $r1['data']['status'] ?? null, json_encode($r1));
        assert_same($r1, \App\Services\Sync\Push::run([$close])[0], 'mismo op_id: mismo resultado');
        assert_same(true, \App\Services\Sync\Push::run([$op('action.close', ['uuid' => $a['uuid'], 'closure_text' => 'otra vez'])])[0]['data']['duplicate'],
            'reenvío con otro op_id: no falla ni pisa el cierre');
        assert_same('Salida despejada y señalizada', Actions::findByUuid($a['uuid'])['closure_text']);
        $late = \App\Services\Uploads::init(['upload_uuid' => \App\Core\Uuid::v4(), 'action_uuid' => $a['uuid'], 'name' => 'x.jpg', 'size' => 10, 'sha256' => str_repeat('a', 64)]);
        assert_same(404, $late['code'] ?? null, 'a una acción cerrada ya no se le sube evidencia');
        UserAuth::setCurrent($st['rep2']);
        assert_true(str_contains((string) (\App\Services\Sync\Push::run([$op('action.start', ['uuid' => $st['a1']['uuid']])])[0]['error'] ?? ''), 'acceso'),
            'otro usuario no puede tocar una acción que no ve');
    },

    'aislamiento: la empresa B no ve acciones de A' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['b']);
        UserAuth::setCurrent(null);
        assert_same(0, Actions::count([], null));
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
