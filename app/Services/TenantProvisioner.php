<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Crypto;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Migrator;
use App\Core\TenantFiles;
use App\Core\Uuid;
use App\Models\Tenants;
use PDO;

/**
 * Alta de empresa: prepara su base (propia, independiente), la migra y la registra.
 * Si algo falla a mitad, no queda registrada; si creamos la base nosotros, se borra.
 */
final class TenantProvisioner
{
    /** Nombre de base sugerido para el modo automático: securityapp_{slug} */
    public static function defaultDbName(string $slug): string
    {
        $prefix = (string) Config::get('db.master.database', 'securityapp');
        return substr($prefix . '_' . str_replace('-', '_', $slug), 0, 64);
    }

    /**
     * @param array $company name, slug, legal_name, cuit, timezone, status, plan
     * @param array|null $db credenciales de una base existente; null = crearla automáticamente
     * @return string uuid de la empresa
     */
    public static function create(array $company, ?array $db): string
    {
        $created = false;
        if ($db === null) {
            $db = Config::get('db.master');
            $db['database'] = self::defaultDbName($company['slug']);
            self::createDatabase($db['database']);
            $created = true;
        }
        if (Tenants::databaseInUse($db['host'], (int) $db['port'], $db['database'])) {
            throw new \DomainException('Esa base de datos ya está asignada a otra empresa.');
        }

        try {
            $pdo = DB::connect($db);
            self::assertUsable($pdo);

            $result = Migrator::run($pdo, BASE_PATH . Migrator::TENANT_DIR);
            if ($result['error'] !== null) {
                throw new \RuntimeException('Falló la migración ' . $result['error']['migration'] . ': ' . $result['error']['message']);
            }

            $uuid = Uuid::v4();
            $row = $company + [
                'uuid'        => $uuid,
                'db_host'     => $db['host'],
                'db_port'     => (int) $db['port'],
                'db_name'     => $db['database'],
                'db_user'     => $db['username'],
                'db_pass_enc' => ($db['password'] ?? '') === '' ? null : Crypto::encrypt($db['password']),
            ];
            Tenants::create($row);
        } catch (\Throwable $e) {
            if ($created) {
                self::dropDatabase($db['database']);
            }
            throw $e;
        }

        TenantFiles::dir($uuid);
        $public = array_diff_key($row, ['db_pass_enc' => 1]);
        Audit::platform('tenant.create', 'tenant', $uuid, null, $public);
        Audit::tenant('tenant.create', 'tenant', $uuid, null, $public, $pdo);
        Logger::info('Empresa creada', ['uuid' => $uuid, 'slug' => $company['slug'], 'db' => $db['database']]);
        return $uuid;
    }

    /** Prueba una base existente sin registrar nada (botón "Probar conexión"). */
    public static function probe(array $db): array
    {
        $pdo = DB::connect($db);
        self::assertUsable($pdo);
        return ['version' => (string) $pdo->query('SELECT VERSION()')->fetchColumn()];
    }

    /** La base tiene que estar vacía, o ser una base nuestra de empresa (reintento de un alta). */
    private static function assertUsable(PDO $pdo): void
    {
        $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
        if ($tables && !in_array('schema_migrations', $tables, true)) {
            throw new \DomainException('La base no está vacía (' . count($tables) . ' tablas). Usá una base nueva.');
        }
        if (in_array('platform_admins', $tables, true) || in_array('tenants', $tables, true)) {
            throw new \DomainException('Esa es la base maestra: cada empresa necesita una base propia.');
        }
    }

    private static function createDatabase(string $name): void
    {
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new \DomainException('Nombre de base inválido.');
        }
        $exists = DB::master()->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$name]);
        if ($exists->fetchColumn()) {
            throw new \DomainException("Ya existe una base llamada {$name}. Usá la opción \"base existente\" o cambiá el identificador.");
        }
        try {
            DB::master()->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\PDOException $e) {
            throw new \DomainException('El usuario de la base maestra no puede crear bases (normal en hosting compartido). '
                . 'Creala desde el panel del hosting y usá la opción "base existente".');
        }
    }

    private static function dropDatabase(string $name): void
    {
        try {
            DB::master()->exec("DROP DATABASE IF EXISTS `{$name}`");
        } catch (\Throwable $e) {
            Logger::error('No se pudo borrar la base de un alta fallida', ['db' => $name, 'error' => $e->getMessage()]);
        }
    }
}
