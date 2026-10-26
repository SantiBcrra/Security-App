<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Storage;
use App\Core\Tenant;
use App\Core\Uuid;
use App\Models\PlatformSettings;
use App\Models\Roles;
use App\Models\Tenants;
use App\Models\Users;
use App\Services\Logbook;
use App\Services\Sync\Pull;
use App\Services\Sync\Push;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;

/**
 * Libro de guardia (docs/design/libro-de-guardia.md). Maestra `securityapp_test_logbook` + empresa lb-a.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_logbook';
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
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Lb A', 'slug' => 'lb-a'] + $company, null));
    Tenant::activate($st['a']);
    $mk = fn (string $role, string $email) => Users::findById(Users::create(['name' => ucfirst(explode('@', $email)[0]), 'email' => $email,
        'role_id' => (int) Roles::findBySlug($role)['id'], 'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
    $st['hys'] = $mk('responsable_hys', 'jefe@lb.test');
    $st['sup'] = $mk('supervisor', 'super@lb.test');
    $st['g1'] = $mk('reportante', 'noche@lb.test');
    $st['g2'] = $mk('reportante', 'dia@lb.test');
    $st['aud'] = $mk('auditor', 'art@lb.test');
    $st['site'] = \App\Models\Sites::findById(\App\Models\Sites::create(['name' => 'Planta Norte', 'code' => 'PN', 'is_active' => 1]));
    $st['site2'] = \App\Models\Sites::findById(\App\Models\Sites::create(['name' => 'Depósito', 'code' => 'DE', 'is_active' => 1]));
    file_put_contents(Storage::path('installed.lock'), 'test');
    $st['ready'] = true;
};

$op = fn (string $type, array $data) => ['op_id' => Uuid::v4(), 'type' => $type, 'data' => $data];
$count = fn (string $event) => (int) DB::tenant()->query('SELECT COUNT(*) FROM notifications WHERE event = ' . DB::tenant()->quote($event))->fetchColumn();
$sign = function (): string {
    $img = imagecreatetruecolor(400, 140);
    imagesavealpha($img, true);
    imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
    $ink = imagecolorallocate($img, 20, 20, 30);
    imagesetthickness($img, 3);
    for ($i = 0; $i < 6; $i++) imageline($img, 20 + $i * 50, 100 - ($i % 2) * 60, 70 + $i * 50, 40 + ($i % 2) * 60, $ink);
    ob_start();
    imagepng($img);
    return 'data:image/png;base64,' . base64_encode((string) ob_get_clean());
};

return [
    'libro: permisos del módulo en los roles base (guardia anota, auditor solo mira)' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        assert_true(UserAuth::can('libro', 'crear') && !UserAuth::can('libro', 'editar'));
        UserAuth::setCurrent($st['aud']);
        assert_true(UserAuth::can('libro', 'ver') && !UserAuth::can('libro', 'crear'));
        try { Logbook::create(['site_uuid' => $st['site']['uuid'], 'kind' => 'novedad', 'text' => 'x']); assert_true(false, 'el auditor no anota'); } catch (App\Core\UserError) {}
    },

    'libro: novedad, persona y vehículo; validaciones, patente normalizada, folios por predio e idempotencia' => function () use ($setup, &$st, $count) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $site = $st['site']['uuid'];
        $n = Logbook::create(['site_uuid' => $site, 'kind' => 'novedad', 'category' => 'rutina', 'text' => 'Recorrida perimetral sin novedad']);
        $p = Logbook::create(['site_uuid' => $site, 'kind' => 'persona', 'person_name' => 'Juan Pérez', 'person_doc' => '30.111.222', 'company' => 'Gas SA', 'visiting' => 'Mantenimiento']);
        $v = Logbook::create(['site_uuid' => $site, 'kind' => 'vehiculo', 'plate' => 'ab 123-cd', 'vehicle_type' => 'camion', 'driver' => 'Luis', 'company' => 'Transportes Sur']);
        assert_same([1, 2, 3], [(int) $n['entry']['folio'], (int) $p['entry']['folio'], (int) $v['entry']['folio']]);
        assert_same(['30111222', 'AB123CD'], [$p['entry']['person_doc'], $v['entry']['plate']]);
        $other = Logbook::create(['site_uuid' => $st['site2']['uuid'], 'kind' => 'novedad', 'text' => 'Otro predio']);
        assert_same(1, (int) $other['entry']['folio'], 'cada predio tiene su numeración');
        $uuid = Uuid::v4();
        Logbook::create(['uuid' => $uuid, 'site_uuid' => $site, 'kind' => 'novedad', 'text' => 'Desde la app sin señal'], 'app');
        assert_true(Logbook::create(['uuid' => $uuid, 'site_uuid' => $site, 'kind' => 'novedad', 'text' => 'otra vez'], 'app')['duplicate'], 'reenvío: no duplica');
        foreach ([['kind' => 'persona', 'reason' => 'nada que identifique'], ['kind' => 'vehiculo', 'driver' => 'sin patente'], ['kind' => 'novedad', 'text' => ' '], ['kind' => 'otra']] as $bad) {
            try { Logbook::create(['site_uuid' => $site] + $bad); assert_true(false, json_encode($bad)); } catch (App\Core\UserError) {}
        }
        $before = $count('logbook.important');
        Logbook::create(['site_uuid' => $site, 'kind' => 'novedad', 'category' => 'incidente', 'text' => 'Portón 2 forzado', 'important' => 1]);
        assert_same($before + 2, $count('logbook.important'), 'avisa a SyH y supervisor');
        assert_same(['AB123CD'], array_column(Logbook::lookup('vehiculo', 'ab1'), 'plate'), 'padrón para autocompletar');
        assert_same('Juan Pérez', Logbook::lookup('persona', '3011')[0]['name']);
    },

    'libro: condición insegura avisa al instante (crítico, también a la casilla de RRHH) y abre una observación' => function () use ($setup, &$st, $count) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        \App\Services\IndustryTemplates::apply('metalurgica'); // categorías y severidades
        \App\Models\Sectors::create(['site_id' => (int) $st['site']['id'], 'parent_id' => null, 'name' => 'Depósito 2']);
        $rule = \App\Models\NotificationRules::all();
        $unsafe = array_values(array_filter($rule, fn ($r) => $r['event'] === 'logbook.unsafe'));
        assert_true($unsafe !== [], 'regla por defecto (migración 0080)');
        // RRHH como casilla de email en la regla
        DB::tenant()->prepare('UPDATE notification_rules SET recipients = ? WHERE id = ?')->execute([json_encode(array_merge($unsafe[0]['recipients'], [['type' => 'email', 'value' => 'rrhh@lb.test']])), (int) $unsafe[0]['id']]);
        UserAuth::setCurrent($st['g1']);
        $before = $count('logbook.unsafe');
        $r = Logbook::create(['site_uuid' => $st['site']['uuid'], 'kind' => 'novedad', 'category' => 'riesgo', 'text' => 'Cable pelado en el tablero del depósito 2']);
        assert_same(1, (int) $r['entry']['important'], 'lo inseguro siempre es importante');
        assert_true($count('logbook.unsafe') >= $before + 2, 'aviso en la app a SyH y supervisor');
        $mail = DB::tenant()->query("SELECT * FROM notification_queue WHERE event = 'logbook.unsafe' AND to_address = 'rrhh@lb.test'")->fetch();
        assert_true($mail !== false && (int) $mail['is_critical'] === 1 && $mail['user_id'] === null, 'email a la casilla de RRHH, crítico');
        assert_true(str_contains($mail['body_html'], 'Cable pelado') && !str_contains($mail['body_html'], 'Hola,'), 'el email lleva el contenido y no saluda a un usuario');
        $obs = DB::tenant()->query("SELECT * FROM observations ORDER BY id DESC LIMIT 1")->fetch();
        assert_true($obs !== false && str_contains($obs['description'], 'Cable pelado'), 'se abrió la observación para SyH');
        // Formulario de reglas: la casilla se ve y se guarda
        $admin = Users::findById(Users::create(['name' => 'Admin', 'email' => 'admin@lb.test', 'role_id' => (int) Roles::findBySlug('admin_empresa')['id'],
            'password_hash' => password_hash('clave-larga-123', PASSWORD_DEFAULT)]));
        $_SESSION = [\App\Services\Impersonation::TENANT_KEY => $st['a']['uuid'], UserAuth::USER_KEY => $admin['uuid'], '_csrf_token' => 't'];
        $router = new App\Core\Router();
        (require BASE_PATH . '/app/routes.php')($router);
        $res = $router->dispatch(new App\Core\Request('POST', '/panel/configuracion/notificaciones', [], ['csrf_token' => 't', 'name' => 'Reporte RRHH', 'event' => 'guard.shift_report',
            'channels' => ['email'], 'emails' => 'rrhh@lb.test, mal-email']));
        assert_same(302, $res->status);
        assert_same(0, (int) DB::tenant()->query("SELECT COUNT(*) FROM notification_rules WHERE name = 'Reporte RRHH'")->fetchColumn(), 'email inválido: no guarda');
        $router->dispatch(new App\Core\Request('POST', '/panel/configuracion/notificaciones', [], ['csrf_token' => 't', 'name' => 'Reporte RRHH', 'event' => 'guard.shift_report',
            'channels' => ['email'], 'emails' => 'RRHH@lb.test']));
        $saved = DB::tenant()->query("SELECT recipients FROM notification_rules WHERE name = 'Reporte RRHH'")->fetchColumn();
        assert_same([['type' => 'email', 'value' => 'rrhh@lb.test']], json_decode((string) $saved, true));
        $_SESSION = [];
        UserAuth::setCurrent($st['hys']);
    },

    'libro: lista de autorizados (DNI, patente, días, horario, vigencia), aviso si no está, importación y celular' => function () use ($setup, &$st, $count) {
        $setup();
        Tenant::activate($st['a']);
        $A = \App\Services\LogbookAuthorized::class;
        UserAuth::setCurrent($st['g1']);
        assert_true(!$A::canManage(), 'el guardia no la edita');
        UserAuth::setCurrent($st['hys']);
        assert_true($A::canManage());
        $siteId = (int) $st['site']['id'];
        // Sin nadie en la lista: no se controla
        assert_same(null, $A::check($siteId, 'persona', '30111222', null, null, gmdate('Y-m-d H:i:s')));
        [$p, $e] = $A::validate(['kind' => 'persona', 'person_name' => 'Juan Pérez', 'person_doc' => '30.111.222', 'company' => 'Gas SA', 'site' => $st['site']['uuid'],
            'days' => [1, 2, 3, 4, 5], 'time_from' => '08:00', 'time_to' => '17:00', 'valid_from' => '2026-01-01', 'valid_until' => '2030-12-31']);
        assert_same([], $e);
        $A::save(null, $p);
        [$v] = $A::validate(['kind' => 'vehiculo', 'plate' => 'ab 123 cd', 'company' => 'Transportes Sur']);
        $A::save(null, $v);
        [$old] = $A::validate(['kind' => 'persona', 'person_name' => 'Vencido', 'person_doc' => '20999888', 'valid_until' => '2020-01-01']);
        $A::save(null, $old);
        [, $bad] = $A::validate(['kind' => 'vehiculo', 'plate' => '', 'time_from' => '08:00']);
        assert_true(isset($bad['plate'], $bad['time_from']));
        // Hora local de Buenos Aires (UTC−3): miércoles 07/10/2026 10:00 = 13:00 UTC; sábado 10/10 10:00; miércoles 20:00
        assert_same('autorizado', $A::check($siteId, 'persona', '30111222', null, null, '2026-10-07 13:00:00')['status']);
        assert_same('fuera_horario', $A::check($siteId, 'persona', '30111222', null, null, '2026-10-10 13:00:00')['status'], 'sábado');
        assert_same('fuera_horario', $A::check($siteId, 'persona', '30111222', null, null, '2026-10-07 23:00:00')['status'], '20 h');
        assert_same('vencido', $A::check($siteId, 'persona', '20999888', null, null, '2026-10-07 13:00:00')['status']);
        assert_same('no_autorizado', $A::check($siteId, 'persona', '11111111', 'Nadie', null, '2026-10-07 13:00:00')['status']);
        assert_same('autorizado', $A::check((int) $st['site2']['id'], 'vehiculo', null, null, 'AB-123-CD', '2026-10-07 13:00:00')['status'], 'vehículo para todos los predios');
        // Anotar un ingreso: queda marcado (fuera de la huella) y si no está autorizado avisa
        UserAuth::setCurrent($st['g1']);
        $before = $count('logbook.unauthorized');
        $r = Logbook::create(['site_uuid' => $st['site']['uuid'], 'kind' => 'persona', 'person_name' => 'Desconocido', 'person_doc' => '44.555.666']);
        assert_same('no_autorizado', $r['entry']['auth_status']);
        Logbook::exit($r['entry']['uuid'], []); // que no quede adentro para los otros tests
        assert_true($count('logbook.unauthorized') >= $before + 2, 'avisa a SyH y supervisor');
        $r = Logbook::create(['site_uuid' => $st['site']['uuid'], 'kind' => 'vehiculo', 'plate' => 'AB123CD']);
        assert_same('autorizado', $r['entry']['auth_status']);
        assert_same('autorizado', Logbook::shape(Logbook::find($r['entry']['uuid']))['auth_status']);
        Logbook::exit($r['entry']['uuid'], []);
        assert_true(Logbook::verify((int) $st['site']['id'])['ok'] ?? true, 'la huella del libro sigue íntegra');
        // Importación (CSV con ";")
        UserAuth::setCurrent($st['hys']);
        $csv = tempnam(sys_get_temp_dir(), 'aut') . '.csv';
        file_put_contents($csv, "tipo;nombre;dni;empresa;patente;predio;desde;hasta;dias;hora_desde;hora_hasta;motivo\n"
            . "persona;Ana Gómez;27.333.444;Limpieza SRL;;Planta Norte;01/11/2026;30/11/2026;lun a vie;07:00;15:00;Limpieza\n"
            . "vehiculo;;;Fletes;cc 999 dd;;;;;;;Fletes\n"
            . "persona;Sin predio;1;;;Inexistente;;;;;;\n");
        $res = $A::import($csv, 'lista.csv');
        assert_same([2, 0, 1], [$res['created'], $res['updated'], count($res['errors'])]);
        $res = $A::import($csv, 'lista.csv');
        assert_same([0, 2], [$res['created'], $res['updated']], 'reimportar actualiza, no duplica');
        $ana = array_values(array_filter($A::list(['q' => '27333444', 'estado' => 'todas']), fn ($x) => $x['person_doc'] === '27333444'))[0];
        assert_same(['1,2,3,4,5', '07:00', '2026-11-30', $st['site']['uuid']], [$ana['days'], $ana['time_from'], $ana['valid_until'], $ana['site_uuid']]);
        @unlink($csv);
        // Al celular le baja la lista
        UserAuth::setCurrent($st['g1']);
        $pulled = (array) (App\Services\Sync\Pull::run(null, 1000)['changes']->logbook_authorized ?? []);
        assert_true(in_array('AB123CD', array_column($pulled, 'plate'), true) && in_array('30111222', array_column($pulled, 'person_doc'), true));
        // Pantallas
        UserAuth::setCurrent($st['hys']);
        $html = \App\Core\View::render('panel/logbook/authorized', ['rows' => $A::list(['estado' => 'todas']), 'filters' => ['q' => '', 'kind' => '', 'estado' => 'todas', 'predio' => ''],
            'sites' => \App\Models\Sites::list(), 'canManage' => true], null);
        assert_true(str_contains($html, 'Juan Pérez') && str_contains($html, 'Lun a Vie · 08:00 a 17:00') && str_contains($html, 'Vencido'));
        $html = \App\Core\View::render('panel/logbook/authorized_form', ['row' => $A::find($ana['uuid']), 'old' => null, 'sites' => \App\Models\Sites::list(), 'kind' => 'persona'], null);
        assert_true(str_contains($html, 'Ana Gómez') && str_contains($html, 'Cuándo puede entrar'));
        // Suspender y quitar
        $A::setActive($A::find($ana['uuid']), false);
        assert_same('no_autorizado', $A::check($siteId, 'persona', '27333444', null, null, '2026-11-04 13:00:00')['status'], 'suspendido = no autorizado');
        $A::delete($A::find($ana['uuid']));
        assert_same(null, $A::find($ana['uuid']));
    },

    'libro: adentro ahora, salida (una sola vez) y corrección sin tocar la original' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $sid = (int) $st['site']['id'];
        $inside = Logbook::inside($sid);
        assert_same(2, count($inside), 'la persona y el camión');
        $truck = array_values(array_filter($inside, fn ($e) => $e['kind'] === 'vehiculo'))[0];
        $out = Logbook::exit($truck['uuid'], ['load_note' => 'Remito 0001-123']);
        assert_same(['salida', $truck['uuid']], [$out['entry']['kind'], $out['entry']['ref_uuid']]);
        assert_true(Logbook::exit($truck['uuid'])['duplicate'], 'no se marca dos veces');
        assert_same(1, count(Logbook::inside($sid)));
        $orig = Logbook::find($truck['uuid']);
        $c = Logbook::correct($truck['uuid'], ['text' => 'La patente era AB123CE']);
        assert_same(['correccion', (int) $orig['folio']], [$c['entry']['kind'], (int) $c['entry']['ref_folio']]);
        assert_same('AB123CD', Logbook::find($truck['uuid'])['plate'], 'la original no cambia');
        try { Logbook::exit($c['entry']['uuid']); assert_true(false); } catch (App\Core\UserError) {}
    },

    'libro: cadena de huellas — íntegro, alteración detectada, purga de datos personales' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $sid = (int) $st['site']['id'];
        assert_same(true, Logbook::verify($sid)['ok']);
        DB::tenant()->exec("UPDATE logbook_entries SET text = 'Todo tranquilo' WHERE site_id = {$sid} AND folio = 1");
        $v = Logbook::verify($sid);
        assert_same([false, 1], [$v['ok'], $v['broken_folio']], 'alguien tocó la base: se detecta');
        DB::tenant()->exec("UPDATE logbook_entries SET text = 'Recorrida perimetral sin novedad' WHERE site_id = {$sid} AND folio = 1");
        assert_same(true, Logbook::verify($sid)['ok']);
        // Purga (a riesgo del usuario): borra datos personales, el libro sigue verificable.
        UserAuth::setCurrent($st['sup']);
        try { Logbook::purge(date('Y-m-d', strtotime('+1 day'))); assert_true(false, 'el supervisor no purga'); } catch (App\Core\UserError) {}
        UserAuth::setCurrent($st['hys']);
        $n = Logbook::purge(date('Y-m-d', strtotime('+2 days')));
        assert_true($n >= 2);
        $p = Logbook::search(['q' => 'Gas SA']);
        assert_true($p && $p[0]['person_name'] === null && $p[0]['purged_at'] !== null && Logbook::label($p[0]) === '(datos borrados)');
        assert_same(true, Logbook::verify($sid)['ok'], 'la cadena sigue válida');
        assert_same([], Logbook::lookup('vehiculo', 'AB'), 'también se vacía el padrón');
    },

    'libro: cierre de turno con firma (avisa si quedó gente adentro), recepción por otro guardia' => function () use ($setup, &$st, $sign, $count) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']);
        $site = $st['site']['uuid'];
        Logbook::create(['site_uuid' => $site, 'kind' => 'persona', 'person_name' => 'Técnico de guardia']);
        try { Logbook::close(['site_uuid' => $site, 'text' => 'Sin firma']); assert_true(false); } catch (App\Core\UserError) {}
        $before = $count('logbook.inside_at_close');
        $close = Logbook::close(['site_uuid' => $site, 'text' => 'Dejo el portón 2 con traba provisoria', 'signature' => $sign()])['entry'];
        assert_true($close['signature_sha256'] !== null && count(json_decode($close['data'], true)['adentro']) >= 1);
        assert_same($before + 2, $count('logbook.inside_at_close'));
        try { Logbook::receive($close['uuid'], ['signature' => $sign()]); assert_true(false, 'no se recibe a sí mismo'); } catch (App\Core\UserError) {}
        UserAuth::setCurrent($st['g2']);
        assert_same($close['uuid'], Logbook::pendingClose((int) $st['site']['id'], (int) $st['g2']['id'])['uuid']);
        $rec = Logbook::receive($close['uuid'], ['text' => 'Recibo con portón 2 trabado', 'signature' => $sign()])['entry'];
        assert_same(['recepcion', (int) $close['folio']], [$rec['kind'], (int) $rec['ref_folio']]);
        assert_same(null, Logbook::pendingClose((int) $st['site']['id'], (int) $st['g2']['id']));
        try { Logbook::receive($close['uuid'], ['signature' => $sign()]); assert_true(false, 'ya recibido'); } catch (App\Core\UserError) {}
        assert_same(true, Logbook::verify((int) $st['site']['id'])['ok']);
    },

    'libro: cron (adentro hace muchas horas, un aviso por día) y app (push idempotente, pull del libro y del padrón)' => function () use ($setup, &$st, $op, $count, $sign) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['g1']); // un ingreso de hace 20 h que nadie cerró (se anota con su hora real, no se toca la base)
        Logbook::create(['site_uuid' => $st['site2']['uuid'], 'kind' => 'persona', 'person_name' => 'Proveedor', 'occurred_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() - 20 * 3600)]);
        $before = $count('logbook.inside_long');
        UserAuth::setCurrent(null);
        Logbook::run();
        Logbook::run();
        assert_same($before + 2, $count('logbook.inside_long'), 'una vez por predio y día');
        UserAuth::setCurrent($st['g2']);
        $u = Uuid::v4();
        $r = Push::run([$op('logbook.create', ['uuid' => $u, 'site_uuid' => $st['site']['uuid'], 'kind' => 'vehiculo', 'plate' => 'AC 987 ZZ',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 600)])]);
        assert_same(['ok', 'AC987ZZ', false], [$r[0]['status'], $r[0]['data']['plate'], $r[0]['data']['duplicate']]);
        $r = Push::run([$op('logbook.exit', ['uuid' => Uuid::v4(), 'entry_uuid' => $u])]);
        assert_same('salida', $r[0]['data']['kind']);
        $r = Push::run([$op('logbook.close', ['uuid' => Uuid::v4(), 'site_uuid' => $st['site']['uuid'], 'signature' => $sign()])]);
        assert_same('cierre', $r[0]['data']['kind']);
        $page = Pull::run(null, 1000);
        $kinds = array_column((array) ($page['changes']->logbook_entries ?? []), 'kind');
        assert_true(in_array('vehiculo', $kinds, true) && in_array('cierre', $kinds, true));
        assert_true(in_array('AC987ZZ', array_column((array) ($page['changes']->logbook_known ?? []), 'key'), true));
        UserAuth::setCurrent($st['aud']);
        assert_true(empty((array) (Pull::run(null, 1000)['changes']->logbook_known ?? [])), 'el padrón solo a quien anota');
    },

    'panel: libro del día, búsqueda, parte diario imprimible, CSV y purga con confirmación' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['a']);
        UserAuth::setCurrent($st['hys']);
        $_SESSION = [];
        $ctl = new \App\Controllers\Web\Panel\LogbookController();
        $site = $st['site']['uuid'];
        $res = $ctl->store(new \App\Core\Request('POST', '/', ['predio' => $site], ['kind' => 'novedad', 'category' => 'alarma', 'text' => 'Sonó la alarma del depósito']));
        assert_same(302, $res->status);
        $html = $ctl->index(new \App\Core\Request('GET', '/', ['predio' => $site]))->body;
        assert_true(str_contains($html, 'Sonó la alarma del depósito') && str_contains($html, 'Adentro ahora') && str_contains($html, 'Libro íntegro'), 'libro del día');
        $html = $ctl->search(new \App\Core\Request('GET', '/', ['q' => 'ac987zz']))->body;
        assert_true(str_contains($html, 'AC987ZZ') && str_contains($html, '1 resultado'), 'buscar por patente sin importar el formato');
        $print = $ctl->printable(new \App\Core\Request('GET', '/', ['predio' => $site]))->body;
        assert_true(str_contains($print, 'parte diario') && str_contains($print, 'Libro íntegro'));
        $csv = $ctl->export(new \App\Core\Request('GET', '/', ['q' => 'alarma']))->body;
        assert_true(str_contains($csv, 'Sonó la alarma del depósito') && str_contains($csv, 'Folio'));
        $ctl->purge(new \App\Core\Request('POST', '/', [], ['before' => date('Y-m-d', strtotime('+2 days')), 'confirm' => 'no']));
        assert_true(Logbook::search(['q' => 'AC987ZZ']) !== [], 'sin escribir BORRAR no purga');
    },

    'limpieza: se borran las bases de prueba' => function () use ($root, $dropAll, &$st, $master) {
        if (!$st['ready']) {
            throw new SkipTest('no hubo setup');
        }
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
