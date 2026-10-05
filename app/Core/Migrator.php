<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Migraciones sin consola. Cada base (maestra y cada empresa) tiene su propia tabla
 * schema_migrations; los archivos se aplican en orden alfabético:
 *   database/migrations/master/0001_xxx.sql   → base maestra
 *   database/migrations/tenant/0001_xxx.sql   → base de cada empresa
 *
 * - .sql: varias sentencias separadas por ";" (sin DELIMITER: nada de triggers/procedures).
 * - .php: devuelve function (PDO $db): void, para migraciones de datos.
 *
 * MySQL hace commit implícito en CREATE/ALTER: no hay rollback real. Por eso cada archivo se
 * registra solo si terminó completo y las migraciones se escriben idempotentes
 * (CREATE TABLE IF NOT EXISTS…) para poder reintentar después de un error.
 */
final class Migrator
{
    public const MASTER_DIR = '/database/migrations/master';
    public const TENANT_DIR = '/database/migrations/tenant';

    /**
     * Corre las pendientes de la maestra y de cada empresa.
     * @return list<array{label:string, applied:list<string>, error:?array}>
     */
    public static function runAll(): array
    {
        $results = [];
        foreach (self::targets() as $target) {
            try {
                $pdo = ($target['connect'])();
            } catch (\Throwable $e) {
                $results[] = ['label' => $target['label'], 'applied' => [], 'error' => [
                    'migration' => '', 'statement' => null, 'message' => 'No se pudo conectar: ' . $e->getMessage(),
                ]];
                continue; // una empresa caída no frena a las demás
            }
            $results[] = ['label' => $target['label']] + self::run($pdo, $target['dir']);
            if ($target['master'] && end($results)['error'] !== null) {
                break; // si falla la maestra, no se tocan las empresas
            }
        }
        return $results;
    }

    /** @return list<array{label:string, pending:list<string>, applied:list<array>, error:?string}> */
    public static function statusAll(): array
    {
        $status = [];
        foreach (self::targets() as $target) {
            try {
                $pdo = ($target['connect'])();
                $status[] = [
                    'label'   => $target['label'],
                    'pending' => self::pending($pdo, $target['dir']),
                    'applied' => self::appliedRows($pdo),
                    'error'   => null,
                ];
            } catch (\Throwable $e) {
                $status[] = ['label' => $target['label'], 'pending' => [], 'applied' => [], 'error' => $e->getMessage()];
            }
        }
        return $status;
    }

    /** Bases a migrar: la maestra y la de cada empresa registrada (incluidas las suspendidas). */
    private static function targets(): array
    {
        $targets = [[
            'label' => 'Base maestra', 'master' => true, 'dir' => BASE_PATH . self::MASTER_DIR,
            'connect' => fn () => DB::master(),
        ]];
        $hasTenants = (bool) DB::master()->query("SHOW TABLES LIKE 'tenants'")->fetchColumn();
        foreach ($hasTenants ? \App\Models\Tenants::all() : [] as $tenant) {
            $targets[] = [
                'label' => 'Empresa: ' . $tenant['name'] . ' (' . $tenant['db_name'] . ')', 'master' => false,
                'dir' => BASE_PATH . self::TENANT_DIR, 'connect' => fn () => Tenant::connect($tenant),
            ];
        }
        return $targets;
    }

    /** @return list<string> nombres de archivo pendientes, en orden */
    public static function pending(PDO $db, string $dir): array
    {
        self::ensureTable($db);
        $applied = array_flip(array_column(self::appliedRows($db), 'migration'));
        return array_values(array_filter(self::files($dir), fn (string $f) => !isset($applied[$f])));
    }

    /**
     * Aplica las pendientes de una base.
     * @return array{applied:list<string>, error:?array{migration:string, statement:?string, message:string}}
     */
    public static function run(PDO $db, string $dir): array
    {
        self::ensureTable($db);
        $lock = 'secapp_migrate_' . $db->query('SELECT DATABASE()')->fetchColumn();
        if ((int) $db->query('SELECT GET_LOCK(' . $db->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            return ['applied' => [], 'error' => [
                'migration' => '', 'statement' => null,
                'message'   => 'Ya hay una actualización en curso. Esperá unos segundos y reintentá.',
            ]];
        }

        $applied = [];
        try {
            $batch = (int) $db->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations')->fetchColumn();
            foreach (self::pending($db, $dir) as $file) {
                $start = microtime(true);
                $statement = null;
                try {
                    if (str_ends_with($file, '.php')) {
                        $migration = require $dir . '/' . $file;
                        $migration($db);
                    } else {
                        foreach (self::splitSql((string) file_get_contents($dir . '/' . $file)) as $statement) {
                            $db->exec($statement);
                        }
                    }
                } catch (\Throwable $e) {
                    Logger::error('Migración fallida', ['migration' => $file, 'error' => $e->getMessage()]);
                    return ['applied' => $applied, 'error' => [
                        'migration' => $file, 'statement' => $statement, 'message' => $e->getMessage(),
                    ]];
                }
                $db->prepare('INSERT INTO schema_migrations (migration, batch, duration_ms, applied_at) VALUES (?, ?, ?, UTC_TIMESTAMP())')
                    ->execute([$file, $batch, (int) round((microtime(true) - $start) * 1000)]);
                $applied[] = $file;
            }
        } finally {
            $db->query('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')');
        }

        if ($applied) {
            Logger::info('Migraciones aplicadas', ['migrations' => $applied]);
        }
        return ['applied' => $applied, 'error' => null];
    }

    public static function appliedRows(PDO $db): array
    {
        self::ensureTable($db);
        return $db->query('SELECT migration, batch, duration_ms, applied_at FROM schema_migrations ORDER BY migration')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<string> */
    public static function files(string $dir): array
    {
        $files = array_merge(glob($dir . '/*.sql') ?: [], glob($dir . '/*.php') ?: []);
        $names = array_map('basename', $files);
        sort($names, SORT_STRING);
        return $names;
    }

    private static function ensureTable(PDO $db): void
    {
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(191) NOT NULL,
            batch INT UNSIGNED NOT NULL,
            duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
            applied_at DATETIME NOT NULL,
            UNIQUE KEY uq_schema_migrations_migration (migration)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    /**
     * Separa un script SQL en sentencias. Respeta comillas simples/dobles, backticks
     * (con escapes) y comentarios (-- , # y bloque). Los comentarios se descartan.
     * @return list<string>
     */
    public static function splitSql(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if ($quote !== null) {
                $current .= $c;
                if ($c === '\\' && $quote !== '`') {
                    $current .= $next;
                    $i++;
                } elseif ($c === $quote) {
                    if ($next === $quote) { // comilla duplicada = escapada
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }

            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $current .= $c;
            } elseif (($c === '-' && $next === '-' && in_array($sql[$i + 2] ?? "\n", [' ', "\t", "\n", "\r"], true)) || $c === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $len : $end;
                $current .= "\n";
            } elseif ($c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                $current .= ' ';
            } elseif ($c === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $c;
            }
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
