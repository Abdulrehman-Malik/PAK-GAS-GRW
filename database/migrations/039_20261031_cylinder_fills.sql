-- Perfect LPG - Fill / Empty Cylinders
-- History of shop cylinders converted between empty and full on the Fill / Empty Cylinders screen.
-- Physical cylinders and inventory movements stay the source of stock. This table is the history of each conversion.
-- Idempotent: safe to run more than once.
-- Note for editors: the migration runner splits on semicolons and tracks quotes, so comments must not contain either.

CREATE TABLE IF NOT EXISTS cylinder_fills(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location_id BIGINT UNSIGNED NOT NULL,
  direction ENUM('empty_to_full','full_to_empty') NOT NULL,
  cylinder_type_id BIGINT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  total_gas_kg DECIMAL(14,3) NOT NULL,
  empty_before INT UNSIGNED NOT NULL,
  empty_after INT UNSIGNED NOT NULL,
  notes VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cylinder_fill_location FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cylinder_fill_type FOREIGN KEY(cylinder_type_id) REFERENCES cylinder_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cylinder_fill_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_cylinder_fill_list(location_id,id),
  CHECK(quantity>0)
)ENGINE=InnoDB;
