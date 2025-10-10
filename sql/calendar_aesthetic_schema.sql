--
-- Auxiliary tables for aesthetic procedure scheduling
--
CREATE TABLE IF NOT EXISTS `calendar_procedure_types` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(64) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `default_duration` INT UNSIGNED DEFAULT NULL,
    `color` VARCHAR(32) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_calendar_procedure_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `clinic_equipment` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `location` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `openemr_postcalendar_events`
    ADD COLUMN `procedure_type_id` BIGINT UNSIGNED DEFAULT NULL AFTER `pc_room`,
    ADD COLUMN `equipment_id` BIGINT UNSIGNED DEFAULT NULL AFTER `procedure_type_id`,
    ADD KEY `idx_calendar_event_procedure` (`procedure_type_id`),
    ADD KEY `idx_calendar_event_equipment` (`equipment_id`),
    ADD CONSTRAINT `fk_calendar_event_procedure` FOREIGN KEY (`procedure_type_id`) REFERENCES `calendar_procedure_types` (`id`) ON DELETE SET NULL,
    ADD CONSTRAINT `fk_calendar_event_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `clinic_equipment` (`id`) ON DELETE SET NULL;
