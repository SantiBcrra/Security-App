-- Línea de tiempo de cada observación. Solo inserción: nunca se edita ni se borra.
-- type: created | status | comment | correction | assignment | attachment | imminent_alert
CREATE TABLE IF NOT EXISTS observation_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    uuid CHAR(36) NOT NULL,
    observation_id INT UNSIGNED NOT NULL,
    type VARCHAR(20) NOT NULL,
    from_status VARCHAR(20) NULL,
    to_status VARCHAR(20) NULL,
    comment TEXT NULL,
    data TEXT NULL,
    user_id INT UNSIGNED NULL,
    actor_name VARCHAR(120) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_observation_events_uuid (uuid),
    KEY idx_observation_events_obs (observation_id, id),
    CONSTRAINT fk_observation_events_obs FOREIGN KEY (observation_id) REFERENCES observations (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
