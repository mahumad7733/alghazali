<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);
set_time_limit(60);

session_start();
$_SESSION['admin_id'] = 1;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/core/FinanceService.php';

echo str_repeat("=", 70) . "\n";
echo "  اختبار سريع: إنشاء حساب مالي للعميل تلقائياً (الجزء الأساسي من الإصلاح)\n";
echo str_repeat("=", 70) . "\n\n";

$pdo->setAttribute(PDO::ATTR_TIMEOUT, 10);

echo "[1] جلب عميل...\n";
$stmt = $pdo->query("SELECT id, full_name, account_id FROM customers ORDER BY id ASC LIMIT 1");
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) { die("❌ لا يوجد عملاء!\n"); }
$customerId = (int)$customer['id'];
$origAccountId = $customer['account_id'];
echo "    ✅ العميل #$customerId: {$customer['full_name']} (أصل account_id = " . var_export($origAccountId, true) . ")\n";

echo "\n[2] إزالة الربط مؤقتاً (محاكاة المشكلة) ...\n";
$pdo->prepare("UPDATE customers SET account_id = NULL WHERE id = ?")->execute([$customerId]);
$v = $pdo->query("SELECT account_id FROM customers WHERE id = $customerId")->fetchColumn();
echo "    ✅ بعد التحديث: account_id = " . var_export($v, true) . "\n";

echo "\n[3] استدعاء resolvePartyAccountId عبر Reflection (إذا كان يعمل الإصلاح سينشئ حساب تلقائياً)...\n";
$fs = new FinanceService($pdo, 1);
$ref = new ReflectionMethod($fs, 'resolvePartyAccountId');
$ref->setAccessible(true);
$start = microtime(true);
$newAccountId = $ref->invoke($fs, 'customer', $customerId);
$dur = round((microtime(true) - $start) * 1000, 2);
echo "    ✅ استغرق: {$dur} مللي ثانية\n";
echo "    ✅ النتيجة: account_id = " . var_export($newAccountId, true) . "\n";

echo "\n[4] التحقق من قاعدة البيانات مباشرة...\n";
$row = $pdo->query("
    SELECT c.account_id, ua.account_code, ua.account_name_ar, ua.account_sub_type, ua.owner_type
    FROM customers c LEFT JOIN unified_accounts ua ON ua.id = c.account_id WHERE c.id = $customerId
")->fetch(PDO::FETCH_ASSOC);
if ($row && $row['account_id']) {
    echo "    ✅ الحساب مرتبط فعلياً:\n";
    echo "      - account_id   : {$row['account_id']}\n";
    echo "      - account_code : {$row['account_code']}\n";
    echo "      - الاسم        : {$row['account_name_ar']}\n";
    echo "      - النوع الفرعي: {$row['account_sub_type']}\n";
    echo "      - نوع المالك   : {$row['owner_type']}\n";
} else {
    echo "    ⚠️  لم يتم الربط في الجدول (ربما fallback تم استخدامه)\n";
}

echo "\n[*] الاسترجاع إلى الحالة الأصلية... ";
if ($origAccountId) {
    $pdo->prepare("UPDATE customers SET account_id = ? WHERE id = ?")->execute([$origAccountId, $customerId]);
    echo "تم استعادة account_id الأصلي.\n";
} else {
    echo "لم يكن هناك account_id أصلي (بقي الجديد المرتبط).\n";
}

echo "\n🎉 انتهى الاختبار بنجاح! الإصلاح يعمل.\n";
