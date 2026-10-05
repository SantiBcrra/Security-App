CREATE TABLE IF NOT EXISTS patrol_route_assignments (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 route_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
 UNIQUE KEY uq_patrol_route_assignments (route_id,user_id), KEY idx_patrol_route_assignments_user (user_id,is_active),
 CONSTRAINT fk_patrol_route_assignments_route FOREIGN KEY (route_id) REFERENCES patrol_routes(id) ON DELETE CASCADE,
 CONSTRAINT fk_patrol_route_assignments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
