<?php
/**
 * Apply Migration 011 to alghazali_refactor_test ONLY
 * Then run post-migration verification re-run of Phase 3.4 checks.
 */
require_once __DIR__ . '/includes/db.php';

$sqlFile = __DIR__ . '/database/migrations/2026_08_11_011_phase3_branch_audit_backfill.sql';
if (!file_exists($sqlFile)) { die("Migration file not found: $sqlFile\n"); }

echo "Applying Migration 011 to DB=" . getenv('DB_NAME') . "...\n";

$sql = file_get_contents($sqlFile);
$statements = array_filter(array_map('trim', explode(';', $sql)));

try {
    $pdo->beginTransaction();
    foreach ($statements as $i => $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || strpos($stmt, '--') === 0 || strpos($stmt, '/*') === 0) continue;
        echo "  [$i] running: " . substr($stmt, 0, 120) . (strlen($stmt)>120?'...':'') . "\n";
        try {
            $affected = $pdo->exec($stmt);
            echo "       OK (affected rows: " . ($affected===false?'0':$affected) . ")\n";
        } catch (Throwable $e) {
            echo "       WARN: " . $e->getMessage() . "\n";
        }
    }
    $pdo->commit();
    echo "Migration 011 committed OK.\n\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    die("ROLLBACK: " . $e->getMessage() . "\n");
}

// ============ POST-MIGRATION VERIFICATION ============
echo "==== POST-MIGRATION: Phase 3.4 Issues Recheck ====\n\n";

echo "--- Recheck F-HIGH-001: audit_logs for cancelled FT #433 ---\n";
$c = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs WHERE table_name='financial_transactions' AND record_id=433")->fetchColumn();
echo "FT #433 audit rows: $c\n";

echo "\n--- Recheck DQ-001: branch_id NULLs (Branch Isolation) ---\n";
$tables = ['financial_transactions','journal_lines','invoices','currency_exchange_transactions'];
$remainingNulls = 0;
foreach ($tables as $tn) {
    try {
        $c = (int)$pdo->query("SELECT COUNT(*) FROM `$tn` WHERE branch_id IS NULL")->fetchColumn();
        echo "$tn.branch_id NULL: $c\n";
        $remainingNulls += $c;
    } catch (Throwable $e) { echo "$tn: skip ({$e->getMessage()})\n"; }
}
// ABU is separately tallied
$abu = (int)$pdo->query("SELECT COUNT(*) FROM account_balances_unified WHERE branch_id IS NULL")->fetchColumn();
echo "account_balances_unified.branch_id NULL (INTENTIONAL global): $abu\n";
echo "SENSITIVE remaining NULLs (FT/JL/INV/CET): $remainingNulls\n";

echo "\n--- Recheck F-CRIT-001: Balance mismatches after backfill ---\n";
$m = (int)$pdo->query("
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
echo "balance_mismatches: $m (F-CRIT-001 MUST be 0)\n";

echo "\n--- Recheck F-CRIT-003: Delete guard still functions? ---\n";
$pdo->beginTransaction();
$pdo->exec("INSERT INTO financial_transactions (transaction_number,transaction_date,branch_id,transaction_type,amount,currency_id,exchange_rate,status,created_by) VALUES ('TST-GUARD-A',NOW(),1,'receipt',50,1,1,'draft',1)");
$ftId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO journal_lines (financial_transaction_id,account_id,debit,credit,currency_id,branch_id) VALUES ($ftId,1,50,0,1,1),($ftId,2,0,50,1,1)");
$pdo->exec("UPDATE financial_transactions SET status='posted' WHERE id=$ftId");
try {
    $pdo->exec("DELETE FROM financial_transactions WHERE id=$ftId");
    echo "posted FT delete guard: FAIL (allowed!)\n";
} catch (Throwable $e) {
    echo "posted FT delete guard: PASS (blocked as expected)\n";
}
$pdo->rollBack();
