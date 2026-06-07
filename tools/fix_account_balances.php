
<?php
require_once 'includes/db.php';
echo "<h2>Fixing Account Balances</h2>";

try {
    $pdo->beginTransaction();
    
    // For account 5, currency 1: delete all except the first one (id 1444)
    $stmt = $pdo->prepare("
        DELETE FROM account_balances_unified 
        WHERE account_id = 5 
        AND currency_id = 1 
        AND id != 1444
    ");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    echo "<p>Deleted $deleted duplicate rows for account 5, currency 1</p>";
    
    $pdo->commit();
    echo "<p>Success!</p>";
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<p>Error: " . $e->getMessage() . "</p>";
}

// Check current state
echo "<h3>Current account_balances_unified for account 5</h3>";
$stmt = $pdo->prepare("SELECT * FROM account_balances_unified WHERE account_id = ?");
$stmt->execute([5]);
echo "<pre>";
print_r($stmt->fetchAll());
echo "</pre>";
?>
