<?php
declare(strict_types=1);

use App\Core\Uuid;

/**
 * Lista de autorizados para ingresar (libro de guardia): personas (DNI) o vehículos (patente), por predio o para todos, con
 * vigencia, días y horario. Cada ingreso anotado guarda cómo dio contra la lista (logbook_entries.auth_status, fuera de la
 * huella) y un ingreso no autorizado avisa (`logbook.unauthorized`). Idempotente.
 */
return function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS logbook_authorized (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        site_id INT UNSIGNED NULL,
        kind VARCHAR(10) NOT NULL,
        person_name VARCHAR(150) NULL,
        person_doc VARCHAR(30) NULL,
        company VARCHAR(150) NULL,
        plate VARCHAR(15) NULL,
        reason VARCHAR(255) NULL,
        authorized_by VARCHAR(150) NULL,
        valid_from DATE NULL,
        valid_until DATE NULL,
        days VARCHAR(20) NULL,
        time_from CHAR(5) NULL,
        time_to CHAR(5) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_logbook_authorized_uuid (uuid),
        KEY idx_logbook_authorized_doc (person_doc),
        KEY idx_logbook_authorized_plate (plate),
        KEY idx_logbook_authorized_updated (updated_at, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $add = function (string $col, string $def) use ($db): void {
        if (!$db->query("SHOW COLUMNS FROM logbook_entries LIKE '{$col}'")->fetchAll()) {
            $db->exec("ALTER TABLE logbook_entries ADD COLUMN {$col} {$def}");
        }
    };
    $add('auth_status', 'VARCHAR(16) NULL');
    $add('auth_id', 'INT UNSIGNED NULL');

    $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)')
        ->execute([Uuid::v4(), 'Ingreso que no está en la lista de autorizados', 'logbook.unauthorized',
            json_encode([['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor']]), json_encode(['app', 'push']), 'logbook.unauthorized']);
};
