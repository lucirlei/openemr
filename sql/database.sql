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

  `aesthetic_category` varchar(63) NOT NULL DEFAULT '' COMMENT 'category grouping for aesthetic inventory',
  `photo_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'public URL or storage reference for product photos',
  `unit_cost` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT 'default internal cost per unit',
  `supplier_name` varchar(255) NOT NULL DEFAULT '' COMMENT 'preferred supplier for the product',
