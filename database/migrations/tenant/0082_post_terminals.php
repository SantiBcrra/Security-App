<?php
declare(strict_types=1);

use App\Core\Uuid;

/**
 * Notebook del puesto (libro de guardia en la web): la cuenta del puesto queda abierta en esa notebook ("recordar este equipo",
 * token renovable de 90 días, se guarda su SHA-256) y lo que se anota va a nombre del guardia de turno según el celular.
 * logbook_entries.no_duty = se anotó sin nadie de turno (fuera de la huella). Aviso `logbook.no_duty`. Idempotente.
 */
return function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS post_terminals (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        label VARCHAR(191) NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_post_terminals_uuid (uuid),
        UNIQUE KEY uq_post_terminals_token (token_hash),
        KEY idx_post_terminals_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$db->query("SHOW COLUMNS FROM logbook_entries LIKE 'no_duty'")->fetchAll()) {
        $db->exec('ALTER TABLE logbook_entries ADD COLUMN no_duty TINYINT(1) NOT NULL DEFAULT 0');
    }
    $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)')
        ->execute([Uuid::v4(), 'Libro de guardia: anotación sin guardia de turno', 'logbook.no_duty',
            json_encode([['type' => 'role', 'value' => 'supervisor'], ['type' => 'role', 'value' => 'responsable_hys']]), json_encode(['app', 'push']), 'logbook.no_duty']);
};
