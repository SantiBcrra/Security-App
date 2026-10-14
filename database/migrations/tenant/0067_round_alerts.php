<?php
declare(strict_types=1);

use App\Core\Uuid;

/**
 * Automatizaciones de rondas (fases 2 y 4): registro de alertas de cada ronda (sin progreso, atrasada, cerrada por el
 * sistema) y reglas de aviso a SyH y supervisores. Idempotente.
 */
return function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS patrol_round_alerts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        round_id INT UNSIGNED NOT NULL,
        type VARCHAR(30) NOT NULL,
        detail TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_patrol_round_alerts_uuid (uuid),
        KEY idx_patrol_round_alerts_round (round_id, created_at),
        CONSTRAINT fk_patrol_round_alerts_round FOREIGN KEY (round_id) REFERENCES patrol_rounds (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $managers = json_encode([['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor']]);
    $q = $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)');
    foreach ([['Ronda sin marcar puntos', 'guard.no_progress'], ['Ronda atrasada', 'guard.round_late']] as [$name, $event]) {
        $q->execute([Uuid::v4(), $name, $event, $managers, json_encode(['app', 'push']), $event]);
    }
};
