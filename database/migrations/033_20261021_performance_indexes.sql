-- Performance indexes for dashboard, POS balances, ledgers, void lookups and reports.
-- Index-only change: no data or behaviour is altered. Idempotent (skips existing indexes).

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE sales ADD KEY idx_sales_loc_status_date(location_id,status,transaction_at)",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND INDEX_NAME = 'idx_sales_loc_status_date'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE sales ADD KEY idx_sales_loc_status_customer(location_id,status,customer_id)",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND INDEX_NAME = 'idx_sales_loc_status_customer'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE customer_receipts ADD KEY idx_receipt_loc_customer_status(location_id,customer_id,status)",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_receipts' AND INDEX_NAME = 'idx_receipt_loc_customer_status'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE customer_receipts ADD KEY idx_receipt_loc_status_date(location_id,status,receipt_at)",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customer_receipts' AND INDEX_NAME = 'idx_receipt_loc_status_date'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE cash_transactions ADD KEY idx_cash_reference(reference_type,reference_id)",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cash_transactions' AND INDEX_NAME = 'idx_cash_reference'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE purchases ADD KEY idx_purchase_loc_status_date(location_id,status,transaction_at)",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND INDEX_NAME = 'idx_purchase_loc_status_date'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE supplier_payments ADD KEY idx_supplier_payment_loc_supplier(location_id,supplier_id,status)",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supplier_payments' AND INDEX_NAME = 'idx_supplier_payment_loc_supplier'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = (
  SELECT IF(COUNT(*) = 0,
    "ALTER TABLE expenses ADD KEY idx_expense_loc_date(location_id,expense_at)",
    "SELECT 1")
  FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'expenses' AND INDEX_NAME = 'idx_expense_loc_date'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
