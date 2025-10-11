--
-- Schema for aesthetic treatment packages and patient tracking
--
CREATE TABLE IF NOT EXISTS `aesthetic_packages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `session_count` INT UNSIGNED NOT NULL DEFAULT 1,
    `price` DECIMAL(10,2) DEFAULT NULL,
    `procedures` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `updated_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `patient_package_tracking` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pid` BIGINT UNSIGNED NOT NULL,
    `package_id` BIGINT UNSIGNED NOT NULL,
    `start_date` DATE DEFAULT NULL,
    `status` ENUM('active','completed','cancelled','on_hold') NOT NULL DEFAULT 'active',
    `sessions_completed` INT UNSIGNED NOT NULL DEFAULT 0,
    `session_log` JSON DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `assigned_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_pkg_tracking_pid` (`pid`),
    KEY `idx_pkg_tracking_package` (`package_id`),
    CONSTRAINT `fk_pkg_tracking_patient` FOREIGN KEY (`pid`) REFERENCES `patient_data` (`pid`) ON DELETE CASCADE,
    CONSTRAINT `fk_pkg_tracking_package` FOREIGN KEY (`package_id`) REFERENCES `aesthetic_packages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
