<?php
declare(strict_types=1);

use App\Core\Uuid;

/**
 * Automatizaciones fase 3: programación de rondas (horarios fijos o intervalo, por días, con vigencia y fechas excluidas)
 * y las rondas programadas que se generan (pendiente → realizada a tiempo / tarde, o no realizada). Idempotente.
 */
return function (PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS patrol_schedules (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        route_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NULL,
        mode VARCHAR(10) NOT NULL DEFAULT 'fixed',
        times TEXT NULL,
        window_start CHAR(5) NULL,
        window_end CHAR(5) NULL,
        every_minutes SMALLINT UNSIGNED NULL,
        days VARCHAR(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
        valid_from DATE NULL,
        valid_until DATE NULL,
        except_dates TEXT NULL,
        user_ids TEXT NULL,
        grace_minutes SMALLINT UNSIGNED NULL,
        max_delay_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_patrol_schedules_uuid (uuid),
        KEY idx_patrol_schedules_route (route_id),
        CONSTRAINT fk_patrol_schedules_route FOREIGN KEY (route_id) REFERENCES patrol_routes (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS patrol_slots (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        schedule_id INT UNSIGNED NOT NULL,
        route_id INT UNSIGNED NOT NULL,
        due_at DATETIME NOT NULL,
        grace_minutes SMALLINT UNSIGNED NOT NULL,
        deadline_at DATETIME NOT NULL,
        status VARCHAR(15) NOT NULL DEFAULT 'pendiente',
        round_id INT UNSIGNED NULL,
        started_at DATETIME NULL,
        on_time TINYINT(1) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_patrol_slots_uuid (uuid),
        UNIQUE KEY uq_patrol_slots_schedule_due (schedule_id, due_at),
        KEY idx_patrol_slots_status_due (status, due_at),
        KEY idx_patrol_slots_route_due (route_id, due_at),
        KEY idx_patrol_slots_updated (updated_at, id),
        CONSTRAINT fk_patrol_slots_schedule FOREIGN KEY (schedule_id) REFERENCES patrol_schedules (id),
        CONSTRAINT fk_patrol_slots_route FOREIGN KEY (route_id) REFERENCES patrol_routes (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $managers = json_encode([['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor']]);
    $q = $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)');
    foreach ([['Ronda programada sin empezar', 'guard.round_not_started', ['app', 'push']], ['Ronda programada no realizada', 'guard.round_missed', ['app', 'push', 'email']]] as [$name, $event, $channels]) {
        $q->execute([Uuid::v4(), $name, $event, $managers, json_encode($channels), $event]);
    }
};
