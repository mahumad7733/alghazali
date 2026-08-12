-- ============================================================
-- Migration: 2026_08_11_011_phase3_branch_audit_backfill.sql
-- Scope: alghazali_refactor_test ONLY
-- Purpose: (1) Backfill branch_id NULLs in financial tables
--          that participate in Branch Isolation (F-CRIT-004)
--          (2) Reconcile 1 missing audit_log row (F-HIGH-001)
-- Idempotent: YES — every UPDATE uses WHERE branch_id IS NULL
--             and INSERT uses NOT EXISTS guards.
-- ============================================================

-- ----------------------------------------------------------------
-- FIX-A-1: Reconcile 1 missing audit_log entry for F-HIGH-001
-- Financial transaction #433 (JRN-000433, status=cancelled) had
-- no corresponding audit_logs row.
-- Insert as historical reconciliation, never claim original-time.
-- ----------------------------------------------------------------
INSERT INTO audit_logs
    (user_id, action, entity_type, entity_id, table_name, record_id,
     old_values, new_values, request_method, details_json, severity, created_at)
SELECT
    COALESCE(NULLIF(ft.created_by,0), NULLIF(ft.cancelled_by,0), 1),
    'historical_reconciliation',
    'financial_transaction',
    ft.id,
    'financial_transactions',
    ft.id,
    NULL,
    JSON_OBJECT('status',ft.status,'transaction_number',ft.transaction_number,
                'amount',ft.amount,'currency_id',ft.currency_id,'branch_id',ft.branch_id,
                'cancelled_at',ft.cancelled_at,'cancelled_by',ft.cancelled_by),
    'DB',
    JSON_OBJECT('source','phase3_reconciliation','original_event_time_unavailable',TRUE,
                'reason','cancelled FT had no audit_log entry (F-HIGH-001)'),
    'warning',
    NOW()
FROM financial_transactions ft
WHERE ft.id = 433
  AND NOT EXISTS (SELECT 1 FROM audit_logs al
                  WHERE al.table_name='financial_transactions' AND al.record_id=ft.id);

-- ----------------------------------------------------------------
-- FIX-A-2: Backfill financial_transactions.branch_id = 1 for NULLs
-- These 7 rows are ALL created_by = 2 (developer/admin) in branch 1
-- and are POSTED, so defaulting to branch 1 (فرع صنعاء) is safe.
-- ----------------------------------------------------------------
UPDATE financial_transactions
   SET branch_id = 1,
       updated_at = COALESCE(updated_at, NOW()),
       updated_by = COALESCE(NULLIF(updated_by,0), 2)
 WHERE branch_id IS NULL;
-- 7 rows expected

-- ----------------------------------------------------------------
-- FIX-A-3: Backfill journal_lines.branch_id from parent
-- financial_transaction.branch_id.  Any child JL that still has
-- no branch after this will fall back to 1 below.
-- ----------------------------------------------------------------
UPDATE journal_lines jl
  JOIN financial_transactions ft ON ft.id = jl.financial_transaction_id
   SET jl.branch_id = ft.branch_id
 WHERE jl.branch_id IS NULL
   AND ft.branch_id IS NOT NULL;
-- ~35 rows expected. Remaining NULLs (if parent also NULL) fixed next:

UPDATE journal_lines
   SET branch_id = 1
 WHERE branch_id IS NULL;

-- ----------------------------------------------------------------
-- FIX-A-4: Backfill invoices.branch_id = 1 for the 6 legacy NULLs
-- (First try to infer from linked financial_transactions via
-- payment_allocations, else fall back to branch 1.)
-- ----------------------------------------------------------------
UPDATE invoices i
  JOIN payment_allocations pa ON pa.invoice_id = i.id
  JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id
   SET i.branch_id = ft.branch_id
 WHERE i.branch_id IS NULL
   AND ft.branch_id IS NOT NULL;

UPDATE invoices
   SET branch_id = 1
 WHERE branch_id IS NULL;

-- ----------------------------------------------------------------
-- FIX-A-5: Backfill currency_exchange_transactions.branch_id
-- Only 1 row expected.
-- ----------------------------------------------------------------
UPDATE currency_exchange_transactions
   SET branch_id = 1
 WHERE branch_id IS NULL;

-- ----------------------------------------------------------------
-- NOTE: We DO NOT touch account_balances_unified.branch_id NULLs.
-- ABU with branch_id = NULL is an intentional design representing
-- a GLOBAL / entity-scope account-balance line.  The stored proc
-- sp_rebuild_balances explicitly supports it with:
--   (abu.branch_id IS NULL OR jl.branch_id = abu.branch_id)
-- Modifying these would DOUBLE-Count balances and break F-CRIT-001.
-- ----------------------------------------------------------------
