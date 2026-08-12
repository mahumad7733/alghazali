<?php
require_once '../../includes/db.php';
require_once '../../includes/security.php';

$authenticatedUser = require_active_financial_user($pdo, 'financial_hub_view');

header('Content-Type: application/json');

if (!isset($_GET['account_id']) || !is_numeric($_GET['account_id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing account_id']);
    exit;
}

$account_id = (int)$_GET['account_id'];
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

try {
    // جلب آخر العمليات التي أثرت على هذا الحساب من قيود اليومية
    $branchFilter = '';
    $params = [$account_id];
    $role = strtolower((string)($authenticatedUser['role_name'] ?? ''));
    $isGlobal = in_array($role, ['admin', 'developer'], true) || strtolower((string)($authenticatedUser['user_type'] ?? '')) === 'developer';
    if (!$isGlobal) {
        $branchFilter = ' AND (ft.branch_id IS NULL OR ft.branch_id = ?)';
        $params[] = $authenticatedUser['branch_id'] !== null ? (int)$authenticatedUser['branch_id'] : 0;
    }
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
        WHERE jl.account_id = ? AND ft.status = 'posted'{$branchFilter}
        ORDER BY ft.transaction_date DESC, ft.id DESC
        LIMIT ?
    ");
    $params[] = $limit;
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'transactions' => $transactions]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
