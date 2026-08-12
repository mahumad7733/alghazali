<?php
require_once '../../includes/db.php';
require_once '../../includes/security.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$authenticatedUser = require_active_financial_user($pdo, 'financial_hub_view');

$id = $_GET['id'] ?? 0;

$stmt = $pdo->prepare("
    SELECT t.*,
           u_create.full_name as creator_name,
           u_post.full_name as poster_name,
           u_cancel.full_name as canceller_name,
           coa.account_name_ar as account_name,
           c.currency_name, c.currency_symbol,
           (SELECT account_name_ar FROM unified_accounts WHERE id = t.party_account_id) as party_name
    FROM financial_transactions t
    LEFT JOIN users u_create ON t.created_by = u_create.id
    LEFT JOIN users u_post ON t.posted_by = u_post.id
    LEFT JOIN users u_cancel ON t.cancelled_by = u_cancel.id
    LEFT JOIN unified_accounts coa ON t.cash_bank_account_id = coa.id
    LEFT JOIN currencies c ON t.currency_id = c.id
    WHERE t.id = ?
");
$stmt->execute([$id]);
$voucher = $stmt->fetch(PDO::FETCH_ASSOC);

if ($voucher) {
    require_active_financial_user($pdo, null, null, $voucher['branch_id'] !== null ? (int)$voucher['branch_id'] : null);
    // جلب الفواتير المخصصة (المرحلة فقط أو إذا كان الطلب لعرض تفاصيل سند معين)
    $stmt_alloc = $pdo->prepare("
        SELECT pa.*, i.invoice_number, i.invoice_date, i.net_amount, ft.status as voucher_status
        FROM payment_allocations pa
        JOIN invoices i ON pa.invoice_id = i.id
        JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
        WHERE pa.financial_transaction_id = ?
    ");
    $stmt_alloc->execute([$id]);
    $voucher['allocations'] = $stmt_alloc->fetchAll(PDO::FETCH_ASSOC);

    // جلب سجل التدقيق (Audit Log)
    try {
        $stmt_logs = $pdo->prepare("
            SELECT l.*, u.full_name as user_name
            FROM audit_log l
            LEFT JOIN users u ON l.user_id = u.id
            WHERE l.table_name = 'financial_transactions' AND l.record_id = ?
            ORDER BY l.created_at DESC
        ");
        $stmt_logs->execute([$id]);
        $voucher['audit_logs'] = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // المحاولة مع الجدول البديل (audit_logs)
        try {
            $stmt_logs = $pdo->prepare("
                SELECT l.id, l.user_id, l.action as action_type, l.table_name, l.record_id,
                       l.old_values as old_data, l.new_values as new_data, l.ip_address as user_ip,
                       l.user_agent, l.created_at, u.full_name as user_name
                FROM audit_logs l
                LEFT JOIN users u ON l.user_id = u.id
                WHERE l.table_name = 'financial_transactions' AND l.record_id = ?
                ORDER BY l.created_at DESC
            ");
            $stmt_logs->execute([$id]);
            $voucher['audit_logs'] = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e2) {
            $voucher['audit_logs'] = [];
        }
    }
}

header('Content-Type: application/json');
echo json_encode($voucher);
