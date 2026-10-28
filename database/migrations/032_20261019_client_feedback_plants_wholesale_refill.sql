-- Perfect LPG - Client feedback changes
-- 1) Supplier plants and purchase plant tracking
-- 2) Refill-empty-cylinder purchase line type and filled-cylinder purchase origin
-- 3) Retail / wholesale customer type and wholesale per-cylinder rate
-- Idempotent: safe to run more than once.

CREATE TABLE IF NOT EXISTS supplier_plants(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  city VARCHAR(80) NULL,
  is_active BOOLEAN NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_supplier_plant(supplier_id,name),
  FOREIGN KEY(supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT
)ENGINE=InnoDB;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE purchases ADD COLUMN plant_id BIGINT UNSIGNED NULL AFTER supplier_id, ADD KEY idx_purchase_plant(plant_id), ADD CONSTRAINT fk_purchase_plant FOREIGN KEY(plant_id) REFERENCES supplier_plants(id) ON DELETE RESTRICT",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'plant_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE purchase_items
  MODIFY line_type ENUM('gas_kg','filled_cylinder','empty_cylinder','refill_empty') NOT NULL;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE cylinder_units ADD COLUMN filled_purchase_id BIGINT UNSIGNED NULL AFTER source_id, ADD KEY idx_cylinder_filled_purchase(filled_purchase_id)",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cylinder_units' AND COLUMN_NAME = 'filled_purchase_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE customers ADD COLUMN customer_type ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER name",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'customer_type'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop the old rate-type CHECK (found by its clause) before widening the ENUM.
SET @chk = (
  SELECT tc.CONSTRAINT_NAME
  FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
  JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc
    ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
  WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = 'rate_cards'
    AND tc.CONSTRAINT_TYPE = 'CHECK' AND cc.CHECK_CLAUSE LIKE '%cylinder_package%'
  LIMIT 1
);
SET @sql = IF(@chk IS NULL, 'SELECT 1', CONCAT('ALTER TABLE rate_cards DROP CONSTRAINT `', @chk, '`'));
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE rate_cards
  MODIFY rate_type ENUM('gas_per_kg','cylinder_package','wholesale_cylinder') NOT NULL;

ALTER TABLE rate_change_log
  MODIFY rate_type ENUM('gas_per_kg','cylinder_package','wholesale_cylinder') NOT NULL;

ALTER TABLE rate_cards
  ADD CONSTRAINT chk_rate_card_type CHECK(
    (rate_type='gas_per_kg' AND cylinder_type_id IS NULL)
    OR (rate_type IN ('cylinder_package','wholesale_cylinder') AND cylinder_type_id IS NOT NULL)
  );
