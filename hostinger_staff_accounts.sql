-- Globalife staff accounts for Hostinger
-- Run this in phpMyAdmin > select database u901773288_clinic1_db > SQL tab > Go.
-- Login password for all staff below: password123

SET @current_database = DATABASE();

SET @add_specialty = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN specialty VARCHAR(120) DEFAULT NULL AFTER role',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @current_database
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'specialty'
);
PREPARE add_specialty_stmt FROM @add_specialty;
EXECUTE add_specialty_stmt;
DEALLOCATE PREPARE add_specialty_stmt;

SET @add_is_active = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1',
        'SELECT 1'
    )
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @current_database
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'is_active'
);
PREPARE add_is_active_stmt FROM @add_is_active;
EXECUTE add_is_active_stmt;
DEALLOCATE PREPARE add_is_active_stmt;

INSERT INTO users (username, password, first_name, middle_name, last_name, suffix, role, specialty, email, phone, is_active)
VALUES
('admin', '$2y$10$svkggWd6.aQuKF3sDGSJLON.sqcc4Kfk4kH0PcEGKvcInnR2qftFW', 'Administrator', NULL, NULL, NULL, 'admin', NULL, 'admin@globalife.com', '09123456789', 1),
('doctor1', '$2y$10$svkggWd6.aQuKF3sDGSJLON.sqcc4Kfk4kH0PcEGKvcInnR2qftFW', 'Roda', NULL, 'Tebelin', NULL, 'doctor', NULL, 'doctor1@globalife.com', '09123456790', 1),
('receptionist1', '$2y$10$svkggWd6.aQuKF3sDGSJLON.sqcc4Kfk4kH0PcEGKvcInnR2qftFW', 'Receptionist', NULL, 'User', NULL, 'admin', NULL, 'receptionist1@globalife.com', '09123456791', 1),
('dr.estrada', '$2y$10$svkggWd6.aQuKF3sDGSJLON.sqcc4Kfk4kH0PcEGKvcInnR2qftFW', 'Ryan', 'Clifford', 'Estrada', NULL, 'doctor', 'Internist', 'dr.estrada@globalife.local', '', 1),
('dra.tebelin', '$2y$10$svkggWd6.aQuKF3sDGSJLON.sqcc4Kfk4kH0PcEGKvcInnR2qftFW', 'Roda', NULL, 'Tebelin', NULL, 'doctor', 'Pediatrician', 'dra.tebelin@globalife.local', '', 1)
ON DUPLICATE KEY UPDATE
password = VALUES(password),
first_name = VALUES(first_name),
middle_name = VALUES(middle_name),
last_name = VALUES(last_name),
suffix = VALUES(suffix),
role = VALUES(role),
specialty = VALUES(specialty),
email = VALUES(email),
phone = VALUES(phone),
is_active = 1;

CREATE TABLE IF NOT EXISTS doctor_availability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1=Mon 7=Sun',
    time_start TIME NOT NULL,
    time_end TIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_day (user_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELETE da FROM doctor_availability da
INNER JOIN users u ON u.id = da.user_id
WHERE u.username IN ('dr.estrada', 'dra.tebelin');

INSERT INTO doctor_availability (user_id, day_of_week, time_start, time_end)
SELECT id, 2, '14:00:00', '15:00:00' FROM users WHERE username = 'dr.estrada'
UNION ALL
SELECT id, 6, '11:30:00', '12:30:00' FROM users WHERE username = 'dr.estrada'
UNION ALL
SELECT id, 1, '13:00:00', '15:00:00' FROM users WHERE username = 'dra.tebelin'
UNION ALL
SELECT id, 3, '13:00:00', '15:00:00' FROM users WHERE username = 'dra.tebelin'
UNION ALL
SELECT id, 5, '13:00:00', '16:00:00' FROM users WHERE username = 'dra.tebelin';

