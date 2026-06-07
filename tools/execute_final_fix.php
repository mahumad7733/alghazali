<?php
require_once 'includes/db.php';

echo "=== Executing Final Fix Script ===\n";

function splitSqlFile(string $sql): array {
    $statements = [];
    $current = '';
    $delimiter = ';';
    $inString = false;
    $stringChar = '';
    $inComment = false;
    $inMultiComment = false;
    $len = strlen($sql);
    
    for ($i = 0; $i < $len; $i++) {
        $char = $sql[$i];
        $nextChar = $i + 1 < $len ? $sql[$i + 1] : '';
        
        // Handle comments
        if (!$inString && !$inMultiComment && $char === '-' && $nextChar === '-') {
            $inComment = true;
            $i++;
            continue;
        }
        if (!$inString && !$inComment && $char === '/' && $nextChar === '*') {
            $inMultiComment = true;
            $i++;
            continue;
        }
        if (!$inString && !$inComment && $char === '*' && $nextChar === '/') {
            $inMultiComment = false;
            $i++;
            continue;
        }
        if ($inComment && $char === "\n") {
            $inComment = false;
            continue;
        }
        if ($inComment || $inMultiComment) {
            continue;
        }
        
        // Handle strings
        if (!$inString && ($char === "'" || $char === '"')) {
            $inString = true;
            $stringChar = $char;
            $current .= $char;
            continue;
        }
        if ($inString && $char === $stringChar) {
            $inString = false;
            $current .= $char;
            continue;
        }
        
        // Handle DELIMITER
        if (!$inString && strtoupper(substr($sql, $i, 9)) === 'DELIMITER') {
            // Skip to end of line
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            // Get new delimiter
            $rest = substr($sql, $i);
            if (preg_match('/^\s*(\S+)/', $rest, $matches)) {
                $delimiter = $matches[1];
            }
            continue;
        }
        
        $current .= $char;
        
        // Check for delimiter
        if (!$inString && substr($current, -strlen($delimiter)) === $delimiter) {
            $stmt = trim(substr($current, 0, -strlen($delimiter)));
            if (!empty($stmt)) {
                $statements[] = $stmt;
            }
            $current = '';
        }
    }
    
    // Add remaining statement
    if (!empty(trim($current))) {
        $statements[] = trim($current);
    }
    
    return $statements;
}

try {
    $sqlFile = __DIR__ . '/final_fix_script.sql';
    if (!file_exists($sqlFile)) {
        die("Error: final_fix_script.sql not found at $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    $statements = splitSqlFile($sql);
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $count = 0;
    foreach ($statements as $stmt) {
        if (empty(trim($stmt))) {
            continue;
        }
        $count++;
        echo "Executing statement $count... ";
        try {
            $pdo->exec($stmt);
            echo "✅ Done\n";
        } catch (PDOException $e) {
            echo "❌ Error: " . $e->getMessage() . "\n";
        }
    }
    echo "\n=== All statements executed! ===\n";
    
} catch (Exception $e) {
    echo "Fatal: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
