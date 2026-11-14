-- ALDIUM SISTEM - struktur database MySQL (OPSIONAL)
-- Website membuat database & tabel ini sendiri saat pertama dibuka.
-- File ini hanya untuk Anda yang ingin mengimpornya manual lewat phpMyAdmin.
-- Akun admin dibuat otomatis oleh website saat pertama dibuka (lihat config.php).

CREATE DATABASE IF NOT EXISTS `aldium` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `aldium`;

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  must_change TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (k VARCHAR(64) PRIMARY KEY, v TEXT)
  ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS state (k VARCHAR(64) PRIMARY KEY, v TEXT)
  ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS readings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ts BIGINT NOT NULL,
  temp DOUBLE NULL, level DOUBLE NULL, ph DOUBLE NULL, turb DOUBLE NULL,
  KEY idx_readings_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feed_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ts BIGINT NOT NULL,
  source VARCHAR(100) NOT NULL,
  KEY idx_feed_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ip VARCHAR(64) NOT NULL,
  ts BIGINT NOT NULL,
  KEY idx_login_ip_ts (ip, ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO state (k, v) VALUES
  ('lamp','1'), ('pump','1'), ('autofeed','0'), ('feed_pending','0'), ('last_seen','0');
