--
-- Consent form templates and patient signatures for aesthetic procedures
--
CREATE TABLE IF NOT EXISTS `consent_templates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `body_html` MEDIUMTEXT NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` BIGINT UNSIGNED DEFAULT NULL,
    `updated_by` BIGINT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `patient_signed_consents` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pid` BIGINT UNSIGNED NOT NULL,
    `encounter_id` BIGINT UNSIGNED DEFAULT NULL,
    `template_id` BIGINT UNSIGNED NOT NULL,
    `signed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `signature_path` VARCHAR(512) DEFAULT NULL,
    `signature_hash` VARCHAR(128) DEFAULT NULL,
    `form_data` JSON DEFAULT NULL,
    `signed_by` BIGINT UNSIGNED DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_signed_consents_pid` (`pid`),
    KEY `idx_signed_consents_template` (`template_id`),
    CONSTRAINT `fk_signed_consents_patient` FOREIGN KEY (`pid`) REFERENCES `patient_data` (`pid`) ON DELETE CASCADE,
    CONSTRAINT `fk_signed_consents_template` FOREIGN KEY (`template_id`) REFERENCES `consent_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
