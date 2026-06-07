<?php
require_once 'includes/db.php';

echo "<h1>تحقق من مصادر الرصيد</h1>";

function getAccountInfo($accountCode) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_code = ?");
    $stmt->execute([$accountCode]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function showAccountDetails($accountCode) {
    global $pdo;
    $account = getAccountInfo($accountCode);
    
    if (!$account) {
        echo "<h2 style='color:red'>Account $accountCode not found!</h2>";
        return;
    }
    
    echo "<h2>الحساب: " . htmlspecialchars($account['account_name_ar']) . " (" . htmlspecialchars($account['account_code']) . ") - ID: " . $account['id'] . "</h2>";
    
    // Show current balances
    echo "<h3>الأرصدة الحالية</h3>";
    $stmt_bal = $pdo->prepare("SELECT abu.*, c.currency_code, c.currency_name 
                               FROM account_balances_unified abu 
                               LEFT JOIN currencies c ON abu.currency_id = c.id 
                               WHERE abu.account_id = ?");
    $stmt_bal->execute([$account['id']]);
    $balances = $stmt_bal->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' style='border-collapse:collapse'><tr><th>ID</th><th>العملة</th><th>الرصيد الافتتاحي</th><th>الرصيد الحالي</th><th>الرصيد الأساسي</th></tr>";
    foreach ($balances as $b) {
        echo "<tr><td>" . $b['id'] . "</td><td>" . htmlspecialchars($b['currency_code'] . " - " . $b['currency_name']) . "</td><td style='text-align:right'>" . number_format($b['opening_balance'],2) . "</td><td style='text-align:right'>" . number_format($b['current_balance'],2) . "</td><td style='text-align:right'>" . number_format($b['current_balance_base'],2) . "</td></tr>";
    }
    echo "</table>";
    
    // Show all journal lines for this account
    echo "<h3>القيود اليومية (Journal Lines)</h3>";
    $stmt_jl = $pdo->prepare("
        SELECT jl.*, ft.transaction_number, ft.reference_number, ft.transaction_date, ft.status
        FROM journal_lines jl
        LEFT JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        WHERE jl.account_id = ?
        ORDER BY jl.id
    ");
    $stmt_jl->execute([$account['id']]);
    $journalLines = $stmt_jl->fetchAll(PDO::FETCH_ASSOC);
    if (count($journalLines) >0) {
        echo "<table border='1' style='border-collapse:collapse; width:100%'><tr><th>ID</th><th>رقم المعاملة</th><th>المرجع</th><th>التاريخ</th><th>الحالة</th><th>مدين</th><th>دائن</th></tr>";
        $totalDebit =0; $totalCredit =0;
        foreach ($journalLines as $jl) {
            echo "<tr>";
            echo "<td>" . $jl['id'] . "</td>";
            echo "<td>" . htmlspecialchars($jl['transaction_number']) . "</td>";
            echo "<td>" . htmlspecialchars($jl['reference_number']) . "</td>";
            echo "<td>" . htmlspecialchars($jl['transaction_date']) . "</td>";
            echo "<td>" . htmlspecialchars($jl['status']) . "</td>";
            echo "<td style='text-align:right'>" . number_format($jl['debit'],2) . "</td>";
            echo "<td style='text-align:right'>" . number_format($jl['credit'],2) . "</td>";
            echo "</tr>";
            $totalDebit += $jl['debit'];
            $totalCredit += $jl['credit'];
        }
        echo "<tr style='background:yellow'><td colspan='5' style='text-align:right'><strong>الإجمالي:</strong></td><td style='text-align:right'><strong>" . number_format($totalDebit,2) . "</strong></td><td style='text-align:right'><strong>" . number_format($totalCredit,2) . "</strong></td></tr>";
        echo "</table>";
    } else {
        echo "<p style='color:gray'>لا توجد قيود يومية لهذا الحساب!</p>";
    }
    
    // Show audit logs for this account (or related transactions)
    echo "<h3>سجلات التدقيق (Audit Logs)</h3>";
    $stmt_audit = $pdo->prepare("SELECT * FROM audit_logs WHERE table_name IN ('financial_transactions','journal_lines','unified_accounts','account_balances_unified') ORDER BY id DESC LIMIT 50");
    $stmt_audit->execute();
    $logs = $stmt_audit->fetchAll(PDO::FETCH_ASSOC);
    if (count($logs) >0) {
        echo "<table border='1' style='border-collapse:collapse; width:100%'><tr><th>ID</th><th>العملية</th><th>الجدول</th><th>السجل</th><th>الوقت</th></tr>";
        foreach ($logs as $log) {
            echo "<tr><td>" . $log['id'] . "</td><td>" . htmlspecialchars($log['action']) . "</td><td>" . htmlspecialchars($log['table_name']) . "</td><td>" . $log['record_id'] . "</td><td>" . $log['created_at'] . "</td></tr>";
        }
        echo "</table>";
    }
}

// Check the two accounts
showAccountDetails('11101001');
showAccountDetails('21101003');

echo "<h2>إعادة حساب جميع الأرصدة</h2>";
echo "<p><a href='recalculate_all_balances.php'>اضغط هنا لإعادة حساب جميع الأرصدة من الصفر!</a></p>";
?>