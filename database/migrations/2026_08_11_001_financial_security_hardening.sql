-- Financial security hardening for the isolated/test database.
-- Apply only after backup and explicit approval in any non-test database.

INSERT INTO unified_permissions
    (permission_code, display_name, category, scope_type, allow_specific_target, is_active, created_at)
SELECT 'currency_exchange_post', 'ترحيل صرف العملات', 'finance', 'branch', 1, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM unified_permissions WHERE permission_code = 'currency_exchange_post');

INSERT INTO unified_permissions
    (permission_code, display_name, category, scope_type, allow_specific_target, is_active, created_at)
SELECT 'currency_exchange_unpost', 'إلغاء ترحيل صرف العملات', 'finance', 'branch', 1, 1, NOW()
WHERE NOT EXISTS (SELECT 1 FROM unified_permissions WHERE permission_code = 'currency_exchange_unpost');

INSERT INTO role_permissions_unified (role_id, permission_id, target_type, granted_at)
SELECT r.id, p.id, NULL, NOW()
FROM roles r
JOIN unified_permissions p ON p.permission_code IN ('currency_exchange_post', 'currency_exchange_unpost')
WHERE r.name IN ('admin', 'developer', 'accounts_manager')
  AND NOT EXISTS (
      SELECT 1 FROM role_permissions_unified rp
      WHERE rp.role_id = r.id AND rp.permission_id = p.id
  );

-- Enforce non-negative monetary values at the line level.
ALTER TABLE journal_lines
  ADD CONSTRAINT chk_journal_line_nonnegative CHECK (debit >= 0 AND credit >= 0);

-- A posted transaction must have balanced journal lines. Draft transactions may
-- be assembled in multiple statements and are validated when posted.
DROP TRIGGER IF EXISTS trg_financial_transaction_validate_post_insert;
DROP TRIGGER IF EXISTS trg_financial_transaction_validate_post_update;

DELIMITER $$
CREATE TRIGGER trg_financial_transaction_validate_post_insert
BEFORE INSERT ON financial_transactions
FOR EACH ROW
BEGIN
    IF NEW.status IN ('posted', 'reversed', 'reconciled') THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Financial transaction must be created as draft before journal validation';
    END IF;
END$$

CREATE TRIGGER trg_financial_transaction_validate_post_update
BEFORE UPDATE ON financial_transactions
FOR EACH ROW
BEGIN
    IF NEW.status IN ('posted', 'reversed', 'reconciled')
       AND OLD.status NOT IN ('posted', 'reversed', 'reconciled') THEN
        CALL sp_validate_journal_balance(NEW.id);
    END IF;
END$$
DELIMITER ;
