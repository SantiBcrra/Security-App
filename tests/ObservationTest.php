<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Router;
use App\Core\Storage;
use App\Core\Tenant;
use App\Models\CatalogItems;
use App\Models\ObservationAttachments;
use App\Models\ObservationEvents;
use App\Models\Observations;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\Tenants;
use App\Models\Users;
use App\Models\UserSectors;
use App\Services\Impersonation;
use App\Services\IndustryTemplates;
use App\Services\ObservationService;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Etapa 6: observaciones. Maestra de prueba `securityapp_test_obs` + empresas obs-a y obs-b.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_obs';
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
    $_SESSION = [];
    assert_same(null, Migrator::run(DB::master(), BASE_PATH . Migrator::MASTER_DIR)['error']);
    $company = ['legal_name' => null, 'cuit' => null, 'timezone' => 'America/Argentina/Buenos_Aires', 'status' => 'active', 'plan' => null];
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Obs A', 'slug' => 'obs-a'] + $company, null));
    $st['b'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Obs B', 'slug' => 'obs-b'] + $company, null));

    Tenant::activate($st['a']);
    IndustryTemplates::apply('metalurgica');
    $site = Sites::create(['name' => 'Planta']);
    $st['nave1'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 1']);
    $st['sold'] = Sectors::create(['site_id' => $site, 'parent_id' => $st['nave1'], 'name' => 'Soldadura']);
    $st['nave2'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 2']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst($role), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('x', PASSWORD_DEFAULT)]));
    $st['admin'] = $mk('admin_empresa', 'admin@obs.test');
    $st['hys'] = $mk('responsable_hys', 'hys@obs.test');
    $st['sup'] = $mk('supervisor', 'sup@obs.test');
    $st['rep'] = $mk('reportante', 'rep@obs.test');
    $st['rep2'] = $mk('reportante', 'rep2@obs.test');
    UserSectors::replace((int) $st['sup']['id'], [$st['nave1']]);
    $st['cat'] = CatalogItems::findByName('categoria', 'Condición insegura');
    $st['sevAlta'] = CatalogItems::findByName('severidad', 'Alta');
    $st['sevBaja'] = CatalogItems::findByName('severidad', 'Baja');
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

/** Crea una observación como el usuario dado. */
$report = function (array $user, int $sectorId, array $extra = [], array $photos = []) use (&$st): array {
    Tenant::activate($st['a']);
    UserAuth::setCurrent($user);
    $data = $extra + [
        'category_id' => (int) $st['cat']['id'], 'risk_type_id' => null, 'severity_id' => (int) $st['sevAlta']['id'],
        'site_id' => (int) Sectors::findById($sectorId)['site_id'], 'sector_id' => $sectorId, 'equipment_id' => null,
        'description' => 'Piso con aceite al lado de la plegadora', 'location_text' => null, 'lat' => null, 'lng' => null,
        'gps_accuracy_m' => null, 'imminent_risk' => 0, 'is_anonymous' => 0, 'created_at_device' => gmdate('Y-m-d H:i:s'),
    ];
    return ObservationService::create($data, [], $photos)['observation'];
};

return [
    'numeración correlativa por empresa y formato OBS-000001' => function () use ($setup, &$st, $report) {
        $setup();
        $a = $report($st['rep'], $st['sold']);
        $b = $report($st['rep'], $st['nave2']);
        assert_same((int) $a['number'] + 1, (int) $b['number']);
        assert_same('OBS-000001', Observations::format(1));
        Tenant::activate($st['b']);
        assert_same(0, Observations::count([], null), 'la empresa B no ve observaciones de A');
        $st['obsRepSold'] = $a;
        $st['obsRepNave2'] = $b;
    },

    'reporte original congelado: hash válido y correcciones como evento' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $obs = Observations::findById((int) $st['obsRepSold']['id']);
        assert_same(hash('sha256', $obs['original_data']), $obs['original_hash']);
        $original = $obs['original_data'];
        $err = ObservationService::correct($obs, ['severity_id' => (int) $st['sevBaja']['id'], 'sector_id' => $st['nave2']], 'Se revisó en el lugar: era menor');
        assert_same(null, $err);
        $after = Observations::findById((int) $obs['id']);
        assert_same((int) $st['sevBaja']['id'], (int) $after['severity_id'], 'el valor vigente cambia');
        assert_same($original, $after['original_data'], 'el original no se toca');
        assert_same(hash('sha256', $after['original_data']), $after['original_hash']);
        assert_same('Alta', json_decode($after['original_data'], true)['severidad']);
        $types = array_column(ObservationEvents::forObservation((int) $obs['id']), 'type');
        assert_true(in_array('correction', $types, true));
        assert_same('Se requiere motivo', ObservationService::correct($after, ['severity_id' => (int) $st['sevAlta']['id']], 'x') === null ? 'mal' : 'Se requiere motivo');
        // devolver al sector original para los tests de alcance
        ObservationService::correct($after, ['sector_id' => $st['sold']], 'Vuelve a Soldadura para el test');
    },

    'flujo de estados: transiciones válidas, inválidas y permisos' => function () use ($setup, &$st, $report) {
        $setup();
        $obs = $report($st['rep'], $st['sold']);
        UserAuth::setCurrent($st['rep']);
        assert_same('No tenés permiso para esta acción.', ObservationService::transition($obs, 'analizar', []));
        UserAuth::setCurrent($st['hys']);
        assert_same(null, ObservationService::transition($obs, 'analizar', []));
        $obs = Observations::findById((int) $obs['id']);
        assert_same('en_analisis', $obs['status']);
        assert_true(str_contains((string) ObservationService::transition($obs, 'analizar', []), 'ya está'), 'no se repite');
        assert_same('Elegí el responsable de la acción.', ObservationService::transition($obs, 'asignar', ['action_text' => 'Limpiar', 'action_due_on' => '2030-01-01']));
        assert_same(null, ObservationService::transition($obs, 'asignar', ['assigned_user' => $st['sup']['uuid'], 'action_text' => 'Limpiar y poner bandeja', 'action_due_on' => '2030-01-01']));
        $obs = Observations::findById((int) $obs['id']);
        assert_same('accion_asignada', $obs['status']);
        assert_same((int) $st['sup']['id'], (int) $obs['assigned_user_id']);
        assert_true(str_contains((string) ObservationService::transition($obs, 'descartar', ['comment' => 'no aplica']), 'ya está'));
        assert_true(str_contains((string) ObservationService::transition($obs, 'cerrar', ['comment' => '']), 'comentario'));
        assert_same(null, ObservationService::transition($obs, 'cerrar', ['comment' => 'Se colocó bandeja antigoteo']));
        $obs = Observations::findById((int) $obs['id']);
        assert_same('cerrada', $obs['status']);
        assert_true($obs['closed_at'] !== null);
        assert_same(null, ObservationService::transition($obs, 'reabrir', ['comment' => 'Volvió a pasar']));
        assert_same('en_analisis', Observations::findById((int) $obs['id'])['status']);
        $events = array_column(ObservationEvents::forObservation((int) $obs['id']), 'type');
        assert_same(['created', 'status', 'assignment', 'status', 'status'], $events);
    },

    'alcance: reportante ve lo suyo, supervisor sus sectores, admin todo' => function () use ($setup, &$st, $report) {
        $setup();
        $other = $report($st['rep2'], $st['sold']);
        Tenant::activate($st['a']);
        $count = function (array $user) {
            UserAuth::setCurrent($user);
            return Observations::count([], ObservationService::scope());
        };
        $all = $count($st['admin']);
        assert_true($all >= 4);
        UserAuth::setCurrent($st['rep']);
        assert_true(!ObservationService::canView($other), 'un reportante no ve el reporte de otro');
        assert_true(ObservationService::canView($st['obsRepSold']));
        assert_same($all - 1, $count($st['rep']), 'rep ve todo menos el de rep2');
        UserAuth::setCurrent($st['sup']);
        assert_true(ObservationService::canView($other), 'Soldadura está debajo de Nave 1');
        assert_true(!ObservationService::canView($st['obsRepNave2']), 'Nave 2 no es suya');
        UserAuth::setCurrent($st['hys']);
        assert_same($all, $count($st['hys']));
    },

    'anónimo: no guarda autor ni lo audita' => function () use ($setup, &$st, $report) {
        $setup();
        $obs = $report($st['rep'], $st['sold'], ['is_anonymous' => 1]);
        assert_same(null, $obs['reporter_user_id']);
        $events = ObservationEvents::forObservation((int) $obs['id']);
        assert_same('Anónimo', $events[0]['actor_name']);
        assert_same(null, $events[0]['user_id']);
        assert_same(true, json_decode($obs['original_data'], true)['anonimo']);
        assert_same(null, json_decode($obs['original_data'], true)['reportado_por']);
        $audit = DB::tenant()->prepare("SELECT COUNT(*) FROM audit_log WHERE entity_uuid = ?");
        $audit->execute([$obs['uuid']]);
        assert_same(0, (int) $audit->fetchColumn());
        UserAuth::setCurrent($st['rep']);
        assert_true(!ObservationService::canView($obs), 'ni el propio autor lo puede vincular después');
    },

    'riesgo inminente: queda el evento de alerta' => function () use ($setup, &$st, $report) {
        $setup();
        $obs = $report($st['rep'], $st['sold'], ['imminent_risk' => 1]);
        assert_same(['created', 'imminent_alert'], array_column(ObservationEvents::forObservation((int) $obs['id']), 'type'));
        UserAuth::setCurrent($st['admin']);
        assert_true(Observations::count(['imminent' => 1], null) >= 1);
    },

    'fotos: guarda original con hash, genera miniatura y rechaza archivos falsos' => function () use ($setup, &$st, $report) {
        $setup();
        $jpg = tempnam(sys_get_temp_dir(), 'ph');
        $img = imagecreatetruecolor(1200, 800);
        imagefill($img, 0, 0, imagecolorallocate($img, 200, 50, 50));
        imagejpeg($img, $jpg, 90);
        $fake = tempnam(sys_get_temp_dir(), 'ph');
        file_put_contents($fake, '<?php system($_GET["c"]);');
        $sha = hash_file('sha256', $jpg);
        $obs = $report($st['rep'], $st['sold'], [], [
            ['tmp' => $jpg, 'name' => 'piso.jpg', 'upload' => false],
            ['tmp' => $fake, 'name' => 'foto.jpg', 'upload' => false],
        ]);
        $atts = ObservationAttachments::forObservation((int) $obs['id']);
        assert_same(1, count($atts), 'el PHP disfrazado no se guarda');
        assert_same($sha, $atts[0]['sha256'], 'el archivo guardado es idéntico al recibido');
        assert_same('image/jpeg', $atts[0]['mime']);
        assert_same(1200, (int) $atts[0]['width']);
        assert_true($atts[0]['thumb_path'] !== null && $atts[0]['thumb_path'] !== $atts[0]['path']);
        $full = App\Core\TenantFiles::path($st['a']['uuid'], $atts[0]['path']);
        assert_same($sha, hash_file('sha256', $full));
        $st['photoObs'] = $obs;
        $st['photo'] = $atts[0];
        @unlink($jpg);
        @unlink($fake);
    },

    'rutas: fotos y fichas solo dentro del alcance' => function () use ($setup, &$st) {
        $setup();
        $go = function (string $path, array $user, array $query = []) use ($st): int {
            $_SESSION = [Impersonation::TENANT_KEY => $st['a']['uuid'], UserAuth::USER_KEY => $user['uuid']];
            UserAuth::setCurrent(null);
            Tenant::deactivate();
            $router = new Router();
            (require BASE_PATH . '/app/routes.php')($router);
            return $router->dispatch(new Request('GET', $path, $query))->status;
        };
        $obs = $st['photoObs'];
        $photo = '/panel/observaciones/' . $obs['uuid'] . '/fotos/' . $st['photo']['uuid'];
        assert_same(200, $go($photo, $st['rep']), 'el autor ve su foto: ' . $photo);
        assert_same(404, $go($photo, $st['rep2']), 'otro reportante no');
        assert_same(200, $go($photo, $st['sup'], ['t' => '1']), 'miniatura para el supervisor del sector');
        assert_same(200, $go('/panel/observaciones/' . $obs['uuid'], $st['hys']));
        assert_same(404, $go('/panel/observaciones/' . $obs['uuid'], $st['rep2']));
        assert_same(200, $go('/panel/observaciones', $st['rep2']));
        assert_same(200, $go('/panel/observaciones/nueva', $st['rep2']));
        assert_same(200, $go('/panel/observaciones/' . $obs['uuid'] . '/imprimir', $st['admin']));
        assert_same(200, $go('/panel', $st['sup']));
        $_SESSION = [];
    },

    'limpieza: se borran las bases de prueba' => function () use ($root, $dropAll, &$st, $master) {
        if (!$st['ready']) {
            throw new SkipTest('no hubo setup');
        }
        Tenant::deactivate();
        UserAuth::setCurrent(null);
        DB::reset();
        @unlink(Storage::path('installed.lock'));
        $pdo = $root();
        $dropAll($pdo);
        Config::load(BASE_PATH . '/config');
        $_SESSION = [];
        assert_same([], $pdo->query("SHOW DATABASES LIKE '{$master}%'")->fetchAll(PDO::FETCH_COLUMN));
    },
];
