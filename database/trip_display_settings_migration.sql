SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS trip_display_settings (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
  show_company_cost TINYINT(1) NOT NULL DEFAULT 0,
  show_available_seats TINYINT(1) NOT NULL DEFAULT 1,
  show_bookings_button TINYINT(1) NOT NULL DEFAULT 1,
  show_agent_commission TINYINT(1) NOT NULL DEFAULT 1,
  updated_by BIGINT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_trip_display_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @has_price_change_badge = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_display_settings' AND COLUMN_NAME = 'show_price_change_badge');
SET @add_price_change_badge = IF(@has_price_change_badge = 0, 'ALTER TABLE trip_display_settings ADD COLUMN show_price_change_badge TINYINT(1) NOT NULL DEFAULT 0 AFTER show_agent_commission', 'SELECT 1');
PREPARE stmt_price_change_badge FROM @add_price_change_badge;
EXECUTE stmt_price_change_badge;
DEALLOCATE PREPARE stmt_price_change_badge;

INSERT IGNORE INTO trip_display_settings (id, show_company_cost, show_available_seats, show_bookings_button, show_agent_commission, show_price_change_badge)
VALUES (1, 0, 1, 1, 1, 0);
