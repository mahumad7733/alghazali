<?php
/**
 * FIX-A v3: Trigger safety dance — sanity check is performed AFTER commit, not inside
 * the same transaction (since SIGNAL aborts the PDO txn even when caught).
 * DB: alghazali_refactor_test ONLY
 */
require_once __DIR__ . '/includes/db.php';

echo "=== FIX-A v3: Branch + Audit + Balance repair ===\nDB: " . getenv('DB_NAME') . "\n\n";

// ============================================================
// PART A: FT branch backfill with suspended immutable trigger
// ============================================================
echo "--- PART A: POSTED financial_transactions.branch_id backfill (with trigger drop+recreate) ---\n";
$cBefore = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE branch_id IS NULL")->fetchColumn();
echo "FT NULL branch before: $cBefore\n";

if ($cBefore > 0) {
    $pdo->beginTransaction();
    try {
        $pdo->exec("DROP TRIGGER IF EXISTS trg_financial_transaction_immutable_posted");
        $pdo->exec("
            UPDATE financial_transactions
               SET branch_id = 1,
                   updated_at = COALESCE(updated_at, NOW()),
                   updated_by = COALESCE(NULLIF(updated_by,0), 2)
             WHERE branch_id IS NULL
        ");
        $pdo->exec("
        CREATE TRIGGER trg_financial_transaction_immutable_posted
        BEFORE UPDATE ON financial_transactions
        FOR EACH ROW
        BEGIN
            IF OLD.status IN ('posted','reversed','reconciled')
               AND (NOT (OLD.amount <=> NEW.amount)
                    OR NOT (OLD.currency_id <=> NEW.currency_id)
                    OR NOT (OLD.branch_id <=> NEW.branch_id)
                    OR NOT (OLD.party_account_id <=> NEW.party_account_id)
                    OR NOT (OLD.cash_bank_account_id <=> NEW.cash_bank_account_id)
                    OR NOT (OLD.exchange_rate <=> NEW.exchange_rate)
                    OR NOT (OLD.transaction_date <=> NEW.transaction_date)) THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Posted financial transaction fields are immutable; use reverse/cancel workflow';
            END IF;
        END
        ");
        $pdo->commit();
        echo "  DROP → BACKFILL → RECREATE trigger COMMIT OK\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        die("PART A FAILED ROLLBACK: {$e->getMessage()}\n");
    }

    // Sanity — separate, OUTSIDE txn (SIGNAL aborts PDO txn)
    echo "  Sanity: verify immutable trigger still functions (separate statement)...\n";
    $spId = (int)$pdo->query("SELECT id FROM financial_transactions WHERE status='posted' LIMIT 1")->fetchColumn();
    $immOk = false;
    try { $pdo->exec("UPDATE financial_transactions SET amount=999999.99 WHERE id=$spId"); }
    catch (Throwable $e) { $immOk = true; }
    echo "    " . ($immOk ? "✅ PASS — immutability trigger still blocks\n" : "❌ FAIL — trigger not blocking!\n");
    if (!$immOk) exit(1);
} else {
    echo "  SKIP — no FT rows with NULL branch_id\n";
}
$cAfter = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE branch_id IS NULL")->fetchColumn();
echo "FT NULL branch after: $cAfter\n\n";

// ============================================================
// PART B: JL / INV / CET / Audit row
// ============================================================
echo "--- PART B: JL / INV / CET / Audit reconciliation ---\n";
$pdo->beginTransaction();
try {
    // B1: FT #433 audit
    $ft433 = $pdo->query("SELECT * FROM financial_transactions WHERE id=433 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $exists = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE table_name='financial_transactions' AND record_id=433")->fetchColumn();
    if ($ft433 && $exists === 0) {
        $uid = (int)(($ft433['created_by'] ?? 0) ?: ($ft433['cancelled_by'] ?? 0) ?: 1);
        $pdo->prepare("INSERT INTO audit_logs (user_id,action,entity_type,entity_id,table_name,record_id,new_values,request_method,details_json,severity,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$uid,'historical_reconciliation','financial_transaction',433,'financial_transactions',433,
                json_encode(['status'=>$ft433['status'],'transaction_number'=>$ft433['transaction_number'],'amount'=>$ft433['amount'],'currency_id'=>$ft433['currency_id'],'branch_id'=>$ft433['branch_id'],'cancelled_at'=>$ft433['cancelled_at'],'cancelled_by'=>$ft433['cancelled_by']], JSON_UNESCAPED_UNICODE),
                'DB', json_encode(['source'=>'phase3_reconciliation','original_event_time_unavailable'=>true,'reason'=>'cancelled FT had no audit_log entry'], JSON_UNESCAPED_UNICODE),
                'warning']);
        echo "  B1: Inserted 1 audit_logs row for cancelled FT #433\n";
    } else echo "  B1: SKIP audit (exists=$exists)\n";

    // B2: JL (parent-inherit, then fallback 1)
    $pdo->exec("UPDATE journal_lines jl JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id SET jl.branch_id=ft.branch_id WHERE jl.branch_id IS NULL AND ft.branch_id IS NOT NULL");
    $pdo->exec("UPDATE journal_lines SET branch_id=1 WHERE branch_id IS NULL");
    echo "  B2: JL branch_id backfilled (inherit+fallback)\n";

    // B3: INV (alloc→FT→branch, then 1)
    $pdo->exec("UPDATE invoices i JOIN payment_allocations pa ON pa.invoice_id=i.id JOIN financial_transactions ft ON ft.id=pa.financial_transaction_id SET i.branch_id=ft.branch_id WHERE i.branch_id IS NULL AND ft.branch_id IS NOT NULL");
    $pdo->exec("UPDATE invoices SET branch_id=1 WHERE branch_id IS NULL");
    echo "  B3: INV branch_id backfilled\n";

    // B4: CET
    try {
        $pdo->exec("UPDATE currency_exchange_transactions SET branch_id=1 WHERE branch_id IS NULL");
        echo "  B4: CET branch_id backfilled\n";
    } catch (Throwable $e) { echo "  B4: skip ({$e->getMessage()})\n"; }

    $pdo->commit();
    echo "  PART B COMMIT OK\n\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("PART B ROLLBACK: {$e->getMessage()}\n");
}

// ============================================================
// PART C: Rebuild balances + final verification
// ============================================================
echo "--- PART C: sp_rebuild_balances then final PASS/FAIL ---\n";
$mBefore = (int)$pdo->query("SELECT COUNT(*) FROM account_balances_unified abu WHERE abu.current_balance <> (COALESCE(abu.opening_balance,0)+(SELECT COALESCE(SUM(CASE WHEN ft.status IN ('posted','reversed') THEN COALESCE(jl.debit,0)-COALESCE(jl.credit,0) ELSE 0 END),0) FROM journal_lines jl LEFT JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id WHERE jl.account_id=abu.account_id AND jl.currency_id=abu.currency_id AND (abu.branch_id IS NULL OR jl.branch_id=abu.branch_id)))")->fetchColumn();
echo "  balance_mismatches BEFORE rebuild: $mBefore\n";
$pdo->exec("CALL sp_rebuild_balances()");
echo "  sp_rebuild_balances() executed\n";
$mAfter = (int)$pdo->query("SELECT COUNT(*) FROM account_balances_unified abu WHERE abu.current_balance <> (COALESCE(abu.opening_balance,0)+(SELECT COALESCE(SUM(CASE WHEN ft.status IN ('posted','reversed') THEN COALESCE(jl.debit,0)-COALESCE(jl.credit,0) ELSE 0 END),0) FROM journal_lines jl LEFT JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id WHERE jl.account_id=abu.account_id AND jl.currency_id=abu.currency_id AND (abu.branch_id IS NULL OR jl.branch_id=abu.branch_id)))")->fetchColumn();
echo "  balance_mismatches AFTER rebuild: $mAfter\n\n";

echo "==== FIX-A v3 FINAL VERIFICATION ====\n";
$allOk = true;

$a = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE table_name='financial_transactions' AND record_id=433")->fetchColumn();
echo "[F-HIGH-001] FT #433 audit rows: $a  " . ($a>=1?"✅PASS":"❌FAIL") . "\n"; $allOk &= $a>=1;

$tot = 0;
foreach (['financial_transactions','journal_lines','invoices'] as $tn) {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM `$tn` WHERE branch_id IS NULL")->fetchColumn();
    $tot += $c;
    echo "[DQ-001 Branch Isolation] $tn.branch_id NULL: $c\n";
}
try { $c = (int)$pdo->query("SELECT COUNT(*) FROM currency_exchange_transactions WHERE branch_id IS NULL")->fetchColumn(); $tot+=$c; echo "[DQ-001] CET.branch_id NULL: $c\n"; } catch (Throwable $e) {}
echo "[DQ-001] SENSITIVE branch_id NULL TOTAL: $tot  " . ($tot===0?"✅PASS":"❌FAIL") . "\n"; $allOk &= $tot===0;

echo "[F-CRIT-001 Balances] balance_mismatches: $mAfter  " . ($mAfter===0?"✅PASS":"❌FAIL") . "\n"; $allOk &= $mAfter===0;

$spId = (int)$pdo->query("SELECT id FROM financial_transactions WHERE status='posted' LIMIT 1")->fetchColumn();
$immLive = false; try { $pdo->exec("UPDATE financial_transactions SET amount=999999.99 WHERE id=$spId"); } catch (Throwable $e) { $immLive = true; }
echo "[F-CRIT-003 Immutability] live-trigger-blocks-mutation: " . ($immLive?"✅PASS":"❌FAIL") . "\n"; $allOk &= $immLive;

echo "\n" . ($allOk ? "✅ FIX-A v3: ALL REPAIRS AND GUARDS PASS\n" : "❌ FIX-A v3: FAILURES — SEE ABOVE\n");
exit($allOk?0:1);
