-- Perfect LPG - Old Issued Cylinders
-- Records cylinders customers already held before the system was used. An entry adds to the issued cylinders and OS of the
-- customer only. It creates no sale, cash, deposit or shop stock.
-- Adds the voided status to custody and physical cylinders, used when an entry was a mistake.
-- Idempotent: safe to run more than once.
-- Note for editors: the migration runner splits on semicolons and tracks quotes, so comments must not contain either.

CREATE TABLE IF NOT EXISTS old_issued_cylinders(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  cylinder_type_id BIGINT UNSIGNED NOT NULL,
  issued_on DATE NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  rate_per_cylinder DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  amount_received DECIMAL(14,2) NOT NULL DEFAULT 0,
  outstanding DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('posted','voided') NOT NULL DEFAULT 'posted',
  void_reason VARCHAR(255) NULL,
  voided_by BIGINT UNSIGNED NULL,
  voided_at DATETIME NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_old_issued_location FOREIGN KEY(location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_old_issued_customer FOREIGN KEY(customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_old_issued_type FOREIGN KEY(cylinder_type_id) REFERENCES cylinder_types(id) ON DELETE RESTRICT,
  CONSTRAINT fk_old_issued_created_by FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_old_issued_voided_by FOREIGN KEY(voided_by) REFERENCES users(id) ON DELETE SET NULL,
  KEY idx_old_issued_list(location_id,status,id),
  CHECK(quantity>0),
  CHECK(amount_received>=0),
  CHECK(outstanding>=0)
)ENGINE=InnoDB;

ALTER TABLE cylinder_custody MODIFY COLUMN status ENUM('issued','returned','voided') NOT NULL DEFAULT 'issued';

ALTER TABLE cylinder_units MODIFY COLUMN status ENUM('filled','empty','custody','sold','voided') NOT NULL DEFAULT 'empty';
