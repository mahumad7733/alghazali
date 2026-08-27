CREATE TABLE IF NOT EXISTS trip_reviews (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  company_rating TINYINT UNSIGNED NULL,
  agent_rating TINYINT UNSIGNED NULL,
  recommendation TINYINT(1) NOT NULL DEFAULT 1,
  comment VARCHAR(1000) NULL,
  status ENUM('published','hidden') NOT NULL DEFAULT 'published',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_trip_reviews_booking (booking_id),
  INDEX idx_trip_reviews_trip_status (trip_id, status),
  CONSTRAINT fk_trip_reviews_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_reviews_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_reviews_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ترحيل آمن لقاعدة قائمة: لا يكرر الأعمدة عند تشغيل الملف مرة أخرى.
SET @has_company_rating := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_reviews' AND COLUMN_NAME = 'company_rating');
SET @sql_company_rating := IF(@has_company_rating = 0, 'ALTER TABLE trip_reviews ADD COLUMN company_rating TINYINT UNSIGNED NULL AFTER rating', 'SELECT 1');
PREPARE stmt_company_rating FROM @sql_company_rating;
EXECUTE stmt_company_rating;
DEALLOCATE PREPARE stmt_company_rating;

SET @has_agent_rating := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trip_reviews' AND COLUMN_NAME = 'agent_rating');
SET @sql_agent_rating := IF(@has_agent_rating = 0, 'ALTER TABLE trip_reviews ADD COLUMN agent_rating TINYINT UNSIGNED NULL AFTER company_rating', 'SELECT 1');
PREPARE stmt_agent_rating FROM @sql_agent_rating;
EXECUTE stmt_agent_rating;
DEALLOCATE PREPARE stmt_agent_rating;
