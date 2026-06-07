<?php
require_once 'includes/db.php';

echo "=== الحسابات ذات الأسماء المشوهة ===\n\n";

$stmt = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_name_ar LIKE '%?%' OR account_name_ar LIKE '%????%' ORDER BY id ASC");

$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($accounts)) {
    echo "✅ لم يتم العثور على أي حسابات ذات أسماء مشوهة!\n";
} else {
    foreach ($accounts as $acc) {
        echo "  ID: {$acc['id']}\n";
        echo "  Code: {$acc['account_code']}\n";
        echo "  Name: {$acc['account_name_ar']}\n";
        echo "---\n";
    }
}
?>