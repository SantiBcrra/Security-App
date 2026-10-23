<?php
declare(strict_types=1);

/**
 * Turnos con horario por día (franjas semanales): cada franja tiene día y hora de entrada y día y hora de salida
 * (ej. sábado 14:00 a lunes 08:00). JSON en guard_shifts.blocks; se usa con pattern = 'custom'. Idempotente.
 */
return function (PDO $db): void {
    if (!$db->query("SHOW COLUMNS FROM guard_shifts LIKE 'blocks'")->fetchAll()) {
        $db->exec('ALTER TABLE guard_shifts ADD COLUMN blocks TEXT NULL AFTER end_time');
    }
};
