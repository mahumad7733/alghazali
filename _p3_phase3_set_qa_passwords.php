<?php
/**
 * Phase 3 test-only credential setup.
 * Scope guard: refuses any database other than alghazali_refactor_test.
 */
require_once __DIR__ . '/includes/db.php';

if (getenv('DB_NAME') !== 'alghazali_refactor_test') {
    throw new RuntimeException('Refusing to alter a non-test database.');
}

$testPassword = 'Phase3LocalOnly!2026';
$statement = $pdo->prepare(
    "UPDATE users
        SET password = ?
      WHERE username LIKE 'qa\\_%\\_20260811' ESCAPE '\\\\'"
);
$statement->execute([password_hash($testPassword, PASSWORD_DEFAULT)]);

echo 'Updated isolated QA credentials: ' . $statement->rowCount() . "\n";
