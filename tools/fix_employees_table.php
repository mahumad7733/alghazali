<?php
require_once 'includes/db.php';

try {
    // Check if attendance_location_id column exists
    $check = $pdo->query("SHOW COLUMNS FROM employees LIKE 'attendance_location_id'");
    $columnExists = $check->fetch();
    
    if (!$columnExists) {
        echo "Adding attendance_location_id column to employees table...\n";
        $pdo->exec("ALTER TABLE employees ADD COLUMN attendance_location_id INT DEFAULT NULL AFTER shift_id");
        echo "Column added successfully!\n";
    } else {
        echo "Column attendance_location_id already exists.\n";
    }
    
    // Check if shift_id_2 column exists (from the table structure)
    $check2 = $pdo->query("SHOW COLUMNS FROM employees LIKE 'shift_id_2'");
    $column2Exists = $check2->fetch();
    
    if (!$column2Exists) {
        echo "Adding shift_id_2 column to employees table...\n";
        $pdo->exec("ALTER TABLE employees ADD COLUMN shift_id_2 INT DEFAULT NULL AFTER shift_id");
        echo "Column added successfully!\n";
    }
    
    echo "Done!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>