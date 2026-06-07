<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';

// دالة مساعدة لجلب الحسابات تحت حساب أب معين
function get_accounts_under_parent($pdo, $parent_account_code, $entity_type = null) {
    // جلب معرف الحساب الأب
    $stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
    $stmt_parent->execute([$parent_account_code]);
    $parent_id = $stmt_parent->fetchColumn();
    echo "<h3>Parent account code '$parent_account_code' → Parent ID: " . var_export($parent_id, true) . "</h3>";
    if (!$parent_id) return [];
    
    // جلب الحسابات تحت هذا الأب
    $stmt = $pdo->prepare("
        SELECT ua.id, ua.account_code, ua.account_name_ar,
               (SELECT id FROM customers WHERE account_id = ua.id LIMIT 1) as customer_id,
               (SELECT id FROM agents WHERE account_id = ua.id LIMIT 1) as agent_id,
               (SELECT id FROM suppliers WHERE account_id = ua.id LIMIT 1) as supplier_id
        FROM unified_accounts ua
        WHERE ua.parent_id = ? AND ua.account_status = 'active'
        ORDER BY ua.account_code ASC
    ");
    $stmt->execute([$parent_id]);
    $accounts = [];
    while ($row = $stmt->fetch()) {
        $row['display_name'] = $row['account_code'] . ' - ' . $row['account_name_ar'];
        $row['name'] = $row['account_name_ar'];
        $accounts[] = $row;
    }
    return $accounts;
}

$cashboxes_entities = get_accounts_under_parent($pdo, '11101');
echo "<h3>\$cashboxes_entities:</h3>";
echo "<pre>";
var_dump($cashboxes_entities);
echo "</pre>";

$banks_entities = get_accounts_under_parent($pdo, '11102');
echo "<h3>\$banks_entities:</h3>";
echo "<pre>";
var_dump($banks_entities);
echo "</pre>";

$customers_entities = get_accounts_under_parent($pdo, '11201');
echo "<h3>\$customers_entities:</h3>";
echo "<pre>";
var_dump($customers_entities);
echo "</pre>";

$agents_entities = get_accounts_under_parent($pdo, '11203');
echo "<h3>\$agents_entities:</h3>";
echo "<pre>";
var_dump($agents_entities);
echo "</pre>";
?>