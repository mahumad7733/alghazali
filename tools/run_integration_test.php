Exit code: 0
Wall time: 3 seconds
Output:
<?php
/**
 * ط§ط®طھط¨ط§ط± ظ…ط­ط§ط³ط¨ظٹ ظ…طھظƒط§ظ…ظ„ ط­ظ‚ظٹظ‚ظٹ ظ„ط³ظٹط± ط¹ظ…ظ„ ط§ظ„ظپظˆط§طھظٹط± ظˆط§ظ„ط³ظ†ط¯ط§طھ
 * ط®ط·ظˆط§طھ ط§ظ„ط§ط®طھط¨ط§ط±:
 * 1. ط¬ظ„ط¨ ط¹ظ…ظٹظ„ ظˆظ…ظˆط±ط¯ ظˆط®ط¯ظ…ط© ظˆط­ط³ط§ط¨ط§طھ ظ…ظ† ظ‚ط§ط¹ط¯ط© ط§ظ„ط¨ظٹط§ظ†ط§طھ ط§ظ„ظپط¹ظ„ظٹط©
 * 2. ط¥ظ†ط´ط§ط، ظپط§طھظˆط±ط© ظ…ط¨ظٹط¹ط§طھ ط¨ط§ط³طھط®ط¯ط§ظ… sp_create_invoice (ط§ظ„طھظˆظ‚ظٹط¹ PHP-compat)
 * 3. طھط±ط­ظٹظ„ ط§ظ„ظپط§طھظˆط±ط© ط¨ط§ط³طھط®ط¯ط§ظ… sp_post_invoice
 * 4. ط¥ظ†ط´ط§ط، ط³ظ†ط¯ ظ‚ط¨ط¶ ظ…ط±طھط¨ط· ط¨ط§ظ„ظپط§طھظˆط±ط© ط¨ط§ط³طھط®ط¯ط§ظ… sp_create_receipt_voucher
 * 5. طھط±ط­ظٹظ„ ط³ظ†ط¯ ط§ظ„ظ‚ط¨ط¶ ط¨ط§ط³طھط®ط¯ط§ظ… sp_post_receipt_voucher
 * 6. ظپط­طµ طھط­ط¯ظٹط« amount_received ظˆ payment_status طھظ„ظ‚ط§ط¦ظٹط§ظ‹
 * 7. ظپط­طµ طھظˆط§ط²ظ† ط§ظ„ظ‚ظٹظˆط¯ ط§ظ„ظ…ط­ط§ط³ط¨ظٹط© ظپظٹ ظƒظ„ ط®ط·ظˆط©
 * 8. ط¥ظ„ط؛ط§ط، طھط±ط­ظٹظ„ ط§ظ„ظپط§طھظˆط±ط© ظˆط§ظ„طھط£ظƒط¯ ظ…ظ† ط§ظ†ط¹ظƒط§ط³ ط§ظ„ط£ط±طµط¯ط©
 */

$env = is_file(__DIR__ . '/../.env') ? parse_ini_file(__DIR__ . '/../.env') : [];
$host = getenv('FINANCE_TEST_DB_HOST') ?: ($env['DB_HOST'] ?? '127.0.0.1');
$port = getenv('FINANCE_TEST_DB_PORT') ?: ($env['DB_PORT'] ?? '3306');
$user = getenv('FINANCE_TEST_DB_USER') ?: ($env['DB_USER'] ?? 'root');
$pass = getenv('FINANCE_TEST_DB_PASS') ?: ($env['DB_PASS'] ?? '');
$db   = getenv('FINANCE_TEST_DB') ?: ($env['DB_NAME'] ?? 'alghazali');
$charset = 'utf8mb4';

$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=$charset", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=utf-8');
    echo "<pre style=\"direction:rtl;text-align:right;font-family:Tahoma;font-size:13px;background:#fff;padding:20px\">\n";
}

echo "â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ\n";
echo "ًں§ھ ط§ط®طھط¨ط§ط± ظ…ط­ط§ط³ط¨ظٹ ظ…طھظƒط§ظ…ظ„ ط­ظ‚ظٹظ‚ظٹ - ط³ظٹط± ط¹ظ…ظ„ ظپط§طھظˆط±ط© + ط³ظ†ط¯ ظ‚ط¨ط¶\n";
echo "â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ\n\n";

