<?php
declare(strict_types=1);

/**
 * Guardias = empleados (decisión del usuario 26/10/2026): los guardias tercerizados del celular compartido se cargan como
 * empleados, no como usuarios. Cada uno tiene por detrás un perfil interno SIN ACCESO (no entra a la web ni a la app):
 * - users.employee_id: empleado al que representa (uno por empleado);
 * - users.no_login: 1 = perfil interno, nunca puede ingresar ni aparece en la lista de usuarios. Idempotente.
 */
return function (PDO $db): void {
    $add = function (string $table, string $col, string $def) use ($db): void {
        if (!$db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'")->fetchAll()) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    };
    $add('users', 'employee_id', 'INT UNSIGNED NULL');
    $add('users', 'no_login', 'TINYINT(1) NOT NULL DEFAULT 0');
    if (!$db->query("SHOW INDEX FROM users WHERE Key_name = 'uq_users_employee'")->fetchAll()) {
        $db->exec('ALTER TABLE users ADD UNIQUE KEY uq_users_employee (employee_id)');
    }
};
