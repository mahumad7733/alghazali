<?php
require_once 'includes/db.php';

echo "<h2>All Stored Procedures</h2>";
try {
    $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE()");
    $procedures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($procedures) ===0 ) {
        echo "No stored procedures found!";
    } else {
        echo "<ul>";
        foreach ($procedures as $sp) {
            echo "<li><strong>" . htmlspecialchars($sp['Name']) . "</strong>";
            
            // Try to get CREATE PROCEDURE statement
            try {
                $stmt_create = $pdo->prepare("SHOW CREATE PROCEDURE " . $sp['Name']);
                $stmt_create->execute();
                $create_result = $stmt_create->fetch(PDO::FETCH_ASSOC);
                
                echo "<details><summary>Show Definition</summary>";
                echo "<pre>" . htmlspecialchars($create_result['Create Procedure']) . "</pre>";
                echo "</details>";
            } catch (Exception $e) {
                echo " (Error loading definition: " . $e->getMessage() . ")";
            }
            echo "</li>";
        }
        echo "</ul>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>Error: " . $e->getMessage() . "</p>";
}
?>