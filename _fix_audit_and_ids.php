<?php
require_once __DIR__ . '/includes/db.php';

echo "=== Fix audit_logs columns ===\n";
$existingCols = $pdo->query("SHOW COLUMNS FROM audit_logs")->fetchAll(PDO::FETCH_COLUMN, 0);
echo "Current columns: " . implode(', ', $existingCols) . "\n\n";

$mods = [
    "ADD COLUMN entity_type VARCHAR(100) NULL COMMENT 'نوع الكيان (customers, invoices, ...)' AFTER action_type",
    "ADD COLUMN entity_id INT(11) NULL COMMENT 'معرف الكيان في جدوله' AFTER entity_type",
    "ADD COLUMN branch_id INT(11) NULL COMMENT 'الفرع' AFTER entity_id",
    "ADD COLUMN user_agent VARCHAR(255) NULL AFTER user_id",
    "ADD COLUMN request_method VARCHAR(10) NULL AFTER ip_address",
    "ADD COLUMN route VARCHAR(255) NULL AFTER user_agent",
    "ADD COLUMN severity ENUM('info','warning','error','critical') NOT NULL DEFAULT 'info' AFTER route",
    "ADD COLUMN context JSON NULL AFTER new_value",
    "ADD KEY idx_audit_entity (entity_type, entity_id)",
    "ADD KEY idx_audit_severity (severity)",
    "ADD KEY idx_audit_branch (branch_id)",
];

$existingKeys = [];
$k = $pdo->query("SHOW INDEX FROM audit_logs")->fetchAll(PDO::FETCH_ASSOC);
foreach ($k as $idx) { $existingKeys[] = $idx['Key_name']; }

foreach ($mods as $mod) {
    $colName = null; $keyName = null;
    if (preg_match('/ADD COLUMN (\w+)/i', $mod, $m)) { $colName = $m[1]; }
    if (preg_match('/ADD KEY (\w+)/i', $mod, $m)) { $keyName = $m[1]; }

    if ($colName && in_array($colName, $existingCols, true)) { echo "  SKIP col $colName\n"; continue; }
    if ($keyName && in_array($keyName, $existingKeys, true)) { echo "  SKIP key $keyName\n"; continue; }

    try { $pdo->exec("ALTER TABLE audit_logs $mod"); echo "  OK  $colName$keyName\n"; }
    catch (Throwable $e) { echo "  WARN: " . trim($e->getMessage()) . "\n"; }
}

echo "\n=== Get real cashbox account_id ===\n";
$cashbox = $pdo->query("
    SELECT ua.id, ua.account_code, ua.account_name_ar
    FROM unified_accounts ua
    WHERE ua.parent_id = (SELECT id FROM unified_accounts WHERE account_code='11101' LIMIT 1)
      AND ua.is_active=1 AND ua.account_status='active'
    ORDER BY ua.account_code ASC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if ($cashbox) {
    echo "  Cashbox found: id={$cashbox['id']} code={$cashbox['account_code']} name={$cashbox['account_name_ar']}\n";
} else {
    echo "  No cashbox found, using any active asset account\n";
    $cashbox = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE is_active=1 AND account_status='active' AND account_type='asset' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    echo "  Fallback: id={$cashbox['id']}\n";
}

echo "\n=== Get service_id that has valid accounts ===\n";
$srv = $pdo->query("SELECT id, service_name, revenue_account_id, cost_account_id FROM services WHERE revenue_account_id IS NOT NULL AND status='active' ORDER BY id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($srv) {
    echo "  Service: id={$srv['id']} name={$srv['service_name']} rev={$srv['revenue_account_id']} cost={$srv['cost_account_id']}\n";
} else {
    $srv = ['id' => 0, 'service_name' => 'خدمات العمرة'];
    echo "  Using fallback service: id={$srv['id']}\n";
}

echo "\n";
file_put_contents(__DIR__ . '/_test_cash_fix.env.json', json_encode([
    'cashbox_id' => (int)$cashbox['id'],
    'service_id' => (int)$srv['id'],
    'service_name' => $srv['service_name'],
], JSON_UNESCAPED_UNICODE));
echo "Saved to _test_cash_fix.env.json\n";
