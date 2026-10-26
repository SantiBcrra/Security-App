<?php
declare(strict_types=1);

/**
 * Celular compartido del puesto (la empresa de seguridad tiene UN celular para varios guardias):
 * - guard_shifts.device_user_id: usuario con el que está abierto ese celular (cuenta del puesto);
 * - users.pin_hash: PIN de cada guardia para identificarse en el celular compartido (PBKDF2, lo verifica el celular sin señal);
 * - guard_shift_slots.end_note: motivo de "Dejar el puesto sin persona" (end_mode = sin_persona). Idempotente.
 */
return function (PDO $db): void {
    $add = function (string $table, string $col, string $def) use ($db): void {
        if (!$db->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'")->fetchAll()) {
            $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    };
    $add('guard_shifts', 'device_user_id', 'INT UNSIGNED NULL AFTER user_ids');
    $add('users', 'pin_hash', 'VARCHAR(160) NULL');
    $add('users', 'pin_set_at', 'DATETIME NULL');
    $add('guard_shift_slots', 'end_note', 'VARCHAR(255) NULL AFTER end_mode');
};
