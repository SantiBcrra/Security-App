<?php
declare(strict_types=1);

/** Etapa 14: talles del empleado (ropa, calzado, guantes) para precargar las entregas de EPP. Idempotente. */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'size_clothing' => 'ADD COLUMN size_clothing VARCHAR(10) NULL',
        'size_shoes'    => 'ADD COLUMN size_shoes VARCHAR(10) NULL',
        'size_gloves'   => 'ADD COLUMN size_gloves VARCHAR(10) NULL',
    ];
    $missing = array_values(array_diff_key($add, array_flip($cols)));
    if ($missing) {
        $db->exec('ALTER TABLE employees ' . implode(', ', $missing));
    }
};
