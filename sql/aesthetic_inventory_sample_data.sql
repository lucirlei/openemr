-- Optional sample dataset for the aesthetic inventory workflow.
--
-- This script seeds demonstration drugs, inventory lots, procedure codes,
-- and procedure-to-product mappings that exercise the automatic
-- consumption logic introduced for aesthetic packages.
--
-- The statements are idempotent and may be re-run safely.

#IfNotRow drugs name 'Botox Cosmetic 100U'
INSERT INTO `drugs` (
    `name`, `ndc_number`, `on_order`, `reorder_point`, `max_level`, `last_notify`,
    `reactions`, `form`, `size`, `unit`, `route`, `substitute`, `related_code`,
    `cyp_factor`, `active`, `allow_combining`, `allow_multiple`, `drug_code`,
    `consumable`, `dispensable`, `aesthetic_category`, `photo_url`, `unit_cost`,
    `supplier_name`
) VALUES (
    'Botox Cosmetic 100U', '00023-1145-01', 0, 4, 40, NULL,
    '', 'solution', '100', 'units', 'Intramuscular', 0, '',
    0, 1, 0, 1, 'BTX100', 1, 1, 'Neurotoxin',
    'https://example.org/assets/botox-100u.jpg', 420.00, 'Allergan Aesthetics'
);
#EndIf

#IfNotRow drugs name 'Hyaluronic Acid Filler 1mL'
INSERT INTO `drugs` (
    `name`, `ndc_number`, `on_order`, `reorder_point`, `max_level`, `last_notify`,
    `reactions`, `form`, `size`, `unit`, `route`, `substitute`, `related_code`,
    `cyp_factor`, `active`, `allow_combining`, `allow_multiple`, `drug_code`,
    `consumable`, `dispensable`, `aesthetic_category`, `photo_url`, `unit_cost`,
    `supplier_name`
) VALUES (
    'Hyaluronic Acid Filler 1mL', '55566-0001-01', 0, 6, 60, NULL,
    '', 'gel', '1', 'mL', 'Intradermal', 0, '',
    0, 1, 0, 1, 'FILLER1ML', 1, 1, 'Dermal Filler',
    'https://example.org/assets/ha-filler.jpg', 250.00, 'Galderma Laboratories'
);
#EndIf

#IfNotRow drugs name 'Medical Grade Peel Kit'
INSERT INTO `drugs` (
    `name`, `ndc_number`, `on_order`, `reorder_point`, `max_level`, `last_notify`,
    `reactions`, `form`, `size`, `unit`, `route`, `substitute`, `related_code`,
    `cyp_factor`, `active`, `allow_combining`, `allow_multiple`, `drug_code`,
    `consumable`, `dispensable`, `aesthetic_category`, `photo_url`, `unit_cost`,
    `supplier_name`
) VALUES (
    'Medical Grade Peel Kit', '99999-0001-01', 0, 2, 20, NULL,
    '', 'kit', '1', 'kit', 'Topical', 0, '',
    0, 1, 0, 1, 'PEELKIT', 1, 1, 'Chemical Peel',
    'https://example.org/assets/peel-kit.jpg', 85.00, 'SkinScience Labs'
);
#EndIf

SET @drug_botox := (
    SELECT `drug_id` FROM `drugs` WHERE `name` = 'Botox Cosmetic 100U' LIMIT 1
);
SET @drug_filler := (
    SELECT `drug_id` FROM `drugs` WHERE `name` = 'Hyaluronic Acid Filler 1mL' LIMIT 1
);
SET @drug_peel := (
    SELECT `drug_id` FROM `drugs` WHERE `name` = 'Medical Grade Peel Kit' LIMIT 1
);

-- Seed illustrative inventory lots so the demo data can be exercised immediately.
INSERT INTO `drug_inventory` (
    `drug_id`, `lot_number`, `expiration`, `manufacturer`, `on_hand`, `warehouse_id`,
    `vendor_id`, `last_notify`, `aesthetic_category`, `photo_url`, `unit_cost`,
    `supplier_name`
)
SELECT @drug_botox, 'BTX-24A', DATE_ADD(CURDATE(), INTERVAL 9 MONTH), 'Allergan', 10, '',
    0, NULL, 'Neurotoxin', 'https://example.org/assets/botox-100u.jpg', 420.00,
    'Allergan Aesthetics'
WHERE @drug_botox IS NOT NULL
  AND NOT EXISTS (
        SELECT 1 FROM `drug_inventory`
        WHERE `drug_id` = @drug_botox AND `lot_number` = 'BTX-24A'
    );

