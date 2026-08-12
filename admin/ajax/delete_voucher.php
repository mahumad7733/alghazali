<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once '../../includes/functions.php';
require_once '../../includes/accounting_functions.php';
require_once '../../includes/security.php';
require_once '../../core/FinanceService.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير مدعومة.']);
    exit;
}

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'انتهت الجلسة، يرجى تسجيل الدخول']);
    exit;
}

if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'خطأ في التحقق من الطلب (CSRF).']);
    exit;
}

$authenticatedUser = require_active_financial_user($pdo);

$id = $_POST['id'] ?? 0;
$user_id = $_SESSION['admin_id'];

try {
    $pdo->beginTransaction();

    // 1. جلب السند للتحقق
    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($voucher) {
        require_active_financial_user($pdo, null, null, $voucher['branch_id'] !== null ? (int)$voucher['branch_id'] : null);
        require_open_financial_period($pdo, $voucher['transaction_date']);
    }

    if (!$voucher) throw new Exception("السند غير موجود.");

    // ======================================================
    // التحقق مما إذا كان السند جزءاً من زوج معكوس (الأصلي + العكسي)
    // ======================================================
    $is_part_of_reversal_pair = false;
    $paired_voucher_id = null;
    $is_reversal_side = false; // هل السند الحالي هو العكسي؟

    if (!empty($voucher['original_voucher_id']) || ($voucher['reference_type'] ?? '') === 'reversal') {
        // السند الحالي هو السند العكسي
        $is_part_of_reversal_pair = true;
        $paired_voucher_id = !empty($voucher['original_voucher_id']) ? (int)$voucher['original_voucher_id'] : (int)($voucher['reference_id'] ?? 0);
        $is_reversal_side = true;
    } elseif (!empty($voucher['reversal_voucher_id']) || !empty($voucher['is_reversed'])) {
        // السند الحالي هو السند الأصلي الذي تم عكسه
        $is_part_of_reversal_pair = true;
        $paired_voucher_id = !empty($voucher['reversal_voucher_id']) ? (int)$voucher['reversal_voucher_id'] : 0;
    }

    // A reversal pair is permanent financial evidence.  The database guards
    // intentionally prohibit deleting its posted side, so reject the request
    // explicitly instead of starting a partial pair deletion that must fail.
    if ($is_part_of_reversal_pair) {
        http_response_code(409);
        throw new Exception('لا يمكن حذف سند تم عكسه أو السند العكسي المرتبط به. تبقى السندات محفوظة كسجل محاسبي غير قابل للحذف.');
    }

    // ======================================================
    // التحقق من الصلاحيات: تفصيلي حسب نوع السند (أصلي/عكسي × قبض/صرف)
    // ======================================================
    $user_role_id = (int)($_SESSION['role_id'] ?? 0);
    $user_role    = strtolower($_SESSION['role_name'] ?? $_SESSION['role'] ?? '');

    // السماح المطلق للمطور (role_id=2 أو الاسم developer)
    $is_super = ($user_role === 'developer' || $user_role_id === 2 || $user_role === 'admin');

    if (!$is_super) {
        $ttype       = strtolower($voucher['transaction_type'] ?? '');

        $perm_needed = null;
        if (!$is_part_of_reversal_pair) {
            // سند عادي غير مرتبط بعكس
            if ($ttype === 'receipt') $perm_needed = 'receipt_delete_original';
            elseif ($ttype === 'payment') $perm_needed = 'payment_delete_original';
        } else {
            // جزء من زوج معكوس → نحتاج صلاحية حذف المعكوسات حسب النوع الأصلي للسند
            $orig_ttype = $ttype; // نستخدم نوع السند الحالي
            if ($orig_ttype === 'receipt') $perm_needed = 'receipt_delete_reversal';
            elseif ($orig_ttype === 'payment') $perm_needed = 'payment_delete_reversal';
        }

        $allowed = false;
        // الصلاحية العامة القديمة للتوافق الخلفي
        if (has_permission_v3($pdo, $user_role_id, 'voucher_delete')) $allowed = true;
        // الصلاحية التفصيلية
        if ($perm_needed && has_permission_v3($pdo, $user_role_id, $perm_needed)) $allowed = true;

        if (!$allowed) {
            http_response_code(403);
            log_audit($pdo, 'delete_denied', 'financial_transactions', $id, $voucher, null, "محاولة حذف مرفوضة: المستخدم ليس لديه صلاحية {$perm_needed}");
            throw new Exception("ليس لديك صلاحية لحذف هذا السند ({$perm_needed}).");
        }
    }

    // ======================================================
    // 2. جمع جميع السندات التي سيتم حذفها (السند الحالي + الزوج المعكوس إن وجد)
    // ======================================================
    $ids_to_delete = [(int)$id];
    $voucher_data_to_log = [$voucher];

    if ($is_part_of_reversal_pair && $paired_voucher_id > 0) {
        // جلب بيانات السند الزوج للتحقق من حالته
        $stmt_pair = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
        $stmt_pair->execute([$paired_voucher_id]);
        $paired_voucher = $stmt_pair->fetch(PDO::FETCH_ASSOC);
        if ($paired_voucher) {
            $ids_to_delete[] = (int)$paired_voucher_id;
            $voucher_data_to_log[] = $paired_voucher;
        }
    }

    // ======================================================
    // 3. تنفيذ الحذف لكل السندات المجمعة
    // ======================================================
    $deleted_numbers = [];
    $affected_invoice_ids = [];

    foreach ($ids_to_delete as $del_id) {
        // إعادة جلب بيانات السند للحذف (قد يكون الزوج مختلفاً)
        $stmt_del = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
        $stmt_del->execute([$del_id]);
        $del_voucher = $stmt_del->fetch(PDO::FETCH_ASSOC);
        if (!$del_voucher) continue;

        require_active_financial_user($pdo, 'voucher_delete', null, $del_voucher['branch_id'] !== null ? (int)$del_voucher['branch_id'] : null);
        if (in_array(strtolower((string)$del_voucher['status']), ['posted', 'approved', 'reversed', 'reconciled', 'completed'], true)) {
            http_response_code(409);
            throw new Exception('لا يمكن حذف سند مُرحّل أو معتمد. استخدم الإلغاء أو العكس مع الاحتفاظ بالسجل المحاسبي.');
        }

        $deleted_numbers[] = $del_voucher['transaction_number'];

        // عكس الأرصدة إذا كان السند مرحلاً
        if ($del_voucher['status'] == 'posted') {
            if (function_exists('apply_transaction_balances')) {
                apply_transaction_balances($pdo, (int)$del_id, -1);
            }
        }

        // جمع معرفات الفواتير المرتبطة لإعادة حسابها لاحقاً
        $stmt_inv = $pdo->prepare("SELECT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
        $stmt_inv->execute([$del_id]);
        $inv_ids = $stmt_inv->fetchAll(PDO::FETCH_COLUMN);
        $affected_invoice_ids = array_merge($affected_invoice_ids, $inv_ids);

        // حذف أسطر القيد والتوزيعات والسند
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$del_id]);
        $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$del_id]);
        $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$del_id]);
    }

    // إعادة حساب مبالغ الفواتير المتأثرة
    $affected_invoice_ids = array_unique(array_filter($affected_invoice_ids));
    if (!empty($affected_invoice_ids)) {
        $financeService = new FinanceService($pdo, (int)$user_id);
        foreach ($affected_invoice_ids as $affectedInvoiceId) {
            $financeService->recalculateInvoicePaymentStatus((int)$affectedInvoiceId);
        }
    }

    // تسجيل العملية في Audit Log
    $log_msg = ($is_part_of_reversal_pair)
        ? "حذف زوج سندات معكوسة: " . implode(', ', $deleted_numbers)
        : "حذف نهائي للسند رقم: " . implode(', ', $deleted_numbers);
    log_audit($pdo, 'delete', 'financial_transactions', $id, $voucher, null, $log_msg);

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * دالة التحقق من الصلاحية - تعتمد على البحث في role_permissions_unified
 * @param PDO    $pdo          اتصال قاعدة البيانات
 * @param int    $role_id      رقم تعريف الدور
 * @param string $code         كود الصلاحية
 */
function has_permission_v3($pdo, $role_id, $code) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
              FROM role_permissions_unified rp
              JOIN unified_permissions p ON p.id = rp.permission_id
             WHERE rp.role_id = ? AND p.permission_code = ?
        ");
        $stmt->execute([(int)$role_id, $code]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $t) {
        error_log("has_permission_v3 DB error: " . $t->getMessage());
        return false;
    }
}
