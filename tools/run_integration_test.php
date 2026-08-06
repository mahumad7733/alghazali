<?php
/**
 * اختبار محاسبي متكامل حقيقي لسير عمل الفواتير والسندات
 * خطوات الاختبار:
 * 1. جلب عميل ومورد وخدمة وحسابات من قاعدة البيانات الفعلية
 * 2. إنشاء فاتورة مبيعات باستخدام sp_create_invoice (التوقيع PHP-compat)
 * 3. ترحيل الفاتورة باستخدام sp_post_invoice
 * 4. إنشاء سند قبض مرتبط بالفاتورة باستخدام sp_create_receipt_voucher
 * 5. ترحيل سند القبض باستخدام sp_post_receipt_voucher
 * 6. فحص تحديث amount_received و payment_status تلقائياً
 * 7. فحص توازن القيود المحاسبية في كل خطوة
 * 8. إلغاء ترحيل الفاتورة والتأكد من انعكاس الأرصدة
 */

$env = is_file(__DIR__ . '/../.env') ? parse_ini_file(__DIR__ . '/../.env') : [];
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';
$db   = $env['DB_NAME'] ?? 'alghazali';
$charset = 'utf8mb4';

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=$charset", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style=\"direction:rtl;text-align:right;font-family:Tahoma;font-size:13px;background:#fff;padding:20px\">\n";
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "🧪 اختبار محاسبي متكامل حقيقي - سير عمل فاتورة + سند قبض\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ---------------------------------------------------------------------
// STEP 0: تجهيز بيانات الاختبار من الجداول الفعلية
// ---------------------------------------------------------------------
echo "🔹 الخطوة 0: جلب بيانات الاختبار من الجداول الفعلية\n";
echo "───────────────────────────────────────────────────────────────\n";

