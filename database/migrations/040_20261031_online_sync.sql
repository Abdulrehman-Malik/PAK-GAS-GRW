-- Perfect LPG - Online Database Sync
-- online_sync_settings holds the online MySQL connection (password encrypted) and online_sync_log the history of each sync.
-- Both stay on this computer: the sync never copies their rows online.
-- Idempotent: safe to run more than once.
-- Note for editors: the migration runner splits on semicolons and tracks quotes, so comments must not contain either.

CREATE TABLE IF NOT EXISTS online_sync_settings(
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  host VARCHAR(255) NOT NULL DEFAULT '',
  port INT UNSIGNED NOT NULL DEFAULT 3306,
  database_name VARCHAR(64) NOT NULL DEFAULT '',
  username VARCHAR(128) NOT NULL DEFAULT '',
  password_encrypted TEXT NULL,
  use_tls TINYINT(1) NOT NULL DEFAULT 1,
  instance_id CHAR(32) NOT NULL DEFAULT '',
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NULL
)ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS online_sync_log(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status ENUM('running','success','failed') NOT NULL DEFAULT 'running',
  trigger_type ENUM('schedule','manual') NOT NULL,
  tables_copied INT UNSIGNED NOT NULL DEFAULT 0,
  rows_copied BIGINT UNSIGNED NOT NULL DEFAULT 0,
  migrations_run INT UNSIGNED NOT NULL DEFAULT 0,
  message TEXT NULL,
  created_by BIGINT UNSIGNED NULL,
  KEY idx_online_sync_log_started(started_at)
)ENGINE=InnoDB;
