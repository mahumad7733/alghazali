<?php
require_once '../includes/db.php';
require_once '../includes/security.php';
require_once '../includes/accounting_functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
rate_limit('ajax_post_exchange', 20, 60);
$user = require_active_financial_user($pdo, 'currency_exchange_post');
require_csrf();

$id = $_POST['id'] ?? 0;
$user_id = (int)$user['id'];

try {
    $pdo->beginTransaction();

    // 1. جلب بيانات عملية الصرف
    $stmt = $pdo->prepare("SELECT transaction_number, branch_id, transaction_date FROM currency_exchange_transactions WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $cet = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cet) throw new Exception("عملية الصرف غير موجودة.");

    // 2. جلب المعاملة المالية المرتبطة
    require_active_financial_user($pdo, 'currency_exchange_post', null, $cet['branch_id'] !== null ? (int)$cet['branch_id'] : null);
    require_open_financial_period($pdo, $cet['transaction_date']);

    $stmt_ft = $pdo->prepare("SELECT id, status FROM financial_transactions WHERE transaction_number = ? FOR UPDATE");
    $stmt_ft->execute([$cet['transaction_number']]);
    $ft = $stmt_ft->fetch(PDO::FETCH_ASSOC);
    if (!$ft) throw new Exception("المعاملة المالية غير موجودة.");

    if ($ft['status'] === 'posted') throw new Exception("المعاملة مُرحلة بالفعل.");

    $stmt_lines = $pdo->prepare("SELECT COUNT(*) FROM journal_lines WHERE financial_transaction_id = ?");
    $stmt_lines->execute([$ft['id']]);
    $lines_count = (int)$stmt_lines->fetchColumn();
    if ($lines_count === 0) {
        throw new Exception("لا يمكن ترحيل عملية الصرف بدون قيود يومية مرتبطة.");
    }

    // 3. تحديث حالة المعاملة فقط بعد التأكد من وجود القيود اليومية.
    validate_journal_balance($pdo, (int)$ft['id']);
    $updated = $pdo->prepare("UPDATE financial_transactions SET status = 'posted', posted_by = ?, posted_at = NOW(), posted_ip = ? WHERE id = ? AND status = 'draft'");
    $updated->execute([$user_id, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $ft['id']]);
    if ($updated->rowCount() !== 1) {
        throw new Exception('Transaction state changed; retry was rejected.');
    }
    if (!balances_triggers_enabled($pdo)) {
        apply_transaction_balances($pdo, (int)$ft['id'], 1);
    }
    log_audit($pdo, 'post', 'financial_transactions', (int)$ft['id'], null, null, 'Currency exchange posted');

    $pdo->commit();
    echo json_encode(['success' => true, 'transaction_number' => $cet['transaction_number']]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('ajax_post_exchange.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ داخلي في النظام']);
}
?>
