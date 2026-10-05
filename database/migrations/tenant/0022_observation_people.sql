-- Personas involucradas en una observación (opcional).
CREATE TABLE IF NOT EXISTS observation_people (
    observation_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (observation_id, employee_id),
    KEY idx_observation_people_employee (employee_id),
    CONSTRAINT fk_observation_people_obs FOREIGN KEY (observation_id) REFERENCES observations (id),
    CONSTRAINT fk_observation_people_emp FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
