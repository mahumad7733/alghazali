<?php
require_once '../includes/db.php';
require_once '../includes/security.php';
require_once '../includes/accounting_functions.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
rate_limit('ajax_unpost_exchange', 20, 60);
$user = require_active_financial_user($pdo, 'currency_exchange_unpost');
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
    require_active_financial_user($pdo, 'currency_exchange_unpost', null, $cet['branch_id'] !== null ? (int)$cet['branch_id'] : null);
    require_open_financial_period($pdo, $cet['transaction_date']);

    $stmt_ft = $pdo->prepare("SELECT id, status FROM financial_transactions WHERE transaction_number = ? FOR UPDATE");
    $stmt_ft->execute([$cet['transaction_number']]);
    $ft = $stmt_ft->fetch(PDO::FETCH_ASSOC);
    if (!$ft) throw new Exception("المعاملة المالية غير موجودة.");

    if ($ft['status'] !== 'posted') throw new Exception("المعاملة ليست مُرحلة.");

    // 3. عكس الأرصدة عند الحاجة فقط، ثم حذف القيود ليقوم الـ trigger بالعكس تلقائيا.
    if (!balances_triggers_enabled($pdo)) {
        apply_transaction_balances($pdo, (int)$ft['id'], -1);
    }
    validate_journal_balance($pdo, (int)$ft['id']);

    // 4. تحديث حالة المعاملة إلى draft
    $updated = $pdo->prepare("UPDATE financial_transactions SET status = 'draft', updated_by = ?, updated_at = NOW() WHERE id = ? AND status = 'posted'");
    $updated->execute([$user_id, $ft['id']]);
    if ($updated->rowCount() !== 1) {
        throw new Exception('Transaction state changed; unpost was rejected.');
    }
    log_audit($pdo, 'unpost', 'financial_transactions', (int)$ft['id'], ['status' => 'posted'], ['status' => 'draft'], 'Currency exchange unposted');

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('ajax_unpost_exchange.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ داخلي في النظام']);
}
?>
