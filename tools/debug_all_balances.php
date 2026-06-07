<?php
require_once 'includes/db.php';

echo "<h1>تفاصيل جميع الأرصدة</h1>";

// Step 1: Get accounts by code
function getAccountByCode($code) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM unified_accounts WHERE account_code = ?");
    $stmt->execute([$code]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$cash_acc = getAccountByCode('11101001');
$supplier_acc = getAccountByCode('21101003');

echo "<h3>الحسابات المستهدفة</h3>";
echo "<ul>
        <li><strong>الصندوق الرئيسي:</strong> ID {$cash_acc['id']}, {$cash_acc['account_name_ar']}</li>
        <li><strong>المورد:</strong> ID {$supplier_acc['id']}, {$supplier_acc['account_name_ar']}</li>
      </ul>";

// Step 2: Get all balance rows for these accounts
echo "<h3>أرصدة الحسابات</h3>";
$stmt_bal = $pdo->prepare("SELECT ab.*, c.currency_code, c.currency_name, c.exchange_rate 
                          FROM account_balances_unified ab 
                          LEFT JOIN currencies c ON ab.currency_id = c.id 
                          WHERE account_id IN (?, ?)
                          ORDER BY account_id, currency_id, branch_id");
$stmt_bal->execute([$cash_acc['id'], $supplier_acc['id']]);
$balances = $stmt_bal->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' style='border-collapse: collapse; width:100%;'>
        <tr style='background: #eee;'>
            <th>الحساب</th>
            <th>الفرع</th>
            <th>العملة</th>
            <th>الرصيد الافتتاحي</th>
            <th>الرصيد الحالي</th>
            <th>الرصيد الأساسي</th>
            <th>سعر الصرف</th>
        </tr>";

foreach ($balances as $bal) {
    $acc = ($bal['account_id'] == $cash_acc['id']) ? $cash_acc : $supplier_acc;
    echo "<tr>
            <td>" . htmlspecialchars($acc['account_code'] . " - " . $acc['account_name_ar']) . "</td>
            <td>" . ($bal['branch_id'] ?? 'بدون فرع') . "</td>
            <td>" . htmlspecialchars($bal['currency_code'] . " (" . $bal['currency_name'] . ")") . "</td>
            <td style='text-align:right;'>" . number_format($bal['opening_balance'], 2) . "</td>
            <td style='text-align:right;'>" . number_format($bal['current_balance'], 2) . "</td>
            <td style='text-align:right;'>" . number_format($bal['current_balance_base'], 2) . "</td>
            <td>" . number_format($bal['exchange_rate'],4) . "</td>
          </tr>";
}
echo "</table>";

// Step3: All journal lines for these accounts
echo "<h3>جميع أسطر القيد لهذه الحسابات</h3>";
$stmt_jl = $pdo->prepare("SELECT jl.*, ft.transaction_number, ft.transaction_date, ft.status as trx_status
                          FROM journal_lines jl
                          LEFT JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
                          WHERE jl.account_id IN (?, ?)
                          ORDER BY jl.id DESC");
$stmt_jl->execute([$cash_acc['id'], $supplier_acc['id']]);
$jls = $stmt_jl->fetchAll(PDO::FETCH_ASSOC);

if (count($jls) >0) {
    echo "<table border='1' style='border-collapse: collapse; width:100%;'>
            <tr style='background: #eee;'>
                <th>رقم المعاملة</th>
                <th>تاريخ</th>
                <th>الحالة</th>
                <th>الحساب</th>
                <th>مدين</th>
                <th>دائن</th>
                <th>العملة</th>
            </tr>";

    foreach ($jls as $jl) {
        $acc = ($jl['account_id'] == $cash_acc['id']) ? $cash_acc : $supplier_acc;
        $stmt_curr = $pdo->prepare("SELECT currency_code FROM currencies WHERE id = ?");
        $stmt_curr->execute([$jl['currency_id']]);
        $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);

        echo "<tr>
                <td>" . htmlspecialchars($jl['transaction_number']) . "</td>
                <td>" . htmlspecialchars($jl['transaction_date']) . "</td>
                <td>" . htmlspecialchars($jl['trx_status']) . "</td>
                <td>" . htmlspecialchars($acc['account_code'] . " - " . $acc['account_name_ar']) . "</td>
                <td style='text-align:right;'>" . number_format($jl['debit'],2) . "</td>
                <td style='text-align:right;'>" . number_format($jl['credit'],2) . "</td>
                <td>" . htmlspecialchars($curr['currency_code']) . "</td>
              </tr>";
    }

    echo "</table>";
} else {
    echo "<p style='color:gray;'>لا توجد أسطر قيد لهذه الحسابات!</p>";
}
?>
