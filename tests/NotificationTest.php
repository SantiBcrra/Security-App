<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\MailMessage;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Router;
use App\Core\Smtp;
use App\Core\Storage;
use App\Core\Tenant;
use App\Models\Alerts;
use App\Models\CatalogItems;
use App\Models\NotificationPrefs;
use App\Models\NotificationQueue;
use App\Models\Notifications;
use App\Models\ObservationEvents;
use App\Models\Observations;
use App\Models\PlatformSettings;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\Tenants;
use App\Models\Users;
use App\Models\UserSectors;
use App\Services\Impersonation;
use App\Services\IndustryTemplates;
use App\Services\Notify\Channels;
use App\Services\Notify\CronRunner;
use App\Services\Notify\ActionReminders;
use App\Services\Notify\Digests;
use App\Services\Notify\Escalations;
use App\Services\Notify\MailTransport;
use App\Services\Notify\QueueRunner;
use App\Services\ObservationService;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Etapa 7: motor de notificaciones. Maestra `securityapp_test_notif` + empresas nt-a y nt-b.
 * Los envíos externos se reemplazan por fakes (no sale nada a internet).
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_notif';
$st = ['ready' => false, 'mails' => []];

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
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Nt A', 'slug' => 'nt-a'] + $company, null));
    $st['b'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Nt B', 'slug' => 'nt-b'] + $company, null));

    Tenant::activate($st['a']);
    IndustryTemplates::apply('metalurgica');
    $site = Sites::create(['name' => 'Planta']);
    $st['nave1'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 1']);
    $st['sold'] = Sectors::create(['site_id' => $site, 'parent_id' => $st['nave1'], 'name' => 'Soldadura']);
    $st['nave2'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 2']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('x', PASSWORD_DEFAULT)]));
    $st['admin'] = $mk('admin_empresa', 'admin@nt.test');
    $st['hys'] = $mk('responsable_hys', 'hys@nt.test');
    $st['sup1'] = $mk('supervisor', 'sup1@nt.test');
    $st['sup2'] = $mk('supervisor', 'sup2@nt.test');
    $st['rep'] = $mk('reportante', 'rep@nt.test');
    UserSectors::replace((int) $st['sup1']['id'], [$st['nave1']]);
    UserSectors::replace((int) $st['sup2']['id'], [$st['nave2']]);
    $st['cat'] = (int) CatalogItems::findByName('categoria', 'Condición insegura')['id'];
    $st['alta'] = (int) CatalogItems::findByName('severidad', 'Alta')['id'];
    $st['baja'] = (int) CatalogItems::findByName('severidad', 'Baja')['id'];

    MailTransport::$fake = function (MailMessage $m) use (&$st): void {
        if (str_contains($m->to, 'rompe')) {
            throw new RuntimeException('550 buzón inexistente');
        }
        $st['mails'][] = $m;
    };
    Channels::$fakeExternal = fn (array $row) => null;
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

$report = function (array $user, int $sector, int $severity, bool $imminent = false) use (&$st): array {
    Tenant::activate($st['a']);
    UserAuth::setCurrent($user);
    return ObservationService::create([
        'category_id' => $st['cat'], 'risk_type_id' => null, 'severity_id' => $severity,
        'site_id' => (int) Sectors::findById($sector)['site_id'], 'sector_id' => $sector, 'equipment_id' => null,
        'description' => 'Prueba de notificaciones en planta', 'location_text' => null, 'lat' => null, 'lng' => null,
        'gps_accuracy_m' => null, 'imminent_risk' => $imminent ? 1 : 0, 'is_anonymous' => 0, 'created_at_device' => gmdate('Y-m-d H:i:s'),
    ], [], [])['observation'];
};
$inbox = fn (array $user) => Notifications::forUser((int) $user['id'], 100);

return [
    'MIME: asunto UTF-8, multipart texto+HTML y punto al inicio de línea' => function () {
        $m = new MailMessage('avisos@x.test', 'Seguridad Ñandú', 'a@b.test', 'Riesgo inminente · Plegadora', "Hola\n.punto", '<p>Hola</p>');
        $mime = $m->toMime();
        assert_true(str_contains($mime, 'Subject: =?UTF-8?B?' . base64_encode('Riesgo inminente · Plegadora') . '?='));
        assert_true(str_contains($mime, 'multipart/alternative') && str_contains($mime, 'text/html') && str_contains($mime, 'text/plain'));
        assert_true(str_contains($mime, "\r\n"), 'CRLF');
        assert_same("Linea\r\n..empieza con punto\r\nfin", Smtp::dotStuff("Linea\n.empieza con punto\nfin"));
    },

    'riesgo inminente: avisa en el momento a supervisores del sector, SyH y admin' => function () use ($setup, &$st, $report, $inbox) {
        $setup();
        $st['mails'] = [];
        $obs = $report($st['rep'], $st['sold'], $st['alta'], true);
        foreach (['sup1', 'hys', 'admin'] as $who) {
            $n = $inbox($st[$who]);
            assert_true(count($n) >= 1 && str_contains($n[0]['title'], 'RIESGO INMINENTE'), "aviso en la app para {$who}");
            assert_same(1, (int) $n[0]['is_critical']);
        }
        assert_same([], $inbox($st['sup2']), 'el supervisor de otra nave no');
        $to = array_map(fn ($m) => $m->to, $st['mails']);
        sort($to);
        assert_same(['admin@nt.test', 'hys@nt.test', 'sup1@nt.test'], $to, 'emails enviados en el mismo request');
        $alert = Alerts::latestForObservation((int) $obs['id']);
        assert_same(0, (int) $alert['level']);
        assert_true($alert['next_escalation_at'] !== null);
        $st['imminent'] = $obs;
    },

    'observación nueva: solo severidad alta o más, y no al que la cargó' => function () use ($setup, &$st, $report, $inbox) {
        $setup();
        $before = count($inbox($st['sup1']));
        $report($st['rep'], $st['sold'], $st['baja']);
        assert_same($before, count($inbox($st['sup1'])), 'severidad baja: no avisa');
        $report($st['sup1'], $st['sold'], $st['alta']);
        assert_same($before, count($inbox($st['sup1'])), 'el supervisor que la cargó no recibe su propio aviso');
        assert_true(str_contains($inbox($st['hys'])[0]['title'], 'Alta'), 'SyH sí');
    },

    'acción asignada: le avisa al responsable; las preferencias silencian lo no crítico' => function () use ($setup, &$st, $report, $inbox) {
        $setup();
        $st['mails'] = [];
        Tenant::activate($st['a']);
        NotificationPrefs::set((int) $st['sup2']['id'], 'email', false);
        $obs = $report($st['rep'], $st['nave2'], $st['baja']);
        UserAuth::setCurrent($st['hys']);
        assert_same(null, ObservationService::transition($obs, 'asignar', ['assigned_user' => $st['sup2']['uuid'], 'action_text' => 'Ordenar el sector', 'action_due_on' => gmdate('Y-m-d', strtotime('+2 days'))]));
        assert_true(str_contains($inbox($st['sup2'])[0]['title'], 'Te asignaron'), 'aviso en la app');
        // Pasan los días: la acción vence (la fecha no se puede cargar en el pasado, se simula)
        DB::tenant()->prepare("UPDATE actions SET due_on = ? WHERE origin_type = 'observacion' AND origin_id = ?")->execute([gmdate('Y-m-d', strtotime('-2 days')), (int) $obs['id']]);
        ObservationService::syncActions((int) $obs['id']);
        QueueRunner::run(5);
        assert_same([], array_values(array_filter($st['mails'], fn ($m) => $m->to === 'sup2@nt.test')), 'silenció el email');
        $st['assigned'] = Observations::findById((int) $obs['id']);
    },

    'cola: reintento con espera creciente y "fallido" al 5° intento' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $id = NotificationQueue::add(['channel' => 'email', 'to_address' => 'rompe@nt.test', 'subject' => 'x', 'body_text' => 'x']);
        QueueRunner::run(5, 10, [$id]);
        $row = NotificationQueue::findById($id);
        assert_same('pending', $row['status']);
        assert_same(1, (int) $row['attempts']);
        assert_true(str_contains($row['last_error'], '550'));
        assert_true(strtotime($row['next_attempt_at'] . ' UTC') > time() + 50, 'reintenta en ~1 minuto');
        for ($i = 0; $i < 4; $i++) {
            DB::tenant()->prepare('UPDATE notification_queue SET next_attempt_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE id = ?')->execute([$id]);
            QueueRunner::run(5, 10, [$id]);
        }
        $row = NotificationQueue::findById($id);
        assert_same('failed', $row['status']);
        assert_same(5, (int) $row['attempts']);
    },

    'escalamiento: sin "Recibido" sube de nivel; confirmar lo corta' => function () use ($setup, &$st, $inbox) {
        $setup();
        Tenant::activate($st['a']);
        $alert = Alerts::latestForObservation((int) $st['imminent']['id']);
        assert_same(0, Escalations::run(), 'todavía no venció');
        DB::tenant()->prepare('UPDATE alerts SET next_escalation_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$alert['id']]);
        assert_same(1, Escalations::run());
        $alert = Alerts::findByUuid($alert['uuid']);
        assert_same(1, (int) $alert['level']);
        assert_true(str_contains($inbox($st['hys'])[0]['title'], 'SIN CONFIRMAR'));
        assert_true(in_array('escalation', array_column(ObservationEvents::forObservation((int) $st['imminent']['id']), 'type'), true));
        Escalations::ack($alert, $st['sup1']);
        DB::tenant()->prepare('UPDATE alerts SET next_escalation_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?')->execute([$alert['id']]);
        assert_same(0, Escalations::run(), 'confirmada: no escala más');
        assert_same('Sup1', Alerts::findByUuid($alert['uuid'])['acked_name']);
        assert_true(in_array('alert_ack', array_column(ObservationEvents::forObservation((int) $st['imminent']['id']), 'type'), true));
    },

    'cerrar la observación corta el escalamiento de su alerta' => function () use ($setup, &$st, $report) {
        $setup();
        $obs = $report($st['rep'], $st['sold'], $st['alta'], true);
        UserAuth::setCurrent($st['hys']);
        assert_same(null, ObservationService::transition($obs, 'cerrar', ['comment' => 'Se frenó la tarea y se reparó']));
        assert_true(Alerts::latestForObservation((int) $obs['id'])['closed_at'] !== null);
    },

    'vencidas: un recordatorio por día; resumen diario una sola vez' => function () use ($setup, &$st, $inbox) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent(null);
        $before = count($inbox($st['sup2']));
        assert_true(ActionReminders::run()['overdue'] >= 1);
        assert_same($before + 1, count($inbox($st['sup2'])));
        assert_same(0, ActionReminders::run()['overdue'], 'el mismo día no se repite');
        $morning = new DateTimeImmutable('today 09:30', new DateTimeZone('America/Argentina/Buenos_Aires'));
        assert_true(in_array('daily', Digests::run($morning), true));
        assert_same(false, in_array('daily', Digests::run($morning), true), 'una vez por día');
        assert_same([], Digests::run(new DateTimeImmutable('today 06:00', new DateTimeZone('America/Argentina/Buenos_Aires'))), 'antes de las 8 no');
    },

    'cron por URL: clave inválida 403, válida procesa todas las empresas; lock' => function () use ($setup, &$st) {
        $setup();
        $go = function (string $key): App\Core\Response {
            $router = new Router();
            (require BASE_PATH . '/app/routes.php')($router);
            return $router->dispatch(new Request('GET', '/cron/run', ['key' => $key]));
        };
        assert_same(403, $go('mala')->status);
        $res = $go(CronRunner::key());
        assert_same(200, $res->status);
        $data = json_decode($res->body, true)['data'];
        assert_true(isset($data['nt-a'], $data['nt-b']), 'recorre cada empresa');
        $other = Tenant::connect($st['a']);
        $other->query("SELECT GET_LOCK('secapp_cron_" . $st['a']['db_name'] . "', 0)");
        assert_same('en curso (otra corrida)', CronRunner::run(5)['nt-a']);
        $other->query("SELECT RELEASE_LOCK('secapp_cron_" . $st['a']['db_name'] . "')");
    },

    'campanita: JSON con no leídas; aislamiento entre empresas' => function () use ($setup, &$st) {
        $setup();
        $_SESSION = [Impersonation::TENANT_KEY => $st['a']['uuid'], UserAuth::USER_KEY => $st['hys']['uuid']];
        UserAuth::setCurrent(null);
        $router = new Router();
        (require BASE_PATH . '/app/routes.php')($router);
        $res = $router->dispatch(new Request('GET', '/panel/notificaciones/recientes', [], [], ['accept' => 'application/json']));
        $data = json_decode($res->body, true)['data'];
        assert_true($data['unread'] >= 2 && count($data['items']) >= 2);
        Tenant::activate($st['b']);
        assert_same(0, (int) DB::tenant()->query('SELECT COUNT(*) FROM notifications')->fetchColumn(), 'la empresa B no tiene avisos de A');
        $_SESSION = [];
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
