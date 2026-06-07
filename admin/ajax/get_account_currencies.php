<?php
require_once '../../includes/db.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$account_id = $_GET['account_id'] ?? 0;
$currencies = [];
$error_message = null;

if (!$account_id) {
    header('Content-Type: application/json');
    echo json_encode(['currencies' => [], 'error' => 'Missing account_id']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.currency_name, c.currency_symbol, ab.credit_limit, ab.debit_limit, ab.current_balance, ab.is_frozen
        FROM account_balances_unified ab
        JOIN currencies c ON ab.currency_id = c.id
        WHERE ab.account_id = ? AND ab.is_frozen = 0
    ");
    $stmt->execute([$account_id]);
    $currencies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no account_balances_unified records found, return all active currencies!
    if (empty($currencies)) {
        $stmt_all = $pdo->prepare("SELECT c.id, c.currency_name, c.currency_symbol FROM currencies c WHERE c.is_active = 1 ORDER BY c.is_default DESC");
        $stmt_all->execute();
        $currencies = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('Error in get_account_currencies.php for account_id ' . $account_id . ': ' . $e->getMessage());
    $currencies = [];
    $error_message = 'Database error: ' . $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode(['currencies' => $currencies, 'error' => $error_message]);
?>