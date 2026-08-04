<?php
require_once __DIR__ . '/includes/db.php';

echo "=== Add audit_logs columns required by FinanceService::writeAudit ===\n";
$existingCols = $pdo->query("SHOW COLUMNS FROM audit_logs")->fetchAll(PDO::FETCH_COLUMN, 0);

$required = [
    "ADD COLUMN entity_type VARCHAR(100) NULL COMMENT 'نوع الكيان' AFTER action",
    "ADD COLUMN entity_id INT(11) NULL COMMENT 'معرف الكيان' AFTER entity_type",
    "ADD COLUMN details_json JSON NULL COMMENT 'تفاصيل JSON' AFTER user_agent",
    "ADD KEY idx_audit_logs_entity (entity_type, entity_id)",
];

$existingKeys = [];
foreach ($pdo->query("SHOW INDEX FROM audit_logs")->fetchAll(PDO::FETCH_ASSOC) as $i) { $existingKeys[] = $i['Key_name']; }

foreach ($required as $mod) {
    $colName = null; $keyName = null;
    if (preg_match('/ADD COLUMN (\w+)/i', $mod, $m)) { $colName = $m[1]; }
    if (preg_match('/ADD KEY (\w+)/i', $mod, $m)) { $keyName = $m[1]; }

    if ($colName && in_array($colName, $existingCols, true)) { echo "  SKIP col $colName\n"; continue; }
    if ($keyName && in_array($keyName, $existingKeys, true)) { echo "  SKIP key $keyName\n"; continue; }

    try { $pdo->exec("ALTER TABLE audit_logs $mod"); echo "  OK  $colName$keyName\n"; }
    catch (Throwable $e) { echo "  WARN: " . trim($e->getMessage()) . "\n"; }
}

echo "\n=== ALSO: Update is_default_cash flag on the auto-created cash customer ===\n";
$pdo->exec("UPDATE customers SET is_default_cash = 1, customer_code = 'CASH-CUSTOMER', customer_status = 'active' WHERE full_name = 'مبيعات نقدية عام' AND deleted_at IS NULL LIMIT 1");
echo "  Rows affected: " . $pdo->query("SELECT ROW_COUNT()")->fetchColumn() . "\n";

echo "\nDone.\n";
