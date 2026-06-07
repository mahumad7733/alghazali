<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/functions.php';
require_once '../../includes/accounting_functions.php';

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الطلب (CSRF).']);
    exit;
}

$id = $_POST['id'] ?? 0;
$user_id = $_SESSION['admin_id'] ?? 1;

try {
    $pdo->beginTransaction();

    // 1. جلب السند للتحقق من وجوده وصلاحية حذفه
    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) throw new Exception("السند غير موجود.");

    // 2. التحقق من الصلاحيات
    $user_role    = $_SESSION['role_name'] ?? $_SESSION['role'] ?? '';
    $user_role_id = $_SESSION['role_id'] ?? 0;

    $has_perm = false;
    if ($user_role == 'developer' || $user_role_id == 2) {
        $has_perm = true;
    } else {
        $stmt_p = $pdo->prepare("
            SELECT COUNT(*) FROM role_permissions_unified rp
            JOIN unified_permissions p ON rp.permission_id = p.id
            WHERE rp.role_id = ? AND p.permission_code = 'voucher_delete'
        ");
        $stmt_p->execute([$user_role_id]);
        if ($stmt_p->fetchColumn() > 0) {
            if (in_array($user_role, ['admin', 'accountant'])) {
                $has_perm = true;
            } elseif ($voucher['created_by'] == $user_id) {
                $has_perm = true;
            }
        }
    }

    if (!$has_perm) throw new Exception("ليس لديك صلاحية لحذف هذا السند.");

    // 3. إذا كان السند مرحلاً: عكس القيود وتحديث الأرصدة بشكل صحيح
    if ($voucher['status'] == 'posted') {
        php_delete_financial_transaction_and_reverse($pdo, $id);
    } else {
        // If not posted, just delete journal lines
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$id]);
    }

    // 4. تسجيل العملية في audit_log قبل الحذف
    log_audit($pdo, 'delete', 'financial_transactions', $id, $voucher, null, "حذف نهائي للسند");

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>