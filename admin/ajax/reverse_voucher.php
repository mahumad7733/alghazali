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
$reason = $_POST['reason'] ?? '';
$user_id = $_SESSION['admin_id'] ?? 1;
$user_ip = $_SERVER['REMOTE_ADDR'];

try {
    $pdo->beginTransaction();

    // 1. جلب السند
    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt->execute([$id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) throw new Exception("السند غير موجود.");

    // 2. عكس تأثير السند على الأرصدة (فقط إذا كان مرحلاً)
    if ($voucher['status'] == 'posted') {

        // جلب التوزيعات على الفواتير لإعادة حسابها لاحقاً
        $stmt_alloc = $pdo->prepare("SELECT * FROM payment_allocations WHERE financial_transaction_id = ?");
        $stmt_alloc->execute([$id]);
        $allocations = $stmt_alloc->fetchAll(PDO::FETCH_ASSOC);

        // جلب أسطر القيد قبل حذفها
        $stmt_jl = $pdo->prepare("SELECT account_id, debit, credit, currency_id, branch_id FROM journal_lines WHERE financial_transaction_id = ?");
        $stmt_jl->execute([$id]);
        $journalLines = $stmt_jl->fetchAll(PDO::FETCH_ASSOC);

        // جلب branch_id من المعاملة
        $stmt_branch = $pdo->prepare("SELECT branch_id FROM financial_transactions WHERE id = ?");
        $stmt_branch->execute([$id]);
        $defaultBranchId = $stmt_branch->fetchColumn();

        // عكس الأرصدة
        foreach ($journalLines as $line) {
            $accountId = $line['account_id'];
            $currencyId = $line['currency_id'];
            $branchId = $line['branch_id'] ?? $defaultBranchId;
            $amount = $line['credit'] - $line['debit']; // عكس القيد

            // جلب سعر الصرف و currency code
            $stmt_curr = $pdo->prepare("SELECT exchange_rate, currency_code FROM currencies WHERE id = ?");
            $stmt_curr->execute([$currencyId]);
            $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
            $rate = (float)($curr['exchange_rate'] ?? 1);
            $currencyCode = $curr['currency_code'] ?? '';
            $amountBase = $amount * $rate;

            // تحديث أرصدة الحسابات
            // First check if the row exists
            if ($branchId === null) {
                $stmt_check = $pdo->prepare("
                    SELECT id FROM account_balances_unified 
                    WHERE account_id = ? AND branch_id IS NULL AND currency_id = ?
                ");
                $stmt_check->execute([$accountId, $currencyId]);
            } else {
                $stmt_check = $pdo->prepare("
                    SELECT id FROM account_balances_unified 
                    WHERE account_id = ? AND branch_id = ? AND currency_id = ?
                ");
                $stmt_check->execute([$accountId, $branchId, $currencyId]);
            }
            $exists = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                // Update existing row
                $stmt_upd = $pdo->prepare("
                    UPDATE account_balances_unified 
                    SET 
                        current_balance = current_balance + ?, 
                        current_balance_base = current_balance_base + ?,
                        currency_code = ?
                    WHERE id = ?
                ");
                $stmt_upd->execute([$amount, $amountBase, $currencyCode, $exists['id']]);
            } else {
                // Insert new row with all required columns
                $stmt_ins = $pdo->prepare("
                    INSERT INTO account_balances_unified (
                        account_id, branch_id, currency_id, currency_code,
                        opening_balance, current_balance, current_balance_base,
                        opening_balance_base, credit_limit, debit_limit, is_frozen
                    ) VALUES (?, ?, ?, ?, 0, ?, ?, 0, 0, 0, 0)
                ");
                $stmt_ins->execute([$accountId, $branchId, $currencyId, $currencyCode, $amount, $amountBase]);
            }
        }

        // حذف أسطر القيد
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")
            ->execute([$id]);
    }

    // 3. تحديث حالة السند إلى ملغى
    $pdo->prepare("
        UPDATE financial_transactions
        SET status = 'cancelled',
            cancelled_at = NOW(),
            cancelled_by = ?,
            cancelled_ip = ?,
            cancellation_reason = ?
        WHERE id = ?
    ")->execute([$user_id, $user_ip, $reason, $id]);

    // 4. إعادة حساب مبالغ الفواتير المرتبطة (بعد إلغاء الترحيل)
    if ($voucher['status'] == 'posted' && !empty($allocations)) {
        foreach ($allocations as $alloc) {
            php_recalculate_invoice_payment($pdo, $alloc['invoice_id']);
        }
    }

    // 5. تسجيل في audit_log
    $voucher_after = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $voucher_after->execute([$id]);
    $voucher_after = $voucher_after->fetch(PDO::FETCH_ASSOC);
    log_audit($pdo, 'cancel', 'financial_transactions', $id, $voucher, $voucher_after,
        "إلغاء سند " . ($voucher['transaction_type'] == 'receipt' ? 'قبض' : 'صرف') . ": " . $reason);

    $pdo->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>