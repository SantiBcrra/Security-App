<?php
// Token de notificaciones push (Expo) por dispositivo de la app. ALTER idempotente:
// MySQL 5.7 no tiene ADD COLUMN IF NOT EXISTS, así que se consulta antes.
return function (PDO $db): void {
    $exists = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'user_devices' AND COLUMN_NAME = 'push_token'")->fetchColumn();
    if (!(int) $exists) {
        $db->exec('ALTER TABLE user_devices ADD COLUMN push_token VARCHAR(255) NULL AFTER device_name');
    }
};
