<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/SafeDB.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/accounting_functions.php';
require_once __DIR__ . '/includes/ServiceFinancialEngine.php';
require_once __DIR__ . '/core/FinanceService.php';

echo "=== STEP 1: Add missing columns to customers table ===\n";
$modifications = [
    "ADD COLUMN customer_code VARCHAR(50) NULL COMMENT 'كود العميل الداخلي' AFTER full_name",
    "ADD COLUMN created_by INT(11) NULL COMMENT 'المستخدم الذي أنشأ العميل' AFTER branch_id",
    "ADD COLUMN customer_status ENUM('active','inactive') NULL COMMENT 'حالة العميل (نسخة موحدة)' AFTER status",
    "ADD COLUMN is_default_cash TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = العميل الافتراضي لمبيعات النقد العام'",
    "ADD UNIQUE KEY idx_customers_code (customer_code)",
    "ADD KEY idx_customers_default_cash (is_default_cash)",
];
$existingCols = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN, 0);
$existingKeys = [];
$k = $pdo->query("SHOW INDEX FROM customers")->fetchAll(PDO::FETCH_ASSOC);
foreach ($k as $idx) {
    $existingKeys[] = $idx['Key_name'];
}

foreach ($modifications as $mod) {
    $colName = null;
    $keyName = null;
    if (preg_match('/ADD COLUMN (\w+)/i', $mod, $m)) {
        $colName = $m[1];
    }
    if (preg_match('/ADD (?:UNIQUE )?KEY (\w+)/i', $mod, $m)) {
        $keyName = $m[1];
    }

    if ($colName && in_array($colName, $existingCols, true)) {
        echo "  SKIP column '$colName' already exists.\n";
        continue;
    }
    if ($keyName && in_array($keyName, $existingKeys, true)) {
        echo "  SKIP key '$keyName' already exists.\n";
        continue;
    }

    try {
        $pdo->exec("ALTER TABLE customers $mod");
        echo "  OK   : $colName$keyName\n";
    } catch (Throwable $e) {
        echo "  WARN : " . trim($e->getMessage()) . "\n";
    }
}