INSERT INTO `drug_inventory` (
    `drug_id`, `lot_number`, `expiration`, `manufacturer`, `on_hand`, `warehouse_id`,
    `vendor_id`, `last_notify`, `aesthetic_category`, `photo_url`, `unit_cost`,
    `supplier_name`
)
SELECT @drug_filler, 'FILL-24B', DATE_ADD(CURDATE(), INTERVAL 6 MONTH), 'Galderma', 24, '',
    0, NULL, 'Dermal Filler', 'https://example.org/assets/ha-filler.jpg', 250.00,
    'Galderma Laboratories'
WHERE @drug_filler IS NOT NULL
  AND NOT EXISTS (
        SELECT 1 FROM `drug_inventory`
        WHERE `drug_id` = @drug_filler AND `lot_number` = 'FILL-24B'
    );

INSERT INTO `drug_inventory` (
    `drug_id`, `lot_number`, `expiration`, `manufacturer`, `on_hand`, `warehouse_id`,
    `vendor_id`, `last_notify`, `aesthetic_category`, `photo_url`, `unit_cost`,
    `supplier_name`
)
SELECT @drug_peel, 'PEEL-24C', DATE_ADD(CURDATE(), INTERVAL 4 MONTH), 'SkinScience', 12, '',
    0, NULL, 'Chemical Peel', 'https://example.org/assets/peel-kit.jpg', 85.00,
    'SkinScience Labs'
WHERE @drug_peel IS NOT NULL
  AND NOT EXISTS (
        SELECT 1 FROM `drug_inventory`
        WHERE `drug_id` = @drug_peel AND `lot_number` = 'PEEL-24C'
    );

-- Provide matching fee sheet codes for the demo procedures if they do not already exist.
INSERT INTO `codes` (`code_text`, `code_text_short`, `code`, `code_type`, `fee`, `superbill`, `related_code`, `taxrates`, `cyp_factor`, `active`, `reportable`, `financial_reporting`, `revenue_code`)
SELECT 'Rhytidectomy, face; SMAS flap', 'Facelift SMAS flap', '15828', `ct_id`, 0.00, '', '', '', 0, 1, 0, 0, ''
FROM `code_types`
WHERE `ct_key` = 'CPT4'
  AND NOT EXISTS (
        SELECT 1 FROM `codes`
        WHERE `code` = '15828' AND `code_type` = `code_types`.`ct_id`
    );

INSERT INTO `codes` (`code_text`, `code_text_short`, `code`, `code_type`, `fee`, `superbill`, `related_code`, `taxrates`, `cyp_factor`, `active`, `reportable`, `financial_reporting`, `revenue_code`)
SELECT 'Injection of filler material, face', 'Facial filler injection', '11950', `ct_id`, 0.00, '', '', '', 0, 1, 0, 0, ''
FROM `code_types`
WHERE `ct_key` = 'CPT4'
  AND NOT EXISTS (
        SELECT 1 FROM `codes`
        WHERE `code` = '11950' AND `code_type` = `code_types`.`ct_id`
    );

INSERT INTO `codes` (`code_text`, `code_text_short`, `code`, `code_type`, `fee`, `superbill`, `related_code`, `taxrates`, `cyp_factor`, `active`, `reportable`, `financial_reporting`, `revenue_code`)
SELECT 'Chemical peel, facial; epidermal', 'Epidermal chemical peel', '15788', `ct_id`, 0.00, '', '', '', 0, 1, 0, 0, ''
FROM `code_types`
WHERE `ct_key` = 'CPT4'
  AND NOT EXISTS (
        SELECT 1 FROM `codes`
        WHERE `code` = '15788' AND `code_type` = `code_types`.`ct_id`
    );

-- Tie the sample procedures to inventory consumption expectations.
INSERT INTO `procedure_products` (`code_type`, `code`, `drug_id`, `quantity`)
SELECT 'CPT4', '15828', @drug_botox, 1.0000
WHERE @drug_botox IS NOT NULL
  AND NOT EXISTS (
        SELECT 1 FROM `procedure_products`
        WHERE `code_type` = 'CPT4' AND `code` = '15828' AND `drug_id` = @drug_botox
    );

INSERT INTO `procedure_products` (`code_type`, `code`, `drug_id`, `quantity`)
SELECT 'CPT4', '11950', @drug_filler, 1.0000
WHERE @drug_filler IS NOT NULL
  AND NOT EXISTS (
        SELECT 1 FROM `procedure_products`
        WHERE `code_type` = 'CPT4' AND `code` = '11950' AND `drug_id` = @drug_filler
    );

INSERT INTO `procedure_products` (`code_type`, `code`, `drug_id`, `quantity`)
SELECT 'CPT4', '15788', @drug_peel, 1.0000
WHERE @drug_peel IS NOT NULL
  AND NOT EXISTS (
        SELECT 1 FROM `procedure_products`
        WHERE `code_type` = 'CPT4' AND `code` = '15788' AND `drug_id` = @drug_peel
    );

-- Reset the working variables.
SET @drug_botox = NULL;
SET @drug_filler = NULL;
SET @drug_peel = NULL;
