<?php
require_once '../../includes/db.php';
require_once '../../includes/security.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$authenticatedUser = require_active_financial_user($pdo, 'financial_hub_view');
$role = strtolower((string)($authenticatedUser['role_name'] ?? ''));
$isGlobal = in_array($role, ['admin', 'developer'], true) || strtolower((string)($authenticatedUser['user_type'] ?? '')) === 'developer';
$invoiceBranchFilter = $isGlobal ? '' : ' AND (i.branch_id IS NULL OR i.branch_id = ?)';

$payer_id = $_GET['customer_id'] ?? 0; // keeping the param name for compatibility
$currency_id = $_GET['currency_id'] ?? 0;
$type = $_GET['type'] ?? 'sales';
$type_payer = $_GET['type_payer'] ?? 'customer';

// Whitelist validation for security (prevents SQL injection)
$allowed_tables = [
    'customer' => 'customers',
    'agent'    => 'agents',
    'supplier' => 'suppliers',
    'branch'   => 'branches',
    'employee' => 'employees'
];

$allowed_id_columns = [
    'customer' => 'customer_id',
    'agent'    => 'agent_id',
    'supplier' => 'supplier_id',
    'branch'   => 'branch_entity_id',
    'employee' => 'employee_id'
];

// Validate and whitelist the inputs
if (!isset($allowed_tables[$type_payer]) || !isset($allowed_id_columns[$type_payer])) {
    header('Content-Type: application/json');
    echo json_encode([
        'invoices' => [],
        'other_currencies' => [],
        'error' => 'Invalid payer type'
    ]);
    exit;
}

$table = $allowed_tables[$type_payer];
$id_column = $allowed_id_columns[$type_payer];
$voucher_id = $_GET['voucher_id'] ?? 0;

$invoices = [];
$other_currencies = [];
$error_message = null;

// التحقق من المدخلات الأساسية
if (empty($payer_id) || empty($currency_id)) {
    header('Content-Type: application/json');
    echo json_encode([
        'invoices' => [],
        'other_currencies' => [],
        'error' => 'Missing payer_id or currency_id'
    ]);
    exit;
}

