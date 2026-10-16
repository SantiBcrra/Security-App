<?php
declare(strict_types=1);

use App\Core\Uuid;

/**
 * Turnos de guardias: programación (fija o rotativa), turnos de cada guardia con inicio/fin real, ausencias con aprobación,
 * días sin servicio, salidas del predio con motivo y posiciones fuera del predio. Avisos nuevos. Idempotente.
 * Diseño: docs/design/turnos-guardias.md
 */
return function (PDO $db): void {
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db->exec("CREATE TABLE IF NOT EXISTS guard_shifts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        name VARCHAR(120) NULL,
        site_id INT UNSIGNED NOT NULL,
        user_ids TEXT NOT NULL,
        pattern VARCHAR(10) NOT NULL DEFAULT 'weekly',
        days VARCHAR(20) NULL,
        rotation_on TINYINT UNSIGNED NULL,
        rotation_off TINYINT UNSIGNED NULL,
        anchor_date DATE NULL,
        start_time CHAR(5) NOT NULL,
        end_time CHAR(5) NOT NULL,
        valid_from DATE NULL,
        valid_until DATE NULL,
        grace_minutes SMALLINT UNSIGNED NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        deleted_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_guard_shifts_uuid (uuid),
        KEY idx_guard_shifts_site (site_id),
        CONSTRAINT fk_guard_shifts_site FOREIGN KEY (site_id) REFERENCES sites (id)
    ){$t}");
    $db->exec("CREATE TABLE IF NOT EXISTS guard_absences (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        date_from DATE NOT NULL,
        date_to DATE NOT NULL,
        type VARCHAR(20) NOT NULL,
        reason VARCHAR(500) NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'solicitada',
        replacement_user_id INT UNSIGNED NULL,
        requested_by INT UNSIGNED NULL,
        decided_by INT UNSIGNED NULL,
        decided_at DATETIME NULL,
        decision_note VARCHAR(500) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_guard_absences_uuid (uuid),
        KEY idx_guard_absences_user_dates (user_id, date_from, date_to),
        KEY idx_guard_absences_status (status),
        KEY idx_guard_absences_updated (updated_at, id),
        CONSTRAINT fk_guard_absences_user FOREIGN KEY (user_id) REFERENCES users (id)
    ){$t}");
    $db->exec("CREATE TABLE IF NOT EXISTS guard_closures (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        site_id INT UNSIGNED NULL,
        day DATE NOT NULL,
        reason VARCHAR(191) NOT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_guard_closures_uuid (uuid),
        KEY idx_guard_closures_day (day)
    ){$t}");
    $db->exec("CREATE TABLE IF NOT EXISTS guard_shift_slots (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        shift_id INT UNSIGNED NULL,
        user_id INT UNSIGNED NOT NULL,
        site_id INT UNSIGNED NOT NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NOT NULL,
        grace_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
        status VARCHAR(12) NOT NULL DEFAULT 'programado',
        unscheduled TINYINT(1) NOT NULL DEFAULT 0,
        replaces_slot_id INT UNSIGNED NULL,
        absence_id INT UNSIGNED NULL,
        started_at DATETIME NULL,
        started_received_at DATETIME NULL,
        start_lat DECIMAL(10,7) NULL,
        start_lng DECIMAL(10,7) NULL,
        start_accuracy_m DECIMAL(11,2) NULL,
        start_distance_m DECIMAL(11,2) NULL,
        start_inside TINYINT(1) NULL,
        start_mock TINYINT(1) NOT NULL DEFAULT 0,
        ended_at DATETIME NULL,
        end_mode VARCHAR(8) NULL,
        out_seconds INT UNSIGNED NOT NULL DEFAULT 0,
        last_contact_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_guard_shift_slots_uuid (uuid),
        UNIQUE KEY uq_guard_shift_slots_shift_user_start (shift_id, user_id, start_at),
        KEY idx_guard_shift_slots_user_start (user_id, start_at),
        KEY idx_guard_shift_slots_status_start (status, start_at),
        KEY idx_guard_shift_slots_updated (updated_at, id),
        CONSTRAINT fk_guard_shift_slots_user FOREIGN KEY (user_id) REFERENCES users (id),
        CONSTRAINT fk_guard_shift_slots_site FOREIGN KEY (site_id) REFERENCES sites (id)
    ){$t}");
    $db->exec("CREATE TABLE IF NOT EXISTS guard_shift_exits (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        slot_id INT UNSIGNED NOT NULL,
        left_at DATETIME NOT NULL,
        returned_at DATETIME NULL,
        reason VARCHAR(20) NULL,
        note VARCHAR(500) NULL,
        reason_at DATETIME NULL,
        announced TINYINT(1) NOT NULL DEFAULT 0,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        max_distance_m DECIMAL(11,2) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_guard_shift_exits_uuid (uuid),
        KEY idx_guard_shift_exits_slot (slot_id),
        KEY idx_guard_shift_exits_open (returned_at, left_at),
        CONSTRAINT fk_guard_shift_exits_slot FOREIGN KEY (slot_id) REFERENCES guard_shift_slots (id)
    ){$t}");
    $db->exec("CREATE TABLE IF NOT EXISTS guard_shift_positions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        slot_id INT UNSIGNED NOT NULL,
        exit_id INT UNSIGNED NULL,
        at DATETIME NOT NULL,
        lat DECIMAL(10,7) NOT NULL,
        lng DECIMAL(10,7) NOT NULL,
        accuracy_m DECIMAL(11,2) NULL,
        distance_m DECIMAL(11,2) NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_guard_shift_positions_uuid (uuid),
        KEY idx_guard_shift_positions_slot_at (slot_id, at),
        KEY idx_guard_shift_positions_created (created_at)
    ){$t}");

    // Rondas programadas cuyos guardias están todos ausentes con ausencia aprobada: sin alarmas.
    $cols = $db->query("SHOW COLUMNS FROM patrol_slots LIKE 'note'")->fetchAll();
    if (!$cols) {
        $db->exec('ALTER TABLE patrol_slots ADD COLUMN note VARCHAR(191) NULL AFTER on_time');
    }

    $managers = json_encode([['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor']]);
    $guard = json_encode([['type' => 'reporter']]);
    $q = $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)');
    foreach ([
        ['Turno sin empezar', 'guard.shift_not_started', $managers, ['app', 'push']],
        ['Guardia ausente (no vino)', 'guard.shift_missed', $managers, ['app', 'push', 'email']],
        ['Turno sin terminar (cerrado solo)', 'guard.shift_no_end', $managers, ['app']],
        ['Turno empezado fuera del predio', 'guard.shift_start_outside', $managers, ['app', 'push']],
        ['Guardia salió del predio (con motivo)', 'guard.shift_exit', $managers, ['app', 'push']],
        ['Guardia fuera del predio sin motivo', 'guard.shift_exit_alarm', $managers, ['app', 'push', 'email']],
        ['Guardia en turno sin contacto', 'guard.shift_silent', $managers, ['app', 'push']],
        ['Puesto sin cubrir', 'guard.shift_uncovered', $managers, ['app', 'push', 'email']],
        ['Pedido de ausencia de un guardia', 'guard.absence_requested', $managers, ['app', 'push']],
        ['Respuesta a mi pedido de ausencia', 'guard.absence_decided', $guard, ['app', 'push']],
    ] as [$name, $event, $recipients, $channels]) {
        $q->execute([Uuid::v4(), $name, $event, $recipients, json_encode($channels), $event]);
    }
};
