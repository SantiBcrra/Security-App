<?php
declare(strict_types=1);

use App\Core\Uuid;

/** Entrega 3 de la app: última posición de la ronda, ajustes por defecto y reglas de aviso (pánico y guardia sin señal). Idempotente. */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patrol_rounds'")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('last_track_at', $cols, true)) {
        $db->exec('ALTER TABLE patrol_rounds ADD COLUMN last_track_at DATETIME NULL AFTER finished_at');
    }
    $setting = $db->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE `value` = `value`');
    foreach (['rondas.track_segundos' => '60', 'rondas.minutos_sin_senal' => '10', 'guardias.panico_telefonos' => ''] as $k => $v) {
        $setting->execute([$k, $v]);
    }
    $everyone = [['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor'], ['type' => 'role', 'value' => 'admin_empresa']];
    $rules = [
        ['Botón de pánico de un guardia', 'guard.panic', $everyone, ['app', 'email', 'push', 'whatsapp']],
        ['Guardia sin señal durante una ronda', 'guard.silent', [['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor']], ['app', 'push', 'email']],
    ];
    $q = $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)');
    foreach ($rules as [$name, $event, $recipients, $channels]) {
        $q->execute([Uuid::v4(), $name, $event, json_encode($recipients), json_encode($channels), $event]);
    }
};
