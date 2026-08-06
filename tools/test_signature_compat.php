<?php
/**
 * اختبار مباشر لاستدعاءات الإجراءات بنفس عدد الأرجام الذي يرسله PHP
 * الهدف: التأكد من عدم ظهور خطأ 1318 Incorrect number of arguments
 */

$env = is_file(__DIR__ . '/../.env') ? parse_ini_file(__DIR__ . '/../.env') : [];
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';
$db   = $env['DB_NAME'] ?? 'alghazali';
$charset = 'utf8mb4';

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=$charset", $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
// استخدام نفس إعدادات الاتصال في التطبيق الحقيقي (db.php:86)
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

echo "===================================\n";
echo "اختبار توافق استدعاءات الإجراءات\n";
echo "===================================\n\n";

// --- 1. اختبار sp_create_invoice بـ 18 معاملاً (تماماً مثل accounting_functions.php) ---
echo "[1] CALL sp_create_invoice(17 IN + 1 OUT = 18 إجمالي) ... ";
try {
    $stmt = $pdo->prepare("CALL sp_create_invoice(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, @iid)");
    $stmt->execute([
        'sales',      // p_invoice_category
        1,            // p_branch_id
        'umrah',      // p_source_type
        1,            // p_source_id
        1,            // p_customer_id
        NULL,         // p_supplier_id
        NULL,         // p_agent_id
        1,            // p_service_id (branch_entity_id)
        1,            // p_currency_id
        1500.00,      // p_total_amount
        50.00,        // p_discount
        1000.00,      // p_cost_amount
        'cash',       // p_payment_type
        'اختبار توافق إجراء الفاتورة', // p_description
        1,            // p_created_by
        NULL,         // p_cost_center_id
        'INV-TEST-' . time(), // p_invoice_number (رقم حقيقي للتجربة)
    ]);
    $stmt->closeCursor();
    $res = $pdo->query("SELECT @iid AS inv_id")->fetch();
    $invoice_id = (int)$res['inv_id'];
    if ($invoice_id > 0) {
        echo "✅ نجاح (invoice_id = $invoice_id)\n";
    } else {
        echo "⚠️  نجاح الاستدعاء لكن invoice_id = 0\n";
    }
} catch (Exception $e) {
    echo "❌ فشل: " . $e->getMessage() . "\n";
    die("توقف عند الاختبار الأول\n");
}

// --- 2. اختبار sp_post_invoice بـ 2 معامل (تماماً مثل PHP) ---
echo "[2] CALL sp_post_invoice(invoice_id, posted_by) = 2 ... ";
if ($invoice_id > 0) {
    try {
        $stmt = $pdo->prepare("CALL sp_post_invoice(?,?)");
        $stmt->execute([$invoice_id, 1]);
        $stmt->closeCursor();
        $s = $pdo->query("SELECT invoice_status, posted_by FROM invoices WHERE id = $invoice_id");
        $r = $s->fetch();
        if ($r && $r['invoice_status'] === 'posted') {
            echo "✅ نجاح (invoice_status = posted, posted_by = {$r['posted_by']})\n";
        } else {
            echo "⚠️  نجاح الاستدعاء لكن الحالة غير صحيحة: " . var_export($r, true) . "\n";
        }
    } catch (Exception $e) {
        echo "❌ فشل: " . $e->getMessage() . "\n";
    }
} else {
    echo "⏭️  تم التخطي (لا توجد فاتورة جديدة)\n";
}

// --- 3. اختبار sp_create_receipt_voucher بـ 14 معاملاً (12 IN + 2 OUT) ---
echo "[3] CALL sp_create_receipt_voucher(12 IN + 2 OUT = 14 إجمالي) ... ";
try {
    $stmt = $pdo->prepare("CALL sp_create_receipt_voucher(?,?,?,?,?,?,?,?,NULL,?,?,NULL,@vid,@vnum)");
    $stmt->execute([
        1,          // branch_id
        'customer', // reference_type (final_party_type)
        1,          // reference_id (final_party_id)
        200.00,     // amount
        1,          // currency_id
        1.0,        // exchange_rate (equivalent_amount)
        1,          // financial_account_id (cash_bank)
        1,          // party_account_id
        'اختبار سند قبض', // description
        1,          // admin_id (created_by)
    ]);
    $stmt->closeCursor();
    $res = $pdo->query("SELECT @vid AS v_id, @vnum AS v_num")->fetch();
    if ((int)$res['v_id'] > 0) {
        echo "✅ نجاح (id={$res['v_id']}, num={$res['v_num']})\n";
    } else {
        echo "⚠️  نجاح الاستدعاء لكن المعرف = 0\n";
    }
} catch (Exception $e) {
    echo "❌ فشل: " . $e->getMessage() . "\n";
}

// --- 4. اختبار sp_create_payment_voucher بـ 14 معاملاً ---
echo "[4] CALL sp_create_payment_voucher(12 IN + 2 OUT = 14 إجمالي) ... ";
try {
    $stmt = $pdo->prepare("CALL sp_create_payment_voucher(?,?,?,?,?,?,?,?,NULL,?,?,NULL,@vid,@vnum)");
    $stmt->execute([
        1,            // branch_id
        'supplier',   // reference_type
        1,            // reference_id
        150.00,       // amount
        1,            // currency_id
        1.0,          // equivalent_amount
        1,            // financial_account_id
        1,            // party_account_id
        'اختبار سند صرف', // description
        1,            // created_by
    ]);
    $stmt->closeCursor();
    $res = $pdo->query("SELECT @vid AS v_id, @vnum AS v_num")->fetch();
    if ((int)$res['v_id'] > 0) {
        echo "✅ نجاح (id={$res['v_id']}, num={$res['v_num']})\n";
    } else {
        echo "⚠️  نجاح الاستدعاء لكن المعرف = 0\n";
    }
} catch (Exception $e) {
    echo "❌ فشل: " . $e->getMessage() . "\n";
}

echo "\n===================================\n";
echo "🏁 تم الانتهاء من الاختبارات. إذا ظهرت علامة ✅ فوق كل اختبار، فهذا يعني\n";
echo "   أن خطأ 1318 (Incorrect number of arguments) قد اُحلّ بشكل كامل!\n";
echo "===================================\n";
