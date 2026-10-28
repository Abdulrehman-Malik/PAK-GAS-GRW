-- Perfect LPG - LPG Rates price grid
-- 1) Every rate belongs to a price list (retail or wholesale)
-- 2) Rate types become gas_per_kg, gas_per_cylinder and empty_cylinder
--    wholesale_cylinder rows become wholesale gas_per_cylinder rows
--    cylinder_package rows become retail empty_cylinder rows
-- 3) Rates can be stopped with effective_to instead of being deleted
-- Idempotent: safe to run more than once.
-- Note for editors: the migration runner splits on semicolons, so comments must not contain one.

-- Drop the rate-type CHECK (found by its clause) so the ENUM can change.
SET @chk = (
  SELECT tc.CONSTRAINT_NAME
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
  JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc
    ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
  WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'rate_cards'
    AND tc.CONSTRAINT_TYPE = 'CHECK' AND cc.CHECK_CLAUSE LIKE '%cylinder_type_id%'
  LIMIT 1
);
SET @sql = IF(@chk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE rate_cards DROP CONSTRAINT `', @chk, '`'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE rate_cards
  MODIFY rate_type ENUM('gas_per_kg','cylinder_package','wholesale_cylinder','gas_per_cylinder','empty_cylinder') NOT NULL;
ALTER TABLE rate_change_log
  MODIFY rate_type ENUM('gas_per_kg','cylinder_package','wholesale_cylinder','gas_per_cylinder','empty_cylinder') NOT NULL;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE rate_cards ADD COLUMN price_list ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER location_id",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_cards' AND COLUMN_NAME = 'price_list'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE rate_change_log ADD COLUMN price_list ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER location_id",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_change_log' AND COLUMN_NAME = 'price_list'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE rate_cards SET rate_type = 'gas_per_cylinder', price_list = 'wholesale' WHERE rate_type = 'wholesale_cylinder';
UPDATE rate_cards SET rate_type = 'empty_cylinder', price_list = 'retail' WHERE rate_type = 'cylinder_package';
UPDATE rate_change_log SET rate_type = 'gas_per_cylinder', price_list = 'wholesale' WHERE rate_type = 'wholesale_cylinder';
UPDATE rate_change_log SET rate_type = 'empty_cylinder', price_list = 'retail' WHERE rate_type = 'cylinder_package';

ALTER TABLE rate_cards
  MODIFY rate_type ENUM('gas_per_kg','gas_per_cylinder','empty_cylinder') NOT NULL;
ALTER TABLE rate_change_log
  MODIFY rate_type ENUM('gas_per_kg','gas_per_cylinder','empty_cylinder') NOT NULL;

ALTER TABLE rate_cards
  ADD CONSTRAINT chk_rate_card_type CHECK(
    (rate_type = 'gas_per_kg' AND cylinder_type_id IS NULL)
    OR (rate_type IN ('gas_per_cylinder','empty_cylinder') AND cylinder_type_id IS NOT NULL)
  );

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE rate_cards ADD KEY idx_rate_price_lookup(location_id,price_list,rate_type,cylinder_type_id,effective_from)",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_cards' AND INDEX_NAME = 'idx_rate_price_lookup'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
