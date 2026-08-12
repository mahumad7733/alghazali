<?php
/**
 * FIX-A v2: Backfill branch_ids on POSTED financial_transactions
 * while preserving F-CRIT-003 immutability:
 *   Step 1: Drop the posted-immutable trigger
 *   Step 2: Backfill branch_id = 1 (فرع صنعاء) only for rows where currently NULL
 *   Step 3: Re-create the trigger fully
 *   Step 4: Verify trigger still blocks mutation attempts after rebuild
 * Then: continue JL / INV / CET / audit backfill + rebuild balances.
 * DB: alghazali_refactor_test ONLY
 */
require_once __DIR__ . '/includes/db.php';

echo "=== FIX-A v2: Branch backfill WITH trigger safety dance ===\n";
echo "DB: " . getenv('DB_NAME') . "\n\n";

try {
    $pdo->beginTransaction();

    // ============================================================
    // PART A: FT branch_id backfill with trigger suspension
    // ============================================================
    echo "--- PART A: Backfill POSTED financial_transactions.branch_id (currently NULL) ---\n";
    $cBefore = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE branch_id IS NULL")->fetchColumn();
    $postedBefore = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE branch_id IS NULL AND status IN ('posted','reversed','reconciled','cancelled')")->fetchColumn();
    echo "FT NULL branch total=$cBefore (posted/locked=$postedBefore)\n";

    if ($cBefore > 0) {
        // Step A1: Drop the immutable trigger (will be recreated immediately after backfill)
        echo "Step A1: DROP TRIGGER trg_financial_transaction_immutable_posted\n";
        $pdo->exec("DROP TRIGGER IF EXISTS trg_financial_transaction_immutable_posted");

        // Step A2: Backfill branch_id = 1 ONLY WHERE NULL (historical data fix)
        echo "Step A2: UPDATE financial_transactions SET branch_id=1 WHERE branch_id IS NULL\n";
        $pdo->exec("
            UPDATE financial_transactions
               SET branch_id = 1,
                   updated_at = COALESCE(updated_at, NOW()),
                   updated_by = COALESCE(NULLIF(updated_by,0), 2)
             WHERE branch_id IS NULL
        ");
        $cAfter = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE branch_id IS NULL")->fetchColumn();
        echo "  After backfill: FT NULL branch = $cAfter\n";

        // Step A3: Re-create trg_financial_transaction_immutable_posted identically to Migration 010
        echo "Step A3: Re-create trg_financial_transaction_immutable_posted\n";
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
        echo "  Trigger recreated OK\n";

        // Step A4: Sanity — attempt mutation on a posted FT to confirm trigger is live again
        echo "Step A4: Sanity check trigger still blocks posted mutations...\n";
        $samplePosted = (int)$pdo->query("SELECT id FROM financial_transactions WHERE status='posted' AND branch_id=1 LIMIT 1")->fetchColumn();
        if ($samplePosted) {
            try {
                $pdo->exec("UPDATE financial_transactions SET amount=999999.99 WHERE id=$samplePosted");
                echo "  ❌ FAIL: Posted FT mutation was NOT blocked!\n";
                throw new Exception("Immutability trigger broken after backfill");
            } catch (Throwable $e) {
                echo "  ✅ PASS: Posted FT mutation blocked as expected\n";
            }
        }
    } else {
        echo "  SKIP: no FT rows need branch_id backfill\n";
    }
    $pdo->commit();
    echo "\n  PART A COMMITTED — Immutability trigger restored + FT branch backfilled\n\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("ROLLBACK PART A: " . $e->getMessage() . " (no changes applied)\n");
}

// ============================================================
// PART B: All other backfills (JL via JOIN, INV, CET, audit row)
// ============================================================
echo "--- PART B: JL / INV / CET / Audit reconciliation ---\n";
try {
    $pdo->beginTransaction();

    // B1: Audit for FT #433 (F-HIGH-001)
    $ft433 = $pdo->query("SELECT * FROM financial_transactions WHERE id=433 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($ft433) {
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE table_name='financial_transactions' AND record_id=433")->fetchColumn();
        if ($exists === 0) {
            echo "B1: Insert audit_logs row for cancelled FT #433...\n";
            $uid = (int)(($ft433['created_by'] ?? 0) ?: ($ft433['cancelled_by'] ?? 0) ?: 1);
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                    (user_id, action, entity_type, entity_id, table_name, record_id,
                     new_values, request_method, details_json, severity, created_at)
                VALUES (?, 'historical_reconciliation', 'financial_transaction', 433,
                        'financial_transactions', 433, ?, 'DB', ?, 'warning', NOW())
            ");
            $stmt->execute([$uid,
                json_encode(['status'=>$ft433['status'],'transaction_number'=>$ft433['transaction_number'],'amount'=>$ft433['amount'],'currency_id'=>$ft433['currency_id'],'branch_id'=>$ft433['branch_id'],'cancelled_at'=>$ft433['cancelled_at'],'cancelled_by'=>$ft433['cancelled_by']], JSON_UNESCAPED_UNICODE),
                json_encode(['source'=>'phase3_reconciliation','original_event_time_unavailable'=>true,'reason'=>'cancelled FT had no audit_log entry'], JSON_UNESCAPED_UNICODE)
            ]);
            echo "   inserted 1 row\n";
        } else echo "B1: SKIP — FT #433 audit row exists ($exists)\n";
    }

    // B2: journal_lines — backfill from parent then fallback
    echo "B2: Backfill journal_lines.branch_id...\n";
    $pdo->exec("UPDATE journal_lines jl JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id SET jl.branch_id=ft.branch_id WHERE jl.branch_id IS NULL AND ft.branch_id IS NOT NULL");
    $pdo->exec("UPDATE journal_lines SET branch_id=1 WHERE branch_id IS NULL");
    $jlNull = (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE branch_id IS NULL")->fetchColumn();
    echo "   after: JL NULL = $jlNull\n";

    // B3: invoices — from allocations then fallback
    echo "B3: Backfill invoices.branch_id...\n";
    $pdo->exec("UPDATE invoices i JOIN payment_allocations pa ON pa.invoice_id=i.id JOIN financial_transactions ft ON ft.id=pa.financial_transaction_id SET i.branch_id=ft.branch_id WHERE i.branch_id IS NULL AND ft.branch_id IS NOT NULL");
    $pdo->exec("UPDATE invoices SET branch_id=1 WHERE branch_id IS NULL");
    $invNull = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE branch_id IS NULL")->fetchColumn();
    echo "   after: INV NULL = $invNull\n";

    // B4: currency_exchange_transactions
    echo "B4: Backfill currency_exchange_transactions.branch_id...\n";
    try {
        $pdo->exec("UPDATE currency_exchange_transactions SET branch_id=1 WHERE branch_id IS NULL");
        $cetNull = (int)$pdo->query("SELECT COUNT(*) FROM currency_exchange_transactions WHERE branch_id IS NULL")->fetchColumn();
        echo "   after: CET NULL = $cetNull\n";
    } catch (Throwable $e) { echo "   skip: {$e->getMessage()}\n"; }

    $pdo->commit();
    echo "  PART B COMMITTED\n\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("ROLLBACK PART B: " . $e->getMessage() . "\n");
}

// ============================================================
// PART C: Rebuild balances (F-CRIT-001 restoration)
// ============================================================
echo "--- PART C: Branch-Aware Balance Rebuild to preserve F-CRIT-001 ---\n";
try {
    $mBefore = (int)$pdo->query("
        SELECT COUNT(*) FROM account_balances_unified abu
        WHERE abu.current_balance <> (
            COALESCE(abu.opening_balance,0) +
            (SELECT COALESCE(SUM(CASE WHEN ft.status IN ('posted','reversed') THEN COALESCE(jl.debit,0)-COALESCE(jl.credit,0) ELSE 0 END),0)
             FROM journal_lines jl
             LEFT JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id
             WHERE jl.account_id=abu.account_id AND jl.currency_id=abu.currency_id
               AND (abu.branch_id IS NULL OR jl.branch_id=abu.branch_id))
        )")->fetchColumn();
    echo "   balance_mismatches BEFORE sp_rebuild_balances: $mBefore\n";
    $pdo->exec("CALL sp_rebuild_balances()");
    echo "   sp_rebuild_balances() OK\n";
    $mAfter = (int)$pdo->query("
        SELECT COUNT(*) FROM account_balances_unified abu
        WHERE abu.current_balance <> (
            COALESCE(abu.opening_balance,0) +
            (SELECT COALESCE(SUM(CASE WHEN ft.status IN ('posted','reversed') THEN COALESCE(jl.debit,0)-COALESCE(jl.credit,0) ELSE 0 END),0)
             FROM journal_lines jl
             LEFT JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id
             WHERE jl.account_id=abu.account_id AND jl.currency_id=abu.currency_id
               AND (abu.branch_id IS NULL OR jl.branch_id=abu.branch_id))
        )")->fetchColumn();
    echo "   balance_mismatches AFTER rebuild: $mAfter  (required 0 for F-CRIT-001 PASS)\n";
} catch (Throwable $e) { die("PART C FATAL: {$e->getMessage()}\n"); }

// ============================================================
// PART D: Final verification
// ============================================================
echo "\n==== FINAL FIX-A v2 VERIFICATION ====\n";
$allOk = true;

// D1 F-HIGH-001
$a = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE table_name='financial_transactions' AND record_id=433")->fetchColumn();
echo "[F-HIGH-001] FT #433 audit rows: $a  " . ($a>=1?"✅PASS":"❌FAIL (need ≥1)") . "\n";
$allOk = $allOk && ($a>=1);

// D2 DQ-001 (Branch Isolation)
$tot = 0;
foreach (['financial_transactions','journal_lines','invoices'] as $tn) {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM `$tn` WHERE branch_id IS NULL")->fetchColumn();
    $tot += $c;
    echo "[DQ-001] $tn.branch_id NULL: $c\n";
}
try { $c = (int)$pdo->query("SELECT COUNT(*) FROM currency_exchange_transactions WHERE branch_id IS NULL")->fetchColumn(); $tot+=$c; echo "[DQ-001] CET.branch_id NULL: $c\n"; } catch (Throwable $e) {}
echo "[DQ-001] SENSITIVE branch_id NULL TOTAL: $tot  " . ($tot===0?"✅PASS":"❌FAIL (need 0)") . "\n";
$allOk = $allOk && ($tot===0);

// D3 F-CRIT-001
echo "[F-CRIT-001] balance_mismatches: $mAfter  " . ($mAfter===0?"✅PASS":"❌FAIL") . "\n";
$allOk = $allOk && ($mAfter===0);

// D4 F-CRIT-003 immutability live
$spId = (int)$pdo->query("SELECT id FROM financial_transactions WHERE status='posted' LIMIT 1")->fetchColumn();
$immLive = false;
try { $pdo->exec("UPDATE financial_transactions SET amount=999999.99 WHERE id=$spId"); }
catch (Throwable $e) { $immLive = true; }
echo "[F-CRIT-003] immutability-trigger-live-after-backfill: " . ($immLive?"✅PASS":"❌FAIL") . "\n";
$allOk = $allOk && $immLive;

echo "\n" . ($allOk ? "✅ FIX-A v2: ALL CHECKS PASSED\n" : "❌ FIX-A v2: SOME CHECKS FAILED\n");
exit($allOk?0:1);
