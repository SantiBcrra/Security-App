<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\Crypto;
use App\Core\Cuit;
use App\Core\DB;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Response;
use App\Core\SignedUrl;
use App\Core\Tenant;
use App\Core\TenantFiles;
use App\Core\TenantSuspended;
use App\Middleware\ReadOnlyImpersonation;
use App\Models\Tenants;
use App\Services\Audit;
use App\Services\TenantProvisioner;

/**
 * Aislamiento entre empresas (una base por empresa) contra MariaDB/MySQL local real.
 * Usa una maestra de prueba `securityapp_test_master` y crea dos empresas con base propia.
 * Todo se borra al final. Sin MySQL local, los tests de base se saltean.
 */
$server = [
    'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'port'     => 3306,
    'username' => getenv('TEST_DB_USER') ?: 'root',
    'password' => getenv('TEST_DB_PASS') ?: '',
];
$master = 'securityapp_test_master';
$state = ['ready' => false, 'a' => null, 'b' => null];

$root = function () use ($server): PDO {
    try {
        return new PDO("mysql:host={$server['host']};charset=utf8mb4", $server['username'], $server['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
    } catch (PDOException) {
        throw new SkipTest('sin MySQL local');
    }
};

$dropAll = function (PDO $root) use ($master): void {
    $dbs = $root->query("SHOW DATABASES LIKE '" . $master . "%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($dbs as $db) {
        $root->exec("DROP DATABASE `{$db}`");
    }
};

/** Prepara una vez: maestra de prueba migrada + empresas A y B. */
$setup = function () use (&$state, $root, $dropAll, $server, $master): void {
    if ($state['ready']) {
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
    $state['a'] = TenantProvisioner::create(['name' => 'Empresa A', 'slug' => 'test-a'] + $company, null);
    $state['b'] = TenantProvisioner::create(['name' => 'Empresa B', 'slug' => 'test-b'] + $company, null);
    $state['ready'] = true;
};

return [
    'Crypto cifra, descifra y detecta manipulación' => function () {
        Config::set('app.key', base64_encode(random_bytes(32)));
        $enc = Crypto::encrypt('clave-secreta-ñ');
        assert_true($enc !== Crypto::encrypt('clave-secreta-ñ'), 'cada cifrado usa IV distinto');
        assert_same('clave-secreta-ñ', Crypto::decrypt($enc));
        $raw = base64_decode(substr($enc, 3));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);
        $threw = false;
        try {
            Crypto::decrypt('v1:' . base64_encode($raw));
        } catch (RuntimeException) {
            $threw = true;
        }
        assert_true($threw, 'un dato alterado no debe descifrar');
    },

    'SignedUrl: válida, alterada y vencida' => function () {
        Config::set('app.key', base64_encode(random_bytes(32)));
        $toRequest = function (string $url): Request {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
            return new Request('GET', (string) parse_url($url, PHP_URL_PATH), $q);
        };
        $url = SignedUrl::make('/archivos/logo/abc', 60);
        assert_true(SignedUrl::verify($toRequest($url)), 'debe ser válida');
        assert_true(!SignedUrl::verify($toRequest(str_replace('/abc', '/otra', $url))), 'otra ruta con la misma firma');
        assert_true(!SignedUrl::verify($toRequest($url . 'x')), 'firma alterada');
        assert_true(!SignedUrl::verify($toRequest(SignedUrl::make('/archivos/logo/abc', -1))), 'vencida');
    },

    'CUIT: dígito verificador y formato' => function () {
        assert_true(Cuit::isValid('20-12345678-6'));
        assert_true(!Cuit::isValid('20-12345678-5'));
        assert_true(!Cuit::isValid('123'));
        assert_same('20-12345678-6', Cuit::format('20123456786'));
    },

    'alta: cada empresa tiene su propia base migrada' => function () use ($setup, &$state, $master) {
        $setup();
        $a = Tenants::findByUuid($state['a']);
        $b = Tenants::findByUuid($state['b']);
        assert_same($master . '_test_a', $a['db_name']);
        assert_same($master . '_test_b', $b['db_name']);
        foreach ([$a, $b] as $t) {
            $tables = Tenant::connect($t)->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach (['schema_migrations', 'audit_log', 'settings'] as $table) {
                assert_true(in_array($table, $tables, true), "falta {$table} en {$t['db_name']}");
            }
            assert_true(!in_array('tenants', $tables, true), 'la base de empresa no tiene tablas de la maestra');
        }
    },

    'aislamiento: lo que escribe A no lo ve B (y viceversa)' => function () use ($setup, &$state) {
        $setup();
        $a = Tenants::findByUuid($state['a']);
        $b = Tenants::findByUuid($state['b']);

        Tenant::activate($a);
        DB::tenant()->exec("INSERT INTO settings (`key`, `value`, updated_at) VALUES ('secreto', 'de-A', UTC_TIMESTAMP())");
        Audit::tenant('prueba.a', 'test', null, null, ['solo' => 'A']);

        Tenant::activate($b);
        assert_same(false, DB::tenant()->query("SELECT `value` FROM settings WHERE `key` = 'secreto'")->fetchColumn(), 'B no debe ver datos de A');
        assert_same(0, (int) DB::tenant()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'prueba.a'")->fetchColumn());
        DB::tenant()->exec("INSERT INTO settings (`key`, `value`, updated_at) VALUES ('secreto', 'de-B', UTC_TIMESTAMP())");

        Tenant::activate($a);
        assert_same('de-A', DB::tenant()->query("SELECT `value` FROM settings WHERE `key` = 'secreto'")->fetchColumn(), 'A sigue viendo lo suyo');
        assert_same(1, (int) DB::tenant()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'prueba.a'")->fetchColumn());
        Tenant::deactivate();
    },

    'cambiar de empresa cambia la conexión (no queda pegada la anterior)' => function () use ($setup, &$state) {
        $setup();
        Tenant::activate(Tenants::findByUuid($state['a']));
        $dbA = DB::tenant()->query('SELECT DATABASE()')->fetchColumn();
        Tenant::activate(Tenants::findByUuid($state['b']));
        $dbB = DB::tenant()->query('SELECT DATABASE()')->fetchColumn();
        assert_true($dbA !== $dbB, 'deben ser bases distintas');
        Tenant::deactivate();
        $threw = false;
        try {
            DB::tenant();
        } catch (RuntimeException) {
            $threw = true;
        }
        assert_true($threw, 'sin empresa activa no hay conexión de empresa');
    },

    'una empresa suspendida no se puede activar' => function () use ($setup, &$state) {
        $setup();
        Tenants::update($state['b'], ['status' => 'suspended', 'status_reason' => 'test']);
        $threw = false;
        try {
            Tenant::activate(Tenants::findByUuid($state['b']));
        } catch (TenantSuspended) {
            $threw = true;
        }
        Tenants::update($state['b'], ['status' => 'active', 'status_reason' => null]);
        assert_true($threw);
    },

    'alta rechazada: base maestra, base ya usada o identificador repetido' => function () use ($setup, $server, $master) {
        $setup();
        $company = ['name' => 'X', 'slug' => 'test-x', 'legal_name' => null, 'cuit' => null, 'timezone' => 'UTC', 'status' => 'trial', 'plan' => null];
        $fails = function (callable $fn): string {
            try {
                $fn();
            } catch (DomainException $e) {
                return $e->getMessage();
            }
            return '';
        };
        assert_true(str_contains($fails(fn () => TenantProvisioner::create($company, $server + ['database' => $master])), 'maestra'));
        assert_true(str_contains($fails(fn () => TenantProvisioner::create($company, $server + ['database' => $master . '_test_a'])), 'asignada'));
        assert_true(str_contains($fails(fn () => TenantProvisioner::create(['slug' => 'test-a'] + $company, null)), 'Ya existe'));
        assert_same(2, count(Tenants::all()), 'no debe quedar registrada ninguna empresa nueva');
    },

    'el migrador incluye a cada empresa' => function () use ($setup) {
        $setup();
        $labels = array_column(Migrator::statusAll(), 'label');
        assert_same(3, count($labels));
        assert_true(str_contains($labels[1] . $labels[2], 'Empresa A') && str_contains($labels[1] . $labels[2], 'Empresa B'));
    },

    'modo soporte: bloquea escrituras, deja leer' => function () {
        $_SESSION = ['impersonating' => true];
        $next = fn () => Response::html('ok');
        $mw = new ReadOnlyImpersonation();
        assert_same(200, $mw(new Request('GET', '/panel'), $next)->status);
        assert_same(403, $mw(new Request('POST', '/panel/algo', [], [], ['accept' => 'application/json']), $next)->status);
        $_SESSION = [];
        assert_same(200, $mw(new Request('POST', '/panel/algo'), $next)->status);
    },

    'archivos: valida el tipo real y no deja salir de la carpeta' => function () {
        $uuid = App\Core\Uuid::v4();
        $png = tempnam(sys_get_temp_dir(), 'img');
        $img = imagecreatetruecolor(4, 4);
        imagepng($img, $png);
        $path = TenantFiles::storeFile($uuid, $png, 'logo', TenantFiles::IMAGE_TYPES, 1024 * 1024);
        assert_true(str_starts_with($path, 'logo/') && str_ends_with($path, '.png'));
        assert_true(TenantFiles::path($uuid, $path) !== null);

        $fake = tempnam(sys_get_temp_dir(), 'fake');
        file_put_contents($fake, '<?php echo "hola";');
        $threw = false;
        try {
            TenantFiles::storeFile($uuid, $fake, 'logo', TenantFiles::IMAGE_TYPES, 1024 * 1024);
        } catch (DomainException) {
            $threw = true;
        }
        assert_true($threw, 'un PHP disfrazado no se acepta');
        assert_same(null, TenantFiles::path($uuid, '../../config/config.local.php'));
        @unlink($png);
        @unlink($fake);
    },

    'limpieza: se borran las bases de prueba' => function () use ($root, $dropAll, &$state, $master) {
        if (!$state['ready']) {
            throw new SkipTest('no hubo setup');
        }
        Tenant::deactivate();
        DB::reset();
        $pdo = $root();
        $dropAll($pdo);
        Config::load(BASE_PATH . '/config'); // vuelve a la config real
        assert_same([], $pdo->query("SHOW DATABASES LIKE '{$master}%'")->fetchAll(PDO::FETCH_COLUMN));
    },
];
