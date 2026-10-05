<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\DB;
use App\Core\Jwt;
use App\Core\JwtException;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Router;
use App\Core\Storage;
use App\Core\Tenant;
use App\Core\Totp;
use App\Models\Roles;
use App\Models\Tenants;
use App\Models\UserDevices;
use App\Models\Users;
use App\Policies\Permissions;
use App\Services\ApiAuth;
use App\Services\Impersonation;
use App\Services\TenantProvisioner;
use App\Services\UserAuth;
use App\Services\UserInvitation;

/**
 * Etapa 3: usuarios/roles DENTRO de la base de cada empresa, login web y API, permisos.
 * Maestra de prueba `securityapp_test_auth` + empresas auth-a y auth-b. Se borra todo al final.
 */
$server = [
    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1', 'port' => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root', 'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_auth';
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

/** Crea un usuario activo con contraseña en la empresa activa. */
$makeUser = function (string $name, ?string $email, ?string $dni, string $roleSlug, ?string $password, array $extra = []): array {
    $role = Roles::findBySlug($roleSlug);
    $id = Users::create(['name' => $name, 'email' => $email, 'dni' => $dni, 'role_id' => (int) $role['id'],
        'password_hash' => $password ? password_hash($password, PASSWORD_DEFAULT) : null] + $extra);
    return Users::findById($id);
};

$setup = function () use (&$st, $root, $dropAll, $server, $master, $makeUser): void {
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
    $st['a'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Auth A', 'slug' => 'auth-a'] + $company, null));
    $st['b'] = Tenants::findByUuid(TenantProvisioner::create(['name' => 'Auth B', 'slug' => 'auth-b'] + $company, null));

    // Misma persona (mismo email) en las dos empresas, con contraseñas distintas: son cuentas distintas.
    Tenant::activate($st['b']);
    $st['b_admin'] = $makeUser('Ana B', 'ana@demo.test', null, 'admin_empresa', 'clave-de-B-123');
    Tenant::activate($st['a']);
    $st['a_admin'] = $makeUser('Ana A', 'ana@demo.test', null, 'admin_empresa', 'clave-de-A-123');
    $st['a_rep'] = $makeUser('Raúl Reportante', null, '30111222', 'reportante', 'clave-rep-1234');
    Tenant::deactivate();

    file_put_contents(Storage::path('installed.lock'), 'test'); // para pasar RequireInstalled en las rutas
    $st['ready'] = true;
};

/** Despacha una ruta real del sistema con la sesión dada. */
$dispatch = function (string $method, string $path, array $session): App\Core\Response {
    $_SESSION = $session;
    UserAuth::setCurrent(null);
    Tenant::deactivate();
    $router = new Router();
    (require BASE_PATH . '/app/routes.php')($router);
    return $router->dispatch(new Request($method, $path));
};

return [
    'TOTP: vectores de la RFC 6238 y base32' => function () {
        $key = '12345678901234567890';
        foreach ([59 => '94287082', 1111111109 => '07081804', 1234567890 => '89005924', 2000000000 => '69279037'] as $t => $code) {
            assert_same($code, Totp::codeForStep($key, intdiv($t, 30), 8), "T={$t}");
        }
        $secret = Totp::generateSecret();
        assert_same(32, strlen($secret));
        assert_same(Totp::base32Decode($secret), Totp::base32Decode(Totp::base32Encode(Totp::base32Decode($secret))));
    },

    'TOTP: acepta ±30 s, rechaza otro código y no deja reutilizar' => function () {
        $secret = Totp::generateSecret();
        $now = 1_800_000_000;
        $step = Totp::verify($secret, Totp::code($secret, $now - 30), null, $now);
        assert_true($step !== null, 'código del período anterior');
        assert_same(null, Totp::verify($secret, '000000', null, $now) === null ? null : 'x');
        assert_same(null, Totp::verify($secret, Totp::code($secret, $now - 30), $step, $now), 'el mismo código no se acepta dos veces');
        assert_same(null, Totp::verify($secret, Totp::code($secret, $now - 120), null, $now), 'fuera de ventana');
    },

    'JWT: válido, firma alterada, vencido y alg none' => function () {
        Config::set('app.key', base64_encode(random_bytes(32)));
        $token = Jwt::encode(['sub' => 'x'], 60);
        assert_same('x', Jwt::decode($token)['sub']);
        $fails = function (string $t): string {
            try {
                Jwt::decode($t);
            } catch (JwtException $e) {
                return $e->reason;
            }
            return 'ok';
        };
        [$h, $p, $s] = explode('.', $token);
        assert_same('invalid', $fails($h . '.' . Jwt::b64('{"sub":"admin","iat":1,"exp":9999999999}') . '.' . $s));
        assert_same('expired', $fails(Jwt::encode(['sub' => 'x', 'iat' => time() - 7200, 'exp' => time() - 3600], 0)));
        assert_same('invalid', $fails(Jwt::b64('{"alg":"none"}') . '.' . $p . '.'));
    },

    'permisos: matriz de los roles base' => function () {
        $roles = Permissions::baseRoles();
        $can = fn (string $r, string $m, string $a) => Permissions::can($roles[$r]['permissions'], $m, $a);
        foreach (Permissions::MODULES as $m => $_) {
            foreach (Permissions::ACTIONS as $a => $__) {
                assert_true($can('admin_empresa', $m, $a), "admin: {$m}.{$a}");
            }
        }
        assert_true($can('reportante', 'observaciones', 'crear'));
        assert_true(!$can('reportante', 'usuarios', 'ver'), 'reportante no ve usuarios');
        assert_true(!$can('reportante', 'observaciones', 'cerrar'));
        assert_same('propios', Permissions::scope($roles['reportante']['permissions'], 'observaciones'));
        assert_true($can('auditor', 'inspecciones', 'ver') && $can('auditor', 'inspecciones', 'exportar'));
        assert_true(!$can('auditor', 'inspecciones', 'crear') && !$can('auditor', 'usuarios', 'ver'));
        assert_same('sectores', Permissions::scope($roles['supervisor']['permissions'], 'observaciones'));
        assert_true(!$can('supervisor', 'roles', 'ver'));
        assert_true($can('responsable_hys', 'acciones', 'cerrar') && !$can('responsable_hys', 'roles', 'editar'));
        $clean = Permissions::normalize(['epp' => ['acciones' => ['crear', 'borrar_todo'], 'alcance' => 'raro'], 'inventado' => ['acciones' => ['ver']]]);
        assert_same(['epp' => ['acciones' => ['ver', 'crear'], 'alcance' => 'todo']], $clean);
    },

    'cada empresa tiene sus 5 roles base en su propia base' => function () use ($setup, &$st) {
        $setup();
        foreach (['a', 'b'] as $k) {
            Tenant::activate($st[$k]);
            assert_same(5, count(Roles::all()));
        }
        Tenant::deactivate();
    },

    'login: las cuentas son por empresa (mismo email, otra base, otra contraseña)' => function () use ($setup) {
        $setup();
        assert_same('ok', UserAuth::check('auth-a', 'ana@demo.test', 'clave-de-A-123', '10.0.0.1')['status']);
        assert_same('invalid', UserAuth::check('auth-b', 'ana@demo.test', 'clave-de-A-123', '10.0.0.1')['status'], 'la clave de A no sirve en B');
        assert_same('ok', UserAuth::check('auth-b', 'ANA@demo.test', 'clave-de-B-123', '10.0.0.1')['status']);
        assert_same('ok', UserAuth::check('auth-a', '30.111.222', 'clave-rep-1234', '10.0.0.1')['status'], 'login por DNI');
        assert_same('invalid', UserAuth::check('auth-b', '30111222', 'clave-rep-1234', '10.0.0.1')['status'], 'el reportante de A no existe en B');
        assert_same('invalid', UserAuth::check('no-existe', 'ana@demo.test', 'clave-de-A-123', '10.0.0.1')['status']);
    },

    'login: empresa suspendida, usuario desactivado, auditor vencido o sin activar no entran' => function () use ($setup, &$st, $makeUser) {
        $setup();
        Tenants::update($st['b']['uuid'], ['status' => 'suspended']);
        assert_same('invalid', UserAuth::check('auth-b', 'ana@demo.test', 'clave-de-B-123', '10.0.0.2')['status']);
        Tenants::update($st['b']['uuid'], ['status' => 'active']);

        Tenant::activate($st['a']);
        $makeUser('Inactivo', 'inactivo@demo.test', null, 'supervisor', 'clave-inact-1', ['is_active' => 0]);
        $makeUser('Auditor', 'auditor@demo.test', null, 'auditor', 'clave-audit-1', ['access_expires_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
        $makeUser('Pendiente', 'pendiente@demo.test', null, 'supervisor', null);
        foreach (['inactivo@demo.test' => 'clave-inact-1', 'auditor@demo.test' => 'clave-audit-1', 'pendiente@demo.test' => ''] as $email => $pass) {
            assert_same('invalid', UserAuth::check('auth-a', $email, $pass, '10.0.0.2')['status'], $email);
        }
    },

    'login: bloqueo tras 5 fallos, incluso con la contraseña correcta' => function () use ($setup) {
        $setup();
        for ($i = 0; $i < 5; $i++) {
            UserAuth::check('auth-a', 'ana@demo.test', 'mala', '10.9.9.9');
        }
        assert_same('locked', UserAuth::check('auth-a', 'ana@demo.test', 'clave-de-A-123', '10.9.9.9')['status']);
        assert_same('ok', UserAuth::check('auth-a', 'ana@demo.test', 'clave-de-A-123', '10.9.9.8')['status'], 'otra IP no queda bloqueada');
    },

    'login web con 2FA: pide código y entra con el correcto' => function () use ($setup, &$st, $makeUser) {
        $setup();
        Tenant::activate($st['a']);
        $secret = Totp::generateSecret();
        $makeUser('Con 2FA', 'dospasos@demo.test', null, 'supervisor', 'clave-2fa-1234',
            ['totp_enabled' => 1, 'totp_secret_enc' => App\Core\Crypto::encrypt($secret)]);
        $_SESSION = [];
        assert_same('totp', UserAuth::attempt('auth-a', 'dospasos@demo.test', 'clave-2fa-1234', '10.0.0.3'));
        assert_same(null, $_SESSION[UserAuth::USER_KEY] ?? null, 'sin código no hay sesión');
        assert_same('invalid', UserAuth::verifyPending2fa('000000', '10.0.0.3'));
        assert_same('ok', UserAuth::verifyPending2fa(Totp::code($secret), '10.0.0.3'));
        assert_true(is_string($_SESSION[UserAuth::USER_KEY] ?? null));
        $_SESSION = [];
    },

    'invitación: link válido una sola vez y vencimiento' => function () use ($setup, &$st, $makeUser) {
        $setup();
        Tenant::activate($st['a']);
        $user = $makeUser('Invitado', 'invitado@demo.test', null, 'reportante', null);
        $link = UserInvitation::issue($user);
        assert_true(str_contains($link, '/activar/auth-a/'));
        $token = substr($link, strrpos($link, '/') + 1);
        assert_same($user['uuid'], UserInvitation::findValid($token)['uuid']);
        UserInvitation::activate(UserInvitation::findValid($token), 'mi-clave-nueva-1');
        assert_same(null, UserInvitation::findValid($token), 'ya usado');
        assert_same('ok', UserAuth::check('auth-a', 'invitado@demo.test', 'mi-clave-nueva-1', '10.0.0.4')['status']);

        Tenant::activate($st['a']);
        $link2 = UserInvitation::issue(Users::findByUuid($user['uuid']));
        Users::update((int) $user['id'], ['activation_expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        assert_same(null, UserInvitation::findValid(substr($link2, strrpos($link2, '/') + 1)), 'vencido');
        Tenant::deactivate();
    },

    'rutas: cada rol ve solo lo que le corresponde' => function () use ($setup, &$st, $dispatch) {
        $setup();
        $admin = [Impersonation::TENANT_KEY => $st['a']['uuid'], UserAuth::USER_KEY => $st['a_admin']['uuid']];
        $rep = [Impersonation::TENANT_KEY => $st['a']['uuid'], UserAuth::USER_KEY => $st['a_rep']['uuid']];
        assert_same(200, $dispatch('GET', '/panel/usuarios', $admin)->status);
        assert_same(200, $dispatch('GET', '/panel/roles', $admin)->status);
        assert_same(200, $dispatch('GET', '/panel', $rep)->status);
        assert_same(403, $dispatch('GET', '/panel/usuarios', $rep)->status, 'reportante no entra a usuarios');
        assert_same(403, $dispatch('GET', '/panel/roles', $rep)->status);
        $home = $dispatch('GET', '/panel', $rep)->body;
        assert_true(!str_contains($home, '/panel/usuarios'), 'el menú del reportante no muestra Usuarios');
        assert_same(302, $dispatch('GET', '/panel/usuarios', [])->status, 'sin sesión → login');
        // Sesión de un usuario de A con la empresa B en la sesión: el usuario no existe en la base de B.
        $mixed = [Impersonation::TENANT_KEY => $st['b']['uuid'], UserAuth::USER_KEY => $st['a_rep']['uuid']];
        assert_same(302, $dispatch('GET', '/panel', $mixed)->status, 'un usuario de A no puede usar la empresa B');
        $_SESSION = [];
    },

    'rutas: si desactivan al usuario, pierde el acceso en el próximo request' => function () use ($setup, &$st, $dispatch, $makeUser) {
        $setup();
        Tenant::activate($st['a']);
        $u = $makeUser('Se va', 'sefue@demo.test', null, 'supervisor', 'clave-sefue-1');
        $session = [Impersonation::TENANT_KEY => $st['a']['uuid'], UserAuth::USER_KEY => $u['uuid']];
        assert_same(200, $dispatch('GET', '/panel', $session)->status);
        Tenant::activate($st['a']);
        Users::update((int) $u['id'], ['is_active' => 0]);
        assert_same(302, $dispatch('GET', '/panel', $session)->status);
        $_SESSION = [];
    },

    'API: login, me, refresh rotativo y reutilización revoca el dispositivo' => function () use ($setup) {
        $setup();
        $device = App\Core\Uuid::v4();
        $tokens = ApiAuth::login('auth-a', 'ana@demo.test', 'clave-de-A-123', $device, 'Moto G', null, '10.0.0.5');
        assert_true(isset($tokens['access_token'], $tokens['refresh_token']), json_encode($tokens));
        assert_same(null, ApiAuth::authenticate($tokens['access_token']));
        assert_same('Ana A', UserAuth::user()['name']);

        $second = ApiAuth::refresh($tokens['refresh_token'], '10.0.0.5');
        assert_true(isset($second['access_token']) && $second['refresh_token'] !== $tokens['refresh_token']);
        assert_same(null, ApiAuth::authenticate($second['access_token']));

        // Alguien reutiliza el refresh viejo → se revoca el dispositivo entero.
        assert_same('invalid_refresh', ApiAuth::refresh($tokens['refresh_token'], '10.6.6.6')['error']);
        assert_same('session_revoked', ApiAuth::authenticate($second['access_token']));
        assert_same('invalid_refresh', ApiAuth::refresh($second['refresh_token'], '10.0.0.5')['error']);

        assert_same('invalid_credentials', ApiAuth::login('auth-b', 'ana@demo.test', 'clave-de-A-123', $device, null, null, '10.0.0.5')['error']);
        assert_same('device_invalid', ApiAuth::login('auth-a', 'ana@demo.test', 'clave-de-A-123', 'no-uuid', null, null, '10.0.0.5')['error']);
    },

    'API: el token de la empresa A solo abre la base de A' => function () use ($setup) {
        $setup();
        $tokens = ApiAuth::login('auth-b', 'ana@demo.test', 'clave-de-B-123', App\Core\Uuid::v4(), 'Tablet', null, '10.0.0.6');
        assert_same(null, ApiAuth::authenticate($tokens['access_token']));
        assert_same('Ana B', UserAuth::user()['name']);
        assert_true(str_ends_with((string) DB::tenant()->query('SELECT DATABASE()')->fetchColumn(), 'auth_b'));
        ApiAuth::logout();
        assert_same('session_revoked', ApiAuth::authenticate($tokens['access_token']), 'logout revoca el dispositivo');
    },

    'no se puede dejar a la empresa sin administrador' => function () use ($setup, &$st) {
        $setup();
        Tenant::activate($st['b']);
        assert_same(0, Users::countActiveAdmins((int) $st['b_admin']['id']), 'Ana es la única admin de B');
        Tenant::activate($st['a']);
        assert_same(0, Users::countActiveAdmins((int) $st['a_admin']['id']));
        Tenant::deactivate();
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
