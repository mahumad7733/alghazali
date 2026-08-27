-- Rihla: ensure the customer role can register and create website bookings.
-- Idempotent migration; does not contain credentials or customer data.

INSERT INTO roles (code, name_ar, is_system)
VALUES ('customer', 'عميل', 0)
ON DUPLICATE KEY UPDATE name_ar = VALUES(name_ar);

INSERT INTO permissions (code, name_ar, module_code)
VALUES ('create_booking', 'إنشاء حجز', 'bookings')
ON DUPLICATE KEY UPDATE
    name_ar = VALUES(name_ar),
    module_code = VALUES(module_code);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.code = 'customer'
  AND p.code = 'create_booking';
