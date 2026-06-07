<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/functions.php';
require_once '../../includes/accounting_functions.php';

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الطلب (CSRF).']);
    exit;
}

$id      = $_POST['id'] ?? 0;
$user_id = $_SESSION['admin_id'] ?? 1;

try {
    // 1. جلب السند
    $stmt = $pdo->prepare("
        SELECT ft.*,
               (SELECT COUNT(*) FROM journal_lines WHERE financial_transaction_id = ft.id) AS lines_count
        FROM financial_transactions ft
        WHERE ft.id = ? AND ft.status IN ('draft', 'cancelled')
    ");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$voucher) throw new Exception("السند غير موجود أو مرحل مسبقاً.");

    // 2. ترحيل السند باستخدام دوال PHP
    if ($voucher['transaction_type'] == 'receipt') {
        php_post_receipt_voucher($pdo, $id, $user_id);
    } else {
        php_post_payment_voucher($pdo, $id, $user_id);
    }

    // 3. تسجيل في audit_log
    $voucher_after = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $voucher_after->execute([$id]);
    $voucher_after = $voucher_after->fetch(PDO::FETCH_ASSOC);
    log_audit($pdo, 'post', 'financial_transactions', $id, $voucher, $voucher_after,
        "ترحيل سند " . ($voucher['transaction_type'] == 'receipt' ? 'قبض' : 'صرف'));

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