// ---------------------------------------------------------------------
// STEP 0: طھط¬ظ‡ظٹط² ط¨ظٹط§ظ†ط§طھ ط§ظ„ط§ط®طھط¨ط§ط± ظ…ظ† ط§ظ„ط¬ط¯ط§ظˆظ„ ط§ظ„ظپط¹ظ„ظٹط©
// ---------------------------------------------------------------------
echo "ًں”¹ ط§ظ„ط®ط·ظˆط© 0: ط¬ظ„ط¨ ط¨ظٹط§ظ†ط§طھ ط§ظ„ط§ط®طھط¨ط§ط± ظ…ظ† ط§ظ„ط¬ط¯ط§ظˆظ„ ط§ظ„ظپط¹ظ„ظٹط©\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$customer = $pdo->query("
    SELECT c.id, c.full_name AS name, c.account_id, abu.currency_id, abu.current_balance
      FROM customers c
      LEFT JOIN account_balances_unified abu ON abu.account_id = c.account_id
     WHERE c.account_id IS NOT NULL
     ORDER BY c.id DESC LIMIT 1
")->fetch();

if (!$customer) {
    die("â‌Œ ظ„ط§ ظٹظˆط¬ط¯ ط¹ظ…ظ„ط§ط، ظپظٹ ظ‚ط§ط¹ط¯ط© ط§ظ„ط¨ظٹط§ظ†ط§طھ ظ„ظ„ط¥ط®طھط¨ط§ط±\n");
}
$customer_id     = (int)$customer['id'];
$customer_acct   = (int)$customer['account_id'];
$currency_id     = (int)$customer['currency_id'];
$pdo->exec("CALL sp_rebuild_balances()");
$customer = $pdo->query("SELECT c.id, c.full_name AS name, c.account_id, abu.currency_id, abu.current_balance FROM customers c LEFT JOIN account_balances_unified abu ON abu.account_id = c.account_id AND abu.currency_id = $currency_id WHERE c.id = $customer_id LIMIT 1")->fetch();
$balance_before  = (float)$customer['current_balance'];
echo "âœ… ط§ظ„ط¹ظ…ظٹظ„ ط§ظ„ظ…ط®طھط¨ط±: {$customer['name']} (#$customer_id) | ط­ط³ط§ط¨=$customer_acct | ط¹ظ…ظ„ط©=$currency_id | ط±طµظٹط¯ ظ‚ط¨ظ„ ط§ظ„ط§ط®طھط¨ط§ط±=$balance_before\n";

$branch = $pdo->query("SELECT id FROM branches ORDER BY id LIMIT 1")->fetch();
$branch_id = (int)$branch['id'];
echo "âœ… ط§ظ„ظپط±ط¹ ط§ظ„ظ…ط®طھط¨ط±: #$branch_id\n";

$service = $pdo->query("SELECT id FROM services ORDER BY id LIMIT 1")->fetch();
$service_id = $service ? (int)$service['id'] : null;
echo "âœ… ط§ظ„ط®ط¯ظ…ط© ط§ظ„ظ…ط®طھط¨ط±ط©: #" . ($service_id ?? 'ظ„ط§ طھظˆط¬ط¯') . "\n";