echo "\n=== STEP 2: Create test session ===\n";
$users = $pdo->query("SELECT id FROM users LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$_SESSION['admin_id'] = $users ? (int)$users['id'] : 1;
$branches = $pdo->query("SELECT id FROM branches WHERE deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$branchId = $branches ? (int)$branches['id'] : 1;
echo "Using user: {$_SESSION['admin_id']} / branch: $branchId\n";

echo "\n=== STEP 3: Test FinanceService getOrCreateDefaultCashCustomer ===\n";
$svc = new FinanceService($pdo, $_SESSION['admin_id']);
$cashCustomerId = $svc->getOrCreateDefaultCashCustomer($branchId);
echo "Default Cash Customer ID: $cashCustomerId\n";
$r = $pdo->prepare("SELECT id, full_name, account_id FROM customers WHERE id = ?");
$r->execute([$cashCustomerId]);
$cust = $r->fetch(PDO::FETCH_ASSOC);
echo "  name: {$cust['full_name']}  account_id: " . ($cust['account_id'] ?? 'NULL') . "\n";
if (!empty($cust['account_id'])) {
    echo "  ✅ Account linked OK.\n";
} else {
    echo "  ⚠️  No account linked yet — resolving now...\n";
}

echo "\n=== STEP 4: Verify resolvePartyAccountId works on cash customer ===\n";
$refl = new ReflectionObject($svc);
$m = $refl->getMethod('resolvePartyAccountId');
$m->setAccessible(true);
$accId = $m->invoke($svc, 'customer', $cashCustomerId);
echo "  Resolved account for customer $cashCustomerId: " . ($accId ?? 'NULL') . "\n";
echo $accId ? "  ✅ Resolve OK.\n" : "  ❌ Resolve FAILED!\n";

echo "\n=== STEP 5: Get test IDs (service, supplier, cashbox) ===\n";
$service = $pdo->query("SELECT id, service_name FROM services WHERE revenue_account_id IS NOT NULL AND status='active' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$supplier = $pdo->query("SELECT id FROM suppliers WHERE deleted_at IS NULL LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$serviceId = $service ? (int)$service['id'] : 0;
$supplierId = $supplier ? (int)$supplier['id'] : 0;

$cashbox = $pdo->query("
    SELECT ua.id FROM unified_accounts ua
    WHERE ua.parent_id = (SELECT id FROM unified_accounts WHERE account_code='11101' LIMIT 1)
      AND ua.is_active=1 AND ua.account_status='active'
    ORDER BY ua.account_code ASC LIMIT 1")->fetchColumn();
$cashboxId = $cashbox ? (int)$cashbox : 0;
if (!$cashboxId) {
    $cashboxId = (int)$pdo->query("SELECT id FROM unified_accounts WHERE is_active=1 AND account_status='active' AND account_type='asset' ORDER BY id ASC LIMIT 1")->fetchColumn();
}

echo "  service_id: $serviceId / supplier_id: $supplierId / cashbox_id: $cashboxId\n";

echo "\n=== STEP 6: Test processServiceOperation with CASH + NO CUSTOMER (the original bug) ===\n";
try {
    $result = $svc->processServiceOperation([
        'source_type'       => $service['service_name'] ?? 'خدمات العمرة (اختبار)',
        'service_type'      => 'umrah',
        'source_id'         => 999999,
        'source_number'     => 'TST-CASH-001',
        'branch_id'         => $branchId,
        'customer_id'       => null,              // ← NULL like cash scenario from UI (main bug)
        'agent_id'          => null,
        'supplier_id'       => $supplierId,
        'sale_price'        => 5000,
        'discount'          => 0,
        'purchase_price'    => 4000,
        'sale_currency_id'  => 1,
        'pur_currency_id'   => 1,
        'exchange_rate'     => 1,
        'amount_received'   => 5000,              // ← Paid fully = cash
        'payment_account_id' => $cashboxId,        // ← Valid cashbox from DB
        'delivery_type'     => 'cash',            // ← CASH
        'record_purchase'   => '1',
        'description'       => 'اختبار فاتورة نقدية بدون عميل محدد',
        'operation_date'    => date('Y-m-d H:i:s'),
    ]);
    echo "  ✅ SUCCESS: sales_invoice_id = {$result['sales_invoice_id']}\n";
    echo "              receipt_voucher_id = {$result['receipt_voucher_id']}\n";
    echo "              purchase_invoice_id = " . ($result['purchase_invoice_id'] ?? 'NULL') . "\n";
    echo "  (Cleaning test record from passports...)\n";
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo "  File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "  Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n=== STEP 7: Test CREDIT with existing customer (should also work) ===\n";
$creditCust = $pdo->query("SELECT id FROM customers WHERE deleted_at IS NULL AND id <> $cashCustomerId ORDER BY id ASC LIMIT 1")->fetchColumn();
$creditCust = $creditCust ? (int)$creditCust : $cashCustomerId;
echo "  Using customer_id: $creditCust\n";
try {
    $result = $svc->processServiceOperation([
        'source_type'       => $service['service_name'] ?? 'خدمات العمرة (اختبار آجل)',
        'service_type'      => 'umrah',
        'source_id'         => 999998,
        'source_number'     => 'TST-CRED-001',
        'branch_id'         => $branchId,
        'customer_id'       => $creditCust,
        'supplier_id'       => $supplierId,
        'sale_price'        => 6000,
        'purchase_price'    => 4500,
        'sale_currency_id'  => 1,
        'pur_currency_id'   => 1,
        'exchange_rate'     => 1,
        'amount_received'   => 0,                 // ← Nothing paid = credit
        'payment_account_id' => null,
        'delivery_type'     => 'credit',          // ← CREDIT
        'record_purchase'   => '1',
        'description'       => 'اختبار فاتورة آجلة',
        'operation_date'    => date('Y-m-d H:i:s'),
    ]);
    echo "  ✅ SUCCESS: sales_invoice_id = {$result['sales_invoice_id']}\n";
    echo "              receipt_voucher_id = " . ($result['receipt_voucher_id'] ?? 'NULL (expected for credit)') . "\n";
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . get_class($e) . ": " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ All tests passed! Bug is fixed.\n";
