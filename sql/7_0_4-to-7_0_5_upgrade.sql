--
--  Comment Meta Language Constructs:
--
--  #IfNotTable
--    argument: table_name
--    behavior: if the table_name does not exist,  the block will be executed
--
--  #IfTable
--    argument: table_name
--    behavior: if the table_name does exist, the block will be executed
--
--  #IfColumn
--    arguments: table_name colname
--    behavior:  if the table and column exist,  the block will be executed
--
--  #IfMissingColumn
--    arguments: table_name colname
--    behavior:  if the table exists but the column does not,  the block will be executed
--
--  #IfNotColumnType
--    arguments: table_name colname value
--    behavior:  If the table table_name does not have a column colname with a data type equal to value, then the block will be executed
--
--  #IfNotColumnTypeDefault
--    arguments: table_name colname value value2
--    behavior:  If the table table_name does not have a column colname with a data type equal to value and a default equal to value2, then the block will be executed
--
--  #IfNotRow
--    arguments: table_name colname value
--    behavior:  If the table table_name does not have a row where colname = value, the block will be executed.
--
--  #IfNotRow2D
--    arguments: table_name colname value colname2 value2
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2, the block will be executed.
--
--  #IfNotRow3D
--    arguments: table_name colname value colname2 value2 colname3 value3
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2 AND colname3 = value3, the block will be executed.
--
--  #IfNotRow4D
--    arguments: table_name colname value colname2 value2 colname3 value3 colname4 value4
--    behavior:  If the table table_name does not have a row where colname = value AND colname2 = value2 AND colname3 = value3 AND colname4 = value4, the block will be executed.
--
--  #IfNotRow2Dx2
--    desc:      This is a very specialized function to allow adding items to the list_options table to avoid both redundant option_id and title in each element.
--    arguments: table_name colname value colname2 value2 colname3 value3
--    behavior:  The block will be executed if both statements below are true:
--               1) The table table_name does not have a row where colname = value AND colname2 = value2.
--               2) The table table_name does not have a row where colname = value AND colname3 = value3.
--
--  #IfRow
--    arguments: table_name colname value
--    behavior:  If the table table_name does have a row where colname = value, the block will be executed.
--
--  #IfRow2D
--    arguments: table_name colname value colname2 value2
--    behavior:  If the table table_name does have a row where colname = value AND colname2 = value2, the block will be executed.
--
--  #IfRow3D
--        arguments: table_name colname value colname2 value2 colname3 value3
--        behavior:  If the table table_name does have a row where colname = value AND colname2 = value2 AND colname3 = value3, the block will be executed.
--
--  #IfRowIsNull
--    arguments: table_name colname
--    behavior:  If the table table_name does have a row where colname is null, the block will be executed.
--
--  #IfIndex
--    desc:      This function is most often used for dropping of indexes/keys.
--    arguments: table_name colname
--    behavior:  If the table and index exist the relevant statements are executed, otherwise not.
--
--  #IfNotIndex
--    desc:      This function will allow adding of indexes/keys.
--    arguments: table_name colname
--    behavior:  If the index does not exist, it will be created
--
--  #EndIf
--    all blocks are terminated with a #EndIf statement.
--
--  #IfNotListReaction
--    Custom function for creating Reaction List
--
--  #IfNotListOccupation
--    Custom function for creating Occupation List
--
--  #IfTextNullFixNeeded
--    desc: convert all text fields without default null to have default null.
--    arguments: none
--
--  #IfTableEngine
--    desc:      Execute SQL if the table has been created with given engine specified.
--    arguments: table_name engine
--    behavior:  Use when engine conversion requires more than one ALTER TABLE
--
--  #IfInnoDBMigrationNeeded
--    desc: find all MyISAM tables and convert them to InnoDB.
--    arguments: none
--    behavior: can take a long time.
--
--  #IfDocumentNamingNeeded
--    desc: populate name field with document names.
--    arguments: none
--
--  #IfUpdateEditOptionsNeeded
--    desc: Change Layout edit options.
--    arguments: mode(add or remove) layout_form_id the_edit_option comma_separated_list_of_field_ids
--
--  #IfVitalsDatesNeeded
--    desc: Change date from zeroes to date of vitals form creation.
--    arguments: none
--
#IfNotTable patient_media_album
CREATE TABLE `patient_media_album` (
    `id`                INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36) NOT NULL,
    `patient_id`        INT(11) NOT NULL,
    `title`             VARCHAR(255) NOT NULL,
    `description`       TEXT NULL,
    `cover_asset_id`    INT(11) UNSIGNED NULL,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted`           TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_patient_media_album_uuid` (`uuid`),
    KEY `idx_patient_media_album_patient` (`patient_id`),
    KEY `idx_patient_media_album_cover_asset` (`cover_asset_id`)
) ENGINE=InnoDB COMMENT='Patient aesthetic media albums';
#EndIf

