<?php
require_once 'includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    // Get all procedures
    $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE()");
    $procedures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($procedures as $proc) {
        echo str_repeat('=', 80) . "\n";
        echo "Procedure: " . $proc['Name'] . "\n";
        echo str_repeat('=', 80) . "\n";
        
        $stmt2 = $pdo->query("SHOW CREATE PROCEDURE " . $proc['Name']);
        $result = $stmt2->fetch(PDO::FETCH_ASSOC);
        echo $result['Create Procedure'] . "\n\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>