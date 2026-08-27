-- Add passenger gender for the customer booking form.
SET @has_gender := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'passengers' AND COLUMN_NAME = 'gender'
);
SET @sql := IF(@has_gender = 0,
  'ALTER TABLE passengers ADD COLUMN gender ENUM(''male'',''female'') NULL AFTER full_name_ar',
  'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
