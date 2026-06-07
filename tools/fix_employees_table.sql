-- Fix employees table by adding missing attendance_location_id column
ALTER TABLE employees ADD COLUMN attendance_location_id INT DEFAULT NULL AFTER shift_id;

-- Verify the change
SHOW COLUMNS FROM employees;
