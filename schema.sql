CREATE DATABASE IF NOT EXISTS malinalli_pairing
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE malinalli_pairing;

CREATE TABLE IF NOT EXISTS pairing_requests (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(160) NOT NULL,
  country_code VARCHAR(8) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  email VARCHAR(254) NOT NULL,
  platform ENUM('telegram', 'whatsapp') NOT NULL,
  runtime ENUM('hermes', 'openclaw') NOT NULL DEFAULT 'hermes',
  code_hash CHAR(64) NOT NULL,
  status ENUM('received', 'approved', 'failed') NOT NULL DEFAULT 'received',
  command_output TEXT NULL,
  client_ip VARBINARY(16) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  INDEX idx_code_hash (code_hash),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB;
