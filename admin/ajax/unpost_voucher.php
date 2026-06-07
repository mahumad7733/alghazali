<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الطلب (CSRF).']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول']);
    exit;
}

if (!has_permission('vouchers_unpost')) {
    echo json_encode(['success' => false, 'message' => 'ليس لديك صلاحية لإلغاء ترحيل السندات']);
    exit;
}

$id = $_POST['id'] ?? 0;
$user_id = $_SESSION['admin_id'];
$user_ip = $_SERVER['REMOTE_ADDR'];

try {
    $pdo->beginTransaction();

    // 1. جلب السند
    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) throw new Exception("السند غير موجود.");

    if ($voucher['status'] !== 'posted') {
        throw new Exception("السند ليس في حالة ترحيل.");
    }

    // 2. عكس الأرصدة قبل الحذف (تعريف القيد المعاكس وتحديث الأرصدة)
    $pdo->prepare("UPDATE journal_lines SET debit = -debit, credit = -credit WHERE financial_transaction_id = ?")->execute([$id]);
    $pdo->prepare("CALL sp_update_account_balances(?)")->execute([$id]);

    // 3. حذف سطور القيد المحاسبي
    $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$id]);

    // 4. تحديث حالة السند إلى مسودة
    $stmt_reset = $pdo->prepare("UPDATE financial_transactions SET status = 'draft', posted_at = NULL, posted_by = NULL WHERE id = ?");
    $stmt_reset->execute([$id]);

    // 5. إعادة حساب مبالغ الفواتير المرتبطة (لأن التوزيعات ما زالت موجودة ولكن السند لم يعد مرحلاً)
    $stmt_allocs = $pdo->prepare("SELECT DISTINCT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
    $stmt_allocs->execute([$id]);
    $invoice_ids = $stmt_allocs->fetchAll(PDO::FETCH_COLUMN);

    foreach ($invoice_ids as $inv_id) {
        $pdo->prepare("CALL sp_recalculate_invoice_payment(?)")->execute([$inv_id]);
    }

    // 6. تسجيل في audit_log
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
