<?php
require_once 'includes/db.php';

echo "<h1>آخر معاملة مالية وآثارها</h1>";

// Step 1: Get last transaction
$stmt = $pdo->query("SELECT * FROM financial_transactions ORDER BY id DESC LIMIT 1");
$last_trx = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$last_trx) {
    echo "<p style='color:red;'>لا توجد معاملات</p>";
    exit;
}

echo "<h3>المعاملة الأخيرة</h3>";
echo "<pre>";
print_r($last_trx);
echo "</pre>";

// Step 2: Get journal lines for this transaction
echo "<h3>أسطر القيد لهذه المعاملة</h3>";
$stmt_jl = $pdo->prepare("SELECT * FROM journal_lines WHERE financial_transaction_id = ?");
$stmt_jl->execute([$last_trx['id']]);
$journal_lines = $stmt_jl->fetchAll(PDO::FETCH_ASSOC);
echo "<table border='1' style='border-collapse: collapse; width: 100%;'>
        <tr><th>الحساب</th><th>مدين</th><th>دائن</th><th>العملة</th></tr>";
foreach ($journal_lines as $line) {
    $stmt_acc = $pdo->prepare("SELECT account_code, account_name_ar FROM unified_accounts WHERE id = ?");
    $stmt_acc->execute([$line['account_id']]);
    $acc = $stmt_acc->fetch(PDO::FETCH_ASSOC);

    $stmt_curr = $pdo->prepare("SELECT currency_code FROM currencies WHERE id = ?");
    $stmt_curr->execute([$line['currency_id']]);
    $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);

    echo "<tr><td>" . htmlspecialchars($acc['account_code'] . " - " . $acc['account_name_ar']) . "</td><td style='text-align:right'>" . number_format($line['debit'], 2) . "</td><td style='text-align:right'>" . number_format($line['credit'], 2) . "</td><td>" . htmlspecialchars($curr['currency_code']) . "</td></tr>";
}
echo "</table>";

// Step 3: Get account balances
echo "<h3>أرصدة الحسابات المتأثرة</h3>";
foreach ($journal_lines as $line) {
    $stmt_bal = $pdo->prepare("SELECT * FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
    $stmt_bal->execute([$line['account_id'], $line['currency_id']]);
    $bal = $stmt_bal->fetch(PDO::FETCH_ASSOC);
    
    $stmt_acc = $pdo->prepare("SELECT account_code, account_name_ar FROM unified_accounts WHERE id = ?");
    $stmt_acc->execute([$line['account_id']]);
    $acc = $stmt_acc->fetch(PDO::FETCH_ASSOC);

    $stmt_curr = $pdo->prepare("SELECT * FROM currencies WHERE id = ?");
    $stmt_curr->execute([$line['currency_id']]);
    $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);

    echo "<div style='border:1px solid #ccc; margin:10px 0; padding:10px;'>
            <h4>" . htmlspecialchars($acc['account_name_ar']) . " (" . htmlspecialchars($acc['account_code']) . ")</h4>
            <p>العملة: " . htmlspecialchars($curr['currency_name']) . "</p>
            <p>سعر الصرف: " . number_format($curr['exchange_rate'], 4) . "</p>
            <p>الرصيد الحالي: " . number_format($bal['current_balance'], 2) . "</p>
            <p>الرصيد الأساسي (YER): " . number_format($bal['current_balance_base'], 2) . "</p>
          </div>";
}
?>
