<?php
require_once 'includes/db.php';

echo "=== تحليل شجرة الحسابات ===\n\n";

echo "1. الحسابات بأسماء غير مقروءة (تحتوي على ?):\n";
$stmt_bad_names = $pdo->query("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE account_name_ar LIKE '%?%' ORDER BY account_code");
while ($row = $stmt_bad_names->fetch()) {
    echo "  ID: {$row['id']}, Code: {$row['account_code']}, Name: {$row['account_name_ar']}, Parent ID: {$row['parent_id']}\n";
}
echo "\n";

echo "2. الحسابات وآباؤها الحاليون:\n";
$stmt_all = $pdo->query("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts ORDER BY account_code");
$accounts = [];
while ($row = $stmt_all->fetch()) {
    $accounts[$row['id']] = $row;
    echo "  ID: {$row['id']}, Code: {$row['account_code']}, Name: {$row['account_name_ar']}, Parent ID: {$row['parent_id']}\n";
}
echo "\n";

echo "3. الحسابات التي تستخدم كـ parent_id ولكنها ليست موجودة:\n";
$all_ids = array_keys($accounts);
foreach ($accounts as $acc) {
    if ($acc['parent_id'] !== null && !in_array($acc['parent_id'], $all_ids)) {
        echo "  Account ID {$acc['id']} ({$acc['account_code']}) has invalid parent ID: {$acc['parent_id']}\n";
    }
}
echo "\n";

echo "=== انتهى التحليل ===\n";
?>