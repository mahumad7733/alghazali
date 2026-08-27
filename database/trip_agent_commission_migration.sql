-- إضافة إعداد عمولة الوكيل لكل رحلة.
-- نفّذ هذا الترحيل مرة واحدة على قاعدة بيانات موجودة.
SET @has_trip_agent_commission_type := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trips' AND COLUMN_NAME = 'agent_commission_type');
SET @sql := IF(@has_trip_agent_commission_type = 0, "ALTER TABLE trips ADD COLUMN agent_commission_type ENUM('percentage','fixed') NULL AFTER bus_type", 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_trip_agent_commission_value := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'trips' AND COLUMN_NAME = 'agent_commission_value');
SET @sql := IF(@has_trip_agent_commission_value = 0, 'ALTER TABLE trips ADD COLUMN agent_commission_value DECIMAL(12,4) NULL AFTER agent_commission_type', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
