<?php
require 'header.php';

// حساب الأرصدة الحقيقية مباشرة من journal_lines لكل حساب (مثل كشف الحساب)
$stmt_real_balances = $pdo->query("
    SELECT 
        jl.account_id, 
        jl.currency_id,
        SUM(jl.debit - jl.credit) as net_balance,
        SUM((jl.debit - jl.credit) * COALESCE(c.exchange_rate, 1)) as net_balance_base
    FROM journal_lines jl
    JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
    LEFT JOIN currencies c ON jl.currency_id = c.id
    WHERE ft.status = 'posted'
    GROUP BY jl.account_id, jl.currency_id
");
$real_balances_raw = $stmt_real_balances->fetchAll(PDO::FETCH_ASSOC);

$real_balances = [];
$real_balances_base = [];
foreach ($real_balances_raw as $b) {
    $account_id = $b['account_id'];
    if (!isset($real_balances[$account_id])) {
        $real_balances[$account_id] = [];
        $real_balances_base[$account_id] = 0;
    }
    $real_balances[$account_id][$b['currency_id']] = $b['net_balance'];
    $real_balances_base[$account_id] += $b['net_balance_base'];
}

// جلب جميع الحسابات لبناء الشجرة من الجدول الموحد مع الأرصدة التجميعية (مقومة بالعملة الأساسية)
$all_chart_accounts = $pdo->query("
    SELECT coa.*, 0 as direct_unified_balance
    FROM unified_accounts coa
    WHERE coa.account_status = 'active' OR coa.account_status = 'dormant'
    ORDER BY account_code
")->fetchAll();

// تحديث الرصيد المباشر لكل حساب من الأرصدة الحقيقية
foreach ($all_chart_accounts as &$acc) {
    $acc['direct_unified_balance'] = $real_balances_base[$acc['id']] ?? 0;
}
unset($acc);

// debug: print all_chart_accounts with id, account_code, parent_id, direct_unified_balance
echo "<pre>all_chart_accounts (id, account_code, parent_id, direct_unified_balance):\n";
foreach ($all_chart_accounts as $acc) {
    echo "id: {$acc['id']}, code: {$acc['account_code']}, parent_id: {$acc['parent_id']}, direct: {$acc['direct_unified_balance']}\n";
}

// Now run calculateAggregateBalances
function calculateAggregateBalances(&$accounts) {
    $grouped = [];
    $idToAccount = [];
    
    foreach ($accounts as &$acc) {
        $idToAccount[$acc['id']] = &$acc;
        $p_id = $acc['parent_id'] ? (int)$acc['parent_id'] : null;
        $grouped[$p_id][] = &$acc;
        $acc['current_balance'] = (float)$acc['direct_unified_balance'];
    }
    unset($acc);
    
    $calculateRecursive = function($parentId = null) use (&$calculateRecursive, &$grouped, &$idToAccount) {
        if (!isset($grouped[$parentId])) return;
        
        foreach ($grouped[$parentId] as &$account) {
            $calculateRecursive($account['id']);
            if (isset($grouped[$account['id']])) {
                foreach ($grouped[$account['id']] as &$child) {
                    $account['current_balance'] += $child['current_balance'];
                }
                unset($child);
            }
        }
        unset($account);
    };
    
    $calculateRecursive(null);
}
calculateAggregateBalances($all_chart_accounts);

echo "\n\nAfter calculateAggregateBalances:\n";
foreach ($all_chart_accounts as $acc) {
    echo "id: {$acc['id']}, code: {$acc['account_code']}, parent_id: {$acc['parent_id']}, current: {$acc['current_balance']}\n";
}

echo "</pre>";
?>