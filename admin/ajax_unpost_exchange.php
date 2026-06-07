<?php
require_once '../includes/db.php';
require_once '../includes/security.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');
rate_limit('ajax_unpost_exchange', 20, 60);
require_csrf();

$id = $_POST['id'] ?? 0;

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

    if ($ft['status'] !== 'posted') throw new Exception("المعاملة ليست مُرحلة.");

    // 3. عكس الأرصدة (جلب أسطر القيد ثم عكسها)
    $stmt_jl = $pdo->prepare("
        SELECT jl.account_id, jl.debit, jl.credit, jl.currency_id, ua.normal_balance
        FROM journal_lines jl
        JOIN unified_accounts ua ON jl.account_id = ua.id
        WHERE jl.financial_transaction_id = ?
    ");
    $stmt_jl->execute([$ft['id']]);
    $lines = $stmt_jl->fetchAll(PDO::FETCH_ASSOC);

    foreach ($lines as $line) {
        $amount = $line['debit'] - $line['credit'];
        
        // إذا كان الحساب مدينًا (normal_balance = 'debit')، نقوم بطرح المبلغ
        // إذا كان الحساب دائنًا (normal_balance = 'credit')، نقوم بطرح المبلغ (العكس)
        if ($line['normal_balance'] === 'debit') {
            $pdo->prepare("
                UPDATE account_balances_unified 
                SET current_balance = current_balance - ? 
                WHERE account_id = ? AND currency_id = ?
            ")->execute([$amount, $line['account_id'], $line['currency_id']]);
        } else {
            $amount_c = $line['credit'] - $line['debit'];
            $pdo->prepare("
                UPDATE account_balances_unified 
                SET current_balance = current_balance - ? 
                WHERE account_id = ? AND currency_id = ?
            ")->execute([$amount_c, $line['account_id'], $line['currency_id']]);
        }
    }

    // 4. تحديث حالة المعاملة إلى draft
    $pdo->prepare("UPDATE financial_transactions SET status = 'draft' WHERE id = ?")->execute([$ft['id']]);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('ajax_unpost_exchange.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'حدث خطأ داخلي في النظام']);
}
?>