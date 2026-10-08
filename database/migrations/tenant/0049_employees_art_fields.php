<?php
declare(strict_types=1);

/** Etapa 12: datos del trabajador para la denuncia ante la ART (todos opcionales). Idempotente. */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'employees'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'cuil'       => 'ADD COLUMN cuil CHAR(11) NULL AFTER dni',
        'birth_date' => 'ADD COLUMN birth_date DATE NULL AFTER hire_date',
        'gender'     => 'ADD COLUMN gender CHAR(1) NULL AFTER birth_date',
        'address'    => 'ADD COLUMN address VARCHAR(191) NULL AFTER gender',
    ];
    $missing = array_values(array_diff_key($add, array_flip($cols)));
    if ($missing) {
        $db->exec('ALTER TABLE employees ' . implode(', ', $missing));
    }
};