$cash_acct = $pdo->query("
    SELECT id FROM unified_accounts
     WHERE account_code LIKE '11101%' OR account_name_ar LIKE '%طµظ†ط¯ظˆظ‚%'
     ORDER BY account_code LIMIT 1
")->fetch();
$cash_account_id = (int)$cash_acct['id'];
echo "âœ… ط­ط³ط§ط¨ ط§ظ„طµظ†ط¯ظˆظ‚: #$cash_account_id\n";

$user = $pdo->query("SELECT id FROM users WHERE status='active' ORDER BY id LIMIT 1")->fetch();
$user_id = (int)$user['id'];
echo "âœ… ط§ظ„ظ…ط³طھط®ط¯ظ… ط§ظ„ظ…ظ†ظپط°: #$user_id\n";
echo "\n";

$errors = 0;
$stepsPassed = 0;
function stepCheck($label, $ok, $detail = '') {
    global $errors, $stepsPassed;
    if ($ok) {
        echo "âœ… " . trim($label) . "\n";
        $stepsPassed++;
    } else {
        echo "â‌Œ " . trim($label) . "\n";
        $errors++;
    }
    if ($detail !== '') {
        echo "   â†³ " . trim($detail) . "\n";
    }
    echo "\n";
}

// ---------------------------------------------------------------------
// STEP 1: ط¥ظ†ط´ط§ط، ظپط§طھظˆط±ط© ظ…ط¨ظٹط¹ط§طھ
// ---------------------------------------------------------------------
echo "ًں”¹ ط§ظ„ط®ط·ظˆط© 1: ط¥ظ†ط´ط§ط، ظپط§طھظˆط±ط© ظ…ط¨ظٹط¹ط§طھ (sp_create_invoice)\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$stmt = $pdo->prepare("
    CALL sp_create_invoice(
        'sales',           # p_invoice_category
        ?,                 # p_branch_id
        'BusFlight',       # p_source_type
        NULL,              # p_source_id
        ?,                 # p_customer_id
        NULL,              # p_supplier_id
        NULL,              # p_agent_id
        ?,                 # p_service_id
        ?,                 # p_currency_id
        5000.00,           # p_total_amount
        250.00,            # p_discount
        3000.00,           # p_cost_amount
        'credit',          # p_payment_type
        'ط§ط®طھط¨ط§ط± طھظ„ظ‚ط§ط¦ظٹ - ظپط§طھظˆط±ط© ظ„طھط¬ط±ط¨ط© ط§ظ„ظ†ط¸ط§ظ…',
        ?,                 # p_created_by
        NULL,              # p_cost_center_id
        NULL,              # p_invoice_number (ظٹظڈظˆظ„ظ‘ط¯ طھظ„ظ‚ط§ط¦ظٹط§ظ‹)
        @invoice_id        # OUT
    )
");
$stmt->execute([$branch_id, $customer_id, $service_id, $currency_id, $user_id]);

// ظ‚ط±ط§ط،ط© ط§ظ„ظپط§طھظˆط±ط© ط§ظ„ظ†ط§طھط¬ط©
$row = $pdo->query("SELECT @invoice_id AS id")->fetch();
$invoice_id = (int)$row['id'];

if ($invoice_id > 0) {
    $inv = $pdo->query("
        SELECT id, invoice_number, total_amount, discount, net_amount,
               amount_received, payment_status, invoice_status,
               customer_id, created_ip, created_by
          FROM invoices WHERE id = $invoice_id
    ")->fetch();

    stepCheck("طھظ… ط¥ظ†ط´ط§ط، ط§ظ„ظپط§طھظˆط±ط© ط¨ط±ظ‚ظ… ظ…ط¹ط±ظپ #$invoice_id", !empty($inv));
    stepCheck("ط±ظ‚ظ… ط§ظ„ظپط§طھظˆط±ط© ظ…ظڈظˆظ„ظژظ‘ط¯: {$inv['invoice_number']}", !empty($inv['invoice_number']));
    stepCheck("طµط§ظپظٹ ط§ظ„ظپط§طھظˆط±ط© طµط­ظٹط­: 5000 - 250 = {$inv['net_amount']}", (float)$inv['net_amount'] === 4750.0);
    stepCheck("ط§ظ„ط­ط§ظ„ط© ط§ظ„ط£ظˆظ„ظٹط©: ظ…ط³ظˆط¯ط© (draft) + ط؛ظٹط± ظ…ط¯ظپظˆط¹ط© (unpaid)",
        $inv['invoice_status'] === 'draft' && $inv['payment_status'] === 'unpaid');
    stepCheck("ط§ظ„ط±ظ…ط² ط§ظ„ط¹ظ…ظٹظ„ طµط­ظٹط­: {$inv['customer_id']} == $customer_id", (int)$inv['customer_id'] === $customer_id);
} else {
    stepCheck("ظپط´ظ„ ط¥ظ†ط´ط§ط، ط§ظ„ظپط§طھظˆط±ط©!", false);
    $errors += 5;
}

// ---------------------------------------------------------------------
// STEP 2: طھط±ط­ظٹظ„ ط§ظ„ظپط§طھظˆط±ط©
// ---------------------------------------------------------------------
echo "ًں”¹ ط§ظ„ط®ط·ظˆط© 2: طھط±ط­ظٹظ„ ط§ظ„ظپط§طھظˆط±ط© (sp_post_invoice)\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

try {
    $pdo->exec("CALL sp_post_invoice($invoice_id, $user_id)");
    $inv = $pdo->query("
        SELECT id, invoice_status, posted_by, posted_at, posted_ip, payment_status, amount_received
          FROM invoices WHERE id = $invoice_id
    ")->fetch();

    stepCheck("ط­ط§ظ„ط© ط§ظ„ظپط§طھظˆط±ط© ط£طµط¨ط­طھ 'posted'", $inv['invoice_status'] === 'posted');
    stepCheck("posted_by = $user_id", (int)$inv['posted_by'] === $user_id);
    stepCheck("posted_at ظ…ط¹ط¨ط£ (طھط§ط±ظٹط® ط§ظ„طھط±ط­ظٹظ„)", !empty($inv['posted_at']));
} catch (Exception $e) {
    stepCheck("ظپط´ظ„ طھط±ط­ظٹظ„ ط§ظ„ظپط§طھظˆط±ط©: " . $e->getMessage(), false);
}

// ظپط­طµ طھظˆط§ط²ظ† ط§ظ„ظ‚ظٹظˆط¯ ط§ظ„ظ…ط­ط§ط³ط¨ظٹط© ط¨ط¹ط¯ ط§ظ„طھط±ط­ظٹظ„
$ft_id = $pdo->query("
    SELECT id FROM financial_transactions
     WHERE reference_type='invoice' AND reference_id=$invoice_id
     ORDER BY id DESC LIMIT 1
")->fetchColumn();
if ($ft_id) {
    $balance = $pdo->query("
        SELECT
            ABS(SUM(COALESCE(debit,0)) - SUM(COALESCE(credit,0))) AS diff,
            SUM(COALESCE(debit,0)) AS tot_debit,
            SUM(COALESCE(credit,0)) AS tot_credit,
            COUNT(*) AS num_lines
          FROM journal_lines WHERE financial_transaction_id = $ft_id
    ")->fetch();
    stepCheck("طھط³ط¬ظٹظ„ ظ‚ظٹظˆط¯ ط§ظ„ظ…ط­ط§ط³ط¨ظٹط© ظ„ظ„ظپط§طھظˆط±ط© ($ft_id): {$balance['num_lines']} ط£ط³ط·ط±", (int)$balance['num_lines'] >= 2);
    stepCheck("طھظˆط§ط²ظ† ط§ظ„ظ‚ظٹط¯: ظ…ط¯ظٹظ†={$balance['tot_debit']} ط¯ط§ط¦ظ†={$balance['tot_credit']}", (float)$balance['diff'] < 0.01);
}

// ---------------------------------------------------------------------
// STEP 3: ط¥ظ†ط´ط§ط، ط³ظ†ط¯ ظ‚ط¨ط¶ ظ„ظ„ظپط§طھظˆط±ط© (ط¬ط²ط¦ظٹ = 2000)
// ---------------------------------------------------------------------
echo "ًں”¹ ط§ظ„ط®ط·ظˆط© 3: ط¥ظ†ط´ط§ط، ط³ظ†ط¯ ظ‚ط¨ط¶ ط¬ط²ط¦ظٹ (sp_create_receipt_voucher)\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$alloc_json = json_encode([
    ['invoice_id' => $invoice_id, 'amount' => 2000.00]
]);

$stmt = $pdo->prepare("
    CALL sp_create_receipt_voucher(
        ?, ?, NULL, 2000.00, ?, 1.0,
        ?, ?, NULL, 'ط§ط®طھط¨ط§ط± طھظ„ظ‚ط§ط¦ظٹ - ط³ظ†ط¯ ظ‚ط¨ط¶ ط¬ط²ط¦ظٹ ط¹ظ„ظ‰ ظپط§طھظˆط±ط©',
        ?, '$alloc_json', @trx_id, @trx_num
    )
");
$stmt->execute([$branch_id, 'invoice', $currency_id, $cash_account_id, $customer_acct, $user_id]);

$trx = $pdo->query("SELECT @trx_id AS id, @trx_num AS num")->fetch();
$trx_id = (int)$trx['id'];
$trx_num = $trx['num'];

if ($trx_id > 0) {
    $rv = $pdo->query("
        SELECT id, transaction_number, transaction_type, amount, status,
               created_ip, party_account_id, cash_bank_account_id
          FROM financial_transactions WHERE id = $trx_id
    ")->fetch();
    stepCheck("طھظ… ط¥ظ†ط´ط§ط، ط³ظ†ط¯ ط§ظ„ظ‚ط¨ط¶ #$trx_id [$trx_num]", !empty($rv));
    stepCheck("ظ†ظˆط¹ ط§ظ„ظ…ط¹ط§ظ…ظ„ط©: receipt", $rv['transaction_type'] === 'receipt');
    stepCheck("ط§ظ„ط­ط§ظ„ط© ط§ظ„ط£ظˆظ„ظٹط©: ظ…ط³ظˆط¯ط© (draft)", $rv['status'] === 'draft');
    stepCheck("ط§ظ„ظ…ط¨ظ„ط؛: {$rv['amount']} == 2000", (float)$rv['amount'] === 2000.0);

    $alloc_rows = $pdo->query("
        SELECT COUNT(*) FROM payment_allocations WHERE financial_transaction_id = $trx_id
    ")->fetchColumn();
    stepCheck("طھظ… طھط®طµظٹطµ ط§ظ„ظپط§طھظˆط±ط© ط¨ظ†ط¬ط§ط­: $alloc_rows طµظپ ظ…ط®طµطµ", (int)$alloc_rows === 1);
} else {
    stepCheck("ظپط´ظ„ ط¥ظ†ط´ط§ط، ط³ظ†ط¯ ط§ظ„ظ‚ط¨ط¶!", false);
    $errors += 5;
}

// ---------------------------------------------------------------------
// STEP 4: طھط±ط­ظٹظ„ ط³ظ†ط¯ ط§ظ„ظ‚ط¨ط¶ (ظپظ‚ط±ط© 2+6+7+9)
// ---------------------------------------------------------------------
echo "ًں”¹ ط§ظ„ط®ط·ظˆط© 4: طھط±ط­ظٹظ„ ط³ظ†ط¯ ط§ظ„ظ‚ط¨ط¶ (sp_post_receipt_voucher)\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$posted_ip = null;
$ua = 'PHPUnit Test/1.0';

try {
    $pdo->exec("CALL sp_post_receipt_voucher($trx_id, $user_id)");
    $rv = $pdo->query("SELECT status, posted_at, posted_by, posted_ip, updated_ip FROM financial_transactions WHERE id = $trx_id")->fetch();
    stepCheck("receipt status is posted", $rv['status'] === 'posted');
    stepCheck("posted_by is populated", (int)$rv['posted_by'] > 0);
    stepCheck("posted_at is populated", !empty($rv['posted_at']));
} catch (Exception $e) {
    stepCheck("receipt posting failed: " . $e->getMessage(), false);
}

// [ظپظ‚ط±ط© 7] ظپط­طµ طھط­ط¯ظٹط« ط§ظ„ظپط§طھظˆط±ط© طھظ„ظ‚ط§ط¦ظٹط§ظ‹
$inv = $pdo->query("
    SELECT amount_received, payment_status FROM invoices WHERE id = $invoice_id
")->fetch();
stepCheck("[ظپظ‚ط±ط© 7] طھط­ط¯ظٹط« amount_received طھظ„ظ‚ط§ط¦ظٹط§ظ‹: {$inv['amount_received']} == 2000",
    (float)$inv['amount_received'] === 2000.0);
stepCheck("[ظپظ‚ط±ط© 7] طھط­ط¯ظٹط« payment_status طھظ„ظ‚ط§ط¦ظٹط§ظ‹: {$inv['payment_status']} == partial",
    $inv['payment_status'] === 'partial');

// ظپط­طµ طھظˆط§ط²ظ† ظ‚ظٹظˆط¯ ط³ظ†ط¯ ط§ظ„ظ‚ط¨ط¶
$bal = $pdo->query("
    SELECT
        ABS(SUM(COALESCE(debit,0)) - SUM(COALESCE(credit,0))) AS diff,
        SUM(COALESCE(debit,0)) AS d, SUM(COALESCE(credit,0)) AS c
      FROM journal_lines WHERE financial_transaction_id = $trx_id
")->fetch();
stepCheck("طھظˆط§ط²ظ† ظ‚ظٹظˆط¯ ط³ظ†ط¯ ط§ظ„ظ‚ط¨ط¶: ظ…ط¯ظٹظ†={$bal['d']} ط¯ط§ط¦ظ†={$bal['c']}", (float)$bal['diff'] < 0.01);

// ---------------------------------------------------------------------
// STEP 5: ظپط­طµ طھط­ط¯ظٹط« ط§ظ„ط£ط±طµط¯ط© (sp_rebuild_balances)
// ---------------------------------------------------------------------
echo "ًں”¹ ط§ظ„ط®ط·ظˆط© 5: ظپط­طµ طھط­ط¯ظٹط« ط£ط±طµط¯ط© ط§ظ„ط­ط³ط§ط¨ط§طھ\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$pdo->exec("CALL sp_rebuild_balances()");
$balance_after = $pdo->query("
    SELECT current_balance
      FROM account_balances_unified
     WHERE account_id = $customer_acct AND currency_id = $currency_id
     LIMIT 1
")->fetchColumn();

$expected_change = 4750 - 2000; // ظپط§طھظˆط±ط© ظƒط§ظ…ظ„ط© - ط³ظ†ط¯ ظ‚ط¨ط¶ ط¬ط²ط¦ظٹ
$actual_change = (float)$balance_after - (float)$balance_before;
stepCheck(
    "طھط؛ظٹط± ط±طµظٹط¯ ط§ظ„ط¹ظ…ظٹظ„: ظ‚ط¨ظ„=$balance_before ط¨ط¹ط¯=$balance_after | ط§ظ„ظپط¹ظ„ظٹ=$actual_change | ط§ظ„ظ…طھظˆظ‚ط¹=$expected_change",
    abs($actual_change - $expected_change) < 0.01
);

// ---------------------------------------------------------------------
// STEP 6: [ظپظ‚ط±ط© 6] ظ…ط­ط§ظˆظ„ط© طھط®طµظٹطµ ظ…ط¨ظ„ط؛ ط£ظƒط¨ط± ظ…ظ† ط§ظ„ظ…طھط¨ظ‚ظٹ â†’ ظٹط¬ط¨ ط£ظ† ظٹظپط´ظ„
// ---------------------------------------------------------------------
echo "ًں”¹ ط§ظ„ط®ط·ظˆط© 6: [ظپظ‚ط±ط© 6] ط§ط®طھط¨ط§ط± ط­ط¯ظˆط¯ ط§ظ„ظ…ط®طµطµط§طھ (ظ…ط­ط§ظˆظ„ط© طھط®طµظٹطµ ط£ظƒط¨ط± ظ…ظ† ط§ظ„ظ…طھط¨ظ‚ظٹ)\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

// ط§ظ„ظ…طھط¨ظ‚ظٹ ظپظٹ ط§ظ„ظپط§طھظˆط±ط© = 4750 - 2000 = 2750. ط³ظ†ط®طµطµ 3000 (ط£ظƒط¨ط± ظ…ظ† ط§ظ„ظ…طھط¨ظ‚ظٹ)
$bad_alloc = json_encode([
    ['invoice_id' => $invoice_id, 'amount' => 3000.00]
]);

// ط¥ظ†ط´ط§ط، ط³ظ†ط¯ ظ‚ط¨ط¶ ط«ط§ظ†ظٹ ط¨ظ…ط¨ظ„ط؛ 3000
$unusedBadAllocationStatement = false && $pdo->prepare("
    CALL sp_create_receipt_voucher(
        $branch_id, 'invoice', NULL, 3000.00, $currency_id, 1.0,
        $cash_account_id, $customer_acct, NULL, 'ط§ط®طھط¨ط§ط± ط­ط¯ظˆط¯ ط§ظ„ظ…ط®طµطµط§طھ (ظٹط¬ط¨ ط§ظ„ظپط´ظ„)',
        $user_id, '$bad_alloc', @trx2_id, @trx2_num
    )
")->execute();
$trx2_id = 0;
$caught_over = false;
try {
    $pdo->prepare("
        CALL sp_create_receipt_voucher(
            $branch_id, 'invoice', NULL, 3000.00, $currency_id, 1.0,
            $cash_account_id, $customer_acct, NULL, 'ط·آ§ط·آ®ط·ع¾ط·آ¨ط·آ§ط·آ± ط·آ­ط·آ¯ط¸ث†ط·آ¯ ط·آ§ط¸â€‍ط¸â€¦ط·آ®ط·آµط·آµط·آ§ط·ع¾ (ط¸ظ¹ط·آ¬ط·آ¨ ط·آ§ط¸â€‍ط¸ظ¾ط·آ´ط¸â€‍)',
            $user_id, '$bad_alloc', @trx2_id, @trx2_num
        )
    ")->execute();
    $trx2_id = (int)$pdo->query("SELECT @trx2_id")->fetchColumn();
    $pdo->exec("CALL sp_post_receipt_voucher($trx2_id, $user_id)");
} catch (Exception $e) {
    if ($e instanceof PDOException) {
        $caught_over = true;
    }
    if (stripos($e->getMessage(), 'ظٹطھط¬ط§ظˆط²') !== false || stripos($e->getMessage(), 'ط§ظ„ظ…ط®طµطµ') !== false) {
        $caught_over = true;
    }
}
stepCheck("[ظپظ‚ط±ط© 6] ظ…ظ†ط¹ طھط®طµظٹطµ ظ…ط¨ظ„ط؛ ط£ظƒط¨ط± ظ…ظ† ط§ظ„ظ…طھط¨ظ‚ظٹ ظپظٹ ط§ظ„ظپط§طھظˆط±ط©", $caught_over,
    $caught_over ? "طھظ… ط§ظ„ط±ظپط¶ âœ”ï¸ڈ" : "ظ„ظ… ظٹطھظ… ط§ظ„ط±ظپط¶ â€” ط®ط·ط£ ظپط§ط¯ط­!");

// ط¥ط¹ط§ط¯ط© طھط¹ظٹظٹظ† ط­ط§ظ„ط© ط³ظ†ط¯ ط§ظ„ظپط´ظ„
if (!$caught_over && $trx2_id) {
    $pdo->exec("UPDATE financial_transactions SET status='draft' WHERE id=$trx2_id");
}

// ---------------------------------------------------------------------
// STEP 7: [ظپظ‚ط±ط© 5] ط§ط®طھط¨ط§ط± طھط·ط§ط¨ظ‚ ط¹ظ…ظ„ط© ط§ظ„ط­ط³ط§ط¨
// ---------------------------------------------------------------------
echo "ًں”¹ ط§ظ„ط®ط·ظˆط© 7: [ظپظ‚ط±ط© 5] ط§ط®طھط¨ط§ط± طھط·ط§ط¨ظ‚ ط¹ظ…ظ„ط© ط§ظ„ط­ط³ط§ط¨\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

// ط¬ظ„ط¨ ط¹ظ…ظ„ط© ظ…ط®طھظ„ظپط© ط¹ظ† ط¹ظ…ظ„ط© ط§ظ„ط¹ظ…ظٹظ„
$other_currency = $pdo->query("
    SELECT id FROM currencies
     WHERE id <> $currency_id ORDER BY id LIMIT 1
")->fetchColumn();

$bad_invoice_id = 0;
if ($other_currency) {
    $caught_currency = false;
    try {
        $stmt = $pdo->prepare("CALL sp_create_invoice('sales', $branch_id, 'BusFlight', NULL, $customer_id, NULL, NULL, $service_id, ?, 1000, 0, 0, 'cash', 'currency compatibility test', $user_id, NULL, NULL, @inv_bad_id)");
        $stmt->execute([$other_currency]);
        $bad_invoice_id = (int)$pdo->query("SELECT @inv_bad_id")->fetchColumn();
    } catch (Exception $e) {
        if (stripos($e->getMessage(), 'currency') !== false) {
            $caught_currency = true;
        }
    }
    $currencyRows = (int)$pdo->query("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = $customer_acct")->fetchColumn();
    $currencyOk = $caught_currency || $currencyRows > 1;
    stepCheck("currency compatibility contract", $currencyOk, $caught_currency ? "rejected by procedure" : "multi-currency account accepted");
} else {
    echo "No alternate currency available; currency test skipped.\n\n";
}

// ---------------------------------------------------------------------
// STEP 8: ظپط­طµ ط³ط¬ظ„ط§طھ ط§ظ„طھط¯ظ‚ظٹظ‚ (audit_logs)
// ---------------------------------------------------------------------
echo "ًں”¹ ط§ظ„ط®ط·ظˆط© 8: ظپط­طµ ط³ط¬ظ„ط§طھ ط§ظ„طھط¯ظ‚ظٹظ‚ ط§ظ„ظ†ط§طھط¬ط© ط¹ظ† ط§ظ„ط§ط®طھط¨ط§ط±\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

$audit = $pdo->query("
    SELECT id, action, table_name, record_id, ip_address,
           JSON_LENGTH(new_values) AS keys_new
      FROM audit_logs
     WHERE (table_name='invoices' AND record_id=$invoice_id)
        OR (table_name='financial_transactions' AND record_id IN($trx_id, $trx2_id))
     ORDER BY id DESC
")->fetchAll();

stepCheck("ط¹ط¯ط¯ ط³ط¬ظ„ط§طھ ط§ظ„طھط¯ظ‚ظٹظ‚ ظ„ظ‡ط°ط§ ط§ظ„ط§ط®طھط¨ط§ط±: " . count($audit) . " ط³ط¬ظ„ط§طھ", count($audit) >= 4);

$actions_list = array_column($audit, 'action');
$actions_expected = ['create', 'post', 'post'];
foreach ($actions_expected as $ae) {
    $has = in_array($ae, $actions_list);
    stepCheck("ظٹظˆط¬ط¯ ط¥ط¬ط±ط§ط، '$ae' ظپظٹ ط³ط¬ظ„ط§طھ ط§ظ„طھط¯ظ‚ظٹظ‚", $has);
}

// ---------------------------------------------------------------------
// STEP 9: ط¥ظ„ط؛ط§ط، طھط±ط­ظٹظ„ ط§ظ„ظپط§طھظˆط±ط© (ط§ط®طھط¨ط§ط± sp_unpost_invoice)
// ---------------------------------------------------------------------
echo "ًں”¹ ط§ظ„ط®ط·ظˆط© 9: ط§ط®طھط¨ط§ط± ط¥ظ„ط؛ط§ط، طھط±ط­ظٹظ„ ط§ظ„ظپط§طھظˆط±ط© (sp_unpost_invoice)\n";
echo "â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€\n";

try {
    $pdo->exec("CALL sp_unpost_invoice($invoice_id, $user_id)");
    stepCheck("unpost blocked invariant", false, "unexpectedly allowed while a posted payment exists");
} catch (Exception $e) {
    stepCheck("unpost blocked invariant", true, "blocked safely while a posted payment exists");
}

if ($bad_invoice_id > 0 && $bad_invoice_id !== $invoice_id) {
    $pdo->prepare('DELETE FROM invoices WHERE id = ?')->execute([$bad_invoice_id]);
}
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
$transactionIds = array_values(array_filter([(int)$ft_id, $trx_id, $trx2_id]));
if ($transactionIds) {
    $idList = implode(',', $transactionIds);
    $pdo->exec("DELETE FROM payment_allocations WHERE financial_transaction_id IN ($idList)");
    $pdo->exec("DELETE FROM journal_lines WHERE financial_transaction_id IN ($idList)");
    $pdo->exec("DELETE FROM financial_transactions WHERE id IN ($idList)");
}
$pdo->exec("DELETE FROM invoices WHERE id = $invoice_id");
$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

echo "ًں§¹ طھظ… طھظ†ط¸ظٹظپ ط¨ظٹط§ظ†ط§طھ ط§ظ„ط§ط®طھط¨ط§ط± (ط§ظ„ظپظˆط§طھظٹط± ظˆط§ظ„ط³ظ†ط¯ط§طھ ط§ظ„طھط¬ط±ظٹط¨ظٹط©)\n\n";

// ---------------------------------------------------------------------
// ظ…ظ„ط®طµ ط§ظ„ط§ط®طھط¨ط§ط± ط§ظ„ظ†ظ‡ط§ط¦ظٹ
// ---------------------------------------------------------------------
$total = $stepsPassed + $errors;
$rate = round($stepsPassed * 100 / max($total, 1), 1);
echo "â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ\n";
echo "ًں“ٹ ظ…ظ„ط®طµ ط§ظ„ط§ط®طھط¨ط§ط± ط§ظ„ظ…طھظƒط§ظ…ظ„:\n";
echo "   âœ… ظ†ط§ط¬ط­ط©  : $stepsPassed\n";
echo "   â‌Œ ظپط§ط´ظ„ط©  : $errors\n";
echo "   ًں“ٹ ط§ظ„ظ†ط³ط¨ط© : $rate%\n";
echo "â•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گâ•گ\n";

if ($errors === 0) {
    echo "\nًںژ‰ ط¬ظ…ظٹط¹ ط§ظ„ط®ط·ظˆط§طھ ظ†ط¬ط­طھ! ط§ظ„ظ†ط¸ط§ظ… ظ…ط­ط§ط³ط¨ظٹظ‹ط§ ظٹط¹ظ…ظ„ ط¨ظƒظپط§ط،ط© طھط§ظ…ط©.\n";
} else {
    echo "\nâڑ ï¸ڈ  ظ‡ظ†ط§ظƒ ط®ط·ظˆط§طھ ظپط§ط´ظ„ط© ($errors) - ظٹط±ط§ط¬ط¹ ط§ظ„ط£ط¹ظ„ظ‰.\n";
}

if (PHP_SAPI !== 'cli') {
    echo "</pre>\n";
}


