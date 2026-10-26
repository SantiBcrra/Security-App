<?php
declare(strict_types=1);

use App\Core\Uuid;

/**
 * Turnos por puesto (guardias tercerizados que se organizan solos): la programación define el horario que hay que cubrir y
 * un grupo de guardias; cualquiera del grupo lo cubre empezando su turno. Franjas a cubrir en guard_post_coverage; cada
 * guardia que empieza abre su tramo (guard_shift_slots.coverage_id). Avisos de cobertura. Idempotente.
 */
return function (PDO $db): void {
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    if (!$db->query("SHOW COLUMNS FROM guard_shifts LIKE 'mode'")->fetchAll()) {
        $db->exec("ALTER TABLE guard_shifts ADD COLUMN mode VARCHAR(10) NOT NULL DEFAULT 'personal' AFTER name");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS guard_post_coverage (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        shift_id INT UNSIGNED NOT NULL,
        site_id INT UNSIGNED NOT NULL,
        start_at DATETIME NOT NULL,
        end_at DATETIME NOT NULL,
        grace_minutes SMALLINT UNSIGNED NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'pendiente',
        covered_seconds INT UNSIGNED NULL,
        first_start_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_guard_post_coverage_uuid (uuid),
        UNIQUE KEY uq_guard_post_coverage_shift_start (shift_id, start_at),
        KEY idx_guard_post_coverage_window (status, start_at, end_at),
        KEY idx_guard_post_coverage_updated (updated_at, id)
    ){$t}");
    if (!$db->query("SHOW COLUMNS FROM guard_shift_slots LIKE 'coverage_id'")->fetchAll()) {
        $db->exec('ALTER TABLE guard_shift_slots ADD COLUMN coverage_id INT UNSIGNED NULL AFTER shift_id');
        $db->exec('ALTER TABLE guard_shift_slots ADD KEY idx_guard_shift_slots_coverage (coverage_id)');
    }

    $managers = json_encode([['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor']]);
    $q = $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)');
    foreach ([
        ['Puesto sin cubrir (nadie empezó)', 'guard.shift_post_uncovered', ['app', 'push', 'email']],
        ['Relevo que no llegó', 'guard.shift_relief_missing', ['app', 'push', 'email']],
        ['Guardia con muchas horas seguidas', 'guard.shift_long', ['app', 'push']],
    ] as [$name, $event, $channels]) {
        $q->execute([Uuid::v4(), $name, $event, $managers, json_encode($channels), $event]);
    }
};
