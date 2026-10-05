CREATE TABLE IF NOT EXISTS patrol_points (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(160) NOT NULL,
    code VARCHAR(60) NOT NULL,
    description TEXT NULL,
    site_id INT UNSIGNED NULL,
    sector_id INT UNSIGNED NULL,
    lat DECIMAL(10,7) NOT NULL,
    lng DECIMAL(10,7) NOT NULL,
    radius_m INT UNSIGNED NOT NULL DEFAULT 50,
    is_critical TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_patrol_points_uuid (uuid),
    UNIQUE KEY uq_patrol_points_code (code),
    KEY idx_patrol_points_updated (updated_at, id),
    KEY idx_patrol_points_site (site_id),
    KEY idx_patrol_points_sector (sector_id),
    CONSTRAINT fk_patrol_points_site FOREIGN KEY (site_id) REFERENCES sites(id),
    CONSTRAINT fk_patrol_points_sector FOREIGN KEY (sector_id) REFERENCES sectors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patrol_routes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    frequency VARCHAR(20) NOT NULL DEFAULT 'manual',
    expected_minutes INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_patrol_routes_uuid (uuid),
    KEY idx_patrol_routes_updated (updated_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patrol_route_points (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    route_id INT UNSIGNED NOT NULL,
    point_id INT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_patrol_route_points (route_id, point_id),
    KEY idx_patrol_route_points_order (route_id, sort_order),
    CONSTRAINT fk_patrol_route_points_route FOREIGN KEY (route_id) REFERENCES patrol_routes(id) ON DELETE CASCADE,
    CONSTRAINT fk_patrol_route_points_point FOREIGN KEY (point_id) REFERENCES patrol_points(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patrol_rounds (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    route_id INT UNSIGNED NULL,
    user_id INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'en_curso',
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    started_lat DECIMAL(10,7) NULL,
    started_lng DECIMAL(10,7) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_patrol_rounds_uuid (uuid),
    KEY idx_patrol_rounds_user_status (user_id, status),
    KEY idx_patrol_rounds_updated (updated_at, id),
    CONSTRAINT fk_patrol_rounds_route FOREIGN KEY (route_id) REFERENCES patrol_routes(id),
    CONSTRAINT fk_patrol_rounds_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS patrol_scans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    round_id INT UNSIGNED NOT NULL,
    point_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    scanned_at_device DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    accuracy_m DECIMAL(8,2) NULL,
    distance_m DECIMAL(8,2) NULL,
    within_radius TINYINT(1) NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_patrol_scans_uuid (uuid),
    UNIQUE KEY uq_patrol_scans_round_point (round_id, point_id),
    KEY idx_patrol_scans_updated (updated_at, id),
    CONSTRAINT fk_patrol_scans_round FOREIGN KEY (round_id) REFERENCES patrol_rounds(id),
    CONSTRAINT fk_patrol_scans_point FOREIGN KEY (point_id) REFERENCES patrol_points(id),
    CONSTRAINT fk_patrol_scans_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