#IfNotTable patient_media_asset
CREATE TABLE `patient_media_asset` (
    `id`                INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`              CHAR(36) NOT NULL,
    `album_id`          INT(11) UNSIGNED NOT NULL,
    `patient_id`        INT(11) NOT NULL,
    `document_id`       INT(11) UNSIGNED NOT NULL,
    `captured_at`       DATETIME NULL,
    `metadata`          JSON NULL,
    `consent_status`    VARCHAR(32) NOT NULL DEFAULT 'pending',
    `watermark_notes`   VARCHAR(255) NULL,
    `watermark_applied` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_patient_media_asset_uuid` (`uuid`),
    KEY `idx_patient_media_asset_album` (`album_id`),
    KEY `idx_patient_media_asset_document` (`document_id`),
    KEY `idx_patient_media_asset_patient` (`patient_id`),
    CONSTRAINT `fk_patient_media_asset_album`
        FOREIGN KEY (`album_id`) REFERENCES `patient_media_album` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_patient_media_asset_document`
        FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Media assets with metadata, consent, and watermark info';
#EndIf

#IfNotTable patient_media_timeline
CREATE TABLE `patient_media_timeline` (
    `id`            INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `uuid`          CHAR(36) NOT NULL,
    `patient_id`    INT(11) NOT NULL,
    `asset_id`      INT(11) UNSIGNED NOT NULL,
    `event_type`    VARCHAR(64) NOT NULL DEFAULT 'capture',
    `event_date`    DATETIME NOT NULL,
    `notes`         TEXT NULL,
    `sort_order`    INT(11) NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_patient_media_timeline_uuid` (`uuid`),
    KEY `idx_patient_media_timeline_patient` (`patient_id`),
    KEY `idx_patient_media_timeline_asset` (`asset_id`),
    CONSTRAINT `fk_patient_media_timeline_asset`
        FOREIGN KEY (`asset_id`) REFERENCES `patient_media_asset` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB COMMENT='Chronological events for patient media assets';
#EndIf

#IfNotRow categories name 'Aesthetic Media'
INSERT INTO `categories` (`name`, `parent`, `is_folder`)
VALUES ('Aesthetic Media', NULL, 1);
#EndIf

#IfNotRow categories name 'Media Gallery'
INSERT INTO `categories` (`name`, `parent`, `is_folder`)
SELECT 'Media Gallery', `id`, 0
FROM `categories`
WHERE `name` = 'Aesthetic Media'
LIMIT 1;
#EndIf

#IfMissingColumn drugs aesthetic_category
ALTER TABLE `drugs`
    ADD COLUMN `aesthetic_category` varchar(63) NOT NULL DEFAULT '' COMMENT 'category grouping for aesthetic inventory';
#EndIf

#IfMissingColumn drugs photo_url
ALTER TABLE `drugs`
    ADD COLUMN `photo_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'public URL or storage reference for product photos';
#EndIf

#IfNotTable scheduler_resources
CREATE TABLE `scheduler_resources` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `uuid` char(36) DEFAULT NULL,
    `name` varchar(191) NOT NULL,
    `resource_type` varchar(64) NOT NULL DEFAULT 'generic',
    `facility_id` int(11) DEFAULT NULL,
    `color` varchar(7) DEFAULT NULL,
    `active` tinyint(1) NOT NULL DEFAULT 1,
    `notes` text,
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_scheduler_resources_uuid` (`uuid`),
    KEY `idx_scheduler_resources_facility` (`facility_id`),
    CONSTRAINT `scheduler_resources_facility_fk` FOREIGN KEY (`facility_id`) REFERENCES `facility` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;
#EndIf

#IfNotTable event_resource_link
CREATE TABLE `event_resource_link` (
    `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
    `event_id` int(11) NOT NULL,
    `resource_id` int(11) unsigned NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_event_resource` (`event_id`,`resource_id`),
    KEY `idx_event_resource_link_resource` (`resource_id`),
    CONSTRAINT `event_resource_link_event_fk` FOREIGN KEY (`event_id`) REFERENCES `openemr_postcalendar_events` (`pc_eid`) ON DELETE CASCADE,
    CONSTRAINT `event_resource_link_resource_fk` FOREIGN KEY (`resource_id`) REFERENCES `scheduler_resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
#EndIf

#IfNotRow2D list_options list_id apptstat option_id AGDAV
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `notes`, `codes`, `modifier`, `activity`)
VALUES ('apptstat', 'AGDAV', 'Aguardando avaliação', 55, '#0d6efd|0', '', '', 1);
#EndIf

#IfNotRow2D list_options list_id apptstat option_id PROCED
INSERT INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `notes`, `codes`, `modifier`, `activity`)
VALUES ('apptstat', 'PROCED', 'Em procedimento', 56, '#6610f2|0', '', '', 1);
#EndIf

#IfMissingColumn drugs unit_cost
ALTER TABLE `drugs`
    ADD COLUMN `unit_cost` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'default internal cost per unit';
#EndIf

#IfMissingColumn drugs supplier_name
ALTER TABLE `drugs`
    ADD COLUMN `supplier_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'preferred supplier for the product';
#EndIf

#IfMissingColumn drug_inventory aesthetic_category
ALTER TABLE `drug_inventory`
    ADD COLUMN `aesthetic_category` varchar(63) NOT NULL DEFAULT '' COMMENT 'category override for aesthetic workflows';
#EndIf

#IfMissingColumn drug_inventory photo_url
ALTER TABLE `drug_inventory`
    ADD COLUMN `photo_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'lot-specific photo or documentation reference';
#EndIf

#IfMissingColumn drug_inventory unit_cost
ALTER TABLE `drug_inventory`
    ADD COLUMN `unit_cost` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'actual acquired cost per unit for the lot';
#EndIf

#IfMissingColumn drug_inventory supplier_name
ALTER TABLE `drug_inventory`
    ADD COLUMN `supplier_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'supplier captured for the lot';
#EndIf

#IfNotTable procedure_products
CREATE TABLE `procedure_products` (
    `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    `code_type` varchar(31) NOT NULL COMMENT 'service code type (e.g. CPT4, HCPCS)',
    `code` varchar(64) NOT NULL COMMENT 'service/procedure code',
    `drug_id` int(11) NOT NULL COMMENT 'references drugs.drug_id',
    `quantity` decimal(12,4) NOT NULL DEFAULT '1.0000' COMMENT 'inventory units to consume per service unit',
    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_procedure_products_code_drug` (`code_type`,`code`,`drug_id`),
    KEY `idx_procedure_products_drug` (`drug_id`),
    CONSTRAINT `procedure_products_ibfk_drug` FOREIGN KEY (`drug_id`) REFERENCES `drugs` (`drug_id`) ON DELETE CASCADE
) ENGINE=InnoDB;
#EndIf

#IfNotRow background_services name 'AestheticInventoryAlerts'
INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`)
VALUES ('AestheticInventoryAlerts', 'Aesthetic Inventory Alerts', 1, 0, NOW(), 1440, 'run_aesthetic_inventory_alerts', '/library/aesthetic_inventory_alerts.php', 110);
#EndIf
