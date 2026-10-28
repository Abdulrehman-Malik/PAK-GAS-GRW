-- Perfect LPG - Shop-level pricing settings (Retail / Wholesale price lists)
-- 1) Six Pricing settings on shop_settings
-- 2) Customer price list may follow the shop default ("shop_default")
-- 3) Sales record the price list used and any rate-change reason, and lines record the rate source
-- Idempotent: safe to run more than once.

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE shop_settings
       ADD COLUMN wholesale_price_list_enabled BOOLEAN NOT NULL DEFAULT 1 AFTER shop_credit_limit,
       ADD COLUMN default_price_list ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER wholesale_price_list_enabled,
       ADD COLUMN walkin_price_list ENUM('retail','wholesale') NOT NULL DEFAULT 'retail' AFTER default_price_list,
       ADD COLUMN customer_special_rates_enabled BOOLEAN NOT NULL DEFAULT 1 AFTER walkin_price_list,
       ADD COLUMN pos_rate_change_mode ENUM('not_allowed','allowed','reason_required') NOT NULL DEFAULT 'reason_required' AFTER customer_special_rates_enabled,
       ADD COLUMN pos_price_list_switch BOOLEAN NOT NULL DEFAULT 0 AFTER pos_rate_change_mode",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shop_settings' AND COLUMN_NAME = 'wholesale_price_list_enabled'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Existing Retail customers follow the shop default (Retail unless the shop changes it).
-- Run only while the ENUM does not yet contain shop_default, so a retry cannot re-convert customers.
SET @needs_customer_conversion = (
  SELECT IF(COUNT(*) = 0, 1, 0)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'customer_type'
    AND COLUMN_TYPE LIKE '%shop_default%'
);

ALTER TABLE customers
  MODIFY customer_type ENUM('shop_default','retail','wholesale') NOT NULL DEFAULT 'shop_default';

UPDATE customers SET customer_type = 'shop_default'
WHERE customer_type = 'retail' AND @needs_customer_conversion = 1;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE sales
       ADD COLUMN price_list ENUM('retail','wholesale') NULL AFTER custom_rate_flag,
       ADD COLUMN rate_change_reason VARCHAR(255) NULL AFTER price_list",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'price_list'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE sale_items ADD COLUMN rate_source VARCHAR(30) NULL AFTER custom_rate_flag",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'rate_source'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
