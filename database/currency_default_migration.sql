SET @has_currency_default := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'currencies' AND COLUMN_NAME = 'is_default');
SET @sql_currency_default := IF(@has_currency_default = 0, 'ALTER TABLE currencies ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER decimal_places', 'SELECT 1');
PREPARE stmt_currency_default FROM @sql_currency_default;
EXECUTE stmt_currency_default;
DEALLOCATE PREPARE stmt_currency_default;
