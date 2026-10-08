<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Storage;
use App\Core\Tenant;
use App\Core\Uuid;
use App\Models\Actions;
use App\Models\CatalogItems;
use App\Models\Equipment;
use App\Models\InspectionTemplates;
use App\Models\Inspections;
use App\Models\PlatformSettings;
use App\Models\Roles;
use App\Models\Sectors;
use App\Models\Sites;
use App\Models\Tenants;
use App\Models\Users;
use App\Models\UserSectors;
use App\Policies\Permissions;
use App\Services\ActionService;
use App\Services\IndustryTemplates;
use App\Services\InspectionService;
use App\Services\InspectionStructure;
use App\Services\InspectionTemplateService;
use App\Services\Notify\Channels;
use App\Services\Notify\MailTransport;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Etapa 11 (entrega 1): plantillas, inspecciones, acciones automáticas. Maestra `securityapp_test_insp`.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_insp';
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
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'In A', 'slug' => 'in-a'] + $company, null));
    $st['b'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'In B', 'slug' => 'in-b'] + $company, null));
    MailTransport::$fake = fn () => null;
    Channels::$fakeExternal = fn () => null;

    Tenant::activate($st['a']);
    $site = Sites::create(['name' => 'Planta']);
    $st['nave1'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 1']);
    $st['nave2'] = Sectors::create(['site_id' => $site, 'parent_id' => null, 'name' => 'Nave 2']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    $st['hys'] = $mk('responsable_hys', 'hys@in.test');
    $st['sup'] = $mk('supervisor', 'sup@in.test');
    $st['sup2'] = $mk('supervisor', 'sup2@in.test');
    $st['rep'] = $mk('reportante', 'rep@in.test');
    $st['rep2'] = $mk('reportante', 'rep2@in.test');
    UserSectors::replace((int) $st['sup']['id'], [$st['nave1']]);
    UserSectors::replace((int) $st['sup2']['id'], [$st['nave2']]);
    UserAuth::setCurrent($st['hys']);
    IndustryTemplates::apply('metalurgica'); // catálogos + checklists precargados
    $st['ae'] = Equipment::findById(Equipment::create(['code' => 'AE-01', 'name' => 'Autoelevador Toyota', 'site_id' => $site, 'sector_id' => $st['nave1'],
        'type_id' => (int) CatalogItems::findByName('tipo_equipo', 'Autoelevador')['id']]));
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

$photo = function (): array {
    $img = imagecreatetruecolor(240, 180);
    imagefilledrectangle($img, 10, 10, 120, 120, imagecolorallocate($img, 30, 30, 200));
    $tmp = tempnam(sys_get_temp_dir(), 'in');
    imagejpeg($img, $tmp, 85);
    return ['tmp' => $tmp, 'name' => 'falla.jpg', 'upload' => false];
};

/** item_key por texto, de la versión vigente de la plantilla. */
$keys = function (array $template): array {
    $out = [];
    foreach (InspectionStructure::items(InspectionTemplates::version((int) $template['current_version_id'])['structure']) as ['item' => $item]) {
        $out[$item['text']] = $item;
    }
    return $out;
};

/** Respuestas que cumplen todo (según ok_when / rango), con $over por texto. */
$allOk = function (array $items, array $over = []): array {
    $answers = [];
    foreach ($items as $text => $item) {
        $answers[$item['key']] = $over[$text] ?? ['value' => match ($item['type']) {
            'numero' => (string) ($item['min'] ?? $item['max'] ?? 1), 'texto' => 'ok', default => $item['ok_when'],
        }];
    }
    return $answers;
};

return [
    'precargadas: 6 checklists de metalúrgica, idempotentes y con ítems críticos' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $all = InspectionTemplates::all();
        assert_same(6, count($all));
        $ae = InspectionTemplates::findByPreset('metalurgica/autoelevador');
        assert_same(['equipo', 'Autoelevador'], [$ae['scope'], $ae['equipment_type_name']]);
        $structure = InspectionTemplates::version((int) $ae['current_version_id'])['structure'];
        $critical = array_filter(iterator_to_array(InspectionStructure::items($structure), false), fn ($r) => $r['item']['critical']);
        assert_true(count($critical) >= 5, 'frenos, bocina, alarma, cinturón, horquillas…');
        $leak = array_values(array_filter(iterator_to_array(InspectionStructure::items($structure), false), fn ($r) => str_contains($r['item']['text'], 'Pierde aceite')))[0];
        assert_same('no', $leak['item']['ok_when'], 'pregunta invertida: cumple con "no"');
        assert_same(['created' => 0, 'existing' => 6], IndustryTemplates::applyInspections('metalurgica'), 'volver a cargar no duplica');
        assert_same('sector', InspectionTemplates::findByPreset('metalurgica/orden_limpieza')['scope']);
        $st['tplAe'] = $ae;
    },

    'estructura: validación y normalización' => function () {
        [, $count, $errors] = InspectionStructure::normalize(['sections' => [['title' => 'X', 'items' => [['text' => '']]]]]);
        assert_same(0, $count);
        assert_true(in_array('El checklist necesita al menos un ítem.', $errors, true));
        [$s, $count, $errors] = InspectionStructure::normalize(['sections' => [['title' => '', 'items' => [
            ['text' => 'Presión', 'type' => 'numero', 'min' => '10', 'max' => '5', 'critical' => true],
            ['text' => 'Observación', 'type' => 'texto', 'critical' => true],
            ['text' => 'Rara', 'type' => 'inventado', 'ok_when' => 'tal vez'],
        ]]]]);
        assert_same(3, $count);
        assert_true(str_contains($errors[0], 'mínimo es mayor'));
        assert_same('Sección 1', $s['sections'][0]['title']);
        assert_same(false, $s['sections'][0]['items'][1]['critical'], 'un texto no puede ser crítico');
        assert_same(['si_no', 'si'], [$s['sections'][0]['items'][2]['type'], $s['sections'][0]['items'][2]['ok_when']]);
        assert_true(Uuid::isValid($s['sections'][0]['items'][0]['key']));
    },

    'inspección: validaciones (comentario y foto si no cumple, respuestas completas)' => function () use ($setup, &$st, $keys, $allOk, $photo) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        $items = $keys($st['tplAe']);
        $base = ['template' => $st['tplAe']['uuid'], 'equipment' => $st['ae']['uuid']];
        $r = InspectionService::create($base + ['answers' => []]);
        assert_true(count($r['errors']) >= 10, 'faltan todas las respuestas');
        $noEq = InspectionService::create(['template' => $st['tplAe']['uuid'], 'answers' => $allOk($items)]);
        assert_true(isset($noEq['errors']['equipment']));
        $brake = $items['Frenos de servicio funcionan'];
        $r = InspectionService::create($base + ['answers' => $allOk($items, ['Frenos de servicio funcionan' => ['value' => 'no']])]);
        assert_true(str_contains($r['errors'][$brake['key']] ?? '', 'Contá qué pasa'));
        $r = InspectionService::create($base + ['answers' => $allOk($items, ['Frenos de servicio funcionan' => ['value' => 'no', 'comment' => 'Pedal largo']])]);
        assert_true(str_contains($r['errors'][$brake['key']] ?? '', 'Falta la foto'), 'crítico: foto si no cumple');
        assert_same(0, Inspections::count([], null), 'nada se guardó');
    },

    'inspección: resultado en el servidor, una acción por ítem y aviso de falla crítica' => function () use ($setup, &$st, $keys, $allOk, $photo) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        $items = $keys($st['tplAe']);
        $answers = $allOk($items, [
            'Frenos de servicio funcionan' => ['value' => 'no', 'comment' => 'Pedal largo, frena poco'],
            'Neumáticos en buen estado' => ['value' => 'no', 'comment' => 'Trasero izquierdo gastado'],
            'Espejos y vidrios en buen estado' => ['value' => 'na'],
            '¿Pierde aceite, combustible o líquido hidráulico?' => ['value' => 'no'], // cumple
        ]);
        $uuid = Uuid::v4();
        $r = InspectionService::create(['uuid' => $uuid, 'template' => $st['tplAe']['uuid'], 'equipment' => $st['ae']['uuid'], 'answers' => $answers,
            'done_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 600), 'lat' => '-34.6', 'lng' => '-58.4'],
            [$items['Frenos de servicio funcionan']['key'] => [$photo()]]);
        assert_same([], $r['errors']);
        $i = $r['inspection'];
        $total = count($items) - 1; // sin el N/A
        assert_same(['no_conforme_critico', 2, 1, (int) round(100 * ($total - 2) / $total)], [$i['result'], (int) $i['items_fail'], (int) $i['items_critical_fail'], (int) $i['score']]);
        assert_same((int) $st['nave1'], (int) $i['sector_id'], 'el sector sale del equipo');
        assert_same(hash('sha256', $i['original_data']), $i['original_hash']);
        $actions = Actions::forOrigin('inspeccion', (int) $i['id']);
        assert_same(2, count($actions), 'una acción por ítem que no cumple');
        $byPriority = array_column($actions, null, 'priority');
        assert_same((int) $st['sup']['id'], (int) $byPriority['critica']['responsible_user_id'], 'responsable: el supervisor del sector');
        $today = ActionService::today();
        assert_same((new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d'), $byPriority['critica']['due_on']);
        assert_same((new DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d'), $byPriority['media']['due_on']);
        assert_true(str_starts_with($byPriority['critica']['title'], 'AE-01: Frenos'));
        $linked = array_filter(Inspections::answers((int) $i['id']), fn ($a) => $a['action_id'] !== null);
        assert_same(2, count($linked));
        assert_same(1, count(Inspections::attachments((int) $i['id'])));
        $inbox = fn (array $u) => array_column(DB::tenant()->query('SELECT title FROM notifications WHERE user_id = ' . (int) $u['id'])->fetchAll(), 'title');
        $critical = fn (array $u) => count(array_filter($inbox($u), fn ($t) => str_contains($t, 'Falla crítica')));
        assert_same(1, $critical($st['sup']), 'avisa al supervisor del sector');
        assert_same(1, $critical($st['hys']), 'y a SyH');
        assert_same(0, $critical($st['sup2']), 'no al de otra nave');
        $dup = InspectionService::create(['uuid' => $uuid, 'template' => $st['tplAe']['uuid'], 'equipment' => $st['ae']['uuid'], 'answers' => $answers]);
        assert_same([true, (int) $i['id']], [$dup['duplicate'] ?? false, (int) $dup['inspection']['id']], 'reenvío con el mismo uuid: no duplica');
        $st['insp'] = $i;
        $st['inspAction'] = $actions[0];
    },

    'versiones: editar sin uso corrige; con uso crea versión nueva' => function () use ($setup, &$st, $keys) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        [$t] = InspectionTemplateService::save(null, ['name' => 'Escaleras', 'scope' => 'general',
            'structure' => ['sections' => [['title' => 'Control', 'items' => [['text' => 'Patas antideslizantes']]]]]]);
        $v1 = (int) $t['current_version_id'];
        $items = $keys($t);
        [$t] = InspectionTemplateService::save($t, ['name' => 'Escaleras', 'scope' => 'general', 'structure' => ['sections' => [['title' => 'Control', 'items' => [
            ['key' => $items['Patas antideslizantes']['key'], 'text' => 'Patas antideslizantes'], ['text' => 'Peldaños firmes']]]]]]);
        assert_same([$v1, 1], [(int) $t['current_version_id'], (int) $t['current_version']], 'sin inspecciones: misma versión');
        $ae = InspectionTemplates::findById((int) $st['tplAe']['id']);
        $aeV1 = (int) $ae['current_version_id'];
        $struct = InspectionTemplates::version($aeV1)['structure'];
        $struct['sections'][0]['items'][] = ['text' => 'Extintor con tarjeta al día', 'type' => 'si_no'];
        [$ae2, $errors] = InspectionTemplateService::save($ae, ['name' => $ae['name'], 'scope' => 'equipo', 'equipment_type' => $ae['equipment_type_uuid'], 'structure' => $struct]);
        assert_same([], $errors);
        assert_same(2, (int) $ae2['current_version'], 'ya usada: versión 2');
        assert_same($aeV1, (int) Inspections::findById((int) $st['insp']['id'])['template_version_id'], 'la inspección vieja sigue en la versión 1');
        $first = array_values($keys($ae2))[0]['key'];
        assert_same(array_values($keys($ae))[0]['key'], $first, 'las claves de los ítems se conservan entre versiones');
    },

    'alcance y anulación' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        $count = function (array $u) {
            UserAuth::setCurrent($u);
            return Inspections::count(['status' => 'completa'], InspectionService::scope());
        };
        assert_same(1, $count($st['rep']), 'el operario ve las suyas');
        assert_same(0, $count($st['rep2']));
        assert_same(1, $count($st['sup']), 'el supervisor, las de su nave');
        assert_same(0, $count($st['sup2']));
        assert_same(1, $count($st['hys']));
        $i = $st['insp'];
        UserAuth::setCurrent($st['rep']);
        assert_true(str_contains((string) InspectionService::annul($i, 'me equivoqué'), 'permiso'));
        UserAuth::setCurrent($st['hys']);
        assert_true(str_contains((string) InspectionService::annul($i, 'x'), 'motivo'));
        assert_same(null, InspectionService::annul($i, 'Se cargó en el equipo equivocado'));
        $fresh = Inspections::findById((int) $i['id']);
        assert_same('anulada', $fresh['status']);
        assert_same($i['original_hash'], $fresh['original_hash'], 'las respuestas originales no cambian');
        assert_same(0, Inspections::count(['status' => 'completa'], null));
        assert_same(1, Inspections::count(['status' => 'anulada'], null));
    },

    'permisos: el operario hace inspecciones; migración y regla de aviso' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        assert_same(['ver', 'crear'], Permissions::baseRoles()['reportante']['permissions']['inspecciones']['acciones']);
        UserAuth::setCurrent($st['rep']);
        assert_true(UserAuth::can('inspecciones', 'crear') && !UserAuth::can('inspecciones', 'editar'));
        assert_same(1, (int) DB::tenant()->query("SELECT COUNT(*) FROM notification_rules WHERE event = 'inspection.critical_fail'")->fetchColumn());
        (require BASE_PATH . '/database/migrations/tenant/0044_seed_inspections_setup.php')(DB::tenant());
        assert_same(1, (int) DB::tenant()->query("SELECT COUNT(*) FROM notification_rules WHERE event = 'inspection.critical_fail'")->fetchColumn(), 'idempotente');
    },

    'HTTP: páginas, QR del equipo y guardar por formulario' => function () use ($setup, &$st, $keys, $allOk) {
        $setup();
        $call = function (array $user, string $method, string $path, array $post = []) use (&$st) {
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
        $tpl = $st['tplAe'];
        $eq = $st['ae'];
        assert_same(200, $call($st['hys'], 'GET', '/panel/inspecciones')->status);
        assert_same(200, $call($st['rep'], 'GET', '/panel/inspecciones/nueva')->status, 'elegir checklist');
        $form = $call($st['rep'], 'GET', '/panel/inspecciones/nueva?plantilla=' . $tpl['uuid'] . '&equipo=' . $eq['uuid']);
        assert_same(200, $form->status);
        assert_true(str_contains($form->body, 'Frenos de servicio funcionan') && str_contains($form->body, 'AE-01'));
        $field = $call($st['rep'], 'GET', '/panel/equipo/' . $eq['uuid']);
        assert_same(200, $field->status, 'el operario abre la ficha de campo del QR');
        assert_true(str_contains($field->body, 'Hacer: Pre-uso autoelevador'));
        assert_true(str_ends_with((string) ($call($st['rep'], 'GET', '/q/' . $eq['uuid'])->headers['Location'] ?? ''), '/panel/equipo/' . $eq['uuid']),
            'el QR lleva a la ficha de campo');
        $act = $call($st['hys'], 'GET', '/panel/acciones/' . $st['inspAction']['uuid']);
        assert_true(str_contains($act->body, 'Inspección INS-') && str_contains($act->body, '/panel/inspecciones/'), 'la acción linkea a su inspección');
        assert_same(403, $call($st['rep'], 'GET', '/panel/inspecciones/plantillas')->status);
        assert_same(200, $call($st['hys'], 'GET', '/panel/inspecciones/plantillas')->status);
        assert_same(200, $call($st['hys'], 'GET', '/panel/inspecciones/plantillas/' . $tpl['uuid'])->status);
        Tenant::activate($st['a']);
        $tpl = InspectionTemplates::findById((int) $tpl['id']); // vigente (versión 2)
        $post = ['template' => $tpl['uuid'], 'equipment' => $eq['uuid'], 'answers' => $allOk($keys($tpl))];
        $res = $call($st['rep'], 'POST', '/panel/inspecciones', $post);
        assert_same(302, $res->status);
        Tenant::activate($st['a']);
        $new = Inspections::search(['status' => 'completa'], null, 1)[0];
        assert_same('conforme', $new['result']);
        assert_true(str_ends_with($res->headers['Location'], '/panel/inspecciones/' . $new['uuid']));
        assert_same(200, $call($st['rep'], 'GET', '/panel/inspecciones/' . $new['uuid'])->status);
        assert_same(200, $call($st['rep'], 'GET', '/panel/inspecciones/' . $new['uuid'] . '/imprimir')->status);
        assert_same(404, $call($st['rep2'], 'GET', '/panel/inspecciones/' . $new['uuid'])->status, 'otro operario no la ve');
        $_SESSION = [];
    },

    'aislamiento: la empresa B no ve plantillas ni inspecciones de A' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['b']);
        UserAuth::setCurrent(null);
        assert_same([0, 0], [count(InspectionTemplates::all()), Inspections::count([], null)]);
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
