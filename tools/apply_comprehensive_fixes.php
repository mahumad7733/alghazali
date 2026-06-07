
<?php
require_once 'includes/db.php';

echo "=== Applying comprehensive database fixes ===\n";

try {
    $sqlFile = __DIR__ . '/comprehensive_fixes.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }

    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new Exception("Failed to read SQL file: $sqlFile");
    }

    // Execute the SQL (using a simpler approach for now)
    $pdo->exec($sql);
    
    echo "\n=== Fixes applied successfully! ===\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n❌ Error applying fixes: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

