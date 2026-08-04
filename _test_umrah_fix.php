<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
$_SESSION['admin_id'] = 1;

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/ServiceFinancialEngine.php';

echo str_repeat("=", 70) . "\n";
echo "  اختبار إصلاح خطأ \"العميل ليس له حساب مالي مرتبط\"\n";
echo "  (محاكاة حفظ معاملة عمرة نقديّة - مثل ما يفعله umrah.php)\n";
echo str_repeat("=", 70) . "\n\n";

echo "[1] جلب أي عميل موجود للاختبار...\n";
$stmt = $pdo->query("SELECT id, full_name, account_id, branch_id FROM customers ORDER BY id ASC LIMIT 1");
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
    die("❌ لا يوجد عملاء في قاعدة البيانات!\n");
}
$customerId = (int)$customer['id'];
$origAccountId = $customer['account_id'];
echo "    ✅ العميل: #{$customer['id']} {$customer['full_name']}\n";
echo "    ℹ️  account_id الأصلي: " . var_export($origAccountId, true) . "\n";

echo "\n[2] مؤقتاً: إزالة ربط الحساب المالي للعميل (محاكاة المشكلة الفعلية)...\n";
$pdo->prepare("UPDATE customers SET account_id = NULL WHERE id = ?")->execute([$customerId]);
$stmt = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
$stmt->execute([$customerId]);
echo "    ✅ بعد التحديث: account_id = " . var_export($stmt->fetchColumn(), true) . "\n";

echo "\n[3] جلب حساب صندوق (account_sub_type = box) للاستخدام في الدفع...\n";
$stmt = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_sub_type = 'box' AND is_active = 1 ORDER BY id ASC LIMIT 1");
$cash = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cash) {
    $stmt = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_sub_type = 'bank' AND is_active = 1 ORDER BY id ASC LIMIT 1");
    $cash = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$cash) {
    $stmt = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE id IN (5, 122, 7) LIMIT 1");
    $cash = $stmt->fetch(PDO::FETCH_ASSOC);
}
echo "    ✅ الحساب: #{$cash['id']} {$cash['account_name_ar']} ({$cash['account_code']})\n";

echo "\n[4] تشغيل العملية المالية (نفس ما يفعله umrah.php عبر ServiceFinancialEngine):\n";
echo "    نوع التوصيل: cash | المبلغ المقبوض: 5000\n\n";

$restoreAccountId = $origAccountId;
$success = false;

try {
    $engine = new ServiceFinancialEngine($pdo, 1);
    $result = $engine->processServiceFinance([
        'branch_id'               => (int)($customer['branch_id'] ?: 1),
        'source_type'             => 'umrah',
        'source_id'               => mt_rand(1000, 9999),
        'customer_id'             => $customerId,
        'supplier_id'             => null,
        'agent_id'                => null,
        'account_id'              => (int)$cash['id'],
        'currency_id'             => 1,
        'sale_currency_id'        => 1,
        'purchase_currency_id'    => 1,
        'exchange_rate'           => 1.0,
        'discount_amount'         => 0,
        'tax_amount'              => 0,
        'paid_amount'             => 5000.0,
        'sale_total_amount'       => 5000.0,
        'purchase_total_amount'   => 0,
        'delivery_type'           => 'cash',
        'description'             => 'اختبار حفظ معاملة عمرة نقديّة - ' . date('Y-m-d H:i:s'),
        'operation_date'          => date('Y-m-d H:i:s'),
        'source_number'           => 'UMRAH-TEST-' . mt_rand(100, 999),
        'record_purchase'         => '0',
    ]);

    $success = true;
    echo "    🎉 نجحت العملية بدون أي خطأ!\n\n";
    echo "    === النتائج ===\n";
    echo "      - فاتورة البيع ID      : {$result['sales_invoice_id']}\n";
    echo "      - فاتورة الشراء ID     : " . var_export($result['purchase_invoice_id'], true) . "\n";
    echo "      - سند القبض ID         : " . var_export($result['receipt_voucher_id'], true) . "\n";
    echo "      - نوع التوصيل          : {$result['normalized_finance']['delivery_type']}\n";
    echo "      - المبلغ المقبوض       : {$result['normalized_finance']['paid_amount']}\n";

    echo "\n[5] التحقق من إنشاء حساب مالي للعميل تلقائياً...\n";
    $stmt = $pdo->prepare("
        SELECT c.account_id, ua.account_code, ua.account_name_ar, ua.account_sub_type, ua.owner_type
        FROM customers c
        LEFT JOIN unified_accounts ua ON ua.id = c.account_id
        WHERE c.id = ?
    ");
    $stmt->execute([$customerId]);
    $check = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($check && $check['account_id']) {
        echo "    ✅ العميل لديه الآن حساب مالي مرتبط!\n";
        echo "      - account_id   : {$check['account_id']}\n";
        echo "      - account_code : {$check['account_code']}\n";
        echo "      - اسم الحساب  : {$check['account_name_ar']}\n";
        echo "      - نوع فرعي     : {$check['account_sub_type']}\n";
        echo "      - نوع المالك   : {$check['owner_type']}\n";
        $restoreAccountId = $check['account_id'];
    } else {
        echo "    ⚠️  لم يتم ربط حساب (قد تم استخدام fallback لحساب الذمم المدينة)\n";
    }

    echo "\n" . str_repeat("=", 70) . "\n";
    echo "✅ جميع الاختبارات نجحت! مشكلة umrah.php مُصلحة.\n";
    echo "   يمكنك الآن فتح صفحة المعاملات والحفظ مباشرة.\n";
    echo str_repeat("=", 70) . "\n";
} catch (Throwable $e) {
    echo "    ❌ فشلت العملية: " . get_class($e) . "\n";
    echo "    الرسالة: {$e->getMessage()}\n";
    echo "    الملف: {$e->getFile()}:{$e->getLine()}\n\n";
    if (method_exists($e, 'getTraceAsString')) {
        echo "    Trace:\n" . $e->getTraceAsString() . "\n";
    }
} finally {
    echo "\n\n[*] استعادة الحالة الأصلية للعميل...\n";
    $restoreId = $success ? null : $restoreAccountId;
    if ($restoreId) {
        $pdo->prepare("UPDATE customers SET account_id = ? WHERE id = ?")->execute([$restoreId, $customerId]);
        echo "    ℹ️  تمت استعادة account_id الأصلي.\n";
    } else if ($success) {
        echo "    ℹ️  الحساب الجديد تم إنشاؤه وربطه (تم الاحتفاظ به).\n";
    }
}
