<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

echo "<h1>إصلاح جميع الأرصدة</h1>";
echo "<p>هذا السكربت يقوم ب:</p>";
echo "<ol>";
echo "<li>تصفير جميع الأرصدة الحالية</li>";
echo "<li>إعادة حساب جميع الأرصدة من أسطر القيود (journal lines)</li>";
echo "<li>تحديث الحسابات المرتبطة بالعملاء والموردين</li>";
echo "</ol>";

try {
    $pdo->beginTransaction();

    // Step 1: Reset all account balances to ZERO
    echo "<p style='color:blue'>1. تصفير جميع الأرصدة...</p>";
    $pdo->exec("UPDATE account_balances_unified SET opening_balance = 0, current_balance = 0, current_balance_base = 0, opening_balance_base = 0");
    echo "<p style='color:green'>✅ تم تصفير جميع الأرصدة بنجاح!</p>";

    // Step 2: Get all journal lines from POSTED transactions
    echo "<p style='color:blue'>2. جلب أسطر القيود من المعاملات المرحلة...</p>";
    $stmt_jl = $pdo->prepare("
        SELECT 
            jl.account_id, 
            jl.debit, 
            jl.credit, 
            jl.currency_id, 
            jl.branch_id
        FROM journal_lines jl
        INNER JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        WHERE ft.status = 'posted'
    ");
    $stmt_jl->execute();
    $journalLines = $stmt_jl->fetchAll(PDO::FETCH_ASSOC);
    echo "<p style='color:gray'>تم جلب " . count($journalLines) . " سطر قيد!</p>";

    // Step 3: Calculate balances per account/currency/branch
    echo "<p style='color:blue'>3. حساب الأرصدة...</p>";
    $balanceData = [];

    foreach ($journalLines as $line) {
        $branchId = $line['branch_id']; // Keep NULL if NULL
        $key = $line['account_id'] . '-' . $line['currency_id'] . '-' . ($branchId ?? 'NULL');

        if (!isset($balanceData[$key])) {
            $balanceData[$key] = [
                'account_id' => $line['account_id'],
                'currency_id' => $line['currency_id'],
                'branch_id' => $branchId,
                'debit' => 0,
                'credit' => 0
            ];
        }
        $balanceData[$key]['debit'] += (float)$line['debit'];
        $balanceData[$key]['credit'] += (float)$line['credit'];
    }

    // Step4: Update account_balances_unified
    echo "<p style='color:blue'>4. تحديث أرصدة الحسابات...</p>";
    foreach ($balanceData as $key => $data) {
        // Calculate net balance (depends on normal balance of account)
        $stmt_acc = $pdo->prepare("SELECT normal_balance FROM unified_accounts WHERE id = ?");
        $stmt_acc->execute([$data['account_id']]);
        $account = $stmt_acc->fetch(PDO::FETCH_ASSOC);
        $normalBalance = $account['normal_balance'] ?? 'debit';
        
        $netBalance = $normalBalance === 'debit' 
            ? ($data['debit'] - $data['credit']) 
            : ($data['credit'] - $data['debit']);

        // Get exchange rate and currency code for base currency
        $stmt_curr = $pdo->prepare("SELECT exchange_rate, currency_code FROM currencies WHERE id = ?");
        $stmt_curr->execute([$data['currency_id']]);
        $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
        $rate = (float)($curr['exchange_rate'] ?? 1);
        $currencyCode = $curr['currency_code'] ?? '';
        $netBalanceBase = $netBalance * $rate;

        // First check if the row exists
        if ($data['branch_id'] === null) {
            $stmt_check = $pdo->prepare("
                SELECT id FROM account_balances_unified 
                WHERE account_id = ? AND branch_id IS NULL AND currency_id = ?
            ");
            $stmt_check->execute([$data['account_id'], $data['currency_id']]);
        } else {
            $stmt_check = $pdo->prepare("
                SELECT id FROM account_balances_unified 
                WHERE account_id = ? AND branch_id = ? AND currency_id = ?
            ");
            $stmt_check->execute([$data['account_id'], $data['branch_id'], $data['currency_id']]);
        }
        $exists = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            // Update existing row
            $stmt_upd = $pdo->prepare("
                UPDATE account_balances_unified 
                SET 
                    current_balance = ?, 
                    current_balance_base = ?,
                    currency_code = ?
                WHERE id = ?
            ");
            $stmt_upd->execute([$netBalance, $netBalanceBase, $currencyCode, $exists['id']]);
        } else {
            // Insert new row with all required columns
            $stmt_ins = $pdo->prepare("
                INSERT INTO account_balances_unified (
                    account_id, branch_id, currency_id, currency_code,
                    opening_balance, current_balance, current_balance_base,
                    opening_balance_base, credit_limit, debit_limit, is_frozen
                ) VALUES (?, ?, ?, ?, 0, ?, ?, 0, 0, 0, 0)
            ");
            $stmt_ins->execute([$data['account_id'], $data['branch_id'], $data['currency_id'], $currencyCode, $netBalance, $netBalanceBase]);
        }
    }

    $pdo->commit();
    echo "<h2 style='color:green'>✅ تم إصلاح جميع الأرصدة بنجاح!</h2>";
    echo "<p><a href='admin/financial_accounts.php'>الرجوع إلى شجرة الحسابات</a></p>";
    echo "<p><a href='check_balance_sources.php'>التحقق من مصادر الأرصدة</a></p>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<h2 style='color:red'>❌ خطأ: " . htmlspecialchars($e->getMessage()) . "</h2>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>
