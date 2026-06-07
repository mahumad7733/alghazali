<?php
require_once 'includes/db.php';

echo "<h1>تحقق من رصيد الحساب 11201001 - العميل أحمد علي</h1>";

try {
    // Step 1: Get the account info
    $stmt_account = $pdo->prepare("SELECT * FROM unified_accounts WHERE account_code = ?");
    $stmt_account->execute(['11201001']);
    $account = $stmt_account->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        echo "<p style='color:red;'>الحساب غير موجود!</p>";
        exit;
    }

    echo "<h3>معلومات الحساب:</h3>";
    echo "<pre>";
    print_r($account);
    echo "</pre>";

    $account_id = $account['id'];

    // Step 2: Get the account balances
    echo "<h3>أرصدة الحساب:</h3>";
    $stmt_balances = $pdo->prepare("SELECT * FROM account_balances_unified WHERE account_id = ?");
    $stmt_balances->execute([$account_id]);
    $balances = $stmt_balances->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1' style='border-collapse: collapse; margin:10px 0;'>";
    echo "<tr style='background: #eee;'><th>ID</th><th>العملة</th><th>الرصيد الافتتاحي</th><th>الرصيد الحالي</th><th>الرصيد بالعملة الأساسية</th></tr>";
    foreach ($balances as $b) {
        $stmt_curr = $pdo->prepare("SELECT * FROM currencies WHERE id = ?");
        $stmt_curr->execute([$b['currency_id']]);
        $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
        echo "<tr>";
        echo "<td>{$b['id']}</td>";
        echo "<td>" . htmlspecialchars($curr['currency_name'] . " (" . htmlspecialchars($curr['currency_code']) . ")</td>";
        echo "<td>" . number_format($b['opening_balance'], 2) . "</td>";
        echo "<td>" . number_format($b['current_balance'], 2) . "</td>";
        echo "<td>" . number_format($b['current_balance_base'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // Step3: Get all journal lines for this account
    echo "<h3>خطوط الدفتر اليومي (journal_lines):</h3>";
    $stmt_journal = $pdo->prepare("
        SELECT 
            jl.*, 
            ft.transaction_number, ft.reference_number, ft.status as ft_status
        FROM journal_lines jl
        JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        WHERE jl.account_id = ?
        ORDER BY jl.id
    ");
    $stmt_journal->execute([$account_id]);
    $journal_lines = $stmt_journal->fetchAll(PDO::FETCH_ASSOC);

    if (empty($journal_lines)) {
        echo "<table border='1' style='border-collapse: collapse; margin:10px 0; width: 100%;'>";
        echo "<tr style='background: #eee;'>
                <th>ID</th>
                <th>رقم المعاملة</th>
                <th>المرجع</th>
                <th>حالة المعاملة</th>
                <th>مدين</th>
                <th>دائن</th>
                <th>العملة</th>
            </tr>";
        $total_debit = 0;
        $total_credit = 0;
        foreach ($journal_lines as $jl) {
            $total_debit += $jl['debit'];
            $total_credit += $jl['credit'];

            $stmt_curr_jl = $pdo->prepare("SELECT * FROM currencies WHERE id = ?");
            $stmt_curr_jl->execute([$jl['currency_id']]);
            $curr_jl = $stmt_curr_jl->fetch(PDO::FETCH_ASSOC);

            echo "<tr>";
            echo "<td>{$jl['id']}</td>";
            echo "<td>" . htmlspecialchars($jl['transaction_number']) . "</td>";
            echo "<td>" . htmlspecialchars($jl['reference_number']) . "</td>";
            echo "<td>" . htmlspecialchars($jl['ft_status']) . "</td>";
            echo "<td style='text-align:right;'>" . number_format($jl['debit'],2) . "</td>";
            echo "<td style='text-align:right;'>" . number_format($jl['credit'],2) . "</td>";
            echo "<td>" . htmlspecialchars($curr_jl['currency_code']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        echo "<p style='font-size:1.1rem;'><strong>إجمالي المدين:</strong>: " . number_format($total_debit,2) . "</p>";
        echo "<p style='font-size:1.1rem;'><strong>إجمالي الدائن:</strong>: " . number_format($total_credit,2) . "</p>";
        echo "<p style='font-size:1.1rem;'><strong>الفرق:</strong> " . number_format($total_debit - $total_credit,2) . "</p>";
    } else {
        echo "<p>لا توجد خطوط دفتر يومي لهذا الحساب!</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red;'>خطأ: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>