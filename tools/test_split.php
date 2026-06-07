<?php
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
            // Skip "DELIMITER"
            $i += 8;
            // Skip whitespace
            while ($i < $len && ctype_space($sql[$i])) {
                $i++;
            }
            // Read delimiter
            $delimiter = '';
            while ($i < $len && !ctype_space($sql[$i])) {
                $delimiter .= $sql[$i];
                $i++;
            }
            echo "DEBUG: New delimiter: " . json_encode($delimiter) . "\n";
            continue;
        }
        
        $current .= $char;
        
        // Check for delimiter
        if (!$inString && substr($current, -strlen($delimiter)) === $delimiter) {
            $stmt = trim(substr($current, 0, -strlen($delimiter)));
            if (!empty($stmt)) {
                $statements[] = $stmt;
                echo "DEBUG: Found statement: " . json_encode(substr($stmt, 0, 100)) . "...\n";
            }
            $current = '';
        }
    }
    
    // Add remaining statement
    if (!empty(trim($current))) {
        $statements[] = trim($current);
        echo "DEBUG: Adding remaining statement: " . json_encode(substr($current, 0, 100)) . "...\n";
    }
    
    return $statements;
}

$sqlFile = __DIR__ . '/final_fix_script.sql';
$sql = file_get_contents($sqlFile);
$statements = splitSqlFile($sql);
echo "\nTotal statements found: " . count($statements) . "\n";
foreach ($statements as $i => $stmt) {
    echo "Stmt " . ($i + 1) . ":\n" . substr($stmt, 0, 200) . "\n---\n";
}
