<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Storage;
use App\Core\Tenant;
use App\Models\Contractors;
use App\Models\Employees;
use App\Models\InspectionTemplates;
use App\Models\PlatformSettings;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\Tenants;
use App\Models\Users;
use App\Models\UserSectors;
use App\Models\WorkPermits;
use App\Policies\Permissions;
use App\Services\IndustryTemplates;
use App\Services\InspectionStructure;
use App\Services\Notify\Channels;
use App\Services\Notify\MailTransport;
use App\Services\Signatures;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;
use App\Services\WorkPermitService;

/**
 * Etapa 13 (entrega 1): permisos de trabajo. Maestra `securityapp_test_ptw`.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_ptw';
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
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Pt A', 'slug' => 'pt-a'] + $company, null));
    $st['b'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Pt B', 'slug' => 'pt-b'] + $company, null));
    MailTransport::$fake = fn () => null;
    Channels::$fakeExternal = fn () => null;
    Tenant::activate($st['a']);
    $site = Sites::create(['name' => 'Planta']);
    $st['nave1'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 1']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    $st['admin'] = $mk('admin_empresa', 'admin@pt.test');
    $st['hys'] = $mk('responsable_hys', 'hys@pt.test');
    $st['hys2'] = $mk('responsable_hys', 'hys2@pt.test');
    $st['sup'] = $mk('supervisor', 'sup@pt.test');
    $st['rep'] = $mk('reportante', 'rep@pt.test');
    UserSectors::replace((int) $st['sup']['id'], [$st['nave1']]);
    UserAuth::setCurrent($st['hys']);
    IndustryTemplates::apply('metalurgica');
    $contractor = Contractors::create(['name' => 'Techos SRL']);
    $st['contractor'] = Contractors::findById($contractor);
    $st['emp'] = Employees::findById(Employees::create(['dni' => '30111222', 'last_name' => 'Pérez', 'first_name' => 'Juan', 'sector_id' => $st['nave1']]));
    $st['emp2'] = Employees::findById(Employees::create(['dni' => '31222333', 'last_name' => 'Ruiz', 'first_name' => 'Leo', 'contractor_id' => $contractor]));
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

/** Firma de prueba: PNG con trazos (o en blanco). */
$sign = function (bool $blank = false): string {
    $img = imagecreatetruecolor(400, 140);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    if (!$blank) {
        $ink = imagecolorallocate($img, 20, 20, 30);
        imagesetthickness($img, 3);
        for ($i = 0; $i < 6; $i++) {
            imageline($img, 20 + $i * 50, 100 - ($i % 2) * 60, 70 + $i * 50, 40 + ($i % 2) * 60, $ink);
        }
    }
    ob_start();
    imagepng($img);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
};

/** Respuestas que cumplen todo el checklist de un tipo (con $over por texto). */
$okAnswers = function (string $type, array $over = []): array {
    $t = InspectionTemplates::forPermitType($type);
    $out = [];
    foreach (InspectionStructure::items(InspectionTemplates::version((int) $t['current_version_id'])['structure']) as ['item' => $it]) {
        $out[$it['key']] = $over[$it['text']] ?? ['value' => $it['ok_when'] ?? 'si'];
    }
    return $out;
};

$request = function (array $user, array $extra = []) use (&$st, $sign, $okAnswers): array {
    Tenant::activate($st['a']);
    UserAuth::setCurrent($user);
    $from = time() - 60;
    return WorkPermitService::request($extra + [
        'types' => ['altura', 'caliente'], 'sector' => Sectors::findById($st['nave1'])['uuid'], 'location_text' => 'Techo nave 1',
        'task' => 'Soldar soporte de canaleta en el techo', 'contractor' => $st['contractor']['uuid'],
        'valid_from' => gmdate('Y-m-d\TH:i:s\Z', $from), 'valid_until' => gmdate('Y-m-d\TH:i:s\Z', $from + 4 * 3600),
        'workers' => [['employee' => $st['emp']['uuid'], 'role' => 'ejecutor'], ['employee' => $st['emp2']['uuid'], 'role' => 'vigia'],
            ['external_name' => 'Gómez Ana', 'external_dni' => '33444555', 'role' => 'ejecutor']],
        'checklists' => ['altura' => $okAnswers('altura'), 'caliente' => $okAnswers('caliente')],
        'signature' => $sign(),
    ], ['ip' => '10.0.0.5', 'user_agent' => 'Test']);
};

