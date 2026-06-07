<?php
require_once '../includes/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    die(json_encode(['error' => 'Unauthorized']));
}

$account_type = $_GET['account_type'] ?? $_GET['type'] ?? '';
$currency_id = $_GET['currency_id'] ?? null;
$response = [];

switch ($account_type) {
    case 'customers':
    case 'customer':
        $sql = "SELECT c.id, c.account_id, c.full_name as name, ua.account_code 
                FROM customers c 
                LEFT JOIN unified_accounts ua ON c.account_id = ua.id 
                WHERE c.deleted_at IS NULL ORDER BY c.full_name";
        $stmt = $pdo->query($sql);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'agents':
    case 'agent':
        $sql = "SELECT a.id, a.account_id, a.agent_name as name, ua.account_code 
                FROM agents a 
                LEFT JOIN unified_accounts ua ON a.account_id = ua.id 
                WHERE a.deleted_at IS NULL ORDER BY a.agent_name";
        $stmt = $pdo->query($sql);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'branches':
    case 'branch':
        $sql = "SELECT b.id, b.account_id, b.branch_name as name, ua.account_code 
                FROM branches b 
                LEFT JOIN unified_accounts ua ON b.account_id = ua.id 
                WHERE b.deleted_at IS NULL ORDER BY b.branch_name";
        $stmt = $pdo->query($sql);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'employees':
    case 'employee':
        $sql = "SELECT e.id, e.account_id, e.full_name as name, ua.account_code 
                FROM employees e 
                LEFT JOIN unified_accounts ua ON e.account_id = ua.id 
                WHERE e.deleted_at IS NULL ORDER BY e.full_name";
        $stmt = $pdo->query($sql);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'suppliers':
    case 'supplier':
        $sql = "SELECT s.id, s.account_id, s.supplier_name as name, ua.account_code 
                FROM suppliers s 
                LEFT JOIN unified_accounts ua ON s.account_id = ua.id 
                WHERE s.deleted_at IS NULL ORDER BY s.supplier_name";
        $stmt = $pdo->query($sql);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'banks':
    case 'cash_funds':
    case 'cash_bank':
        $parent_code = ($account_type == 'banks') ? '11102' : (($account_type == 'cash_funds') ? '11101' : '111');
        $sql = "SELECT id, id as account_id, account_name_ar as name, account_code 
                FROM unified_accounts WHERE account_code LIKE '$parent_code%' AND account_status = 'active' ORDER BY account_name_ar";
        $stmt = $pdo->query($sql);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
    case 'expenses':
        $sql = "SELECT id, id as account_id, account_name_ar as name, account_code 
                FROM unified_accounts WHERE account_type = 'expense' AND account_status = 'active' ORDER BY account_code";
        $stmt = $pdo->query($sql);
        $response = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
}

// إضافة رصيد الحساب إذا تم تحديد العملة
if ($currency_id && !empty($response)) {
    foreach ($response as &$item) {
        $acc_id = $item['account_id'];
        if ($acc_id) {
            $stmt_bal = $pdo->prepare("SELECT current_balance FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
            $stmt_bal->execute([$acc_id, $currency_id]);
            $item['balance'] = $stmt_bal->fetchColumn() ?: 0;
        } else {
            $item['balance'] = 0;
        }
    }
}

echo json_encode($response);

