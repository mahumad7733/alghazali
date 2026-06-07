<?php
require_once 'includes/db.php';

try {
    $sql = file_get_contents('database/migration.sql');
    
    // Split into individual statements (simplified)
    $statements = explode(';', $sql);
    
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if (empty($stmt) || strpos($stmt, '--') === 0) {
            continue;
        }
        
        try {
            echo "Executing: " . substr($stmt, 0, 100) . "...\n";
            $pdo->exec($stmt);
            echo "✅ Done!\n";
        } catch (Exception $e) {
            echo "⚠️ Warning: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n✅ Migration applied (with possible warnings for existing columns/indexes)!\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
