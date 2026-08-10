-- Customer profile and service history migration.
-- Apply only to an approved backup/staging target; this file is not executed by the application.

CREATE TABLE IF NOT EXISTS customer_service_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    passport_id INT NOT NULL,
    service_type VARCHAR(100) NOT NULL,
    service_id INT NOT NULL,
    service_number VARCHAR(100) NULL,
    service_date DATE NULL,
    amount DECIMAL(12,2) NULL,
    currency_id INT NULL,
    status VARCHAR(50) NULL,
    branch_id INT NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_customer_service (passport_id, service_type, service_id),
    KEY idx_customer_history_passport_date (passport_id, service_date),
    KEY idx_customer_history_service (service_type, service_id),
    KEY idx_customer_history_currency (currency_id),
    CONSTRAINT fk_customer_history_passport FOREIGN KEY (passport_id) REFERENCES passports (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$
CREATE PROCEDURE add_customer_profile_column_if_missing(
    IN p_table_name VARCHAR(64),
    IN p_column_definition VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table_name
          AND COLUMN_NAME = SUBSTRING_INDEX(p_column_definition, ' ', 1)
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table_name, '` ADD COLUMN ', p_column_definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL add_customer_profile_column_if_missing('passports', 'id_type VARCHAR(50) NULL');
CALL add_customer_profile_column_if_missing('passports', 'id_number VARCHAR(100) NULL');
CALL add_customer_profile_column_if_missing('passports', 'id_issue_place VARCHAR(150) NULL');
CALL add_customer_profile_column_if_missing('passports', 'id_issue_date DATE NULL');
CALL add_customer_profile_column_if_missing('passports', 'mobile_number VARCHAR(50) NULL');
CALL add_customer_profile_column_if_missing('bus_flight_bookings', 'passport_id INT NULL');
CALL add_customer_profile_column_if_missing('passport_transactions', 'passport_id INT NULL');
CALL add_customer_profile_column_if_missing('passport_transactions', 'passport_expiry_date DATE NULL');
CALL add_customer_profile_column_if_missing('family_visit_requests', 'passport_id INT NULL');
CALL add_customer_profile_column_if_missing('postal_shipments', 'passport_id INT NULL');

DROP PROCEDURE add_customer_profile_column_if_missing;

ALTER TABLE passports MODIFY passport_number VARCHAR(100) NULL;

DELIMITER $$
CREATE PROCEDURE add_customer_profile_index_if_missing(
    IN p_table_name VARCHAR(64), IN p_index_name VARCHAR(64), IN p_columns VARCHAR(255)
)
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table_name)
       AND NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = p_table_name AND INDEX_NAME = p_index_name) THEN
        SET @sql = CONCAT('CREATE INDEX `', p_index_name, '` ON `', p_table_name, '` (', p_columns, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$

CREATE PROCEDURE add_customer_profile_fk_if_missing(
    IN p_table_name VARCHAR(64), IN p_constraint_name VARCHAR(64), IN p_column_name VARCHAR(64)
)
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = p_table_name AND COLUMN_NAME = p_column_name)
       AND NOT EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()
                       AND TABLE_NAME = p_table_name AND CONSTRAINT_NAME = p_constraint_name) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table_name, '` ADD CONSTRAINT `', p_constraint_name,
                          '` FOREIGN KEY (`', p_column_name, '`) REFERENCES `passports` (`id`)');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

CALL add_customer_profile_index_if_missing('passports', 'idx_passports_full_name', '`full_name`');
CALL add_customer_profile_index_if_missing('passports', 'idx_passports_passport_number', '`passport_number`');
CALL add_customer_profile_index_if_missing('passports', 'idx_passports_phone_number', '`phone_number`');
CALL add_customer_profile_index_if_missing('passports', 'idx_passports_mobile_number', '`mobile_number`');
CALL add_customer_profile_index_if_missing('passports', 'idx_passports_id_number', '`id_number`');
CALL add_customer_profile_index_if_missing('bus_flight_bookings', 'idx_bus_flight_passport', '`passport_id`');
CALL add_customer_profile_index_if_missing('passport_transactions', 'idx_passport_transactions_passport', '`passport_id`');
CALL add_customer_profile_index_if_missing('family_visit_requests', 'idx_family_visit_passport', '`passport_id`');
CALL add_customer_profile_index_if_missing('postal_shipments', 'idx_postal_shipments_passport', '`passport_id`');
CALL add_customer_profile_fk_if_missing('bus_flight_bookings', 'fk_bus_flight_passport', 'passport_id');
CALL add_customer_profile_fk_if_missing('passport_transactions', 'fk_passport_transaction_profile', 'passport_id');
CALL add_customer_profile_fk_if_missing('family_visit_requests', 'fk_family_visit_profile', 'passport_id');
CALL add_customer_profile_fk_if_missing('postal_shipments', 'fk_postal_shipment_profile', 'passport_id');

DROP PROCEDURE add_customer_profile_index_if_missing;
DROP PROCEDURE add_customer_profile_fk_if_missing;
