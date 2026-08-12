-- Test database only: branch-aware balance rebuilding and database-level audit coverage.
DROP PROCEDURE IF EXISTS sp_rebuild_balances;
DROP TRIGGER IF EXISTS trg_financial_transaction_audit_insert;
DROP TRIGGER IF EXISTS trg_financial_transaction_audit_update;

DELIMITER $$
CREATE PROCEDURE sp_rebuild_balances()
SQL SECURITY INVOKER
MODIFIES SQL DATA
BEGIN
    DROP TEMPORARY TABLE IF EXISTS tmp_balance_movements;
    CREATE TEMPORARY TABLE tmp_balance_movements (
        balance_id INT NOT NULL PRIMARY KEY,
        movement DECIMAL(18,2) NOT NULL DEFAULT 0,
        movement_base DECIMAL(18,2) NOT NULL DEFAULT 0
    );

    INSERT INTO tmp_balance_movements (balance_id, movement, movement_base)
    SELECT
        abu.id,
        COALESCE(SUM(CASE WHEN ft.status IN ('posted','reversed') THEN COALESCE(jl.debit,0)-COALESCE(jl.credit,0) ELSE 0 END), 0),
        COALESCE(SUM(CASE WHEN ft.status IN ('posted','reversed') THEN
            (COALESCE(jl.debit,0)-COALESCE(jl.credit,0)) * COALESCE(c.exchange_rate,1)
            ELSE 0 END), 0)
    FROM account_balances_unified abu
    LEFT JOIN journal_lines jl
      ON jl.account_id = abu.account_id
     AND jl.currency_id = abu.currency_id
     AND (abu.branch_id IS NULL OR jl.branch_id = abu.branch_id)
    LEFT JOIN financial_transactions ft ON ft.id = jl.financial_transaction_id
    LEFT JOIN currencies c ON c.id = jl.currency_id
    GROUP BY abu.id;

    UPDATE account_balances_unified abu
    JOIN tmp_balance_movements m ON m.balance_id = abu.id
    LEFT JOIN currencies c ON c.id = abu.currency_id
       SET abu.current_balance = COALESCE(abu.opening_balance,0) + m.movement,
           abu.current_balance_base = COALESCE(abu.opening_balance_base,0) + m.movement_base,
           abu.currency_code = COALESCE(c.currency_code, abu.currency_code),
           abu.last_updated = NOW();

    DROP TEMPORARY TABLE IF EXISTS tmp_balance_movements;
END$$

CREATE TRIGGER trg_financial_transaction_audit_insert
AFTER INSERT ON financial_transactions
FOR EACH ROW
BEGIN
    INSERT INTO audit_logs
        (user_id, action, entity_type, entity_id, table_name, record_id, old_values, new_values, request_method, details_json, severity)
    VALUES
        (COALESCE(NULLIF(NEW.created_by,0),1), 'financial_transaction_created', 'financial_transaction', NEW.id,
         'financial_transactions', NEW.id, NULL,
         JSON_OBJECT('status',NEW.status,'amount',NEW.amount,'currency_id',NEW.currency_id,'branch_id',NEW.branch_id),
         'DB', JSON_OBJECT('source','database_trigger'), 'info');
END$$

CREATE TRIGGER trg_financial_transaction_audit_update
AFTER UPDATE ON financial_transactions
FOR EACH ROW
BEGIN
    IF NOT (OLD.status <=> NEW.status)
       OR NOT (OLD.amount <=> NEW.amount)
       OR NOT (OLD.currency_id <=> NEW.currency_id)
       OR NOT (OLD.branch_id <=> NEW.branch_id)
       OR NOT (OLD.exchange_rate <=> NEW.exchange_rate) THEN
        INSERT INTO audit_logs
            (user_id, action, entity_type, entity_id, table_name, record_id, old_values, new_values, request_method, details_json, severity)
        VALUES
            (COALESCE(NULLIF(NEW.updated_by,0),NULLIF(NEW.posted_by,0),NULLIF(NEW.cancelled_by,0),NULLIF(NEW.created_by,0),1),
             'financial_transaction_changed', 'financial_transaction', NEW.id, 'financial_transactions', NEW.id,
             JSON_OBJECT('status',OLD.status,'amount',OLD.amount,'currency_id',OLD.currency_id,'branch_id',OLD.branch_id,'exchange_rate',OLD.exchange_rate),
             JSON_OBJECT('status',NEW.status,'amount',NEW.amount,'currency_id',NEW.currency_id,'branch_id',NEW.branch_id,'exchange_rate',NEW.exchange_rate),
             'DB', JSON_OBJECT('source','database_trigger'), 'info');
    END IF;
END$$
DELIMITER ;

-- Reconcile historical records explicitly as historical evidence, never as if it were original-time logging.
INSERT INTO audit_logs
    (user_id, action, entity_type, entity_id, table_name, record_id, old_values, new_values, request_method, details_json, severity)
SELECT COALESCE(NULLIF(ft.created_by,0),1), 'historical_reconciliation', 'financial_transaction', ft.id,
       'financial_transactions', ft.id, NULL,
       JSON_OBJECT('status',ft.status,'amount',ft.amount,'currency_id',ft.currency_id,'branch_id',ft.branch_id),
       'DB', JSON_OBJECT('source','phase2_reconciliation','original_event_time_unavailable',true), 'warning'
FROM financial_transactions ft
WHERE ft.status = 'posted'
  AND NOT EXISTS (SELECT 1 FROM audit_logs al WHERE al.table_name='financial_transactions' AND al.record_id=ft.id);

INSERT INTO financial_transaction_audit
    (user_id, transaction_type, target_table, target_record_id, amount_after, currency_id_after,
     status_after, after_json, change_reason)
SELECT COALESCE(NULLIF(ft.created_by,0),1),
       CASE
         WHEN ft.transaction_type = 'invoice' THEN 'invoice_posted'
         WHEN ft.transaction_type = 'receipt' THEN 'receipt'
         WHEN ft.transaction_type = 'payment' THEN 'payment'
         ELSE 'other'
       END,
       'financial_transactions', ft.id, ft.amount, ft.currency_id, ft.status,
       JSON_OBJECT('transaction_number',ft.transaction_number,'branch_id',ft.branch_id,'amount',ft.amount),
       'historical reconciliation; original event details unavailable'
FROM financial_transactions ft
WHERE ft.status = 'posted'
  AND NOT EXISTS (SELECT 1 FROM financial_transaction_audit fta WHERE fta.target_table='financial_transactions' AND fta.target_record_id=ft.id);

CALL sp_rebuild_balances();
