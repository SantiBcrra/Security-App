<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Conexiones PDO.
 * - master(): base maestra (empresas, super-admin, credenciales de cada base).
 * - tenant(): base de la empresa activa. Cada empresa tiene su propia base, 100% independiente:
 *   no hay tenant_id ni filtros por empresa en las consultas. Se elige al resolver la empresa
 *   (Etapa 2) con useTenant().
 */
final class DB
{
    /** Compatible con MySQL 5.7 y MariaDB 10.4. */
    private const SQL_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,'
        . 'ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private static ?PDO $master = null;
    private static ?PDO $tenant = null;

    public static function master(): PDO
    {
        if (self::$master === null) {
            $config = Config::get('db.master', []);
            if (empty($config['database'])) {
                throw new \RuntimeException('La base maestra no está configurada (falta config.local.php).');
            }
            self::$master = self::connect($config);
        }
        return self::$master;
    }

    public static function tenant(): PDO
    {
        if (self::$tenant === null) {
            throw new \RuntimeException('No hay empresa activa.');
        }
        return self::$tenant;
    }

    /** @param array{host:string,port?:int,database:string,username:string,password:string} $config */
    public static function useTenant(array $config): void
    {
        self::$tenant = self::connect($config);
    }

    public static function hasTenant(): bool
    {
        return self::$tenant !== null;
    }

    public static function connect(array $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'] ?? 'localhost',
            (int) ($config['port'] ?? 3306),
            $config['database']
        );
        $pdo = new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        // Todo se guarda en UTC; la zona de cada empresa se aplica al mostrar.
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("SET time_zone = '+00:00', sql_mode = '" . self::SQL_MODE . "'");
        return $pdo;
    }
}
