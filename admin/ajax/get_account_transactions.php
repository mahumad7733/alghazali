<?php
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_GET['account_id']) || !is_numeric($_GET['account_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing account_id']);
    exit;
}

$account_id = (int)$_GET['account_id'];
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

try {
    // جلب آخر العمليات التي أثرت على هذا الحساب من قيود اليومية
    $stmt = $pdo->prepare("
        SELECT 
            ft.transaction_number,
            ft.transaction_date,
            ft.transaction_type,
            ft.description as main_description,
            jl.description as line_description,
            jl.debit,
            jl.credit,
            c.currency_code,
            c.currency_symbol,
            u.full_name as creator_name
        FROM journal_lines jl
        JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
        JOIN currencies c ON jl.currency_id = c.id
        LEFT JOIN users u ON ft.created_by = u.id
        WHERE jl.account_id = ? AND ft.status = 'posted'
        ORDER BY ft.transaction_date DESC, ft.id DESC
        LIMIT ?
    ");
    $stmt->execute([$account_id, $limit]);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'transactions' => $transactions]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>