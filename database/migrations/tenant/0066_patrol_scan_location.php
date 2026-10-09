<?php
declare(strict_types=1);

use App\Core\Uuid;

/**
 * Estado de la ubicación de cada marca de ronda: ok (en el lugar) | lejos | impreciso (el GPS no alcanza para saberlo) |
 * sin_gps | simulada (app de GPS falso), y la regla de aviso "punto marcado lejos del lugar". Idempotente.
 */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patrol_scans'")
        ->fetchAll(PDO::FETCH_COLUMN);
    $add = [];
    if (!in_array('location_status', $cols, true)) $add[] = 'ADD COLUMN location_status VARCHAR(12) NULL AFTER within_radius';
    if (!in_array('is_mock', $cols, true)) $add[] = 'ADD COLUMN is_mock TINYINT(1) NOT NULL DEFAULT 0 AFTER location_status';
    if ($add) $db->exec('ALTER TABLE patrol_scans ' . implode(', ', $add));
    // Marcas anteriores: mismo criterio que Patrols::locationStatus().
    $db->exec("UPDATE patrol_scans s JOIN patrol_points p ON p.id = s.point_id SET s.location_status = CASE
            WHEN s.distance_m IS NULL THEN 'sin_gps'
            WHEN s.distance_m <= p.radius_m THEN 'ok'
            WHEN s.accuracy_m IS NOT NULL AND s.distance_m - s.accuracy_m <= p.radius_m THEN 'impreciso'
            ELSE 'lejos' END
        WHERE s.location_status IS NULL");
    $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)')
        ->execute([Uuid::v4(), 'Punto de ronda marcado lejos del lugar o con GPS falso', 'guard.off_site',
            json_encode([['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor']]), json_encode(['app', 'push', 'email']), 'guard.off_site']);
};
