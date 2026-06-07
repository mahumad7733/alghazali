<?php
require_once 'includes/db.php';

echo "=== البحث عن معرفات الموردين بناءً على أكواد الحسابات ===\n\n";

$account_codes = ['21101001', '21101002', '21101004'];

foreach ($account_codes as $code) {
    $stmt = $pdo->prepare("
        SELECT ua.id as account_id, ua.account_code, ua.account_name_ar,
               s.id as supplier_id, s.supplier_name
        FROM unified_accounts ua
        LEFT JOIN suppliers s ON ua.id = s.account_id
        WHERE ua.account_code = ?
    ");
    $stmt->execute([$code]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "=== كود الحساب: $code ===\n";
        echo "  اسم الحساب: {$result['account_name_ar']}\n";
        echo "  معرف الحساب: {$result['account_id']}\n";
        echo "  معرف المورد: " . ($result['supplier_id'] ?: 'غير مرتبط') . "\n";
        echo "  اسم المورد: " . ($result['supplier_name'] ?: 'غير موجود') . "\n\n";
    } else {
        echo "❌ لم يتم العثور على حساب بهذا الكود: $code\n\n";
    }
}
?>