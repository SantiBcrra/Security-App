<?php
declare(strict_types=1);

use App\Core\Uuid;
use App\Policies\Permissions;

/**
 * Libro de guardia (docs/design/libro-de-guardia.md): anotaciones inmutables encadenadas por huella, fotos, padrón para
 * autocompletar, fotos subidas desde el celular, permisos del módulo en los roles base y avisos. Idempotente.
 */
return function (PDO $db): void {
    $t = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $db->exec("CREATE TABLE IF NOT EXISTS logbook_entries (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        site_id INT UNSIGNED NOT NULL,
        folio INT UNSIGNED NOT NULL,
        kind VARCHAR(12) NOT NULL,
        category VARCHAR(20) NULL,
        text TEXT NULL,
        important TINYINT(1) NOT NULL DEFAULT 0,
        person_name VARCHAR(150) NULL,
        person_doc VARCHAR(30) NULL,
        company VARCHAR(150) NULL,
        visiting VARCHAR(150) NULL,
        reason VARCHAR(255) NULL,
        badge VARCHAR(50) NULL,
        plate VARCHAR(15) NULL,
        vehicle_type VARCHAR(30) NULL,
        driver VARCHAR(150) NULL,
        load_note VARCHAR(255) NULL,
        ref_entry_id INT UNSIGNED NULL,
        data TEXT NULL,
        signature_path VARCHAR(255) NULL,
        signature_sha256 CHAR(64) NULL,
        lat DECIMAL(10,7) NULL,
        lng DECIMAL(10,7) NULL,
        occurred_at DATETIME NOT NULL,
        user_id INT UNSIGNED NULL,
        shift_slot_id INT UNSIGNED NULL,
        source VARCHAR(10) NOT NULL DEFAULT 'web',
        prev_hash CHAR(64) NULL,
        hash CHAR(64) NOT NULL,
        exited_at DATETIME NULL,
        exit_entry_id INT UNSIGNED NULL,
        received_entry_id INT UNSIGNED NULL,
        purged_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_logbook_entries_uuid (uuid),
        UNIQUE KEY uq_logbook_entries_site_folio (site_id, folio),
        KEY idx_logbook_entries_site_occurred (site_id, occurred_at),
        KEY idx_logbook_entries_inside (site_id, kind, exited_at),
        KEY idx_logbook_entries_plate (plate),
        KEY idx_logbook_entries_doc (person_doc),
        KEY idx_logbook_entries_updated (updated_at, id),
        CONSTRAINT fk_logbook_entries_site FOREIGN KEY (site_id) REFERENCES sites (id)
    ){$t}");
    $db->exec("CREATE TABLE IF NOT EXISTS logbook_attachments (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        entry_id INT UNSIGNED NOT NULL,
        path VARCHAR(255) NOT NULL,
        thumb_path VARCHAR(255) NULL,
        original_name VARCHAR(191) NULL,
        mime VARCHAR(100) NOT NULL,
        size_bytes INT UNSIGNED NOT NULL,
        sha256 CHAR(64) NOT NULL,
        width INT UNSIGNED NULL,
        height INT UNSIGNED NULL,
        exif TEXT NULL,
        uploaded_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_logbook_attachments_uuid (uuid),
        KEY idx_logbook_attachments_entry (entry_id),
        CONSTRAINT fk_logbook_attachments_entry FOREIGN KEY (entry_id) REFERENCES logbook_entries (id)
    ){$t}");
    $db->exec("CREATE TABLE IF NOT EXISTS logbook_known (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        uuid CHAR(36) NOT NULL,
        type VARCHAR(10) NOT NULL,
        lookup_key VARCHAR(30) NOT NULL,
        data TEXT NOT NULL,
        visits INT UNSIGNED NOT NULL DEFAULT 1,
        last_seen_at DATETIME NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_logbook_known_uuid (uuid),
        UNIQUE KEY uq_logbook_known_type_key (type, lookup_key),
        KEY idx_logbook_known_updated (updated_at, id)
    ){$t}");
    if (!$db->query("SHOW COLUMNS FROM uploads LIKE 'logbook_entry_uuid'")->fetchAll()) {
        $db->exec('ALTER TABLE uploads ADD COLUMN logbook_entry_uuid CHAR(36) NULL AFTER incident_uuid');
    }

    // Permisos del módulo en los roles base que ya existían.
    $roles = Permissions::baseRoles();
    $update = $db->prepare('UPDATE roles SET permissions = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?');
    foreach ($db->query('SELECT id, slug, permissions FROM roles WHERE is_system = 1')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $base = $roles[$row['slug']]['permissions']['libro'] ?? null;
        $permissions = json_decode((string) $row['permissions'], true) ?: [];
        if ($base !== null && !isset($permissions['libro'])) {
            $permissions['libro'] = $base;
            $update->execute([json_encode($permissions, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
        }
    }

    $managers = json_encode([['type' => 'role', 'value' => 'responsable_hys'], ['type' => 'role', 'value' => 'supervisor']]);
    $q = $db->prepare('INSERT INTO notification_rules (uuid, name, event, min_severity_level, sector_id, recipients, channels, is_active, created_at, updated_at)
        SELECT ?, ?, ?, NULL, NULL, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM notification_rules WHERE event = ?)');
    foreach ([['Libro de guardia: novedad importante', 'logbook.important', ['app', 'push']],
        ['Libro de guardia: quedaron personas o vehículos adentro al cerrar el turno', 'logbook.inside_at_close', ['app', 'push']],
        ['Libro de guardia: adentro hace muchas horas', 'logbook.inside_long', ['app']]] as [$name, $event, $channels]) {
        $q->execute([Uuid::v4(), $name, $event, $managers, json_encode($channels), $event]);
    }
};
