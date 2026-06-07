<?php
// Override database settings for alghazali
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db = 'alghazali';
$charset = 'utf8mb4';
$collation = 'utf8mb4_unicode_ci';

echo "=== Verifying alghazali database fixes ===\n";

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);
    $pdo->exec("SET NAMES utf8mb4 COLLATE $collation");

    // 1. Check fn_get_default_leaf_account
    echo "\n1. Checking fn_get_default_leaf_account:\n";
    $stmt = $pdo->prepare("SELECT fn_get_default_leaf_account('11101001') AS cash, fn_get_default_leaf_account('11102001') AS bank, fn_get_default_leaf_account('11101') AS cash_old, fn_get_default_leaf_account('11102') AS bank_old");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "  11101001: " . ($row['cash'] ? "✅ Found (id={$row['cash']})" : "❌ Not found") . "\n";
    echo "  11102001: " . ($row['bank'] ? "✅ Found (id={$row['bank']})" : "❌ Not found") . "\n";
    echo "  11101: " . ($row['cash_old'] ? "✅ Found (id={$row['cash_old']})" : "❌ Not found") . "\n";
    echo "  11102: " . ($row['bank_old'] ? "✅ Found (id={$row['bank_old']})" : "❌ Not found") . "\n";

    // 2. Check account 113
    echo "\n2. Checking account 113 and children:\n";
    $stmt = $pdo->prepare("SELECT id, account_code, account_name_ar, parent_id FROM unified_accounts WHERE account_code LIKE '113%' ORDER BY account_code");
    $stmt->execute();
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($accounts as $acc) {
        echo "  - {$acc['account_code']}: {$acc['account_name_ar']}, parent_id={$acc['parent_id']}\n";
    }
    $account113 = array_filter($accounts, fn($a) => $a['account_code'] === '113');
    if ($account113) {
        echo "  ✅ Account 113 exists\n";
    } else {
        echo "  ❌ Account 113 not found\n";
    }

    // 3. Check indexes
    echo "\n3. Checking indexes:\n";
    $stmt = $pdo->prepare("SHOW INDEX FROM journal_lines WHERE Key_name = 'idx_jl_account_currency'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "  ✅ idx_jl_account_currency exists\n";
    } else {
        echo "  ❌ idx_jl_account_currency not found\n";
    }
    $stmt = $pdo->prepare("SHOW INDEX FROM financial_transactions WHERE Key_name = 'idx_ft_created_at'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "  ✅ idx_ft_created_at exists\n";
    } else {
        echo "  ❌ idx_ft_created_at not found\n";
    }

    // 4. Check stored procedures have transactions/rollback
    echo "\n4. Checking stored procedures:\n";
    $procs = ['sp_post_invoice', 'sp_post_receipt_voucher', 'sp_post_payment_voucher'];
    foreach ($procs as $proc) {
        try {
            $stmt = $pdo->prepare("SHOW CREATE PROCEDURE $proc");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $hasStartTx = strpos($row['Create Procedure'], 'START TRANSACTION') !== false;
            $hasRollback = strpos($row['Create Procedure'], 'ROLLBACK') !== false;
            echo "  $proc: " . ($hasStartTx ? '✅ START TRANSACTION' : '❌ No START TRANSACTION') . ", " . ($hasRollback ? '✅ ROLLBACK' : '❌ No ROLLBACK') . "\n";
        } catch (Exception $e) {
            echo "  $proc: ❌ Error - " . $e->getMessage() . "\n";
        }
    }

    echo "\n=== Verification Complete ===\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