try {
    // 1. جلب معرف الجهة (Entity ID) ومعرف الحساب (Account ID) مرة واحدة
    // تحاول البحث أولاً بواسطة id (entity id)، إذا لم يعثر، نبحث بواسطة account_id
    $entity_id = null;
    $account_id = $payer_id; // Default: treat payer_id as account_id
    
    $stmt_entity = $pdo->prepare("SELECT id, account_id FROM $table WHERE (id = ? OR account_id = ?) AND status = 'active' AND deleted_at IS NULL LIMIT 1");
    $stmt_entity->execute([$payer_id, $payer_id]);
    $entity_data = $stmt_entity->fetch();

    if ($entity_data) {
        $entity_id = $entity_data['id'];
        $account_id = $entity_data['account_id'];
    }

    // 2. جلب الفواتير للعملة المختارة - استعلام مبسط
    // For customers, use customer_account_id; for suppliers, supplier_account_id; etc.
    $account_id_column = '';
    if ($type_payer === 'customer') {
        $account_id_column = 'customer_account_id';
    } elseif ($type_payer === 'supplier') {
        $account_id_column = 'supplier_account_id';
    }
    
    $sql = "
        SELECT
            i.id,
            i.invoice_number,
            i.invoice_date,
            i.net_amount,
            c.currency_name,
            c.currency_symbol,
            COALESCE(received.total_received, 0) as total_received,
            IFNULL(pa.allocated_amount, 0) as current_allocated,
            (i.net_amount - COALESCE(received.total_received, 0) - IFNULL(pa.allocated_amount, 0)) as remaining
        FROM invoices i
        JOIN currencies c ON i.currency_id = c.id
        LEFT JOIN (
            SELECT
                pa2.invoice_id,
                SUM(pa2.allocated_amount) as total_received
            FROM payment_allocations pa2
            JOIN financial_transactions ft2 ON pa2.financial_transaction_id = ft2.id
            WHERE ft2.status = 'posted'
            GROUP BY pa2.invoice_id
        ) received ON i.id = received.invoice_id
        LEFT JOIN payment_allocations pa ON i.id = pa.invoice_id AND pa.financial_transaction_id = ?
        WHERE (i.account_id = ? " . 
            ($entity_id ? " OR i.$id_column = ?" : "") . 
            ($account_id_column ? " OR i.$account_id_column = ?" : "") . 
        ")
        AND i.currency_id = ?
        AND i.invoice_category = ?
        AND i.invoice_status = 'posted'
        {$invoiceBranchFilter}
        AND (i.net_amount - COALESCE(received.total_received, 0)) > 0
        GROUP BY i.id
        ORDER BY i.invoice_date ASC
    ";

    $stmt = $pdo->prepare($sql);
    $execute_params = [
        $voucher_id, // ? - للـ LEFT JOIN payment_allocations
        $account_id, // ? - للـ account_id
    ];
    if ($entity_id) {
        $execute_params[] = $entity_id; // ? - للـ $id_column
    }
    if ($account_id_column) {
        $execute_params[] = $account_id; // ? - للـ $account_id_column
    }
    $execute_params[] = $currency_id; // ? - للـ currency_id
    $execute_params[] = $type; // ? - للـ invoice_category
    if (!$isGlobal) $execute_params[] = (int)($authenticatedUser['branch_id'] ?? 0);
    $stmt->execute($execute_params);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. جلب ملخص العملات الأخرى - استعلام مبسط
    $sql_other = "
        SELECT
            c.currency_name,
            c.currency_symbol,
            COUNT(i.id) as count,
            SUM(i.net_amount - COALESCE(received.total_received, 0)) as total_remaining
        FROM invoices i
        JOIN currencies c ON i.currency_id = c.id
        LEFT JOIN (
            SELECT
                pa3.invoice_id,
                SUM(pa3.allocated_amount) as total_received
            FROM payment_allocations pa3
            JOIN financial_transactions ft3 ON pa3.financial_transaction_id = ft3.id
            WHERE ft3.status = 'posted'
            GROUP BY pa3.invoice_id
        ) received ON i.id = received.invoice_id
        WHERE (i.account_id = ? " . 
            ($entity_id ? " OR i.$id_column = ?" : "") . 
            ($account_id_column ? " OR i.$account_id_column = ?" : "") . 
        ")
        AND i.currency_id != ?
        AND i.invoice_category = ?
        AND i.invoice_status = 'posted'
        {$invoiceBranchFilter}
        AND (i.net_amount - COALESCE(received.total_received, 0)) > 0
        GROUP BY i.currency_id
    ";

    $stmt_other = $pdo->prepare($sql_other);
    $other_params = [
        $account_id, // ? - للـ account_id
    ];
    if ($entity_id) {
        $other_params[] = $entity_id; // ? - للـ $id_column
    }
    if ($account_id_column) {
        $other_params[] = $account_id; // ? - للـ $account_id_column
    }
    $other_params[] = $currency_id; // ? - للـ currency_id
    $other_params[] = $type; // ? - للـ invoice_category
    if (!$isGlobal) $other_params[] = (int)($authenticatedUser['branch_id'] ?? 0);
    $stmt_other->execute($other_params);
    $other_currencies = $stmt_other->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Error in get_unpaid_invoices: ' . $e->getMessage());
    $error_message = 'Database error: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode([
    'invoices' => $invoices,
    'other_currencies' => $other_currencies,
    'error' => $error_message,
    'debug' => [
        'entity_id' => $entity_id,
        'account_id' => $account_id,
        'currency_id' => $currency_id,
        'type' => $type,
        'type_payer' => $type_payer,
        'invoice_count' => count($invoices)
    ]
]);
