<?php
declare(strict_types=1);

use App\Core\DB;
use App\Core\Migrator;

/**
 * Tests del migrador contra una base temporal `securityapp_test` en el MySQL/MariaDB local.
 * Variables opcionales: TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASS. Sin MySQL se saltean.
 */
$server = [
    'host'     => getenv('TEST_DB_HOST') ?: '127.0.0.1',
    'username' => getenv('TEST_DB_USER') ?: 'root',
    'password' => getenv('TEST_DB_PASS') ?: '',
];
$dbName = 'securityapp_test';

$fresh = function () use ($server, $dbName): PDO {
    try {
        $root = new PDO("mysql:host={$server['host']};charset=utf8mb4", $server['username'], $server['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 2]);
    } catch (PDOException $e) {
        throw new SkipTest('sin MySQL local');
    }
    $root->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    $root->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    return DB::connect($server + ['database' => $dbName]);
};

$dir = function (array $files): string {
    $path = sys_get_temp_dir() . '/secapp_mig_' . bin2hex(random_bytes(4));
    mkdir($path);
    foreach ($files as $name => $content) {
        file_put_contents("{$path}/{$name}", $content);
    }
    return $path;
};

$cleanup = function () use ($server, $dbName): void {
    try {
        (new PDO("mysql:host={$server['host']}", $server['username'], $server['password']))->exec("DROP DATABASE IF EXISTS `{$dbName}`");
    } catch (PDOException) {
    }
};

return [
    'splitSql separa sentencias y respeta strings y comentarios' => function () {
        $sql = "-- comentario; con punto y coma\n"
            . "CREATE TABLE a (x VARCHAR(10) DEFAULT 'a;b');\n"
            . "/* bloque; */ INSERT INTO a VALUES ('it''s; ok'), (\"dq;\\\"x\");\n"
            . "# otro; comentario\n"
            . "INSERT INTO `t;x` VALUES ('--no es comentario');";
        $parts = Migrator::splitSql($sql);
        assert_same(3, count($parts));
        assert_same("CREATE TABLE a (x VARCHAR(10) DEFAULT 'a;b')", $parts[0]);
        assert_true(str_contains($parts[1], "'it''s; ok'"), 'comilla duplicada');
        assert_true(str_contains($parts[1], '"dq;\\"x"'), 'escape con barra');
        assert_same("INSERT INTO `t;x` VALUES ('--no es comentario')", $parts[2]);
    },

    'splitSql ignora un script vacío o solo comentarios' => function () {
        assert_same([], Migrator::splitSql("-- nada\n/* nada */\n  ;  ;"));
    },

    'aplica en orden, registra y no reaplica' => function () use ($fresh, $dir) {
        $db = $fresh();
        $path = $dir([
            '0002_b.sql' => 'INSERT INTO items (name) VALUES (\'segundo\');',
            '0001_a.sql' => 'CREATE TABLE IF NOT EXISTS items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50)) ENGINE=InnoDB;',
            '0003_c.php' => '<?php return function (PDO $db): void { $db->exec("INSERT INTO items (name) VALUES (\'php\')"); };',
        ]);
        assert_same(['0001_a.sql', '0002_b.sql', '0003_c.php'], Migrator::pending($db, $path));

        $res = Migrator::run($db, $path);
        assert_same(null, $res['error']);
        assert_same(['0001_a.sql', '0002_b.sql', '0003_c.php'], $res['applied']);
        assert_same(['segundo', 'php'], $db->query('SELECT name FROM items ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));

        $again = Migrator::run($db, $path);
        assert_same([], $again['applied']);
        assert_same(2, (int) $db->query('SELECT COUNT(*) FROM items')->fetchColumn(), 'no debe duplicar');
        assert_same([1], array_map('intval', array_unique(array_column(Migrator::appliedRows($db), 'batch'))));
    },

    'si una falla se corta ahí y al corregirla se reintenta desde ese punto' => function () use ($fresh, $dir) {
        $db = $fresh();
        $path = $dir([
            '0001_ok.sql'    => 'CREATE TABLE IF NOT EXISTS t1 (id INT) ENGINE=InnoDB;',
            '0002_mala.sql'  => 'CREATE TABLE IF NOT EXISTS t2 (id INT) ENGINE=InnoDB; INSERT INTO no_existe VALUES (1);',
            '0003_luego.sql' => 'CREATE TABLE IF NOT EXISTS t3 (id INT) ENGINE=InnoDB;',
        ]);
        $res = Migrator::run($db, $path);
        assert_same(['0001_ok.sql'], $res['applied']);
        assert_same('0002_mala.sql', $res['error']['migration']);
        assert_same('INSERT INTO no_existe VALUES (1)', $res['error']['statement']);
        assert_same(['0002_mala.sql', '0003_luego.sql'], Migrator::pending($db, $path));

        file_put_contents("{$path}/0002_mala.sql", 'CREATE TABLE IF NOT EXISTS t2 (id INT) ENGINE=InnoDB;');
        $retry = Migrator::run($db, $path);
        assert_same(null, $retry['error']);
        assert_same(['0002_mala.sql', '0003_luego.sql'], $retry['applied']);
    },

    'no corre si otra ejecución tiene el lock' => function () use ($fresh, $dir, $server, $dbName) {
        $db = $fresh();
        $other = DB::connect($server + ['database' => $dbName]);
        $other->query("SELECT GET_LOCK('secapp_migrate_{$dbName}', 0)");
        $path = $dir(['0001_a.sql' => 'CREATE TABLE IF NOT EXISTS x (id INT);']);

        $res = Migrator::run($db, $path);
        assert_same([], $res['applied']);
        assert_true(str_contains($res['error']['message'], 'en curso'), 'debe avisar que hay otra en curso');

        $other->query("SELECT RELEASE_LOCK('secapp_migrate_{$dbName}')");
        assert_same(['0001_a.sql'], Migrator::run($db, $path)['applied']);
    },

    'las migraciones reales de la base maestra aplican limpio dos veces' => function () use ($fresh, $cleanup) {
        $db = $fresh();
        $path = BASE_PATH . Migrator::MASTER_DIR;
        assert_same(null, Migrator::run($db, $path)['error']);
        $db->exec('DELETE FROM schema_migrations'); // simula reinstalar sobre una base existente
        assert_same(null, Migrator::run($db, $path)['error'], 'deben ser idempotentes');
        $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['schema_migrations', 'platform_admins', 'login_attempts'] as $t) {
            assert_true(in_array($t, $tables, true), "falta la tabla {$t}");
        }
        $cleanup();
    },
];
