<?php
declare(strict_types=1);

/** App Android (entrega 7): cómo se marcó cada punto de ronda (qr | nfc). Idempotente. */
return function (PDO $db): void {
    $cols = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patrol_scans'")
        ->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('method', $cols, true)) {
        $db->exec('ALTER TABLE patrol_scans ADD COLUMN method VARCHAR(10) NULL AFTER point_id');
    }
};
