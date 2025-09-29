INSERT INTO `background_services` (`name`, `title`, `active`, `running`, `next_run`, `execute_interval`, `function`, `require_once`, `sort_order`) VALUES
('AestheticInventoryAlerts', 'Aesthetic Inventory Alerts', 1, 0, NOW(), 1440, 'run_aesthetic_inventory_alerts', '/library/aesthetic_inventory_alerts.php', 110);
  `aesthetic_category` varchar(63) NOT NULL DEFAULT '' COMMENT 'category override for aesthetic workflows',
  `photo_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'lot-specific photo or documentation reference',
  `unit_cost` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'actual acquired cost per unit for the lot',
  `supplier_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'supplier captured for the lot',
--
-- Table structure for table `procedure_products`
--

DROP TABLE IF EXISTS `procedure_products`;
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

-- --------------------------------------------------------

--
-- Table structure for table `scheduler_resources`
--

DROP TABLE IF EXISTS `scheduler_resources`;
CREATE TABLE `scheduler_resources` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) DEFAULT NULL,
  `name` varchar(191) NOT NULL,
  `resource_type` varchar(64) NOT NULL DEFAULT 'generic',
  `facility_id` int(11) DEFAULT NULL,
  `color` varchar(7) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_scheduler_resources_uuid` (`uuid`),
  KEY `idx_scheduler_resources_facility` (`facility_id`),
  CONSTRAINT `scheduler_resources_facility_fk` FOREIGN KEY (`facility_id`) REFERENCES `facility` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `event_resource_link`
--

DROP TABLE IF EXISTS `event_resource_link`;
CREATE TABLE `event_resource_link` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `resource_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_resource` (`event_id`,`resource_id`),
  KEY `idx_event_resource_link_resource` (`resource_id`),
  CONSTRAINT `event_resource_link_event_fk` FOREIGN KEY (`event_id`) REFERENCES `openemr_postcalendar_events` (`pc_eid`) ON DELETE CASCADE,
  CONSTRAINT `event_resource_link_resource_fk` FOREIGN KEY (`resource_id`) REFERENCES `scheduler_resources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

  `aesthetic_category` varchar(63) NOT NULL DEFAULT '' COMMENT 'category grouping for aesthetic inventory',
  `photo_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'public URL or storage reference for product photos',
  `unit_cost` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'default internal cost per unit',
  `supplier_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'preferred supplier for the product',
