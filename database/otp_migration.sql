-- Rihla OTP migration: idempotent, additive only.
-- لا يحذف أي بيانات قائمة. نفّذ مرة واحدة أو اعتمد التهيئة التلقائية داخل OtpService.

CREATE TABLE IF NOT EXISTS otp_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  code_length TINYINT UNSIGNED NOT NULL DEFAULT 6,
  ttl_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  resend_after_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  max_sends_per_hour TINYINT UNSIGNED NOT NULL DEFAULT 5,
  max_sends_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 0,
  sms_enabled TINYINT(1) NOT NULL DEFAULT 0,
  email_enabled TINYINT(1) NOT NULL DEFAULT 1,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_otp_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO otp_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS otp_provider_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  whatsapp_provider VARCHAR(80) NULL,
  whatsapp_api_url VARCHAR(500) NULL,
  whatsapp_api_token TEXT NULL,
  whatsapp_phone_number_id VARCHAR(180) NULL,
  whatsapp_template_name VARCHAR(180) NULL,
  whatsapp_language VARCHAR(40) NOT NULL DEFAULT 'ar',
  sms_provider VARCHAR(80) NULL,
  sms_api_url VARCHAR(500) NULL,
  sms_api_key TEXT NULL,
  sms_sender_id VARCHAR(120) NULL,
  smtp_host VARCHAR(255) NULL,
  smtp_port SMALLINT UNSIGNED NOT NULL DEFAULT 587,
  smtp_username VARCHAR(255) NULL,
  smtp_password TEXT NULL,
  smtp_encryption ENUM('none','tls','ssl') NOT NULL DEFAULT 'tls',
  from_email VARCHAR(190) NULL,
  from_name VARCHAR(180) NULL,
  updated_by_user_id BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_otp_provider_settings_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO otp_provider_settings (id) VALUES (1);

CREATE TABLE IF NOT EXISTS otp_challenges (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  challenge_id CHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  purpose ENUM('registration','login','phone_change','email_change','test') NOT NULL,
  channel ENUM('whatsapp','sms','email') NOT NULL,
  destination VARCHAR(190) NOT NULL,
  destination_hash CHAR(64) NOT NULL,
  code_hash CHAR(64) NOT NULL,
  status ENUM('sent','verified','expired','failed','locked') NOT NULL DEFAULT 'sent',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  send_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  last_sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  verified_at DATETIME NULL,
  failure_reason VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_otp_challenge_id (challenge_id),
  KEY idx_otp_destination_created (destination_hash, created_at),
  KEY idx_otp_ip_created (ip_address, created_at),
  KEY idx_otp_status_expires (status, expires_at),
  KEY idx_otp_user_created (user_id, created_at),
  CONSTRAINT fk_otp_challenges_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS otp_registration_payloads (
  challenge_id CHAR(64) NOT NULL PRIMARY KEY,
  full_name VARCHAR(180) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  country_id BIGINT UNSIGNED NOT NULL,
  city_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_otp_registration_challenge FOREIGN KEY (challenge_id) REFERENCES otp_challenges(challenge_id) ON DELETE CASCADE,
  CONSTRAINT fk_otp_registration_country FOREIGN KEY (country_id) REFERENCES countries(id),
  CONSTRAINT fk_otp_registration_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
