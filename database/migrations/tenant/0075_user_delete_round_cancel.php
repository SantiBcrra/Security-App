<?php
declare(strict_types=1);

/**
 * Eliminar usuarios (baja lógica: no se ven, no entran, su historial queda) y anular rondas realizadas con motivo
 * (no cuentan en métricas ni cumplimiento; el registro queda). Idempotente.
 */
return function (PDO $db): void {
    $add = function (string $table, string $col, string $def) use ($db): void {
        if (!$db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'")->fetchAll()) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    };
    $add('users', 'deleted_at', 'DATETIME NULL');
    $add('patrol_rounds', 'cancelled_at', 'DATETIME NULL');
    $add('patrol_rounds', 'cancelled_by', 'INT UNSIGNED NULL');
    $add('patrol_rounds', 'cancel_note', 'VARCHAR(500) NULL');
};
