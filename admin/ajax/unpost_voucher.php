<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../../includes/functions.php';
require_once '../../includes/accounting_functions.php';
require_once '../../core/FinanceService.php';
require_once '../../includes/security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير مدعومة.']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الطلب (CSRF).']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول']);
    exit;
}

$id = $_POST['id'] ?? 0;
$user_id = $_SESSION['admin_id'];
$user_ip = $_SERVER['REMOTE_ADDR'];

try {
    $pdo->beginTransaction();

    // 1. جلب السند
    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($voucher) {
        require_active_financial_user($pdo, 'vouchers_unpost', null, $voucher['branch_id'] !== null ? (int)$voucher['branch_id'] : null);
        require_open_financial_period($pdo, $voucher['transaction_date']);
    }
    if (!$voucher) throw new Exception("السند غير موجود.");

    if ($voucher['status'] !== 'posted') {
        throw new Exception("السند ليس في حالة ترحيل.");
    }

    if (!empty($voucher['original_voucher_id']) || !empty($voucher['is_reversed']) || !empty($voucher['reversal_voucher_id'])) {
        throw new Exception('لا يمكن إلغاء ترحيل سند مرتبط بعكس. استخدم السجل العكسي المعتمد بدلاً من تعديل القيود المرحلة.');
    }

    if (!balances_triggers_enabled($pdo)) {
        apply_transaction_balances($pdo, (int)$id, -1);
    }

    // 2. Verify the existing journal, then preserve its lines as immutable
    // accounting evidence.  Deleting lines from a posted transaction is
    // intentionally prohibited by the database guard.
    validate_journal_balance($pdo, (int)$id);

    // 3. تحديث حالة السند إلى مسودة
    $stmt_reset = $pdo->prepare("UPDATE financial_transactions SET status = 'draft', posted_at = NULL, posted_by = NULL, updated_by = ?, updated_at = NOW() WHERE id = ? AND status = 'posted'");
    $stmt_reset->execute([$user_id, $id]);

    // 4. إعادة حساب مبالغ الفواتير المرتبطة (لأن التوزيعات ما زالت موجودة ولكن السند لم يعد مرحلاً)
    $stmt_allocs = $pdo->prepare("SELECT DISTINCT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
    $stmt_allocs->execute([$id]);
    $invoice_ids = $stmt_allocs->fetchAll(PDO::FETCH_COLUMN);

    $financeService = new FinanceService($pdo, (int)$user_id);
    foreach ($invoice_ids as $inv_id) {
        $financeService->recalculateInvoicePaymentStatus((int)$inv_id);
    }

    // 5. تسجيل في audit_log
    $stmt_after = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt_after->execute([$id]);
    $voucher_after = $stmt_after->fetch(PDO::FETCH_ASSOC);
    log_audit($pdo, 'unpost', 'financial_transactions', $id, $voucher, $voucher_after, "إلغاء ترحيل سند " . ($voucher['transaction_type'] == 'receipt' ? 'قبض' : 'صرف'));

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
