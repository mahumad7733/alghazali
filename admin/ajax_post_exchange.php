<?php
require_once '../includes/db.php';
require_once '../includes/security.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
rate_limit('ajax_post_exchange', 20, 60);
require_csrf();

$id = $_POST['id'] ?? 0;
$user_id = $_SESSION['admin_id'] ?? 1;

try {
    $pdo->beginTransaction();

    // 1. جلب بيانات عملية الصرف
    $stmt = $pdo->prepare("SELECT transaction_number FROM currency_exchange_transactions WHERE id = ?");
    $stmt->execute([$id]);
    $cet = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cet) throw new Exception("عملية الصرف غير موجودة.");

    // 2. جلب المعاملة المالية المرتبطة
    $stmt_ft = $pdo->prepare("SELECT id, status FROM financial_transactions WHERE transaction_number = ?");
    $stmt_ft->execute([$cet['transaction_number']]);
    $ft = $stmt_ft->fetch(PDO::FETCH_ASSOC);
    if (!$ft) throw new Exception("المعاملة المالية غير موجودة.");

    if ($ft['status'] === 'posted') throw new Exception("المعاملة مُرحلة بالفعل.");

    // 3. تحديث حالة المعاملة إلى posted
    $pdo->prepare("UPDATE financial_transactions SET status = 'posted' WHERE id = ?")->execute([$ft['id']]);

    // 4. تحديث الأرصدة (عبر الإجراء المخزن لتحديث الأرصدة)
    $pdo->prepare("CALL sp_update_account_balances(?)")->execute([$ft['id']]);

    $pdo->commit();
    echo json_encode(['success' => true, 'transaction_number' => $cet['transaction_number']]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('ajax_post_exchange.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ داخلي في النظام']);
}
?>