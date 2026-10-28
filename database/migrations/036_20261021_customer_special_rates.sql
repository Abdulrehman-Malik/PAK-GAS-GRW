-- Perfect LPG - Customer special rates
-- 1) customer_rates holds a customer's own Gas per KG, Gas per full cylinder or
--    Empty cylinder price for one shop. It overrides the customer's price list.
-- 2) rate_change_log also records customer rate changes (customer_id, customer_rate_id)
--    and price_list becomes optional because a customer rate has no price list.
-- Idempotent: safe to run more than once.
-- Note for editors: the migration runner splits on semicolons, so comments must not contain one.

CREATE TABLE IF NOT EXISTS customer_rates(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  rate_type ENUM('gas_per_kg','gas_per_cylinder','empty_cylinder') NOT NULL,
  cylinder_type_id BIGINT UNSIGNED NULL,
  rate_value DECIMAL(14,4) NOT NULL,
  effective_from DATETIME NOT NULL,
  effective_to DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_customer_rate_lookup(location_id,customer_id,rate_type,cylinder_type_id,effective_from),
  KEY idx_customer_rate_customer(customer_id),
  CONSTRAINT fk_customer_rate_location FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_rate_customer FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_rate_cylinder FOREIGN KEY(cylinder_type_id) REFERENCES cylinder_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_customer_rate_user FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_customer_rate_value CHECK(rate_value>0),
  CONSTRAINT chk_customer_rate_type CHECK((rate_type='gas_per_kg' AND cylinder_type_id IS NULL) OR (rate_type IN ('gas_per_cylinder','empty_cylinder') AND cylinder_type_id IS NOT NULL))
) ENGINE=InnoDB;

ALTER TABLE rate_change_log MODIFY price_list ENUM('retail','wholesale') NULL DEFAULT 'retail';

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE rate_change_log ADD COLUMN customer_id BIGINT UNSIGNED NULL AFTER price_list, ADD COLUMN customer_rate_id BIGINT UNSIGNED NULL AFTER rate_card_id, ADD CONSTRAINT fk_rate_log_customer FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE SET NULL, ADD CONSTRAINT fk_rate_log_customer_rate FOREIGN KEY(customer_rate_id) REFERENCES customer_rates(id) ON DELETE SET NULL",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rate_change_log' AND COLUMN_NAME = 'customer_id'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