$inbox = fn (array $u) => array_column(DB::tenant()->query('SELECT title FROM notifications WHERE user_id = ' . (int) $u['id'])->fetchAll(), 'title');

return [
    'permisos: "aprobar" solo en permisos de trabajo, para SyH y admin' => function () {
        assert_true(in_array('aprobar', Permissions::actionsFor('permisos_trabajo'), true) && !in_array('aprobar', Permissions::actionsFor('acciones'), true));
        $roles = Permissions::baseRoles();
        $can = fn (string $r) => in_array('aprobar', $roles[$r]['permissions']['permisos_trabajo']['acciones'] ?? [], true);
        assert_same([true, true, false], [$can('admin_empresa'), $can('responsable_hys'), $can('supervisor')]);
    },

    'checklists de permiso precargados (5 tipos), fuera de Inspecciones' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        foreach (array_keys(WorkPermitService::TYPES) as $type) {
            assert_true(InspectionTemplates::forPermitType($type) !== null, $type);
        }
        assert_true(!array_filter(InspectionTemplates::all(), fn ($t) => $t['scope'] === 'permiso'), 'no aparecen como inspecciones');
        assert_same(11, count(InspectionTemplates::all(false, true)));
        $r = App\Services\InspectionService::create(['template' => InspectionTemplates::forPermitType('altura')['uuid'], 'answers' => []]);
        assert_true(str_contains($r['errors']['_'], 'permisos de trabajo'));
    },

    'firmas: vacía o inválida se rechaza; válida queda con hash' => function () use ($setup, &$st, $sign) {
        $setup();
        Tenant::activate($st['a']);
        foreach (['', 'data:image/png;base64,AAAA', $sign(true)] as $bad) {
            try {
                Signatures::store($bad, 'permits');
                assert_true(false, 'tenía que rechazarla');
            } catch (App\Core\UserError) {
            }
        }
        $ok = Signatures::store($sign(), 'permits');
        assert_true(str_starts_with($ok['path'], 'permits/') && strlen($ok['sha256']) === 64);
    },

    'solicitar: validaciones (tipos, ejecutores, ventana, checklist completo, firma)' => function () use ($setup, &$st, $request, $sign) {
        $setup();
        $r = $request($st['sup'], ['types' => [], 'workers' => []]);
        assert_true(isset($r['errors']['types'], $r['errors']['workers']));
        $r = $request($st['sup'], ['valid_from' => gmdate('Y-m-d\TH:i:s\Z', time()), 'valid_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 13 * 3600)]);
        assert_true(str_contains($r['errors']['valid'] ?? '', 'como máximo 12'));
        $r = $request($st['sup'], ['checklists' => ['altura' => []]]);
        assert_true(count(array_filter(array_keys($r['errors']), fn ($k) => str_starts_with($k, 'altura:') || str_starts_with($k, 'caliente:'))) > 5, 'faltan las respuestas');
        $r = $request($st['sup'], ['signature' => $sign(true)]);
        assert_true(str_contains($r['errors']['signature'] ?? '', 'vacía'));
        UserAuth::setCurrent($st['rep']);
        assert_true(isset(WorkPermitService::request([])['errors']['_']), 'el operario no solicita');
    },

    'solicitar y autorizar: nunca el propio; críticos bloquean; aviso a quienes autorizan' => function () use ($setup, &$st, $request, $sign, $okAnswers, $inbox) {
        $setup();
        $r = $request($st['hys']);
        assert_same([], $r['errors']);
        $p = $r['permit'];
        assert_same(['solicitado', ['altura', 'caliente'], 0], [$p['status'], $p['type_list'], (int) $p['critical_fails']]);
        assert_same(hash('sha256', $p['original_data']), $p['original_hash']);
        assert_same(2, count(WorkPermits::checklists((int) $p['id'])));
        assert_same(['solicitante'], array_column(WorkPermits::signatures((int) $p['id']), 'role'));
        $num = WorkPermits::format((int) $p['number']);
        assert_true((bool) array_filter($inbox($st['hys2']), fn ($t) => str_contains($t, $num)), 'aviso a otro autorizante');
        assert_true(!array_filter($inbox($st['sup']), fn ($t) => str_contains($t, $num)), 'el supervisor no autoriza');
        UserAuth::setCurrent($st['hys']);
        assert_true(str_contains((string) WorkPermitService::approve($p, $sign()), 'solicitaste vos'));
        UserAuth::setCurrent($st['sup']);
        assert_true(str_contains((string) WorkPermitService::approve($p, $sign()), 'permiso'));
        UserAuth::setCurrent($st['hys2']);
        assert_true(str_contains((string) WorkPermitService::approve($p, $sign(true)), 'vacía'));
        assert_same(null, WorkPermitService::approve($p, $sign(), [], 'Usar línea de vida norte'));
        $p = WorkPermits::findById((int) $p['id']);
        assert_same(['aprobado', (int) $st['hys2']['id']], [$p['status'], (int) $p['approved_by']]);
        assert_true(in_array('Permiso autorizado · ' . $num, $inbox($st['hys']), true), 'aviso al solicitante');
        $st['permit'] = $p;
        // Con un crítico sin cumplir no se autoriza
        $bad = $request($st['sup'], ['types' => ['altura'], 'checklists' => ['altura' => $okAnswers('altura', [
            'Arnés de cuerpo completo inspeccionado' => ['value' => 'no', 'comment' => 'No hay arnés del talle']])]])['permit'];
        assert_same(1, (int) $bad['critical_fails']);
        UserAuth::setCurrent($st['hys2']);
        assert_true(str_contains((string) WorkPermitService::approve($bad, $sign()), 'crítico'));
        assert_same(null, WorkPermitService::reject($bad, 'Conseguir arnés talle L'));
        assert_same('rechazado', WorkPermits::findById((int) $bad['id'])['status']);
    },

    'iniciar con la firma de cada ejecutor; cerrar y recibir el área' => function () use ($setup, &$st, $sign) {
        $setup();
        Tenant::activate($st['a']);
        $p = WorkPermits::findById((int) $st['permit']['id']);
        $workers = WorkPermits::workers((int) $p['id']);
        UserAuth::setCurrent($st['rep']);
        assert_true(str_contains((string) WorkPermitService::start($p, []), 'permiso'), 'el operario no inicia');
        UserAuth::setCurrent($st['hys']); // el solicitante
        $err = WorkPermitService::start($p, [$workers[0]['uuid'] => $sign()]);
        assert_true(str_contains((string) $err, 'Falta la firma de'), (string) $err);
        assert_same('aprobado', WorkPermits::findById((int) $p['id'])['status']);
        $sigs = [];
        foreach ($workers as $w) {
            $sigs[$w['uuid']] = $sign();
        }
        assert_same(null, WorkPermitService::start($p, $sigs, ['ip' => '10.0.0.9']));
        $p = WorkPermits::findById((int) $p['id']);
        assert_same('en_ejecucion', $p['status']);
        assert_same(3, count(array_filter(WorkPermits::workers((int) $p['id']), fn ($w) => $w['signature_uuid'] !== null)));
        assert_true(str_contains((string) WorkPermitService::close($p, $sign(), 'ok'), 'mínimo 5'));
        assert_same(null, WorkPermitService::close($p, $sign(), 'Área limpia, sin brasas, guardia cumplida'));
        $p = WorkPermits::findById((int) $p['id']);
        assert_same('cerrado', $p['status']);
        UserAuth::setCurrent($st['hys2']);
        assert_same(null, WorkPermitService::receive($p, $sign()));
        assert_true(str_contains((string) WorkPermitService::receive($p, $sign()), 'ya se recibió'));
        $roles = array_column(WorkPermits::signatures((int) $p['id']), 'role');
        sort($roles);
        assert_same(['autorizante', 'cierre', 'ejecutor', 'ejecutor', 'recepcion', 'solicitante', 'vigia'], $roles);
    },

    'vencimiento automático y aviso 30 minutos antes (una sola vez); cancelar' => function () use ($setup, &$st, $request, $sign, $inbox) {
        $setup();
        $p1 = $request($st['sup'])['permit'];
        $p2 = $request($st['sup'])['permit'];
        $p3 = $request($st['sup'])['permit'];
        UserAuth::setCurrent($st['hys']);
        WorkPermitService::approve($p1, $sign());
        WorkPermitService::approve($p2, $sign());
        DB::tenant()->prepare('UPDATE work_permits SET valid_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?')->execute([(int) $p1['id']]);
        DB::tenant()->prepare('UPDATE work_permits SET valid_until = UTC_TIMESTAMP() + INTERVAL 20 MINUTE WHERE id = ?')->execute([(int) $p2['id']]);
        UserAuth::setCurrent(null);
        $r = WorkPermitService::expire();
        assert_same(['expiring' => 1, 'expired' => 1], $r);
        assert_same(['expiring' => 0, 'expired' => 0], WorkPermitService::expire(), 'no se repite');
        assert_same('vencido', WorkPermits::findById((int) $p1['id'])['status']);
        assert_true(in_array('Permiso vencido · ' . WorkPermits::format((int) $p1['number']), $inbox($st['sup']), true));
        assert_true(in_array('Permiso por vencer · ' . WorkPermits::format((int) $p2['number']), $inbox($st['sup']), true));
        UserAuth::setCurrent($st['sup']);
        assert_same(null, WorkPermitService::cancel($p3, 'Se reprogramó para mañana'));
        assert_same('cancelado', WorkPermits::findById((int) $p3['id'])['status']);
        assert_true(str_contains((string) WorkPermitService::cancel(WorkPermits::findById((int) $p1['id']), 'otra vez'), 'todavía no empezó'));
    },

    'HTTP: tablero, solicitud, detalle, firmas, imprimible con QR y verificación' => function () use ($setup, &$st) {
        $setup();
        $call = function (array $user, string $path) use (&$st) {
            UserAuth::setCurrent(null);
            Tenant::deactivate();
            $_SESSION = [];
            \App\Core\Session::put(\App\Services\Impersonation::TENANT_KEY, $st['a']['uuid']);
            \App\Core\Session::put(UserAuth::USER_KEY, $user['uuid']);
            $router = new \App\Core\Router();
            (require BASE_PATH . '/app/routes.php')($router);
            return $router->dispatch(new \App\Core\Request('GET', $path));
        };
        $p = $st['permit'];
        $board = $call($st['sup'], '/panel/permisos');
        assert_same(200, $board->status);
        assert_same(200, $call($st['sup'], '/panel/permisos/nuevo')->status);
        $show = $call($st['hys2'], '/panel/permisos/' . $p['uuid']);
        assert_true($show->status === 200 && str_contains($show->body, 'Recepción del área') && str_contains($show->body, 'Techo nave 1'));
        $print = $call($st['sup'], '/panel/permisos/' . $p['uuid'] . '/imprimir');
        assert_true(str_contains($print->body, '/verificar') && str_contains($print->body, 'qrcode'));
        Tenant::activate($st['a']);
        $sig = WorkPermits::signatures((int) $p['id'])[0];
        $img = $call($st['sup'], '/panel/permisos/' . $p['uuid'] . '/firmas/' . $sig['uuid']);
        assert_same(200, $img->status);
        assert_same(403, $call($st['rep'], '/panel/permisos')->status, 'el operario no entra al módulo');
        $verify = $call($st['rep'], '/panel/permisos/' . $p['uuid'] . '/verificar');
        assert_true($verify->status === 200 && str_contains($verify->body, 'NO VIGENTE'), 'el QR lo puede ver cualquiera de la empresa (está cerrado)');
        $_SESSION = [];
    },

    'aislamiento: la empresa B no ve permisos de A' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['b']);
        UserAuth::setCurrent(null);
        assert_same(0, WorkPermits::count([], null));
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
