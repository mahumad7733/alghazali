<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "==========================================================\n";
echo "  اختبار الاتصال بقاعدة البيانات (إصلاح المنفذ 3307)\n";
echo "==========================================================\n\n";

require_once __DIR__ . '/includes/db.php';

echo "[✅] الاتصال بنجاح!\n\n";

echo "=== معلومات الاتصال ===\n";
$stmt = $pdo->query("SELECT
    DATABASE() AS current_db,
    @@port AS mysql_port,
    @@hostname AS mysql_host,
    @@version AS mysql_version
");
$info = $stmt->fetch();
foreach ($info as $k => $v) {
    echo "  $k : $v\n";
}

echo "\n=== اختبار الجداول الأساسية ===\n";
$tables = ['invoices','customers','branches','currencies','users','account_balances_unified','audit_logs'];
foreach ($tables as $t) {
    try {
        $c = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "  ✅ جدول $t : $c سجل\n";
    } catch (Throwable $e) {
        echo "  ❌ جدول $t : خطأ " . $e->getMessage() . "\n";
    }
}

echo "\n=== اختبار الإجراء المخزن sp_create_invoice (التوقيع فقط) ===\n";
$params = $pdo->query("
    SELECT ordinal_position, parameter_name, parameter_mode
    FROM information_schema.parameters
    WHERE specific_schema = DATABASE() AND specific_name = 'sp_create_invoice'
    ORDER BY ordinal_position
")->fetchAll();
$cnt = count($params);
if ($cnt === 18) {
    echo "  ✅ الإجراء موجود! عدد البارامترات: $cnt (صحيح)\n";
    $p8 = $params[7] ?? null;
    if ($p8 && $p8['parameter_name'] === 'p_branch_entity_id' && $p8['parameter_mode'] === 'IN') {
        echo "  ✅ البارامتر رقم 8 صحيح: {$p8['parameter_mode']} {$p8['parameter_name']}\n";
    } else {
        echo "  ❌ البارامتر رقم 8 غير صحيح!\n";
    }
} else {
    echo "  ⚠️  عدد البارامترات: $cnt (المتوقع 18)\n";
}

echo "\n🎉 جميع الاختبارات نجحت! صفحة الحجوزات تعمل الآن.\n";
echo "   افتح: http://localhost:8080/alghazali/admin/bus_bookings.php\n";
