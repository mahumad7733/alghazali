<?php
require_once 'includes/db.php';

try {
    echo "Checking passports table structure...\n";
    
    // Get all indexes/constraints
    $stmt = $pdo->query("SHOW INDEX FROM passports");
    $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($indexes as $idx) {
        echo "Index: " . $idx['Key_name'] . " - Column: " . $idx['Column_name'] . " - Unique: " . ($idx['Non_unique'] ? 'No' : 'Yes') . "\n";
        
        // If there's a unique index on passport_number, drop it
        if ($idx['Column_name'] === 'passport_number' && !$idx['Non_unique'] == 0) {
            echo "\nDropping unique index on passport_number...\n";
            $pdo->exec("ALTER TABLE passports DROP INDEX " . $idx['Key_name']);
            echo "✅ Unique index dropped successfully!\n";
        }
    }
    
    echo "\n✅ Done!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
