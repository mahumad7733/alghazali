INSERT INTO unified_permissions
    (permission_code, display_name, category, scope_type, allow_specific_target, is_active, created_at)
SELECT 'currency_exchange_edit', 'تعديل تحويل عملة', 'finance', 'branch', 1, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM unified_permissions WHERE permission_code = 'currency_exchange_edit');

INSERT INTO unified_permissions
    (permission_code, display_name, category, scope_type, allow_specific_target, is_active, created_at)
SELECT 'currency_exchange_delete', 'حذف تحويل عملة', 'finance', 'branch', 1, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM unified_permissions WHERE permission_code = 'currency_exchange_delete');

INSERT INTO role_permissions_unified (role_id, permission_id, target_type, granted_at)
SELECT r.id, p.id, NULL, NOW()
FROM roles r
JOIN unified_permissions p ON p.permission_code IN ('currency_exchange_edit', 'currency_exchange_delete')
WHERE r.name IN ('admin', 'developer', 'accounts_manager')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions_unified rp
      WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );
