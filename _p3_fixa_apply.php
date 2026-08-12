<?php
/**
 * FIX-A: Apply 3 DB repairs directly via PHP (no naive SQL splitting)
 *   1. Audit reconciliation row for FT #433 (F-HIGH-001)
 *   2. branch_id backfill on FT / JL / INV / CET (Branch Isolation — DQ-001)
 *   3. Call sp_rebuild_balances to restore F-CRIT-001 integrity
 * DB: alghazali_refactor_test ONLY
 */
require_once __DIR__ . '/includes/db.php';

echo "=== FIX-A: Applying DB Repairs to " . getenv('DB_NAME') . " ===\n\n";

try {
    $pdo->beginTransaction();

    // --- 1. F-HIGH-001: Insert missing audit_logs row for cancelled FT #433 ---
    echo "1. Inserting audit reconciliation for FT #433...\n";
    $ft433 = $pdo->query("SELECT * FROM financial_transactions WHERE id=433 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$ft433) { echo "   WARNING: FT #433 not found.\n"; }
    else {
        $exists = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE table_name='financial_transactions' AND record_id=433")->fetchColumn();
        if ($exists === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs
                    (user_id, action, entity_type, entity_id, table_name, record_id,
                     old_values, new_values, request_method, details_json, severity, created_at)
                VALUES (?, 'historical_reconciliation', 'financial_transaction', 433,
                        'financial_transactions', 433, NULL, ?, 'DB', ?, 'warning', NOW())
            ");
            $newVals = json_encode([
                'status' => $ft433['status'],
                'transaction_number' => $ft433['transaction_number'],
                'amount' => $ft433['amount'],
                'currency_id' => $ft433['currency_id'],
                'branch_id' => $ft433['branch_id'],
                'cancelled_at' => $ft433['cancelled_at'],
                'cancelled_by' => $ft433['cancelled_by'],
            ], JSON_UNESCAPED_UNICODE);
            $details = json_encode([
                'source' => 'phase3_reconciliation',
                'original_event_time_unavailable' => true,
                'reason' => 'cancelled FT had no audit_log entry (F-HIGH-001)',
            ], JSON_UNESCAPED_UNICODE);
            $uid = (int)(($ft433['created_by'] ?? 0) ?: ($ft433['cancelled_by'] ?? 0) ?: 1);
            $stmt->execute([$uid, $newVals, $details]);
            echo "   OK — inserted 1 audit_log row for FT #433\n";
        } else {
            echo "   SKIP — audit_log row already exists (count=$exists)\n";
        }
    }

    // --- 2A. Backfill financial_transactions.branch_id = 1 for NULL rows ---
    echo "\n2A. Backfilling financial_transactions.branch_id = 1 where NULL...\n";
    $cBefore = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE branch_id IS NULL")->fetchColumn();
    $pdo->exec("
        UPDATE financial_transactions
           SET branch_id = 1,
               updated_at = COALESCE(updated_at, NOW()),
               updated_by = COALESCE(NULLIF(updated_by,0), 2)
         WHERE branch_id IS NULL
    ");
    $cAfter = (int)$pdo->query("SELECT COUNT(*) FROM financial_transactions WHERE branch_id IS NULL")->fetchColumn();
    echo "   Rows: before=$cBefore after=$cAfter (changed=" . ($cBefore-$cAfter) . ")\n";

    // --- 2B. Backfill journal_lines from parent financial_transaction ---
    echo "\n2B. Backfilling journal_lines.branch_id via parent financial_transaction...\n";
    $cBefore = (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE branch_id IS NULL")->fetchColumn();
    $pdo->exec("
        UPDATE journal_lines jl
          JOIN financial_transactions ft ON ft.id = jl.financial_transaction_id
           SET jl.branch_id = ft.branch_id
         WHERE jl.branch_id IS NULL
           AND ft.branch_id IS NOT NULL
    ");
    $cMid = (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE branch_id IS NULL")->fetchColumn();
    // fallback remaining
    $pdo->exec("UPDATE journal_lines SET branch_id = 1 WHERE branch_id IS NULL");
    $cAfter = (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE branch_id IS NULL")->fetchColumn();
    echo "   Rows: before=$cBefore after-parent-join=$cMid after-fallback=$cAfter\n";

    // --- 2C. Backfill invoices ---
    echo "\n2C. Backfilling invoices.branch_id via allocations -> FT...\n";
    $cBefore = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE branch_id IS NULL")->fetchColumn();
    $pdo->exec("
        UPDATE invoices i
          JOIN payment_allocations pa ON pa.invoice_id = i.id
          JOIN financial_transactions ft ON ft.id = pa.financial_transaction_id
           SET i.branch_id = ft.branch_id
         WHERE i.branch_id IS NULL
           AND ft.branch_id IS NOT NULL
    ");
    $pdo->exec("UPDATE invoices SET branch_id = 1 WHERE branch_id IS NULL");
    $cAfter = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE branch_id IS NULL")->fetchColumn();
    echo "   Rows: before=$cBefore after=$cAfter\n";

    // --- 2D. Backfill currency_exchange_transactions ---
    echo "\n2D. Backfilling currency_exchange_transactions.branch_id...\n";
    $cBefore = 0;
    try {
        $cBefore = (int)$pdo->query("SELECT COUNT(*) FROM currency_exchange_transactions WHERE branch_id IS NULL")->fetchColumn();
        $pdo->exec("UPDATE currency_exchange_transactions SET branch_id = 1 WHERE branch_id IS NULL");
        $cAfter = (int)$pdo->query("SELECT COUNT(*) FROM currency_exchange_transactions WHERE branch_id IS NULL")->fetchColumn();
        echo "   Rows: before=$cBefore after=$cAfter\n";
    } catch (Throwable $e) { echo "   SKIP: {$e->getMessage()}\n"; }

    $pdo->commit();
    echo "\n  --- Data backfill COMMITTED ---\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("ROLLBACK during backfill: " . $e->getMessage() . "\n");
}

// --- 3. CRITICAL: Rebuild balances via branch-aware SP to restore F-CRIT-001 ---
echo "\n3. Running sp_rebuild_balances() to restore Branch-Aware Balance integrity...\n";
try {
    $mBefore = (int)$pdo->query("
        SELECT COUNT(*) FROM account_balances_unified abu
        WHERE abu.current_balance <> (
            COALESCE(abu.opening_balance,0) +
            (SELECT COALESCE(SUM(
                CASE WHEN ft.status IN ('posted','reversed')
                THEN COALESCE(jl.debit,0)-COALESCE(jl.credit,0) ELSE 0 END), 0)
             FROM journal_lines jl
             LEFT JOIN financial_transactions ft ON ft.id = jl.financial_transaction_id
             WHERE jl.account_id = abu.account_id
               AND jl.currency_id = abu.currency_id
               AND (abu.branch_id IS NULL OR jl.branch_id = abu.branch_id))
        )
    ")->fetchColumn();
    echo "   balance_mismatches BEFORE rebuild: $mBefore\n";

    $pdo->exec("CALL sp_rebuild_balances()");
    echo "   sp_rebuild_balances() executed OK\n";

    $mAfter = (int)$pdo->query("
        SELECT COUNT(*) FROM account_balances_unified abu
        WHERE abu.current_balance <> (
            COALESCE(abu.opening_balance,0) +
            (SELECT COALESCE(SUM(
                CASE WHEN ft.status IN ('posted','reversed')
                THEN COALESCE(jl.debit,0)-COALESCE(jl.credit,0) ELSE 0 END), 0)
             FROM journal_lines jl
             LEFT JOIN financial_transactions ft ON ft.id = jl.financial_transaction_id
             WHERE jl.account_id = abu.account_id
               AND jl.currency_id = abu.currency_id
               AND (abu.branch_id IS NULL OR jl.branch_id = abu.branch_id))
        )
    ")->fetchColumn();
    echo "   balance_mismatches AFTER rebuild: $mAfter  (F-CRIT-001 requires 0)\n";
} catch (Throwable $e) {
    die("FATAL during sp_rebuild_balances: " . $e->getMessage() . "\n");
}

// --- Final summary ---
echo "\n==== FIX-A FINAL VERIFICATION ====\n";

$a = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE table_name='financial_transactions' AND record_id=433")->fetchColumn();
echo "F-HIGH-001: FT #433 audit_log rows = $a  (required ≥1)\n";

$tot = 0;
foreach (['financial_transactions','journal_lines','invoices'] as $tn) {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM `$tn` WHERE branch_id IS NULL")->fetchColumn();
    $tot += $c;
    echo "DQ-001: $tn.branch_id NULL = $c\n";
}
echo "DQ-001: TOTAL sensitive branch_id NULL = $tot (required 0)\n";

try {
    $c = (int)$pdo->query("SELECT COUNT(*) FROM currency_exchange_transactions WHERE branch_id IS NULL")->fetchColumn();
    echo "DQ-001: currency_exchange_transactions.branch_id NULL = $c\n";
    $tot += $c;
} catch (Throwable $e) {}

echo "\n";
if ($a >= 1 && $tot === 0 && $mAfter === 0) {
    echo "✅ FIX-A: ALL REPAIRS PASSED (F-HIGH-001=OK, DQ-001=OK, F-CRIT-001=OK)\n";
} else {
    echo "❌ FIX-A: SOME REPAIRS STILL FAILED\n";
    exit(1);
}
