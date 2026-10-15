<?php
declare(strict_types=1);

use App\Core\Uuid;

/** Automatizaciones fase 6: regla del resumen diario de rondas (SyH y supervisores, app + email). Idempotente. */
return function (PDO $db): void {
    $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)')
        ->execute([Uuid::v4(), 'Resumen diario de rondas', 'guard.daily_summary',
            json_encode([['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor']]), json_encode(['app', 'email']), 'guard.daily_summary']);
};
