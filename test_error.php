
<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
echo "Testing...\n";
require_once __DIR__ . '/includes/db.php';
echo "DB connected!\n";
require_once __DIR__ . '/includes/functions.php';
echo "Functions loaded!\n";
require_once __DIR__ . '/includes/accounting_functions.php';
echo "Accounting loaded!\n";
echo "\nTesting unified_accounts table:\n";
$stmt = $pdo->query("SELECT id, account_code, account_name_ar, name FROM unified_accounts LIMIT 5");
while($row = $stmt->fetch()) {
    print_r($row);
}
echo "\nAll looks good!\n";
