<?php
/**
 * Phase 3 regression: posted financial records remain immutable.
 * Runs only against alghazali_refactor_test and rolls back all probe data.
 */
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/accounting_functions.php';

if (getenv('DB_NAME') !== 'alghazali_refactor_test') {
    throw new RuntimeException('Refusing to run outside alghazali_refactor_test.');
}

function p3_expect_blocked(callable $operation, string $label): bool
{
    try {
        $operation();
        echo "FAIL $label: operation was allowed\n";
        return false;
    } catch (Throwable $exception) {
        echo "PASS $label: " . $exception->getMessage() . "\n";
        return true;
    }
}

$pdo->beginTransaction();
try {
    $suffix = bin2hex(random_bytes(5));
    $userId = (int)$pdo->query('SELECT id FROM users WHERE status = \'active\' ORDER BY id LIMIT 1')->fetchColumn();
    $branchId = (int)$pdo->query('SELECT id FROM branches ORDER BY id LIMIT 1')->fetchColumn();
    $currencyId = (int)$pdo->query('SELECT id FROM currencies ORDER BY id LIMIT 1')->fetchColumn();
    $accounts = $pdo->query('SELECT id FROM unified_accounts WHERE is_active = 1 ORDER BY id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    if ($userId <= 0 || $branchId <= 0 || $currencyId <= 0 || count($accounts) < 2) {
        throw new RuntimeException('Missing test prerequisites.');
    }

    $pdo->prepare(
        "INSERT INTO financial_transactions
         (transaction_number, transaction_date, branch_id, transaction_type, amount,
          currency_id, exchange_rate, status, created_by, description)
         VALUES (?, CURDATE(), ?, 'receipt', 10, ?, 1, 'draft', ?, 'Phase 3 immutability probe')"
    )->execute(['P3-IMM-' . $suffix, $branchId, $currencyId, $userId]);
    $voucherId = (int)$pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO journal_lines
         (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description)
         VALUES (?, ?, 10, 0, ?, ?, ?), (?, ?, 0, 10, ?, ?, ?)'
    )->execute([
        $voucherId, $accounts[0], $currencyId, $branchId, 'P3 debit',
        $voucherId, $accounts[1], $currencyId, $branchId, 'P3 credit',
    ]);
    $pdo->prepare("UPDATE financial_transactions SET status = 'posted', posted_by = ?, posted_at = NOW() WHERE id = ?")
        ->execute([$userId, $voucherId]);

    $lineId = (int)$pdo->query("SELECT id FROM journal_lines WHERE financial_transaction_id = $voucherId ORDER BY id LIMIT 1")->fetchColumn();
    $passed = 0;
    $passed += p3_expect_blocked(
        fn() => $pdo->prepare('DELETE FROM journal_lines WHERE financial_transaction_id = ?')->execute([$voucherId]),
        'posted journal-line delete blocked'
    ) ? 1 : 0;
    $passed += p3_expect_blocked(
        fn() => $pdo->prepare('DELETE FROM financial_transactions WHERE id = ?')->execute([$voucherId]),
        'posted voucher delete blocked'
    ) ? 1 : 0;
    $passed += p3_expect_blocked(
        fn() => $pdo->prepare('UPDATE journal_lines SET debit = 9 WHERE id = ?')->execute([$lineId]),
        'posted journal-line update blocked'
    ) ? 1 : 0;

    // The valid unpost transition preserves immutable journal evidence.
    $pdo->prepare('UPDATE financial_transactions SET status = \'draft\', posted_at = NULL, posted_by = NULL, updated_by = ? WHERE id = ?')
        ->execute([$userId, $voucherId]);
    $linesAfterUnpost = (int)$pdo->query("SELECT COUNT(*) FROM journal_lines WHERE financial_transaction_id = $voucherId")->fetchColumn();
    $statusAfterUnpost = (string)$pdo->query("SELECT status FROM financial_transactions WHERE id = $voucherId")->fetchColumn();
    $unpostPass = $statusAfterUnpost === 'draft' && $linesAfterUnpost === 2;
    echo ($unpostPass ? 'PASS' : 'FAIL') . " unpost preserves lines: status=$statusAfterUnpost lines=$linesAfterUnpost\n";
    $passed += $unpostPass ? 1 : 0;

    $pdo->rollBack();
    echo "SUMMARY passed=$passed total=4\n";
    exit($passed === 4 ? 0 : 1);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR ' . $exception->getMessage() . "\n");
    exit(1);
}
