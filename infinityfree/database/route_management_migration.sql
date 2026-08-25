-- ترقية إدارة المدن والمسارات: تُنفذ مرة واحدة على قواعد البيانات القائمة.
CREATE TABLE IF NOT EXISTS route_subroutes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  origin_city_id BIGINT UNSIGNED NOT NULL,
  destination_city_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_subroutes_company_status (company_id, status),
  INDEX idx_subroutes_cities (origin_city_id, destination_city_id),
  CONSTRAINT fk_subroutes_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_subroutes_origin_city FOREIGN KEY (origin_city_id) REFERENCES cities(id),
  CONSTRAINT fk_subroutes_destination_city FOREIGN KEY (destination_city_id) REFERENCES cities(id),
  CONSTRAINT fk_subroutes_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_subroutes_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_subroute_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_id BIGINT UNSIGNED NOT NULL,
  subroute_id BIGINT UNSIGNED NOT NULL,
  route_segment_id BIGINT UNSIGNED NOT NULL,
  stop_order SMALLINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_route_subroute (route_id, subroute_id),
  UNIQUE KEY uq_route_subroute_order (route_id, stop_order),
  CONSTRAINT fk_route_subroute_links_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE,
  CONSTRAINT fk_route_subroute_links_subroute FOREIGN KEY (subroute_id) REFERENCES route_subroutes(id),
  CONSTRAINT fk_route_subroute_links_segment FOREIGN KEY (route_segment_id) REFERENCES route_segments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
