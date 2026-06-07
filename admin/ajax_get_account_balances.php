<?php
require_once '../includes/db.php';

if (isset($_GET['account_id'])) {
    $account_id = intval($_GET['account_id']);
    
    try {
        // حساب الرصيد الحقيقي مباشرة من journal_lines
        $stmt_balances = $pdo->prepare("
            SELECT 
                jl.currency_id,
                c.currency_name, 
                c.currency_symbol, 
                c.currency_code, 
                c.is_default,
                c.exchange_rate, 
                c.exchange_rate_sell,
                c.exchange_rate_buy,
                ua.normal_balance,
                ua.credit_limit_base,
                ua.debit_limit_base,
                SUM(jl.debit - jl.credit) AS current_balance,
                SUM((jl.debit - jl.credit) * COALESCE(c.exchange_rate, 1)) AS current_balance_base,
                0 AS opening_balance,
                0 AS opening_balance_base,
                0 AS debit_limit,
                0 AS credit_limit
            FROM journal_lines jl
            JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
            JOIN currencies c ON jl.currency_id = c.id 
            JOIN unified_accounts ua ON jl.account_id = ua.id
            WHERE jl.account_id = ? AND ft.status = 'posted'
            GROUP BY jl.currency_id
        ");
        $stmt_balances->execute([$account_id]);
        $balances = $stmt_balances->fetchAll(PDO::FETCH_ASSOC);
        
        // إذا لم يكن هناك حركات، نحضر فقط معلومات الحساب
        if (empty($balances)) {
            $stmt_account = $pdo->prepare("
                SELECT 
                    ua.normal_balance,
                    ua.credit_limit_base,
                    ua.debit_limit_base
                FROM unified_accounts ua 
                WHERE ua.id = ?
            ");
            $stmt_account->execute([$account_id]);
            $account = $stmt_account->fetch(PDO::FETCH_ASSOC);
            if ($account) {
                $balances[] = [
                    'currency_id' => null,
                    'currency_name' => null,
                    'currency_symbol' => null,
                    'currency_code' => null,
                    'is_default' => null,
                    'exchange_rate' => null,
                    'exchange_rate_sell' => null,
                    'exchange_rate_buy' => null,
                    'normal_balance' => $account['normal_balance'],
                    'credit_limit_base' => $account['credit_limit_base'],
                    'debit_limit_base' => $account['debit_limit_base'],
                    'current_balance' => 0,
                    'current_balance_base' => 0,
                    'opening_balance' => 0,
                    'opening_balance_base' => 0,
                    'debit_limit' => 0,
                    'credit_limit' => 0
                ];
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($balances);
    } catch (Exception $e) {
        http_response_code(500);
        error_log(basename(__FILE__) . ': ' . $e->getMessage());
        echo json_encode(['error' => 'حدث خطأ أثناء جلب أرصدة الحساب']);
    }
}

