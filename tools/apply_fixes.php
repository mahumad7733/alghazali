<?php
require_once 'includes/db.php';

echo "=== Starting to apply fixes ===\n";

try {
    // Read the fix SQL file
    $sqlFile = __DIR__ . '/fix_all_critical_issues.sql';
    if (!file_exists($sqlFile)) {
        die("Error: fix_all_critical_issues.sql not found at $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split SQL into individual statements (handling DELIMITER changes)
    $statements = [];
    $currentStatement = '';
    $delimiter = ';';
    $lines = explode("\n", $sql);
    
    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        
        // Check for DELIMITER change
        if (strtoupper(substr($trimmedLine, 0, 9)) === 'DELIMITER') {
            $delimiter = trim(substr($trimmedLine, 9));
            continue;
        }
        
        $currentStatement .= $line . "\n";
        
        // Check if we've reached the delimiter
        if (substr(trim($currentStatement), -strlen($delimiter)) === $delimiter) {
            $statements[] = trim(substr($currentStatement, 0, -strlen($delimiter)));
            $currentStatement = '';
        }
    }
    
    // Add any remaining statement
    if (!empty(trim($currentStatement))) {
        $statements[] = trim($currentStatement);
    }
    
    // Execute each statement
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    foreach ($statements as $index => $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        
        echo "Executing statement " . ($index + 1) . " of " . count($statements) . "...\n";
        try {
            $pdo->exec($statement);
            echo "✅ Success\n";
        } catch (PDOException $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
            echo "Statement: " . substr($statement, 0, 200) . "...\n";
        }
    }
    
    echo "\n=== Fix application complete! ===\n";
    
} catch (Exception $e) {
    echo "Fatal error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}
