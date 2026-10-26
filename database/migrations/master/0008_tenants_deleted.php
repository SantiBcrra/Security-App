<?php
declare(strict_types=1);

/**
 * Empresas dadas de baja (eliminar desde /admin/empresas): tenants.deleted_at + motivo. La base de la empresa NO se borra
 * (se puede restaurar); mientras está eliminada nadie entra, no corre el cron ni las migraciones. Idempotente.
 */
return function (PDO $db): void {
    $add = function (string $col, string $def) use ($db): void {
        if (!$db->query("SHOW COLUMNS FROM tenants LIKE '{$col}'")->fetchAll()) {
            $db->exec("ALTER TABLE tenants ADD COLUMN {$col} {$def}");
        }
    };
    $add('deleted_at', 'DATETIME NULL');
    $add('deleted_reason', 'VARCHAR(255) NULL');
};
