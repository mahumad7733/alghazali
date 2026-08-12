<?php
/**
 * FIX-A Investigation — find exact rows with:
 *   1. F-HIGH-001: 1 FT missing audit_log entry
 *   2. DQ-001: All branch_id NULL rows (scope for backfill)
 *   3. F-HIGH-004: Dangling reversal_voucher_id reference
 */
require_once __DIR__ . '/includes/db.php';

echo "=== INVESTIGATION: Phase 3.4 DB Issues (alghazali_refactor_test) ===\n\n";

// 1. Find the 1 FT with no audit log
echo "--- 1. F-HIGH-001: Posted/reversed/cancelled FT with no audit_log entry ---\n";
$stmt = $pdo->query("
    SELECT ft.id, ft.transaction_number, ft.status, ft.transaction_type, ft.amount, ft.branch_id, ft.created_by, ft.transaction_date
    FROM financial_transactions ft
    WHERE ft.status IN ('posted','reversed','cancelled')
      AND NOT EXISTS (SELECT 1 FROM audit_logs al WHERE al.table_name='financial_transactions' AND al.record_id=ft.id)
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Missing audit count: " . count($rows) . "\n";
foreach ($rows as $r) { echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"; }
echo "\n";

// 2. Branch NULL inventory
echo "--- 2. DQ-001: branch_id NULL inventory ---\n";
$tables = [
    ['financial_transactions','id, transaction_number, status, transaction_type, amount, created_by, created_at'],
    ['journal_lines','id, financial_transaction_id, account_id, debit, credit, created_at'],
    ['account_balances_unified','id, account_id, currency_id, current_balance, opening_balance'],
    ['invoices','id, invoice_number, total_amount, status, customer_id, created_by, invoice_date'],
    ['expenses','id, expense_date, amount, category_id, created_by'],
    ['payment_allocations','id, financial_transaction_id, invoice_id, allocated_amount'],
    ['currency_exchange_transactions','id, transaction_number, source_currency_id, target_currency_id, source_amount, created_by, transaction_date'],
];
$summary = [];
foreach ($tables as $t) {
    [$tn, $cols] = $t;
    try {
        $cnt = (int)$pdo->query("SELECT COUNT(*) FROM `$tn` WHERE branch_id IS NULL")->fetchColumn();
        echo "$tn.branch_id NULL: $cnt rows\n";
        $summary[$tn] = $cnt;
        if ($cnt > 0 && $cnt < 20) {
            $rs = $pdo->query("SELECT $cols FROM `$tn` WHERE branch_id IS NULL LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rs as $r) echo "  -> " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        } elseif ($cnt >= 20) {
            echo "  (sample first 10)\n";
            $rs = $pdo->query("SELECT $cols FROM `$tn` WHERE branch_id IS NULL LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rs as $r) echo "  -> " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
        }
    } catch (Throwable $e) {
        echo "$tn: SKIP - {$e->getMessage()}\n";
    }
}
echo "\nTOTAL branch_id NULLs: " . array_sum($summary) . "\n\n";

// 3. Dangling reversal_voucher
echo "--- 3. F-HIGH-004: Dangling reversal_voucher_id ---\n";
$stmt = $pdo->query("
    SELECT ft.id, ft.transaction_number, ft.status, ft.is_reversed,
           ft.reversal_voucher_id, ft.original_voucher_id, ft.cancelled_by, ft.cancelled_at
    FROM financial_transactions ft
    WHERE ft.reversal_voucher_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM financial_transactions r WHERE r.id = ft.reversal_voucher_id)
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Dangling reversal_voucher_id count: " . count($rows) . "\n";
foreach ($rows as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

echo "\n--- 3b. F-HIGH-004: reversed/cancelled linkage claimed by verifier ---\n";
$stmt = $pdo->query("\n    SELECT ft.id, ft.transaction_number, ft.status, ft.is_reversed,
           ft.reversal_voucher_id, ft.original_voucher_id
      FROM financial_transactions ft
     WHERE ft.is_reversed = 1
       AND ft.status = 'reversed'
       AND ft.reversal_voucher_id IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM financial_transactions r WHERE r.id = ft.reversal_voucher_id)
");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Verifier linkage mismatch count: " . count($rows) . "\n";
foreach ($rows as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
echo "\n";

// 4. Also check user_branches / users for branch assignment
echo "--- 4. Users / branches context ---\n";
$stmt = $pdo->query("SELECT u.id, u.username, u.role_id, r.name AS role_name, u.branch_id, u.branch_scope, u.status FROM users u LEFT JOIN roles r ON r.id = u.role_id ORDER BY u.id");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) echo "User[{$u['id']}] {$u['username']} role={$u['role_id']}/{$u['role_name']} branch={$u['branch_id']} scope={$u['branch_scope']} status={$u['status']}\n";
echo "\n";
$stmt = $pdo->query("SELECT * FROM user_branches");
$ub = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "user_branches rows: " . count($ub) . "\n";
foreach ($ub as $r) echo json_encode($r) . "\n";
