<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Storage;
use App\Core\Tenant;
use App\Models\Contractors;
use App\Models\Employees;
use App\Models\PlatformSettings;
use App\Models\Positions;
use App\Models\Ppe;
use App\Models\PpeItems;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\Tenants;
use App\Models\Users;
use App\Models\UserSectors;
use App\Services\IndustryTemplates;
use App\Services\Notify\Channels;
use App\Services\Notify\MailTransport;
use App\Services\PpeService;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Etapa 14 (entrega 1): EPP. Catálogo, matriz, estado por empleado, entregas firmadas, anulación y constancia.
 * Maestra `securityapp_test_ppe`.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_ppe';
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
    $company = ['legal_name' => 'Metalúrgica Sur SA', 'cuit' => '30711111117', 'timezone' => 'America/Argentina/Buenos_Aires', 'status' => 'active', 'plan' => null];
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Ppe A', 'slug' => 'ppe-a'] + $company, null));
    $st['b'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Ppe B', 'slug' => 'ppe-b'] + $company, null));
    MailTransport::$fake = fn () => null;
    Channels::$fakeExternal = fn () => null;
    Tenant::activate($st['a']);
    $site = Sites::create(['name' => 'Planta']);
    $st['nave1'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 1']);
    $st['nave2'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 2']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    $st['hys'] = $mk('responsable_hys', 'hys@ppe.test');
    $st['sup'] = $mk('supervisor', 'sup@ppe.test');
    $st['rep'] = $mk('reportante', 'rep@ppe.test');
    UserSectors::replace((int) $st['sup']['id'], [$st['nave1']]);
    UserAuth::setCurrent($st['hys']);
    $st['template'] = IndustryTemplates::apply('metalurgica');
    $pos = fn (string $name) => (int) Positions::findBy('name', $name)['id'];
    $emp = fn (array $d) => Employees::findById(Employees::create($d + ['first_name' => 'Juan']));
    $st['welder'] = $emp(['dni' => '30111222', 'last_name' => 'Pérez', 'position_id' => $pos('Soldador'), 'sector_id' => $st['nave1']]);
    $st['turner'] = $emp(['dni' => '30222333', 'last_name' => 'Gómez', 'position_id' => $pos('Tornero'), 'sector_id' => $st['nave2']]);
    $st['contractor'] = $emp(['dni' => '30333444', 'last_name' => 'Ruiz', 'position_id' => $pos('Soldador'), 'sector_id' => $st['nave1'],
        'contractor_id' => Contractors::create(['name' => 'Montajes SRL'])]);
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

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
$item = fn (string $preset) => PpeItems::findByPreset('metalurgica/' . $preset);
$fresh = fn (array $e) => Employees::findById((int) $e['id']);
$stateOf = function (array $e, string $preset) use ($item): ?string {
    foreach (PpeService::status($e)['rows'] as $r) {
        if ((int) $r['item_id'] === (int) $item($preset)['id']) {
            return $r['state'];
        }
    }
    return null;
};

return [
    'precarga del rubro: catálogo, matriz por puesto (obligatorio / según tarea / cantidad) e idempotente' => function () use ($setup, &$st, $item) {
        $setup();
        assert_same(16, PpeItems::count(null, true));
        assert_same(['IRAM 3610', 'calzado', 365], [$item('botin')['certification'], $item('botin')['size_type'], (int) $item('botin')['life_days']]);
        $soldador = array_column(Ppe::matrix((int) Positions::findBy('name', 'Soldador')['id']), null, 'item_name');
        assert_true(isset($soldador['Careta de soldar fotosensible']) && !isset($soldador['Anteojos de seguridad']));
        assert_same(2, (int) $soldador['Guantes de soldador (puño largo)']['quantity']);
        $tornero = array_column(Ppe::matrix((int) Positions::findBy('name', 'Tornero')['id']), null, 'item_name');
        assert_true(!isset($tornero['Guantes de vaqueta']), 'el tornero no usa guantes (máquina rotante)');
        assert_same(0, (int) $tornero['Protector facial']['mandatory'], 'según tarea');
        assert_same(0, IndustryTemplates::applyPpe('metalurgica')['created'], 'aplicar de nuevo no agrega nada');
    },

    'estado: nunca entregado, al día, por vencer, vencido; según tarea no falta; extras; solo personal propio' => function () use ($setup, &$st, $sign, $item, $fresh, $stateOf) {
        $setup();
        UserAuth::setCurrent($st['hys']);
        $w = $fresh($st['welder']);
        $status = PpeService::status($w);
        assert_same('nunca', $status['overall']);
        assert_same(8, $status['counts']['nunca']);
        assert_true(!PpeService::canSeeEmployee($st['contractor']), 'los de contratistas no se gestionan');
        assert_same(['opcional'], array_values(array_unique(array_map(fn ($r) => $r['state'],
            array_filter(PpeService::status($st['turner'])['rows'], fn ($r) => !$r['mandatory'])))));
        $deliver = fn (string $preset, int $daysAgo, array $extra = []) => PpeService::deliver($fresh($st['welder']), ['reason' => 'vencimiento',
            'delivered_at' => gmdate('Y-m-d\TH:i:s\Z', time() - $daysAgo * 86400), 'signature' => $sign(),
            'items' => [['item' => $item($preset)['uuid'], 'quantity' => 1] + $extra]]);
        assert_same([], $deliver('endoaural', 35)['errors']);
        assert_same('vencido', $stateOf($w, 'endoaural'));
        assert_same([], $deliver('guantes_soldar', 50, ['size' => '9'])['errors']); // 60 días de vida → vence en 10 (aviso 15)
        assert_same('por_vencer', $stateOf($w, 'guantes_soldar'));
        $r = $deliver('botin', 0, ['size' => '42']);
        assert_same([], $r['errors']);
        assert_same('al_dia', $stateOf($w, 'botin'));
        $line = Ppe::deliveries((int) $w['id'])[0]['items'][0];
        assert_same((new DateTimeImmutable(PpeService::today()))->modify('+365 days')->format('Y-m-d'), $line['next_due_on']);
        assert_same('42', $fresh($st['welder'])['size_shoes'], 'el talle queda en el empleado');
        // Extra: pisa la vida útil del puesto y suma un elemento que el puesto no tiene
        Ppe::setExtra((int) $w['id'], (int) $item('anteojos')['id'], ['quantity' => 1, 'life_days' => 90, 'reason' => 'Anteojos con graduación']);
        $extra = array_values(array_filter(PpeService::status($w)['rows'], fn ($r) => $r['source'] === 'extra'))[0];
        assert_same(['nunca', 90], [$extra['state'], $extra['life_days']]);
        Ppe::setExtra((int) $w['id'], (int) $item('anteojos')['id'], null);
        assert_same(null, $stateOf($w, 'anteojos'));
        $summary = PpeService::summaries([$fresh($st['welder']), $fresh($st['turner'])]);
        assert_same(PpeService::status($fresh($st['welder']))['counts'], $summary[(int) $st['welder']['id']]['counts'], 'resumen = detalle');
    },

    'entregar: validaciones, firma, copia del catálogo, inmutable, número, idempotente y planilla en papel' => function () use ($setup, &$st, $sign, $item, $fresh) {
        $setup();
        UserAuth::setCurrent($st['hys']);
        $w = $fresh($st['welder']);
        $base = ['reason' => 'inicial', 'signature' => $sign()];
        assert_true(isset(PpeService::deliver($w, $base)['errors']['items']));
        assert_true(isset(PpeService::deliver($w, $base + ['items' => [['item' => $item('ropa')['uuid']]]])['errors']['items.0']), 'falta el talle');
        assert_true(isset(PpeService::deliver($w, ['reason' => 'inicial', 'items' => [['item' => $item('casco')['uuid']]]])['errors']['signature']));
        assert_true(str_contains(PpeService::deliver($w, ['reason' => 'inicial', 'signature' => $sign(true), 'items' => [['item' => $item('casco')['uuid']]]])['errors']['signature'] ?? '', 'vacía'));
        assert_true(isset(PpeService::deliver($w, ['reason' => 'x'] + $base + ['items' => [['item' => $item('casco')['uuid']]]])['errors']['reason']));
        $uuid = App\Core\Uuid::v4();
        $r = PpeService::deliver($w, $base + ['uuid' => $uuid, 'notes' => 'Ingreso', 'items' => [['item' => $item('casco')['uuid'], 'quantity' => 1],
            ['item' => $item('ropa')['uuid'], 'quantity' => 2, 'size' => 'L']]]);
        assert_same([], $r['errors']);
        $d = $r['delivery'];
        assert_same(['pantalla', 'Pérez, Juan', '30111222'], [$d['signature_mode'], $d['signer_name'], $d['signer_dni']]);
        assert_same(hash('sha256', $d['original_data']), $d['original_hash']);
        assert_true(str_starts_with(Ppe::format((int) $d['number']), 'EPP-'));
        assert_true(!empty(PpeService::deliver($w, $base + ['uuid' => $uuid, 'items' => [['item' => $item('casco')['uuid']]]])['duplicate']), 'reenvío: no duplica');
        PpeItems::update((int) $item('casco')['id'], ['brand' => 'Marca Nueva']);
        $line = array_values(array_filter(Ppe::deliveries((int) $w['id'])[0]['items'], fn ($i) => $i['item_name'] === 'Casco de seguridad'))[0];
        assert_same([null, 'IRAM 3620'], [$line['brand'], $line['certification']], 'la entrega guarda cómo era el elemento');
        // Planilla en papel
        $tmp = tempnam(sys_get_temp_dir(), 'pap');
        file_put_contents($tmp, base64_decode(explode(',', $sign())[1]));
        assert_true(isset(PpeService::deliver($w, ['reason' => 'inicial', 'signature_mode' => 'papel', 'items' => [['item' => $item('casco')['uuid']]]], ['tmp' => $tmp, 'upload' => false])['errors']['paper_reason']));
        $p = PpeService::deliver($w, ['reason' => 'inicial', 'signature_mode' => 'papel', 'paper_reason' => 'Entrega en obra sin tablet',
            'items' => [['item' => $item('casco')['uuid']]]], ['tmp' => $tmp, 'upload' => false]);
        assert_same([], $p['errors']);
        assert_same(['papel', 'image/png'], [$p['delivery']['signature_mode'], $p['delivery']['signature_mime']]);
        assert_same((int) $d['number'] + 1, (int) $p['delivery']['number']);
        @unlink($tmp);
    },

    'anular: con motivo y permiso; la anulada no cuenta para el estado' => function () use ($setup, &$st, $sign, $item, $fresh, $stateOf) {
        $setup();
        UserAuth::setCurrent($st['hys']);
        $t = $fresh($st['turner']);
        $d = PpeService::deliver($t, ['reason' => 'inicial', 'signature' => $sign(), 'items' => [['item' => $item('anteojos')['uuid']]]])['delivery'];
        assert_same('al_dia', $stateOf($t, 'anteojos'));
        UserAuth::setCurrent($st['rep']);
        assert_true(str_contains((string) PpeService::void($d, 'Empleado equivocado'), 'permiso'));
        UserAuth::setCurrent($st['sup']); // supervisor de la nave 1: el tornero es de la nave 2
        assert_true(str_contains((string) PpeService::void($d, 'Empleado equivocado'), 'permiso'));
        UserAuth::setCurrent($st['hys']);
        assert_true(str_contains((string) PpeService::void($d, 'no'), 'motivo'));
        assert_same(null, PpeService::void($d, 'Se cargó al empleado equivocado'));
        assert_true(str_contains((string) PpeService::void(Ppe::findDelivery($d['uuid']), 'otra vez'), 'ya estaba'));
        assert_same('nunca', $stateOf($t, 'anteojos'));
    },

    'alcance: el supervisor ve y entrega solo en sus sectores; catálogo y matriz solo con alcance total' => function () use ($setup, &$st, $sign, $item, $fresh) {
        $setup();
        UserAuth::setCurrent($st['sup']);
        assert_same(['30111222'], array_column(PpeService::employees(), 'dni'));
        assert_true(!PpeService::canManage(), 'el supervisor no edita el catálogo');
        assert_true(isset(PpeService::deliver($fresh($st['turner']), ['reason' => 'inicial', 'signature' => $sign(), 'items' => [['item' => $item('anteojos')['uuid']]]])['errors']['permiso']));
        assert_same([], PpeService::deliver($fresh($st['welder']), ['reason' => 'rotura', 'signature' => $sign(), 'items' => [['item' => $item('careta')['uuid']]]])['errors']);
        UserAuth::setCurrent($st['rep']);
        assert_same([], PpeService::employees());
        UserAuth::setCurrent($st['hys']);
        assert_true(PpeService::canManage());
    },

    'HTTP: listado, ficha, entregar con firma, catálogo, matriz y constancia SRT 299/11' => function () use ($setup, &$st, $sign, $item) {
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
        $w = $st['welder']['uuid'];
        $list = $call($st['hys'], '/panel/epp');
        assert_true($list->status === 200 && str_contains($list->body, 'Pérez, Juan') && !str_contains($list->body, 'Ruiz, Juan'), 'sin contratistas');
        assert_same(200, $call($st['hys'], '/panel/epp/empleado/' . $w)->status);
        assert_same(404, $call($st['hys'], '/panel/epp/empleado/' . $st['contractor']['uuid'])->status);
        $form = $call($st['sup'], '/panel/epp/empleado/' . $w . '/entregar');
        assert_true($form->status === 200 && str_contains($form->body, 'Careta de soldar') && str_contains($form->body, 'data-signature'));
        Tenant::activate($st['a']);
        $post = ['reason' => 'vencimiento', 'signature_mode' => 'pantalla', 'signature' => $sign(),
            'items' => [['selected' => '1', 'item' => $item('delantal')['uuid'], 'quantity' => '1'], ['item' => $item('casco')['uuid'], 'quantity' => '1']]];
        $res = $call($st['sup'], '/panel/epp/empleado/' . $w . '/entregar', 'POST', $post);
        assert_same(302, $res->status);
        Tenant::activate($st['a']);
        $last = Ppe::deliveries((int) $st['welder']['id'])[0];
        assert_same(['Delantal de cuero para soldador'], array_column($last['items'], 'item_name'), 'solo lo tildado');
        $cert = $call($st['hys'], '/panel/epp/empleado/' . $w . '/constancia');
        assert_true($cert->status === 200 && str_contains($cert->body, '299/11') && str_contains($cert->body, 'Metalúrgica Sur SA')
            && str_contains($cert->body, 'Delantal de cuero') && str_contains($cert->body, '/firma'));
        assert_same(200, $call($st['sup'], '/panel/epp/entregas/' . $last['uuid'] . '/firma')->status);
        assert_same(200, $call($st['sup'], '/panel/epp/catalogo')->status);
        assert_same(200, $call($st['sup'], '/panel/epp/matriz')->status);
        assert_same(404, $call($st['sup'], '/panel/epp/catalogo', 'POST', ['name' => 'Guante X', 'category' => 'manos'])->status, 'el supervisor no edita el catálogo');
        $res = $call($st['hys'], '/panel/epp/catalogo', 'POST', ['name' => 'Guante anticorte', 'category' => 'manos', 'life_days' => '45', 'size_type' => 'guantes']);
        assert_same(302, $res->status);
        Tenant::activate($st['a']);
        assert_true(PpeItems::findBy('name', 'Guante anticorte') !== null);
        assert_same(403, $call($st['rep'], '/panel/epp')->status);
        $_SESSION = [];
    },

    'aislamiento: la empresa B no ve el EPP de A' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['b']);
        UserAuth::setCurrent(null);
        assert_same(0, (int) DB::tenant()->query('SELECT COUNT(*) FROM ppe_deliveries')->fetchColumn());
        assert_same(0, PpeItems::count(null, true));
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
