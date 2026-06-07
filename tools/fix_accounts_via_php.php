<?php
require_once 'includes/db.php';

echo "=== Fixing Accounts via PHP (Correct Encoding) ===\n\n";

try {
    $pdo->beginTransaction();

    // 1. Update existing bad names
    $fixes = [
        '113' => 'سلف وعهد الموظفين',
        '11301' => 'سلف الموظفين',
        '11302' => 'عهد الموظفين',
        '21103' => 'مستحقات الموظفين',
        '21103001' => 'رواتب مستحقة',
        '21103002' => 'بدلات مستحقة',
        '502' => 'المصروفات الوظيفية',
        '50201' => 'الرواتب والأجور',
        '50201001' => 'رواتب الإدارة',
        '50201002' => 'رواتب الموظفين'
    ];

    foreach ($fixes as $code => $name) {
        $stmt = $pdo->prepare("UPDATE unified_accounts SET account_name_ar = ? WHERE account_code = ?");
        $stmt->execute([$name, $code]);
        echo "Updated account $code to name: $name\n";
    }

    // 2. Fix Parent IDs
    $parent_fixes = [
        '11301' => '113',
        '11302' => '113',
        '21103001' => '21103',
        '21103002' => '21103',
        '50201' => '502',
        '50201001' => '50201',
        '50201002' => '50201'
    ];

    foreach ($parent_fixes as $child_code => $parent_code) {
        $stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $stmt_parent->execute([$parent_code]);
        $parent_id = $stmt_parent->fetchColumn();
        if ($parent_id) {
            $stmt_update = $pdo->prepare("UPDATE unified_accounts SET parent_id = ? WHERE account_code = ?");
            $stmt_update->execute([$parent_id, $child_code]);
            echo "Updated account $child_code parent ID to $parent_id (from $parent_code)\n";
        }
    }

    $pdo->commit();
    echo "\n✅ All fixes applied successfully!\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>