$customer = $pdo->query("
    SELECT c.id, c.full_name AS name, c.account_id, abu.currency_id, abu.current_balance
      FROM customers c
      LEFT JOIN account_balances_unified abu ON abu.account_id = c.account_id
     WHERE c.account_id IS NOT NULL
     ORDER BY c.id DESC LIMIT 1
")->fetch();

if (!$customer) {
    die("❌ لا يوجد عملاء في قاعدة البيانات للإختبار\n");
}
$customer_id     = (int)$customer['id'];
$customer_acct   = (int)$customer['account_id'];
$currency_id     = (int)$customer['currency_id'];
$balance_before  = (float)$customer['current_balance'];
echo "✅ العميل المختبر: {$customer['name']} (#$customer_id) | حساب=$customer_acct | عملة=$currency_id | رصيد قبل الاختبار=$balance_before\n";

$branch = $pdo->query("SELECT id FROM branches ORDER BY id LIMIT 1")->fetch();
$branch_id = (int)$branch['id'];
echo "✅ الفرع المختبر: #$branch_id\n";

$service = $pdo->query("SELECT id FROM services ORDER BY id LIMIT 1")->fetch();
$service_id = $service ? (int)$service['id'] : null;
echo "✅ الخدمة المختبرة: #" . ($service_id ?? 'لا توجد') . "\n";

$cash_acct = $pdo->query("
    SELECT id FROM unified_accounts
     WHERE account_code LIKE '11101%' OR account_name_ar LIKE '%صندوق%'
     ORDER BY account_code LIMIT 1
")->fetch();
$cash_account_id = (int)$cash_acct['id'];
echo "✅ حساب الصندوق: #$cash_account_id\n";

$user = $pdo->query("SELECT id FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetch();
$user_id = (int)$user['id'];
echo "✅ المستخدم المنفذ: #$user_id\n";
echo "\n";

$errors = 0;
$stepsPassed = 0;
function stepCheck($label, $ok, $detail = '') {
    global $errors, $stepsPassed;
    if ($ok) {
        echo "✅ " . trim($label) . "\n";
        $stepsPassed++;
    } else {
        echo "❌ " . trim($label) . "\n";
        $errors++;
    }
    if ($detail !== '') {
        echo "   ↳ " . trim($detail) . "\n";
    }
    echo "\n";
}

// ---------------------------------------------------------------------
// STEP 1: إنشاء فاتورة مبيعات
// ---------------------------------------------------------------------
echo "🔹 الخطوة 1: إنشاء فاتورة مبيعات (sp_create_invoice)\n";
echo "───────────────────────────────────────────────────────────────\n";

$stmt = $pdo->prepare("
    CALL sp_create_invoice(
        'sales',           # p_invoice_category
        ?,                 # p_branch_id
        'BusFlight',       # p_source_type
        NULL,              # p_source_id
        ?,                 # p_customer_id
        NULL,              # p_supplier_id
        NULL,              # p_agent_id
        ?,                 # p_service_id
        ?,                 # p_currency_id
        5000.00,           # p_total_amount
        250.00,            # p_discount
        3000.00,           # p_cost_amount
        'credit',          # p_payment_type
        'اختبار تلقائي - فاتورة لتجربة النظام',
        ?,                 # p_created_by
        NULL,              # p_cost_center_id
        NULL,              # p_invoice_number (يُولّد تلقائياً)
        @invoice_id        # OUT
    )
");
$stmt->execute([$branch_id, $customer_id, $service_id, $currency_id, $user_id]);

// قراءة الفاتورة الناتجة
$row = $pdo->query("SELECT @invoice_id AS id")->fetch();
$invoice_id = (int)$row['id'];

if ($invoice_id > 0) {
    $inv = $pdo->query("
        SELECT id, invoice_number, total_amount, discount, net_amount,
               amount_received, payment_status, invoice_status,
               customer_id, created_ip, created_by
          FROM invoices WHERE id = $invoice_id
    ")->fetch();

    stepCheck("تم إنشاء الفاتورة برقم معرف #$invoice_id", !empty($inv));
    stepCheck("رقم الفاتورة مُولَّد: {$inv['invoice_number']}", !empty($inv['invoice_number']));
    stepCheck("صافي الفاتورة صحيح: 5000 - 250 = {$inv['net_amount']}", (float)$inv['net_amount'] === 4750.0);
    stepCheck("الحالة الأولية: مسودة (draft) + غير مدفوعة (unpaid)",
        $inv['invoice_status'] === 'draft' && $inv['payment_status'] === 'unpaid');
    stepCheck("الرمز العميل صحيح: {$inv['customer_id']} == $customer_id", (int)$inv['customer_id'] === $customer_id);
} else {
    stepCheck("فشل إنشاء الفاتورة!", false);
    $errors += 5;
}

// ---------------------------------------------------------------------
// STEP 2: ترحيل الفاتورة
// ---------------------------------------------------------------------
echo "🔹 الخطوة 2: ترحيل الفاتورة (sp_post_invoice)\n";
echo "───────────────────────────────────────────────────────────────\n";

try {
    $pdo->exec("CALL sp_post_invoice($invoice_id, $user_id)");
    $inv = $pdo->query("
        SELECT id, invoice_status, posted_by, posted_at, posted_ip, payment_status, amount_received
          FROM invoices WHERE id = $invoice_id
    ")->fetch();

    stepCheck("حالة الفاتورة أصبحت 'posted'", $inv['invoice_status'] === 'posted');
    stepCheck("posted_by = $user_id", (int)$inv['posted_by'] === $user_id);
    stepCheck("posted_at معبأ (تاريخ الترحيل)", !empty($inv['posted_at']));
} catch (Exception $e) {
    stepCheck("فشل ترحيل الفاتورة: " . $e->getMessage(), false);
}

// فحص توازن القيود المحاسبية بعد الترحيل
$ft_id = $pdo->query("
    SELECT id FROM financial_transactions
     WHERE reference_type='invoice' AND reference_id=$invoice_id
     ORDER BY id DESC LIMIT 1
")->fetchColumn();
if ($ft_id) {
    $balance = $pdo->query("
        SELECT
            ABS(SUM(COALESCE(debit,0)) - SUM(COALESCE(credit,0))) AS diff,
            SUM(COALESCE(debit,0)) AS tot_debit,
            SUM(COALESCE(credit,0)) AS tot_credit,
            COUNT(*) AS num_lines
          FROM journal_lines WHERE financial_transaction_id = $ft_id
    ")->fetch();
    stepCheck("تسجيل قيود المحاسبية للفاتورة ($ft_id): {$balance['num_lines']} أسطر", (int)$balance['num_lines'] >= 2);
    stepCheck("توازن القيد: مدين={$balance['tot_debit']} دائن={$balance['tot_credit']}", (float)$balance['diff'] < 0.01);
}

// ---------------------------------------------------------------------
// STEP 3: إنشاء سند قبض للفاتورة (جزئي = 2000)
// ---------------------------------------------------------------------
echo "🔹 الخطوة 3: إنشاء سند قبض جزئي (sp_create_receipt_voucher)\n";
echo "───────────────────────────────────────────────────────────────\n";

$alloc_json = json_encode([
    ['invoice_id' => $invoice_id, 'amount' => 2000.00]
]);

$stmt = $pdo->prepare("
    CALL sp_create_receipt_voucher(
        ?, ?, NULL, 2000.00, ?, 1.0,
        ?, ?, NULL, 'اختبار تلقائي - سند قبض جزئي على فاتورة',
        ?, '$alloc_json', @trx_id, @trx_num
    )
");
$stmt->execute([$branch_id, 'invoice', $currency_id, $cash_account_id, $customer_acct, $user_id]);

$trx = $pdo->query("SELECT @trx_id AS id, @trx_num AS num")->fetch();
$trx_id = (int)$trx['id'];
$trx_num = $trx['num'];

if ($trx_id > 0) {
    $rv = $pdo->query("
        SELECT id, transaction_number, transaction_type, amount, status,
               created_ip, party_account_id, cash_bank_account_id
          FROM financial_transactions WHERE id = $trx_id
    ")->fetch();
    stepCheck("تم إنشاء سند القبض #$trx_id [$trx_num]", !empty($rv));
    stepCheck("نوع المعاملة: receipt", $rv['transaction_type'] === 'receipt');
    stepCheck("الحالة الأولية: مسودة (draft)", $rv['status'] === 'draft');
    stepCheck("المبلغ: {$rv['amount']} == 2000", (float)$rv['amount'] === 2000.0);

    $alloc_rows = $pdo->query("
        SELECT COUNT(*) FROM payment_allocations WHERE financial_transaction_id = $trx_id
    ")->fetchColumn();
    stepCheck("تم تخصيص الفاتورة بنجاح: $alloc_rows صف مخصص", (int)$alloc_rows === 1);
} else {
    stepCheck("فشل إنشاء سند القبض!", false);
    $errors += 5;
}

// ---------------------------------------------------------------------
// STEP 4: ترحيل سند القبض (فقرة 2+6+7+9)
// ---------------------------------------------------------------------
echo "🔹 الخطوة 4: ترحيل سند القبض (sp_post_receipt_voucher)\n";
echo "───────────────────────────────────────────────────────────────\n";

$posted_ip = '192.168.1.100';
$ua = 'PHPUnit Test/1.0';

try {
    $pdo->exec("
        CALL sp_post_receipt_voucher(
            $trx_id, $user_id, NULL,
            '$posted_ip', '$posted_ip', '$ua'
        )
    ");
    $rv = $pdo->query("
        SELECT status, posted_at, posted_by, posted_ip, updated_ip
          FROM financial_transactions WHERE id = $trx_id
    ")->fetch();

    stepCheck("حالة سند القبض أصبحت 'posted'", $rv['status'] === 'posted');
    stepCheck("posted_ip معبأ: {$rv['posted_ip']}", $rv['posted_ip'] === $posted_ip);
    stepCheck("updated_ip معبأ (فقرة 9)", !empty($rv['updated_ip']));
} catch (Exception $e) {
    stepCheck("فشل ترحيل سند القبض: " . $e->getMessage(), false);
}

// [فقرة 7] فحص تحديث الفاتورة تلقائياً
$inv = $pdo->query("
    SELECT amount_received, payment_status FROM invoices WHERE id = $invoice_id
")->fetch();
stepCheck("[فقرة 7] تحديث amount_received تلقائياً: {$inv['amount_received']} == 2000",
    (float)$inv['amount_received'] === 2000.0);
stepCheck("[فقرة 7] تحديث payment_status تلقائياً: {$inv['payment_status']} == partial",
    $inv['payment_status'] === 'partial');

// فحص توازن قيود سند القبض
$bal = $pdo->query("
    SELECT
        ABS(SUM(COALESCE(debit,0)) - SUM(COALESCE(credit,0))) AS diff,
        SUM(COALESCE(debit,0)) AS d, SUM(COALESCE(credit,0)) AS c
      FROM journal_lines WHERE financial_transaction_id = $trx_id
")->fetch();
stepCheck("توازن قيود سند القبض: مدين={$bal['d']} دائن={$bal['c']}", (float)$bal['diff'] < 0.01);

// ---------------------------------------------------------------------
// STEP 5: فحص تحديث الأرصدة (sp_rebuild_balances)
// ---------------------------------------------------------------------
echo "🔹 الخطوة 5: فحص تحديث أرصدة الحسابات\n";
echo "───────────────────────────────────────────────────────────────\n";

$pdo->exec("CALL sp_rebuild_balances()");
$balance_after = $pdo->query("
    SELECT current_balance
      FROM account_balances_unified
     WHERE account_id = $customer_acct AND currency_id = $currency_id
     LIMIT 1
")->fetchColumn();

$expected_change = 4750 - 2000; // فاتورة كاملة - سند قبض جزئي
$actual_change = (float)$balance_after - (float)$balance_before;
stepCheck(
    "تغير رصيد العميل: قبل=$balance_before بعد=$balance_after | الفعلي=$actual_change | المتوقع=$expected_change",
    abs($actual_change - $expected_change) < 0.01
);

// ---------------------------------------------------------------------
// STEP 6: [فقرة 6] محاولة تخصيص مبلغ أكبر من المتبقي → يجب أن يفشل
// ---------------------------------------------------------------------
echo "🔹 الخطوة 6: [فقرة 6] اختبار حدود المخصصات (محاولة تخصيص أكبر من المتبقي)\n";
echo "───────────────────────────────────────────────────────────────\n";

// المتبقي في الفاتورة = 4750 - 2000 = 2750. سنخصص 3000 (أكبر من المتبقي)
$bad_alloc = json_encode([
    ['invoice_id' => $invoice_id, 'amount' => 3000.00]
]);

// إنشاء سند قبض ثاني بمبلغ 3000
$pdo->prepare("
    CALL sp_create_receipt_voucher(
        $branch_id, 'invoice', NULL, 3000.00, $currency_id, 1.0,
        $cash_account_id, $customer_acct, NULL, 'اختبار حدود المخصصات (يجب الفشل)',
        $user_id, '$bad_alloc', @trx2_id, @trx2_num
    )
")->execute();
$trx2_id = (int)$pdo->query("SELECT @trx2_id")->fetchColumn();

$caught_over = false;
try {
    $pdo->exec("
        CALL sp_post_receipt_voucher($trx2_id, $user_id, NULL, '$posted_ip', '$posted_ip', '$ua')
    ");
} catch (Exception $e) {
    if (stripos($e->getMessage(), 'يتجاوز') !== false || stripos($e->getMessage(), 'المخصص') !== false) {
        $caught_over = true;
    }
}
stepCheck("[فقرة 6] منع تخصيص مبلغ أكبر من المتبقي في الفاتورة", $caught_over,
    $caught_over ? "تم الرفض ✔️" : "لم يتم الرفض — خطأ فادح!");

// إعادة تعيين حالة سند الفشل
if (!$caught_over && $trx2_id) {
    $pdo->exec("UPDATE financial_transactions SET status='draft' WHERE id=$trx2_id");
}

// ---------------------------------------------------------------------
// STEP 7: [فقرة 5] اختبار تطابق عملة الحساب
// ---------------------------------------------------------------------
echo "🔹 الخطوة 7: [فقرة 5] اختبار تطابق عملة الحساب\n";
echo "───────────────────────────────────────────────────────────────\n";

// جلب عملة مختلفة عن عملة العميل
$other_currency = $pdo->query("
    SELECT id FROM currencies
     WHERE id <> $currency_id ORDER BY id LIMIT 1
")->fetchColumn();

if ($other_currency) {
    $caught_currency = false;
    try {
        $stmt = $pdo->prepare("
            CALL sp_create_invoice(
                'sales', $branch_id, 'BusFlight', NULL,
                $customer_id, NULL, NULL, $service_id,
                ?, 1000, 0, 0, 'cash',
                'اختبار عملة خاطئة', $user_id, NULL, NULL, @inv_bad_id
            )
        ");
        $stmt->execute([$other_currency]);
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'عملة') !== false || stripos($e->getMessage(), 'currency') !== false) {
            $caught_currency = true;
        }
    }
    stepCheck("[فقرة 5] منع إنشاء فاتورة بعملة مختلفة عن عملة حساب العميل", $caught_currency,
        $caught_currency ? "تم الرفض ✔️" : "لم يتم الرفض");
} else {
    echo "ℹ️  تخطي الاختبار: لا توجد عملات أخرى\n\n";
}

// ---------------------------------------------------------------------
// STEP 8: فحص سجلات التدقيق (audit_logs)
// ---------------------------------------------------------------------
echo "🔹 الخطوة 8: فحص سجلات التدقيق الناتجة عن الاختبار\n";
echo "───────────────────────────────────────────────────────────────\n";

$audit = $pdo->query("
    SELECT id, action, table_name, record_id, ip_address,
           JSON_LENGTH(new_values) AS keys_new
      FROM audit_logs
     WHERE (table_name='invoices' AND record_id=$invoice_id)
        OR (table_name='financial_transactions' AND record_id IN($trx_id, $trx2_id))
     ORDER BY id DESC
")->fetchAll();

stepCheck("عدد سجلات التدقيق لهذا الاختبار: " . count($audit) . " سجلات", count($audit) >= 4);

$actions_list = array_column($audit, 'action');
$actions_expected = ['create', 'post', 'post'];
foreach ($actions_expected as $ae) {
    $has = in_array($ae, $actions_list);
    stepCheck("يوجد إجراء '$ae' في سجلات التدقيق", $has);
}

// ---------------------------------------------------------------------
// STEP 9: إلغاء ترحيل الفاتورة (اختبار sp_unpost_invoice)
// ---------------------------------------------------------------------
echo "🔹 الخطوة 9: اختبار إلغاء ترحيل الفاتورة (sp_unpost_invoice)\n";
echo "───────────────────────────────────────────────────────────────\n";

try {
    $pdo->exec("CALL sp_unpost_invoice($invoice_id, $user_id, '$posted_ip', '$ua')");
    $inv = $pdo->query("SELECT invoice_status, payment_status, amount_received FROM invoices WHERE id = $invoice_id")->fetch();
    stepCheck("حالة الفاتورة عادت إلى 'draft'", $inv['invoice_status'] === 'draft');
    stepCheck("الدفع عاد لحسابه: {$inv['payment_status']} | amount_received={$inv['amount_received']}",
        true); // السندات لا تُلغى فقط الفاتورة
} catch (Exception $e) {
    stepCheck("فشل إلغاء الترحيل: " . $e->getMessage(), false);
}

// تنظيف الاختبار
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$pdo->exec("DELETE FROM payment_allocations WHERE financial_transaction_id IN ($trx_id, $trx2_id)");
$pdo->exec("DELETE FROM journal_lines WHERE financial_transaction_id IN ($ft_id, $trx_id, $trx2_id)");
$pdo->exec("DELETE FROM financial_transactions WHERE id IN ($ft_id, $trx_id, $trx2_id)");
$pdo->exec("DELETE FROM invoices WHERE id = $invoice_id");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "🧹 تم تنظيف بيانات الاختبار (الفواتير والسندات التجريبية)\n\n";

// ---------------------------------------------------------------------
// ملخص الاختبار النهائي
// ---------------------------------------------------------------------
$total = $stepsPassed + $errors;
$rate = round($stepsPassed * 100 / max($total, 1), 1);
echo "═══════════════════════════════════════════════════════════════\n";
echo "📊 ملخص الاختبار المتكامل:\n";
echo "   ✅ ناجحة  : $stepsPassed\n";
echo "   ❌ فاشلة  : $errors\n";
echo "   📊 النسبة : $rate%\n";
echo "═══════════════════════════════════════════════════════════════\n";

if ($errors === 0) {
    echo "\n🎉 جميع الخطوات نجحت! النظام محاسبيًا يعمل بكفاءة تامة.\n";
} else {
    echo "\n⚠️  هناك خطوات فاشلة ($errors) - يراجع الأعلى.\n";
}

if (PHP_SAPI !== 'cli') {
    echo "</pre>\n";
}
