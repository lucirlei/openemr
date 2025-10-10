--
-- Body composition tracking schema for weight management clinics
--
CREATE TABLE IF NOT EXISTS `body_composition` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pid` BIGINT UNSIGNED NOT NULL,
    `encounter_id` BIGINT UNSIGNED DEFAULT NULL,
    `recorded_date` DATE NOT NULL,
    `weight_kg` DECIMAL(6,2) DEFAULT NULL,
    `height_m` DECIMAL(4,2) DEFAULT NULL,
    `bmi` DECIMAL(5,2) DEFAULT NULL,
    `body_fat_percent` DECIMAL(5,2) DEFAULT NULL,
    `muscle_mass_kg` DECIMAL(6,2) DEFAULT NULL,
    `abdomen_cm` DECIMAL(6,2) DEFAULT NULL,
    `hip_cm` DECIMAL(6,2) DEFAULT NULL,
    `arm_cm` DECIMAL(6,2) DEFAULT NULL,
    `thigh_cm` DECIMAL(6,2) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `updated_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_body_composition_pid` (`pid`),
    KEY `idx_body_composition_encounter` (`encounter_id`),
    CONSTRAINT `fk_body_comp_patient` FOREIGN KEY (`pid`) REFERENCES `patient_data` (`pid`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Media table keeps references to before/after photos stored in OpenEMR documents
CREATE TABLE IF NOT EXISTS `body_composition_media` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `body_composition_id` BIGINT UNSIGNED NOT NULL,
    `document_id` BIGINT UNSIGNED NOT NULL,
    `label` VARCHAR(255) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_body_comp_media_doc` (`document_id`),
    CONSTRAINT `fk_body_comp_media_comp` FOREIGN KEY (`body_composition_id`) REFERENCES `body_composition` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_body_comp_media_doc` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
