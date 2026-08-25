-- منصة حجوزات الباصات والرحلات
-- الاستيراد: اختر قاعدة بيانات UTF8MB4 من لوحة الاستضافة ثم استورد هذا الملف.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(180) NOT NULL,
  username VARCHAR(80) NULL UNIQUE,
  email VARCHAR(190) NOT NULL UNIQUE,
  phone VARCHAR(32) NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  status ENUM('active','suspended','pending') NOT NULL DEFAULT 'active',
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  name_ar VARCHAR(120) NOT NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(80) NOT NULL UNIQUE,
  name_ar VARCHAR(160) NOT NULL,
  module_code VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_roles (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  company_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_roles_scope (user_id, role_id, company_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
  role_id BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS countries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code CHAR(2) NOT NULL UNIQUE,
  name_ar VARCHAR(120) NOT NULL,
  phone_code VARCHAR(10) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS currencies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code CHAR(3) NOT NULL UNIQUE,
  name_ar VARCHAR(120) NOT NULL,
  symbol_ar VARCHAR(16) NOT NULL,
  decimal_places TINYINT UNSIGNED NOT NULL DEFAULT 2,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS exchange_rates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  base_currency_id BIGINT UNSIGNED NOT NULL,
  quote_currency_id BIGINT UNSIGNED NOT NULL,
  rate DECIMAL(18,8) NOT NULL,
  effective_at DATETIME NOT NULL,
  expires_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_exchange_pair_date (base_currency_id, quote_currency_id, effective_at),
  CONSTRAINT fk_exchange_base_currency FOREIGN KEY (base_currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_exchange_quote_currency FOREIGN KEY (quote_currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_exchange_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS companies (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  legal_name VARCHAR(180) NOT NULL,
  trade_name VARCHAR(180) NOT NULL,
  logo_path VARCHAR(500) NULL,
  cover_image_path VARCHAR(500) NULL,
  country_id BIGINT UNSIGNED NOT NULL,
  city_id BIGINT UNSIGNED NULL,
  address VARCHAR(500) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  phone VARCHAR(32) NULL,
  email VARCHAR(190) NULL,
  base_currency_id BIGINT UNSIGNED NOT NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_companies_country_status (country_id, status),
  CONSTRAINT fk_companies_country FOREIGN KEY (country_id) REFERENCES countries(id),
  CONSTRAINT fk_companies_currency FOREIGN KEY (base_currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_images (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  image_path VARCHAR(500) NOT NULL,
  image_order TINYINT UNSIGNED NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_company_image_order (company_id, image_order),
  INDEX idx_company_images_status_order (company_id, status, image_order),
  CONSTRAINT fk_company_images_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  employee_code VARCHAR(64) NULL,
  status ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_company_user (company_id, user_id),
  CONSTRAINT fk_company_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_company_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE user_roles ADD CONSTRAINT fk_user_roles_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS cities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  country_id BIGINT UNSIGNED NOT NULL,
  name_ar VARCHAR(140) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cities_country_name (country_id, name_ar),
  CONSTRAINT fk_cities_country FOREIGN KEY (country_id) REFERENCES countries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE companies ADD CONSTRAINT fk_companies_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS stations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  city_id BIGINT UNSIGNED NOT NULL,
  name_ar VARCHAR(180) NOT NULL,
  address VARCHAR(400) NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stations_city_name (city_id, name_ar),
  CONSTRAINT fk_stations_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS routes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  name_ar VARCHAR(220) NOT NULL,
  route_type ENUM('normal','tourist') NOT NULL DEFAULT 'normal',
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_routes_company_code (company_id, code),
  CONSTRAINT fk_routes_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_routes_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_stops (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_id BIGINT UNSIGNED NOT NULL,
  station_id BIGINT UNSIGNED NOT NULL,
  stop_order SMALLINT UNSIGNED NOT NULL,
  arrival_offset_minutes INT NULL,
  departure_offset_minutes INT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_route_stop_order (route_id, stop_order),
  UNIQUE KEY uq_route_station (route_id, station_id),
  CONSTRAINT fk_route_stops_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE,
  CONSTRAINT fk_route_stops_station FOREIGN KEY (station_id) REFERENCES stations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_segments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_id BIGINT UNSIGNED NOT NULL,
  origin_stop_id BIGINT UNSIGNED NOT NULL,
  destination_stop_id BIGINT UNSIGNED NOT NULL,
  origin_order SMALLINT UNSIGNED NOT NULL,
  destination_order SMALLINT UNSIGNED NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_route_segment (route_id, origin_stop_id, destination_stop_id),
  INDEX idx_segment_search (route_id, origin_order, destination_order),
  CONSTRAINT fk_segments_route FOREIGN KEY (route_id) REFERENCES routes(id) ON DELETE CASCADE,
  CONSTRAINT fk_segments_origin FOREIGN KEY (origin_stop_id) REFERENCES route_stops(id) ON DELETE CASCADE,
  CONSTRAINT fk_segments_destination FOREIGN KEY (destination_stop_id) REFERENCES route_stops(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS segment_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  route_segment_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_segment_prices_lookup (company_id, route_segment_id, currency_id, starts_at, ends_at),
  CONSTRAINT fk_segment_prices_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_segment_prices_segment FOREIGN KEY (route_segment_id) REFERENCES route_segments(id) ON DELETE CASCADE,
  CONSTRAINT fk_segment_prices_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_segment_prices_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_subroutes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NULL,
  origin_city_id BIGINT UNSIGNED NOT NULL,
  destination_city_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  company_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  amount DECIMAL(14,2) NOT NULL,
  origin_arrival_time TIME NULL,
  origin_departure_time TIME NULL,
  destination_arrival_time TIME NULL,
  destination_departure_time TIME NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_subroutes_company_status (company_id, status),
  INDEX idx_subroutes_cities (origin_city_id, destination_city_id),
  CONSTRAINT fk_subroutes_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
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

CREATE TABLE IF NOT EXISTS buses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  name_ar VARCHAR(160) NOT NULL,
  bus_number VARCHAR(64) NOT NULL,
  plate_number VARCHAR(64) NOT NULL,
  bus_type VARCHAR(100) NULL,
  interior_image_path VARCHAR(500) NULL,
  exterior_image_path VARCHAR(500) NULL,
  model_year SMALLINT UNSIGNED NULL,
  seat_count SMALLINT UNSIGNED NOT NULL,
  status ENUM('active','maintenance','inactive') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_buses_company_number (company_id, bus_number),
  UNIQUE KEY uq_buses_company_plate (company_id, plate_number),
  CONSTRAINT fk_buses_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bus_seats (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bus_id BIGINT UNSIGNED NOT NULL,
  seat_code VARCHAR(16) NOT NULL,
  seat_row SMALLINT UNSIGNED NOT NULL,
  column_code VARCHAR(8) NOT NULL,
  seat_type ENUM('regular','vip','female_only','disabled') NOT NULL DEFAULT 'regular',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bus_seat_code (bus_id, seat_code),
  CONSTRAINT fk_bus_seats_bus FOREIGN KEY (bus_id) REFERENCES buses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trips (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  route_id BIGINT UNSIGNED NOT NULL,
  route_subroute_id BIGINT UNSIGNED NULL,
  bus_id BIGINT UNSIGNED NOT NULL,
  trip_number VARCHAR(64) NOT NULL,
  recurrence_group VARCHAR(64) NULL,
  recurrence_index SMALLINT UNSIGNED NULL,
  departure_at DATETIME NOT NULL,
  arrival_at DATETIME NOT NULL,
  booking_open_at DATETIME NULL,
  booking_close_at DATETIME NULL,
  status ENUM('scheduled','open','completed','cancelled','expired') NOT NULL DEFAULT 'scheduled',
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_trips_company_number_date (company_id, trip_number, departure_at),
  INDEX idx_trips_search (company_id, route_id, status, departure_at),
  INDEX idx_trips_route_subroute (route_subroute_id),
  INDEX idx_trips_recurrence_group (recurrence_group, departure_at),
  CONSTRAINT fk_trips_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_trips_route FOREIGN KEY (route_id) REFERENCES routes(id),
  CONSTRAINT fk_trips_subroute FOREIGN KEY (route_subroute_id) REFERENCES route_subroutes(id) ON DELETE SET NULL,
  CONSTRAINT fk_trips_bus FOREIGN KEY (bus_id) REFERENCES buses(id),
  CONSTRAINT fk_trips_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_segment_prices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id BIGINT UNSIGNED NOT NULL,
  route_segment_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  company_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  amount DECIMAL(14,2) NOT NULL,
  source_price_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_trip_segment_price (trip_id, route_segment_id, currency_id),
  CONSTRAINT fk_trip_segment_prices_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_segment_prices_segment FOREIGN KEY (route_segment_id) REFERENCES route_segments(id),
  CONSTRAINT fk_trip_segment_prices_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_trip_segment_prices_source FOREIGN KEY (source_price_id) REFERENCES segment_prices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS trip_seat_inventory (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trip_id BIGINT UNSIGNED NOT NULL,
  bus_seat_id BIGINT UNSIGNED NOT NULL,
  is_available TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_trip_inventory_seat (trip_id, bus_seat_id),
  CONSTRAINT fk_trip_inventory_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
  CONSTRAINT fk_trip_inventory_seat FOREIGN KEY (bus_seat_id) REFERENCES bus_seats(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  country_id BIGINT UNSIGNED NOT NULL,
  city_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_customers_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_customers_country FOREIGN KEY (country_id) REFERENCES countries(id),
  CONSTRAINT fk_customers_city FOREIGN KEY (city_id) REFERENCES cities(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS passengers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id BIGINT UNSIGNED NULL,
  full_name_ar VARCHAR(220) NOT NULL,
  phone_country_code VARCHAR(10) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  passport_number VARCHAR(64) NOT NULL,
  birth_date DATE NOT NULL,
  birth_place VARCHAR(180) NOT NULL,
  passport_issue_date DATE NOT NULL,
  passport_issue_place VARCHAR(180) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_passengers_passport (passport_number),
  CONSTRAINT fk_passengers_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agents (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL UNIQUE,
  country_id BIGINT UNSIGNED NOT NULL,
  latitude DECIMAL(10,7) NULL,
  longitude DECIMAL(10,7) NULL,
  commission_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  commission_value DECIMAL(12,4) NOT NULL DEFAULT 0,
  status ENUM('active','financially_blocked','suspended') NOT NULL DEFAULT 'active',
  credit_enabled TINYINT(1) NOT NULL DEFAULT 0,
  block_at_minimum_balance TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_agents_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_agents_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_agents_country FOREIGN KEY (country_id) REFERENCES countries(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_wallets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit_limit DECIMAL(14,2) NOT NULL DEFAULT 0,
  used_debt DECIMAL(14,2) NOT NULL DEFAULT 0,
  minimum_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agent_wallet_currency (agent_id, currency_id),
  CONSTRAINT fk_agent_wallet_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_wallet_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_number VARCHAR(32) NOT NULL UNIQUE,
  company_id BIGINT UNSIGNED NOT NULL,
  trip_id BIGINT UNSIGNED NOT NULL,
  customer_id BIGINT UNSIGNED NULL,
  agent_id BIGINT UNSIGNED NULL,
  created_by_user_id BIGINT UNSIGNED NOT NULL,
  source ENUM('website','admin','agent','android','iphone','api') NOT NULL DEFAULT 'website',
  currency_id BIGINT UNSIGNED NOT NULL,
  subtotal_amount DECIMAL(14,2) NOT NULL,
  discount_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  agent_commission_type ENUM('percentage','fixed') NULL,
  agent_commission_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
  company_cost_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  company_payable_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  platform_commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(14,2) NOT NULL,
  exchange_rate_used DECIMAL(18,8) NULL,
  status ENUM('pending','confirmed','cancelled','rejected','completed','expired') NOT NULL DEFAULT 'pending',
  payment_status ENUM('unpaid','pending','paid','refunded') NOT NULL DEFAULT 'unpaid',
  held_until DATETIME NOT NULL,
  confirmed_at DATETIME NULL,
  rejected_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  cancellation_reason VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_bookings_company_status (company_id, status, created_at),
  INDEX idx_bookings_customer (customer_id, created_at),
  INDEX idx_bookings_agent (agent_id, created_at),
  INDEX idx_bookings_trip_status (trip_id, status, held_until),
  CONSTRAINT fk_bookings_company FOREIGN KEY (company_id) REFERENCES companies(id),
  CONSTRAINT fk_bookings_trip FOREIGN KEY (trip_id) REFERENCES trips(id),
  CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL,
  CONSTRAINT fk_bookings_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id),
  CONSTRAINT fk_bookings_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_passengers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  passenger_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_booking_passenger (booking_id, passenger_id),
  CONSTRAINT fk_booking_passengers_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_passengers_passenger FOREIGN KEY (passenger_id) REFERENCES passengers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_segments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  route_segment_id BIGINT UNSIGNED NOT NULL,
  origin_stop_order SMALLINT UNSIGNED NOT NULL,
  destination_stop_order SMALLINT UNSIGNED NOT NULL,
  origin_name_ar VARCHAR(180) NOT NULL,
  destination_name_ar VARCHAR(180) NOT NULL,
  company_unit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  unit_amount DECIMAL(14,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_booking_segment (booking_id, route_segment_id),
  INDEX idx_booking_segments_overlap (route_segment_id, origin_stop_order, destination_stop_order),
  CONSTRAINT fk_booking_segments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_segments_route_segment FOREIGN KEY (route_segment_id) REFERENCES route_segments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_seats (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  booking_passenger_id BIGINT UNSIGNED NOT NULL,
  bus_seat_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_booking_seat_passenger (booking_id, booking_passenger_id),
  INDEX idx_booking_seats_conflict (bus_seat_id, booking_id),
  CONSTRAINT fk_booking_seats_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_seats_passenger FOREIGN KEY (booking_passenger_id) REFERENCES booking_passengers(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_seats_bus_seat FOREIGN KEY (bus_seat_id) REFERENCES bus_seats(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tickets (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_number VARCHAR(32) NOT NULL UNIQUE,
  booking_id BIGINT UNSIGNED NOT NULL,
  booking_passenger_id BIGINT UNSIGNED NOT NULL,
  booking_seat_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  status ENUM('active','used','cancelled','void') NOT NULL DEFAULT 'active',
  qr_token CHAR(64) NOT NULL UNIQUE,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tickets_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_tickets_passenger FOREIGN KEY (booking_passenger_id) REFERENCES booking_passengers(id),
  CONSTRAINT fk_tickets_seat FOREIGN KEY (booking_seat_id) REFERENCES booking_seats(id),
  CONSTRAINT fk_tickets_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_wallet_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_wallet_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NULL,
  transaction_type ENUM('top_up','deduction','booking','credit_usage','debt_payment','refund','settlement','commission','admin_adjustment') NOT NULL,
  debit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  balance_before DECIMAL(14,2) NOT NULL,
  balance_after DECIMAL(14,2) NOT NULL,
  debt_before DECIMAL(14,2) NOT NULL,
  debt_after DECIMAL(14,2) NOT NULL,
  performed_by_user_id BIGINT UNSIGNED NULL,
  reason VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_wallet_transactions_wallet_date (agent_wallet_id, created_at),
  CONSTRAINT fk_wallet_transactions_wallet FOREIGN KEY (agent_wallet_id) REFERENCES agent_wallets(id),
  CONSTRAINT fk_wallet_transactions_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_wallet_transactions_user FOREIGN KEY (performed_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS agent_commissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  agent_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  commission_type ENUM('percentage','fixed') NOT NULL,
  rate_value DECIMAL(12,4) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  status ENUM('pending','payable','paid','cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_agent_commission_booking (agent_id, booking_id),
  CONSTRAINT fk_agent_commissions_agent FOREIGN KEY (agent_id) REFERENCES agents(id),
  CONSTRAINT fk_agent_commissions_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_agent_commissions_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  account_code VARCHAR(64) NOT NULL,
  name_ar VARCHAR(180) NOT NULL,
  account_type ENUM('asset','liability','income','expense','equity') NOT NULL,
  current_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_accounts_company_code_currency (company_id, account_code, currency_id),
  CONSTRAINT fk_accounts_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_accounts_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_transactions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  account_id BIGINT UNSIGNED NOT NULL,
  booking_id BIGINT UNSIGNED NULL,
  transaction_type VARCHAR(64) NOT NULL,
  debit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  reference_type VARCHAR(64) NULL,
  reference_id BIGINT UNSIGNED NULL,
  note_ar VARCHAR(500) NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_account_transactions_account_date (account_id, created_at),
  CONSTRAINT fk_account_transactions_account FOREIGN KEY (account_id) REFERENCES accounts(id),
  CONSTRAINT fk_account_transactions_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE SET NULL,
  CONSTRAINT fk_account_transactions_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  payment_method ENUM('cash','bank_transfer','wallet','card','other') NOT NULL,
  status ENUM('pending','completed','failed','refunded') NOT NULL DEFAULT 'pending',
  reference_number VARCHAR(128) NULL,
  received_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_payments_receiver FOREIGN KEY (received_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS refunds (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  reason_ar VARCHAR(500) NOT NULL,
  approved_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_refunds_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_refunds_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  category_ar VARCHAR(160) NOT NULL,
  description_ar VARCHAR(500) NULL,
  expense_date DATE NOT NULL,
  recorded_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_expenses_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_expenses_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_expenses_user FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS company_settlements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_id BIGINT UNSIGNED NOT NULL,
  agent_id BIGINT UNSIGNED NULL,
  currency_id BIGINT UNSIGNED NOT NULL,
  total_sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_commissions DECIMAL(14,2) NOT NULL DEFAULT 0,
  total_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  outstanding_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  settled_at DATETIME NULL,
  status ENUM('draft','settled','cancelled') NOT NULL DEFAULT 'draft',
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_settlements_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
  CONSTRAINT fk_settlements_agent FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL,
  CONSTRAINT fk_settlements_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
  CONSTRAINT fk_settlements_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  company_id BIGINT UNSIGNED NULL,
  type VARCHAR(64) NOT NULL,
  title_ar VARCHAR(180) NOT NULL,
  body_ar VARCHAR(500) NOT NULL,
  reference_type VARCHAR(64) NULL,
  reference_id BIGINT UNSIGNED NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_notifications_user_read (user_id, read_at, created_at),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notifications_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  company_id BIGINT UNSIGNED NULL,
  action VARCHAR(100) NOT NULL,
  entity_type VARCHAR(100) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  old_values JSON NULL,
  new_values JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_company_date (company_id, created_at),
  INDEX idx_audit_entity (entity_type, entity_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_audit_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_name VARCHAR(120) NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  last_used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  device_identifier CHAR(64) NOT NULL,
  device_type VARCHAR(50) NULL,
  operating_system VARCHAR(100) NULL,
  browser VARCHAR(100) NULL,
  last_ip_address VARCHAR(45) NULL,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_device (user_id, device_identifier),
  CONSTRAINT fk_user_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  was_successful TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_email_ip_date (email, ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS contact_channels (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  type VARCHAR(40) NOT NULL,
  title_ar VARCHAR(160) NOT NULL,
  value VARCHAR(500) NOT NULL,
  description_ar VARCHAR(500) NULL,
  icon VARCHAR(80) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_by BIGINT UNSIGNED NULL,
  updated_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_contact_channels_status_order (status, sort_order, id),
  CONSTRAINT fk_contact_channels_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_contact_channels_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(40) NOT NULL,
  email VARCHAR(190) NOT NULL,
  subject VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  status ENUM('new','in_progress','resolved','spam') NOT NULL DEFAULT 'new',
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(500) NULL,
  read_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_contact_messages_status_created (status, created_at),
  INDEX idx_contact_messages_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
