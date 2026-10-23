<?php
declare(strict_types=1);

/**
 * Edición y baja manual de turnos desde el panel: quién y cuándo lo editó y por qué, y el origen del turno en su
 * programación (guardia y hora originales) para que el generador no lo vuelva a crear si se cambió la hora o el guardia,
 * ni reactive uno dado de baja a mano. Idempotente.
 */
return function (PDO $db): void {
    $add = function (string $col, string $def) use ($db): void {
        if (!$db->query("SHOW COLUMNS FROM guard_shift_slots LIKE '{$col}'")->fetchAll()) {
            $db->exec("ALTER TABLE guard_shift_slots ADD COLUMN {$col} {$def}");
        }
    };
    $add('origin_user_id', 'INT UNSIGNED NULL AFTER shift_id');
    $add('origin_start_at', 'DATETIME NULL AFTER origin_user_id');
    $add('edited_by', 'INT UNSIGNED NULL');
    $add('edited_at', 'DATETIME NULL');
    $add('edit_note', 'VARCHAR(500) NULL');
    $db->exec('UPDATE guard_shift_slots SET origin_user_id = user_id, origin_start_at = start_at WHERE shift_id IS NOT NULL AND origin_start_at IS NULL');
    if (!$db->query("SHOW INDEX FROM guard_shift_slots WHERE Key_name = 'idx_guard_shift_slots_origin'")->fetchAll()) {
        $db->exec('ALTER TABLE guard_shift_slots ADD KEY idx_guard_shift_slots_origin (shift_id, origin_user_id, origin_start_at)');
    }
};
