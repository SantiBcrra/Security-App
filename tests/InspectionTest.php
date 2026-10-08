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
        assert_same(['created' => 0, 'existing' => 11], IndustryTemplates::applyInspections('metalurgica'), 'volver a cargar no duplica (6 de inspección + 5 de permisos)');
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

    'programas: fechas por período (diaria, semanal, mensual)' => function () {
        $weekly = App\Services\InspectionPlanner::periods(['frequency' => 'semanal', 'weekday' => 3, 'monthday' => null], '2026-10-01', '2026-10-31');
        assert_same(['2026-10-07', '2026-10-14', '2026-10-21', '2026-10-28'], array_column($weekly, 2), 'los miércoles');
        assert_same(['2026-W41', '2026-10-05'], [$weekly[0][0], $weekly[0][1]], 'la ventana empieza el lunes');
        $monthly = App\Services\InspectionPlanner::periods(['frequency' => 'mensual', 'weekday' => null, 'monthday' => 31], '2027-02-01', '2027-03-31');
        assert_same([['2027-02', '2027-02-01', '2027-02-28'], ['2027-03', '2027-03-01', '2027-03-31']], $monthly, 'día 31 en febrero = último día');
        assert_same(3, count(App\Services\InspectionPlanner::periods(['frequency' => 'diaria', 'weekday' => null, 'monthday' => null], '2026-10-01', '2026-10-03')));
    },

    'programas: validación, generación idempotente y equipos nuevos' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $tpl = InspectionTemplates::findById((int) $st['tplAe']['id']);
        [, $errors] = App\Services\InspectionPlanner::save(null, ['template' => $tpl['uuid'], 'target_type' => 'sector', 'sector' => Sectors::findById($st['nave1'])['uuid']]);
        assert_true(str_contains(implode(' ', $errors), 'es de equipos'));
        [$p, $errors] = App\Services\InspectionPlanner::save(null, ['template' => $tpl['uuid'], 'frequency' => 'diaria', 'target_type' => 'tipo',
            'assignee_type' => 'sector_supervisors', 'action_responsible' => $st['sup2']['uuid']]);
        assert_same([], $errors);
        assert_same('Pre-uso autoelevador', $p['name'], 'sin nombre: el del checklist');
        $count = fn () => (int) DB::tenant()->query('SELECT COUNT(*) FROM inspection_schedule')->fetchColumn();
        assert_same(7, $count(), 'hoy + 6 días para el único autoelevador (al guardar ya se generan)');
        assert_same(0, App\Services\InspectionPlanner::generate(ActionService::today()), 'correrlo de nuevo no duplica');
        $site = (int) $st['ae']['site_id'];
        $st['ae2'] = Equipment::findById(Equipment::create(['code' => 'AE-02', 'name' => 'Autoelevador Hyster', 'site_id' => $site, 'sector_id' => $st['nave1'],
            'type_id' => (int) $st['ae']['type_id']]));
        assert_same(7, App\Services\InspectionPlanner::generate(ActionService::today()), 'el equipo nuevo entra solo');
        $st['program'] = $p;
    },

    'programadas: la inspección marca la de hoy y las acciones van al responsable del programa' => function () use ($setup, &$st, $keys, $allOk) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['rep']);
        $tpl = InspectionTemplates::findById((int) $st['tplAe']['id']);
        $r = InspectionService::create(['template' => $tpl['uuid'], 'equipment' => $st['ae']['uuid'],
            'answers' => $allOk($keys($tpl), ['Luces delanteras y traseras funcionan' => ['value' => 'no', 'comment' => 'Faro trasero quemado']])]);
        assert_same([], $r['errors']);
        $today = ActionService::today();
        $sched = App\Models\InspectionSchedules::search(['equipment_id' => (int) $st['ae']['id'], 'due_on_from' => $today, 'due_on_to' => $today], null)[0];
        assert_same(['hecha', 1, (int) $r['inspection']['id']], [$sched['status'], (int) $sched['on_time'], (int) $sched['inspection_id']]);
        $action = Actions::forOrigin('inspeccion', (int) $r['inspection']['id'])[0];
        assert_same((int) $st['sup2']['id'], (int) $action['responsible_user_id'], 'responsable de acciones del programa');
    },

    'recordatorios: para hoy y vencida, una sola vez; omitir con motivo' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent(null);
        $today = ActionService::today();
        $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $inbox = fn (array $u, string $prefix) => count(array_filter(array_column(DB::tenant()->query('SELECT title FROM notifications WHERE user_id = '
            . (int) $u['id'])->fetchAll(), 'title'), fn ($t) => str_starts_with($t, $prefix)));
        $r = App\Services\Notify\InspectionReminders::run($today);
        assert_same(1, $r['due'], 'solo AE-02 (AE-01 ya está hecha)');
        assert_same(1, $inbox($st['sup'], 'Inspección para hoy'), 'al supervisor del sector (a cargo)');
        assert_same(0, App\Services\Notify\InspectionReminders::run($today)['due'], 'no se repite');
        // AE-02 de mañana: se omite (rep no puede, SyH sí)
        $ae2Tomorrow = App\Models\InspectionSchedules::search(['equipment_id' => (int) $st['ae2']['id'], 'due_on_from' => $tomorrow, 'due_on_to' => $tomorrow], null)[0];
        UserAuth::setCurrent($st['rep']);
        assert_true(str_contains((string) App\Services\InspectionPlanner::skip($ae2Tomorrow, 'en reparación'), 'permiso'));
        UserAuth::setCurrent($st['hys']);
        assert_true(str_contains((string) App\Services\InspectionPlanner::skip($ae2Tomorrow, 'x'), 'motivo'));
        assert_same(null, App\Services\InspectionPlanner::skip($ae2Tomorrow, 'Equipo en el taller'));
        UserAuth::setCurrent(null);
        $r = App\Services\Notify\InspectionReminders::run($tomorrow);
        assert_same(1, $r['overdue'], 'la de hoy de AE-02 quedó vencida');
        assert_same(1, $inbox($st['sup'], 'Inspección vencida'));
        assert_same(1, $r['due'], 'mañana: solo AE-01 (la de AE-02 se omitió)');
    },

    'cumplimiento: a tiempo / (vencidas - omitidas), por programa y por equipo' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $today = ActionService::today();
        $after = (new DateTimeImmutable($today))->modify('+2 days')->format('Y-m-d'); // hoy y mañana ya "vencieron"
        $rows = App\Models\InspectionSchedules::compliance($today, (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d'), $after, 'program', null);
        assert_same(1, count($rows));
        assert_same([4, 1, 0, 2, 1], [(int) $rows[0]['total'], (int) $rows[0]['on_time'], (int) $rows[0]['late'], (int) $rows[0]['missed'], (int) $rows[0]['skipped']]);
        assert_same(33, App\Controllers\Web\Panel\InspectionsController::percent($rows[0]), '1 a tiempo de 3 (la omitida no cuenta)');
        $byEq = array_column(App\Models\InspectionSchedules::compliance($today, $after, $after, 'equipment', null), null, 'label');
        assert_same(1, (int) $byEq['AE-01 · Autoelevador Toyota']['on_time']);
        UserAuth::setCurrent($st['sup2']);
        assert_same([], App\Models\InspectionSchedules::compliance($today, $after, $after, 'program', App\Services\InspectionPlanner::scope()),
            'el supervisor de otra nave no ve estas');
    },

    'HTTP: programadas, cumplimiento, programas, CSV e inicio' => function () use ($setup, &$st) {
        $setup();
        $call = function (array $user, string $path) use (&$st) {
            UserAuth::setCurrent(null);
            Tenant::deactivate();
            $_SESSION = [];
            \App\Core\Session::put(\App\Services\Impersonation::TENANT_KEY, $st['a']['uuid']);
            \App\Core\Session::put(UserAuth::USER_KEY, $user['uuid']);
            [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
            parse_str($q, $query);
            $router = new \App\Core\Router();
            (require BASE_PATH . '/app/routes.php')($router);
            return $router->dispatch(new \App\Core\Request('GET', $p, $query));
        };
        $page = $call($st['sup'], '/panel/inspecciones/programadas?ver=hoy');
        assert_same(200, $page->status);
        assert_true(str_contains($page->body, 'AE-0'), 'lista las programadas de su nave');
        assert_same(200, $call($st['sup'], '/panel/inspecciones/programadas?ver=vencidas&mias=1')->status);
        assert_same(200, $call($st['hys'], '/panel/inspecciones/cumplimiento?por=sector')->status);
        $csv = $call($st['hys'], '/panel/inspecciones/cumplimiento?csv=1');
        assert_true(str_contains($csv->body, 'Programadas;"A tiempo"'), 'csv cumplimiento: ' . substr($csv->body, 0, 120));
        assert_same(200, $call($st['hys'], '/panel/inspecciones/programas')->status);
        assert_same(200, $call($st['hys'], '/panel/inspecciones/programas/' . $st['program']['uuid'])->status);
        assert_same(200, $call($st['hys'], '/panel/inspecciones/programas/nuevo')->status);
        assert_same(403, $call($st['rep'], '/panel/inspecciones/programas')->status);
        $exp = $call($st['hys'], '/panel/inspecciones/exportar');
        assert_true(str_contains($exp->body, 'Número;Fecha;Checklist'), 'csv inspecciones: ' . substr($exp->body, 0, 120));
        $home = $call($st['sup'], '/panel');
        assert_true(str_contains($home->body, 'Inspecciones de hoy'), 'recuadro en el inicio');
        $form = $call($st['sup'], '/panel/inspecciones/nueva?plantilla=' . $st['tplAe']['uuid'] . '&equipo=' . $st['ae2']['uuid'] . '&programada=x');
        assert_same(200, $form->status);
        $_SESSION = [];
    },

    'app de campo: pull de checklists y de mis programadas' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        DB::tenant()->exec('UPDATE inspection_templates SET updated_at = updated_at - INTERVAL 60 SECOND');
        DB::tenant()->exec('UPDATE inspection_schedule SET updated_at = updated_at - INTERVAL 60 SECOND');
        $pull = function (array $u) {
            UserAuth::setCurrent($u);
            $page = App\Services\Sync\Pull::run(null, 1000);
            return [(array) ($page['changes']->inspection_templates ?? []), (array) ($page['changes']->inspection_schedule ?? [])];
        };
        [$tpls, $sched] = $pull($st['rep']);
        $ae = array_values(array_filter($tpls, fn ($t) => $t['name'] === 'Pre-uso autoelevador'))[0];
        assert_true(count($ae['structure']['sections']) === 3 && $ae['version_uuid'] !== null && $ae['type_uuid'] !== null, 'estructura completa para hacerla offline');
        assert_same([], $sched, 'el operario no tiene programadas a su cargo (son de los supervisores)');
        [, $sched] = $pull($st['sup']);
        $today = ActionService::today();
        assert_true(count($sched) >= 1 && !array_filter($sched, fn ($s) => $s['equipment_uuid'] === null), 'el supervisor recibe las de su nave');
        assert_true(in_array($st['ae2']['uuid'], array_column($sched, 'equipment_uuid'), true));
        [, $sched2] = $pull($st['sup2']);
        assert_same([], $sched2, 'el de otra nave no');
    },

    'app de campo: inspección offline por sync con foto por partes; reenvío sin duplicar' => function () use ($setup, &$st, $keys, $allOk, $photo) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['sup']);
        $tpl = InspectionTemplates::findById((int) $st['tplAe']['id']);
        $items = $keys($tpl);
        $brake = $items['Frenos de servicio funcionan']['key'];
        $uuid = Uuid::v4();
        $today = ActionService::today();
        $sched = App\Models\InspectionSchedules::search(['equipment_id' => (int) $st['ae2']['id'], 'status' => 'pendiente', 'due_on_from' => $today, 'due_on_to' => $today], null);
        $data = ['uuid' => $uuid, 'template' => $tpl['uuid'], 'version' => $tpl['current_version_uuid'], 'equipment' => $st['ae2']['uuid'],
            'schedule' => $sched[0]['uuid'] ?? null, 'done_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 300),
            'answers' => $allOk($items, ['Frenos de servicio funcionan' => ['value' => 'no', 'comment' => 'No frena']])];
        $op = fn (array $d) => ['op_id' => Uuid::v4(), 'type' => 'inspection.create', 'data' => $d];
        $noPhoto = App\Services\Sync\Push::run([$op($data)])[0];
        assert_true(str_contains((string) ($noPhoto['error'] ?? ''), 'Falta la foto'), 'sin declarar la foto obligatoria se rechaza');
        $r = App\Services\Sync\Push::run([$op($data + ['photo_counts' => [$brake => 1]])])[0];
        assert_same('ok', $r['status'], json_encode($r));
        assert_same(['no_conforme_critico', 1], [$r['data']['result'], $r['data']['actions']]);
        assert_same(true, App\Services\Sync\Push::run([$op($data + ['photo_counts' => [$brake => 1]])])[0]['data']['duplicate'], 'reenvío: no duplica');
        $i = Inspections::findByUuid($uuid);
        if ($sched) {
            assert_same('hecha', App\Models\InspectionSchedules::findById((int) $sched[0]['id'])['status'], 'marca la programada indicada');
        }
        // La foto por partes, con su ítem
        $bytes = file_get_contents($photo()['tmp']);
        $up = Uuid::v4();
        $init = App\Services\Uploads::init(['upload_uuid' => $up, 'inspection_uuid' => $uuid, 'item_key' => $brake, 'name' => 'freno.jpg',
            'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        assert_same('receiving', $init['status'] ?? null, json_encode($init));
        App\Services\Uploads::chunk($up, 0, $bytes);
        assert_same('completed', App\Services\Uploads::complete($up)['status'] ?? null);
        $att = Inspections::attachments((int) $i['id']);
        assert_same([1, $brake], [count($att), $att[0]['item_key']]);
        UserAuth::setCurrent($st['rep2']);
        $other = App\Services\Uploads::init(['upload_uuid' => Uuid::v4(), 'inspection_uuid' => $uuid, 'size' => 10, 'sha256' => str_repeat('a', 64)]);
        assert_same(404, $other['code'] ?? null, 'nadie más sube fotos a una inspección ajena');
        UserAuth::setCurrent($st['sup']);
        $api = (new App\Controllers\Api\InspectionsController())->show(new App\Core\Request('GET', '/api/v1/inspections/' . $uuid), $uuid);
        $json = json_decode($api->body, true)['data'];
        assert_same(['no_conforme_critico', true], [$json['result'], (bool) array_filter($json['answers'], fn ($a) => $a['ok'] === false && $a['action_code'])]);
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
