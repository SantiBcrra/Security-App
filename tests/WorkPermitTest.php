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
use App\Services\WorkPermitControls;
use App\Services\WorkPermitService;

/**
 * Etapa 13: permisos de trabajo, controles (gases, LOTO, vigía, suspensión, extensión) y app de campo (sync + API). Maestra `securityapp_test_ptw`.
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
        assert_true($p['fire_watch_until'] !== null && strtotime($p['fire_watch_until']) > time() + 25 * 60, 'trabajo en caliente: guardia de 30 min');
        UserAuth::setCurrent($st['hys2']);
        assert_true(str_contains((string) WorkPermitService::receive($p, $sign()), 'guardia de fuego'), 'no se recibe durante la guardia');
        DB::tenant()->prepare('UPDATE work_permits SET fire_watch_until = UTC_TIMESTAMP() - INTERVAL 1 MINUTE WHERE id = ?')->execute([(int) $p['id']]);
        $p = WorkPermits::findById((int) $p['id']);
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

    'espacio confinado: medición en rango para iniciar; fuera de rango suspende solo y avisa (crítico)' => function () use ($setup, &$st, $request, $sign, $okAnswers, $inbox) {
        $setup();
        $p = $request($st['sup'], ['types' => ['espacio_confinado'], 'checklists' => ['espacio_confinado' => $okAnswers('espacio_confinado')],
            'workers' => [['employee' => $st['emp']['uuid'], 'role' => 'ejecutor']]])['permit'];
        assert_same(null, $p['fire_watch_minutes'], 'sin caliente no hay guardia');
        UserAuth::setCurrent($st['hys']);
        assert_same(null, WorkPermitService::approve($p, $sign()));
        $p = WorkPermits::findById((int) $p['id']);
        $worker = WorkPermits::workers((int) $p['id'])[0];
        UserAuth::setCurrent($st['sup']);
        assert_true(str_contains((string) WorkPermitService::start($p, [$worker['uuid'] => $sign()]), 'medición de gases'), 'sin medición no arranca');
        UserAuth::setCurrent($st['rep']);
        assert_true(isset(WorkPermitControls::measure($p, ['o2' => '20.9', 'lel' => '0'])['error']), 'el operario sin permiso no mide');
        UserAuth::setCurrent($st['sup']);
        assert_true(str_contains(WorkPermitControls::measure($p, ['o2' => '20,9'])['error'] ?? '', 'O₂'), 'O2 y LIE obligatorios');
        $uuid = App\Core\Uuid::v4();
        $r = WorkPermitControls::measure($p, ['uuid' => $uuid, 'o2' => '20,9', 'lel' => '0', 'co' => '2', 'instrument' => 'Altair 4X']);
        assert_same([true, []], [$r['ok'], $r['out']]);
        assert_true(!empty(WorkPermitControls::measure($p, ['uuid' => $uuid, 'o2' => '20,9', 'lel' => '0'])['duplicate']), 'reenvío idempotente');
        assert_same(1, count(WorkPermits::measurements((int) $p['id'])));
        assert_same(null, WorkPermitService::start($p, [$worker['uuid'] => $sign()]));
        $p = WorkPermits::findById((int) $p['id']);
        $r = WorkPermitControls::measure($p, ['o2' => '18.2', 'lel' => '12']);
        assert_same([false, true, 2], [$r['ok'], $r['suspended'], count($r['out'])]);
        $p = WorkPermits::findById((int) $p['id']);
        assert_same('suspendido', $p['status']);
        assert_true($p['suspended_at'] !== null && str_contains((string) $p['status_reason'], 'O₂'));
        $num = WorkPermits::format((int) $p['number']);
        assert_true(in_array('⚠ GASES FUERA DE RANGO · ' . $num, $inbox($st['hys']), true), 'aviso al autorizante');
        assert_true(in_array('⚠ GASES FUERA DE RANGO · ' . $num, $inbox($st['sup']), true), 'crítico: también a quien midió');
        $critical = (int) DB::tenant()->query("SELECT COUNT(*) FROM notification_queue WHERE event = 'permit.gas_alarm'")->fetchColumn();
        assert_true($critical > 0, 'sale por email/push');
        assert_true(str_contains((string) WorkPermitControls::resume($p, 'Se ventiló 20 minutos'), 'posterior a la suspensión'));
        assert_true(WorkPermitControls::measure($p, ['o2' => '20.8', 'lel' => '1'])['ok']);
        assert_same(null, WorkPermitControls::resume($p, 'Se ventiló 20 minutos y se volvió a medir'));
        assert_same('en_ejecucion', WorkPermits::findById((int) $p['id'])['status']);
        // Límites configurables
        App\Models\Settings::set('permisos.gas_o2_min', '20');
        assert_same(1, count(WorkPermitControls::evaluateGas(19.8, 0, null, null)));
        App\Models\Settings::set('permisos.gas_o2_min', '19.5');
        assert_same([], WorkPermitControls::evaluateGas(19.8, 0, null, null));
        $alt = $request($st['sup'], ['types' => ['altura'], 'checklists' => ['altura' => $okAnswers('altura')]])['permit'];
        assert_true(str_contains(WorkPermitControls::measure($alt, ['o2' => '20', 'lel' => '0'])['error'] ?? '', 'espacio confinado'));
    },

    'LOTO: bloqueos con energía cero para iniciar; no se cierra con candados puestos' => function () use ($setup, &$st, $request, $sign, $okAnswers) {
        $setup();
        $p = $request($st['sup'], ['types' => ['loto'], 'checklists' => ['loto' => $okAnswers('loto')],
            'workers' => [['employee' => $st['emp']['uuid'], 'role' => 'ejecutor']]])['permit'];
        UserAuth::setCurrent($st['hys']);
        WorkPermitService::approve($p, $sign());
        $p = WorkPermits::findById((int) $p['id']);
        $sigs = [WorkPermits::workers((int) $p['id'])[0]['uuid'] => $sign()];
        UserAuth::setCurrent($st['sup']);
        assert_true(str_contains((string) WorkPermitService::start($p, $sigs), 'puntos de bloqueo'));
        assert_true(str_contains((string) WorkPermitControls::isolate($p, ['point' => 'TG-2', 'energy' => 'nuclear']), 'energía'));
        assert_same(null, WorkPermitControls::isolate($p, ['point' => 'Seccionador TG-2', 'energy' => 'electrica', 'lock_number' => 'C-14']));
        assert_true(str_contains((string) WorkPermitService::start($p, $sigs), 'energía cero'));
        $first = WorkPermits::isolations((int) $p['id'])[0];
        assert_same(null, WorkPermitControls::release($p, $first['uuid'], ''));
        assert_true(str_contains((string) WorkPermitControls::release($p, $first['uuid'], ''), 'ya se retiró'));
        assert_same(null, WorkPermitControls::isolate($p, ['point' => 'Seccionador TG-2', 'energy' => 'electrica', 'lock_number' => 'C-14', 'zero_verified' => '1']));
        assert_same(null, WorkPermitControls::isolate($p, ['point' => 'Válvula aire V-3', 'energy' => 'neumatica', 'zero_verified' => '1']));
        assert_same(null, WorkPermitService::start($p, $sigs));
        $p = WorkPermits::findById((int) $p['id']);
        assert_true(str_contains((string) WorkPermitService::close($p, $sign(), 'Equipo armado y probado'), '2 punto(s) de bloqueo'));
        foreach (WorkPermits::isolations((int) $p['id']) as $iso) {
            if ($iso['removed_at'] === null) {
                assert_same(null, WorkPermitControls::release($p, $iso['uuid'], 'Pérez'));
            }
        }
        assert_same(null, WorkPermitService::close($p, $sign(), 'Equipo armado y probado'));
        $p = WorkPermits::findById((int) $p['id']);
        assert_same([null, 'cerrado'], [$p['fire_watch_until'], $p['status']]);
    },

    'suspender a mano, extensión única firmada por el autorizante y conflictos' => function () use ($setup, &$st, $request, $sign, $okAnswers, $inbox) {
        $setup();
        $p = $request($st['sup'], ['types' => ['altura'], 'checklists' => ['altura' => $okAnswers('altura')],
            'workers' => [['employee' => $st['emp']['uuid'], 'role' => 'ejecutor']]])['permit'];
        $other = $request($st['sup'], ['types' => ['altura'], 'checklists' => ['altura' => $okAnswers('altura')]])['permit'];
        assert_true(in_array((int) $other['id'], array_map('intval', array_column(WorkPermits::conflicts($p), 'id')), true), 'mismo sector y horario');
        UserAuth::setCurrent($st['hys']);
        WorkPermitService::approve($p, $sign());
        $p = WorkPermits::findById((int) $p['id']);
        UserAuth::setCurrent($st['sup']);
        assert_true(str_contains((string) WorkPermitControls::extend($p, gmdate('c', time() + 6 * 3600), $sign()), 'quien autoriza'), 'el solicitante no extiende');
        UserAuth::setCurrent($st['hys']);
        $tooFar = gmdate('Y-m-d\TH:i:s\Z', strtotime($p['valid_until']) + 13 * 3600);
        assert_true(str_contains((string) WorkPermitControls::extend($p, $tooFar, $sign()), 'hasta 12 horas'));
        assert_true(str_contains((string) WorkPermitControls::extend($p, gmdate('Y-m-d\TH:i:s\Z', strtotime($p['valid_until']) - 600), $sign()), 'posterior'));
        $until = gmdate('Y-m-d\TH:i:s\Z', strtotime($p['valid_until']) + 2 * 3600);
        assert_same(null, WorkPermitControls::extend($p, $until, $sign()));
        $p = WorkPermits::findById((int) $p['id']);
        assert_same([gmdate('Y-m-d H:i:s', strtotime($until)), (int) $st['hys']['id']], [$p['ends_at'], (int) $p['extended_by']]);
        assert_true(in_array('extension', array_column(WorkPermits::signatures((int) $p['id']), 'role'), true));
        assert_true(str_contains((string) WorkPermitControls::extend($p, gmdate('c', strtotime($p['ends_at']) + 600), $sign()), 'una vez'));
        UserAuth::setCurrent($st['sup']);
        WorkPermitService::start($p, [WorkPermits::workers((int) $p['id'])[0]['uuid'] => $sign()]);
        $p = WorkPermits::findById((int) $p['id']);
        assert_same('en_ejecucion', $p['status']);
        UserAuth::setCurrent($st['rep']);
        assert_true(str_contains((string) WorkPermitControls::suspend($p, 'Viento fuerte'), 'permiso'));
        UserAuth::setCurrent($st['hys']); // autorizante: puede frenar aunque no sea del equipo
        assert_same(null, WorkPermitControls::suspend($p, 'Viento fuerte en el techo'));
        assert_true(in_array('Permiso suspendido · ' . WorkPermits::format((int) $p['number']), $inbox($st['sup']), true));
        $p = WorkPermits::findById((int) $p['id']);
        assert_same(null, WorkPermitControls::resume($p, 'Bajó el viento, se revisó la línea de vida'));
        $types = array_column(WorkPermits::events((int) $p['id']), 'type');
        assert_true(in_array('extended', $types, true));
    },

    'HTTP: historial con filtros, CSV, configuración y "Mis permisos" en el inicio' => function () use ($setup, &$st) {
        $setup();
        $call = function (array $user, string $path, array $query = []) use (&$st) {
            UserAuth::setCurrent(null);
            Tenant::deactivate();
            $_SESSION = [];
            \App\Core\Session::put(\App\Services\Impersonation::TENANT_KEY, $st['a']['uuid']);
            \App\Core\Session::put(UserAuth::USER_KEY, $user['uuid']);
            $router = new \App\Core\Router();
            (require BASE_PATH . '/app/routes.php')($router);
            return $router->dispatch(new \App\Core\Request('GET', $path, $query));
        };
        $list = $call($st['hys'], '/panel/permisos/historial', ['tipo' => 'loto']);
        assert_true($list->status === 200 && str_contains($list->body, '1 permiso(s)'), 'filtra por tipo (un solo LOTO)');
        $csv = $call($st['hys'], '/panel/permisos/exportar');
        assert_true($csv->status === 200 && str_starts_with($csv->body, "\xEF\xBB\xBF") && str_contains($csv->body, 'PT-000001'));
        assert_same(403, $call($st['rep'], '/panel/permisos/exportar')->status);
        Tenant::activate($st['a']);
        $loto = WorkPermits::search(['type' => 'loto'], null, 1)[0];
        $confined = WorkPermits::search(['type' => 'espacio_confinado'], null, 1)[0];
        $show = $call($st['hys'], '/panel/permisos/' . $loto['uuid']);
        assert_true($show->status === 200 && str_contains($show->body, 'Bloqueos (LOTO)') && str_contains($show->body, 'Válvula aire V-3'));
        $show = $call($st['sup'], '/panel/permisos/' . $confined['uuid']);
        assert_true($show->status === 200 && str_contains($show->body, 'Mediciones de gases') && str_contains($show->body, 'fuera de rango')
            && str_contains($show->body, 'Suspender el trabajo') && str_contains($show->body, 'Registrar medición'));
        $home = $call($st['sup'], '/panel');
        assert_true(str_contains($home->body, 'Mis permisos de trabajo'));
        $settings = $call($st['admin'], '/panel/configuracion');
        assert_true(str_contains($settings->body, 'Límites de gases'));
        $_SESSION = [];
    },

    'app de campo: pull según alcance, operaciones offline idempotentes (firmas, gases, LOTO, cierre)' => function () use ($setup, &$st, $request, $sign, $okAnswers) {
        $setup();
        $p = $request($st['sup'], ['types' => ['espacio_confinado', 'loto'], 'task' => 'Limpiar tolva 3 por dentro',
            'checklists' => ['espacio_confinado' => $okAnswers('espacio_confinado'), 'loto' => $okAnswers('loto')],
            'workers' => [['employee' => $st['emp']['uuid'], 'role' => 'ejecutor'], ['external_name' => 'Sosa Ana', 'external_dni' => '35111222', 'role' => 'vigia']]])['permit'];
        UserAuth::setCurrent($st['hys']);
        WorkPermitService::approve($p, $sign());
        DB::tenant()->exec('UPDATE work_permits SET updated_at = UTC_TIMESTAMP() - INTERVAL 1 MINUTE');
        $pulled = function (array $user) {
            UserAuth::setCurrent($user);
            $page = App\Services\Sync\Pull::run(null, 1000);
            return [array_column((array) ($page['changes']->work_permits ?? []), null, 'uuid'), $page];
        };
        [$mine, $page] = $pulled($st['sup']);
        $row = $mine[$p['uuid']] ?? null;
        assert_true($row !== null && $row['can']['start'] && !$row['can']['approve'] && count($row['workers']) === 2);
        assert_true(isset($page['meta']['gases']['o2_min']), 'límites de gases para evaluar sin señal');
        [$repView] = $pulled($st['rep']);
        assert_true(!isset($repView[$p['uuid']]), 'el operario sin el módulo no lo recibe');
        // Todo hecho sin señal y enviado junto (en orden)
        UserAuth::setCurrent($st['sup']);
        $workers = WorkPermits::workers((int) $p['id']);
        $iso = App\Core\Uuid::v4();
        $op = fn (string $type, array $data) => ['op_id' => App\Core\Uuid::v4(), 'type' => $type, 'data' => ['uuid' => $p['uuid']] + $data];
        $badAt = gmdate('Y-m-d\TH:i:s\Z', time() - 120);
        $ops = [
            $op('permit.isolate', ['isolation_uuid' => $iso, 'point' => 'Seccionador motor tolva', 'energy' => 'electrica', 'zero_verified' => 1]),
            $op('permit.measure', ['measurement_uuid' => App\Core\Uuid::v4(), 'o2' => 20.9, 'lel' => 0, 'measured_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 300)]),
            $op('permit.start', ['worker_signatures' => [$workers[0]['uuid'] => $sign(), $workers[1]['uuid'] => $sign()]]),
            $op('permit.measure', ['measurement_uuid' => App\Core\Uuid::v4(), 'o2' => 17.5, 'lel' => 0, 'measured_at' => $badAt]),
            $op('permit.measure', ['measurement_uuid' => App\Core\Uuid::v4(), 'o2' => 20.8, 'lel' => 1, 'measured_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 30)]),
            $op('permit.resume', ['comment' => 'Se ventiló y se volvió a medir']),
            $op('permit.release', ['isolation_uuid' => $iso, 'removed_by' => 'Pérez']),
            $op('permit.close', ['comment' => 'Tolva limpia, tapa colocada', 'signature' => $sign()]),
        ];
        $results = App\Services\Sync\Push::run($ops);
        foreach ($results as $i => $r) {
            assert_same('ok', $r['status'], ($ops[$i]['type']) . ': ' . ($r['error'] ?? ''));
        }
        assert_same(true, $results[3]['data']['measurement']['suspended'], 'fuera de rango → suspendido');
        $fresh = WorkPermits::findById((int) $p['id']);
        assert_same('cerrado', $fresh['status']);
        assert_same(gmdate('Y-m-d H:i:s', strtotime($badAt)), $fresh['suspended_at'], 'la suspensión cuenta desde la medición del celular');
        assert_same('cerrado', $results[7]['data']['permit']['status']);
        // Reenvíos: mismo op_id = misma respuesta; otro op_id = ok sin repetir nada
        $replay = App\Services\Sync\Push::run($ops);
        assert_same(array_map(fn ($r) => [$r['op_id'], $r['status'], $r['data']['permit']['status']], $results),
            array_map(fn ($r) => [$r['op_id'], $r['status'], $r['data']['permit']['status']], $replay));
        $again = App\Services\Sync\Push::run([$op('permit.close', ['comment' => 'otra vez', 'signature' => $sign()]),
            $op('permit.isolate', ['isolation_uuid' => $iso, 'point' => 'Seccionador motor tolva', 'energy' => 'electrica'])]);
        assert_same([true, true], [$again[0]['data']['duplicate'], $again[1]['data']['duplicate']]);
        assert_same(3, count(WorkPermits::measurements((int) $p['id'])));
        assert_same(1, count(WorkPermits::isolations((int) $p['id'])));
        // Rechazos con motivo
        UserAuth::setCurrent($st['rep']);
        $err = App\Services\Sync\Push::run([$op('permit.suspend', ['comment' => 'Probando'])])[0];
        assert_true($err['status'] === 'error' && str_contains($err['error'], 'no tenés acceso'));
        UserAuth::setCurrent($st['sup']);
        $err = App\Services\Sync\Push::run([$op('permit.measure', ['measurement_uuid' => App\Core\Uuid::v4(), 'o2' => 20, 'lel' => 0])])[0];
        assert_true($err['status'] === 'error' && str_contains($err['error'], 'no está activo'), (string) ($err['error'] ?? ''));
    },

    'API: autorizar y rechazar con conexión, detalle con checklist y verificación por QR' => function () use ($setup, &$st, $request, $sign) {
        $setup();
        $p = $request($st['sup'])['permit'];
        $bad = $request($st['sup'])['permit'];
        $api = new App\Controllers\Api\WorkPermitsController();
        $json = fn ($res) => json_decode($res->body, true);
        UserAuth::setCurrent($st['sup']);
        assert_same(422, $api->approve(new App\Core\Request('POST', '/', [], ['signature' => $sign()]), $p['uuid'])->status, 'el solicitante no autoriza');
        UserAuth::setCurrent($st['hys']);
        $detail = $json($api->show(new App\Core\Request('GET', '/'), $p['uuid']));
        assert_true($detail['ok'] && $detail['data']['can']['approve'] && count($detail['data']['checklists']) === 2);
        $ok = $json($api->approve(new App\Core\Request('POST', '/', [], ['signature' => $sign(), 'comment' => 'Con línea de vida']), $p['uuid']));
        assert_same('aprobado', $ok['data']['status']);
        $no = $json($api->reject(new App\Core\Request('POST', '/', [], ['comment' => 'Falta el vigía']), $bad['uuid']));
        assert_same('rechazado', $no['data']['status']);
        UserAuth::setCurrent($st['rep']);
        assert_same(404, $api->show(new App\Core\Request('GET', '/'), $p['uuid'])->status, 'sin acceso al detalle');
        $v = $json($api->verify(new App\Core\Request('GET', '/'), $p['uuid']));
        assert_true($v['ok'] && $v['data']['valid'] === false && $v['data']['status'] === 'aprobado' && count($v['data']['workers']) === 3, 'el QR lo ve cualquiera');
        assert_same(404, $api->verify(new App\Core\Request('GET', '/'), App\Core\Uuid::v4())->status);
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
