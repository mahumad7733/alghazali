<?php
$host = '127.0.0.1';
$user = 'root';
$pass = '738155';
$db = 'alghazali';

echo "=== Checking if SQL scripts were applied to alghazali database ===\n";

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    $pdo = new PDO($dsn, $user, $pass, $options);

    // List of things to check
    $checks = [
        'functions' => ['fn_get_default_leaf_account', 'fn_get_account_by_type'],
        'procedures' => ['sp_post_invoice', 'sp_post_receipt_voucher', 'sp_post_payment_voucher', 'sp_recalculate_invoice_payment', 'sp_unpost_invoice'],
        'indexes' => [
            ['table' => 'journal_lines', 'key' => 'idx_jl_account_currency'],
            ['table' => 'financial_transactions', 'key' => 'idx_ft_created_at']
        ],
        'accounts' => ['113', '11301', '11302', '11101001', '11102001']
    ];

    echo "\n--- Checking Functions ---\n";
    foreach ($checks['functions'] as $func) {
        $stmt = $pdo->prepare("SHOW FUNCTION STATUS WHERE Db = ? AND Name = ?");
        $stmt->execute([$db, $func]);
        if ($stmt->rowCount() > 0) {
            echo "✅ Function $func: Found\n";
        } else {
            echo "❌ Function $func: NOT Found\n";
        }
    }

    echo "\n--- Checking Procedures ---\n";
    foreach ($checks['procedures'] as $proc) {
        $stmt = $pdo->prepare("SHOW PROCEDURE STATUS WHERE Db = ? AND Name = ?");
        $stmt->execute([$db, $proc]);
        if ($stmt->rowCount() > 0) {
            echo "✅ Procedure $proc: Found\n";
        } else {
            echo "❌ Procedure $proc: NOT Found\n";
        }
    }

    echo "\n--- Checking Indexes ---\n";
    foreach ($checks['indexes'] as $idx) {
        $stmt = $pdo->prepare("SHOW INDEX FROM `{$idx['table']}` WHERE Key_name = ?");
        $stmt->execute([$idx['key']]);
        if ($stmt->rowCount() > 0) {
            echo "✅ Index {$idx['key']} on {$idx['table']}: Found\n";
        } else {
            echo "❌ Index {$idx['key']} on {$idx['table']}: NOT Found\n";
        }
    }

    echo "\n--- Checking Accounts ---\n";
    foreach ($checks['accounts'] as $code) {
        $stmt = $pdo->prepare("SELECT id, account_name_ar FROM unified_accounts WHERE account_code = ?");
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        if ($row) {
            echo "✅ Account $code: Found (id={$row['id']}, name={$row['account_name_ar']})\n";
        } else {
            echo "❌ Account $code: NOT Found\n";
        }
    }

    echo "\n--- Checking for trg_after_employee_insert Trigger: ";
    $stmt = $pdo->prepare("SHOW TRIGGERS FROM `$db` WHERE `Trigger` = 'trg_after_employee_insert'");
    $stmt->execute();
    if ($stmt->rowCount() > 0) {
        echo "✅ Found\n";
    } else {
        echo "❌ NOT Found\n";
    }

    echo "\n=== Check Complete ===\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
