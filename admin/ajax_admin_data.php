<?php
require_once '../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');
$action = $_GET['action'] ?? '';

if ($action === 'get_agent') {
    $id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT a.*, ua.account_code as coa_code, ua.account_name_ar as coa_name
        FROM agents a
        LEFT JOIN unified_accounts ua ON a.account_id = ua.id
        WHERE a.id = ?
    ");
    $stmt->execute([$id]);
    $agent = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($agent) {
        echo json_encode($agent);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'الوكيل غير موجود']);
    }
    exit();
}

if ($action === 'get_branch') {
    $id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT b.*, ua.account_code as coa_code, ua.account_name_ar as coa_name
        FROM branches b
        LEFT JOIN unified_accounts ua ON b.account_id = ua.id
        WHERE b.id = ?
    ");
    $stmt->execute([$id]);
    $branch = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($branch) {
        echo json_encode($branch);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'الفرع غير موجود']);
    }
    exit();
}

if ($action === 'get_customer') {
    $id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT c.*, ua.account_code as coa_code, ua.account_name_ar as coa_name
        FROM customers c
        LEFT JOIN unified_accounts ua ON c.account_id = ua.id
        WHERE c.id = ?
    ");
    $stmt->execute([$id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($customer) {
        echo json_encode($customer);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'العميل غير موجود']);
    }
    exit();
}

if ($action === 'get_employee') {
    $id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT e.*, ua.account_code as coa_code, ua.account_name_ar as coa_name
        FROM employees e
        LEFT JOIN unified_accounts ua ON e.account_id = ua.id
        WHERE e.id = ?
    ");
    $stmt->execute([$id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($employee) {
        echo json_encode($employee);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'الموظف غير موجود']);
    }
    exit();
}

if ($action === 'get_supplier') {
    $id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT s.*, ua.account_code as coa_code, ua.account_name_ar as coa_name
        FROM suppliers s
        LEFT JOIN unified_accounts ua ON s.account_id = ua.id
        WHERE s.id = ?
    ");
    $stmt->execute([$id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($supplier) {
        echo json_encode($supplier);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'المورد غير موجود']);
    }
    exit();
}

if ($action === 'get_user') {
    $id = $_GET['id'] ?? 0;
    $stmt = $pdo->prepare("
        SELECT u.*, ua.account_code as coa_code, ua.account_name_ar as coa_name
        FROM users u
        LEFT JOIN unified_accounts ua ON u.account_id = ua.id
        WHERE u.id = ?
    ");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        echo json_encode($user);
    } else {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'المستخدم غير موجود']);
    }
    exit();
}

/**
 * جلب الكيانات حسب النوع للاختيار (العميل، الوكيل، الفرع، الموظف، المورد، البنك، الصندوق، المصروف)
 */
if ($action === 'get_entities_by_type') {
    $type = $_GET['type'] ?? '';
    $results = [];

    switch ($type) {
        case 'customer':
            $results = $pdo->query("SELECT id, full_name as name FROM customers WHERE status = 'active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'agent':
            $results = $pdo->query("SELECT id, agent_name as name FROM agents WHERE status = 'active' AND deleted_at IS NULL ORDER BY agent_name")->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'branch':
            $results = $pdo->query("SELECT id, branch_name as name FROM branches WHERE status = 'active' AND deleted_at IS NULL ORDER BY branch_name")->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'employee':
            $results = $pdo->query("SELECT id, full_name as name FROM employees WHERE status = 'active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'supplier':
            $results = $pdo->query("SELECT id, supplier_name as name FROM suppliers WHERE status = 'active' AND deleted_at IS NULL ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'bank':
            $results = $pdo->query("SELECT id, account_name_ar as name FROM unified_accounts WHERE account_code LIKE '11102%' ORDER BY account_name_ar")->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'box':
            $results = $pdo->query("SELECT id, account_name_ar as name FROM unified_accounts WHERE account_code LIKE '11101%' ORDER BY account_name_ar")->fetchAll(PDO::FETCH_ASSOC);
            break;
        case 'expense':
            $results = $pdo->query("SELECT id, CONCAT(account_code, ' - ', account_name_ar) as name FROM unified_accounts WHERE account_type = 'expense' ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
            break;
    }

    echo json_encode($results);
    exit();
}

