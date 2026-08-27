CREATE TABLE IF NOT EXISTS trip_price_change_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  trip_id BIGINT UNSIGNED NOT NULL,
  route_segment_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  previous_amount DECIMAL(14,2) NOT NULL,
  current_amount DECIMAL(14,2) NOT NULL,
  change_type ENUM('increase','decrease') NOT NULL,
  changed_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_trip_price_change_trip_segment (trip_id, route_segment_id, created_at),
  CONSTRAINT fk_trip_price_change_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_price_change_segment FOREIGN KEY (route_segment_id) REFERENCES route_segments(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_price_change_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_trip_price_change_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
