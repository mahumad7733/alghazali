<?php
require_once 'includes/db.php'; // Assuming this provides $pdo connection

$ft_ids_to_delete = [94, 95, 96]; // These are the financial_transaction IDs for JRN-26-00034, JRN-26-00035, JRN-26-00036

try {
    $pdo->beginTransaction();

    foreach ($ft_ids_to_delete as $ft_id) {
        // Step 1: Reverse journal lines (debit becomes credit, credit becomes debit)
        // This effectively reverses the impact on account balances for sp_update_account_balances
        $stmt_reverse_jl = $pdo->prepare("UPDATE journal_lines SET debit = credit, credit = debit WHERE financial_transaction_id = ?");
        $stmt_reverse_jl->execute([$ft_id]);

        // Step 2: Update account balances based on the reversed journal entries
        $stmt_update_balances = $pdo->prepare("CALL sp_update_account_balances(?)");
        $stmt_update_balances->execute([$ft_id]);

        // Step 3: Delete journal lines associated with the financial transaction
        $stmt_delete_jl = $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?");
        $stmt_delete_jl->execute([$ft_id]);

        // Step 4: Delete payment allocations associated with the financial transaction
        $stmt_delete_pa = $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?");
        $stmt_delete_pa->execute([$ft_id]);

        // Step 5: Delete the financial transaction itself
        $stmt_delete_ft = $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?");
        $stmt_delete_ft->execute([$ft_id]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'تم حذف السندات المالية بنجاح وتحديث الأرصدة.']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'خطأ في حذف السندات المالية: ' . $e->getMessage()]);
}

?>