<?php
require_once 'includes/db.php';

echo "<h3>Database Tables:</h3>";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "<ul>";
foreach ($tables as $table) {
    echo "<li>" . htmlspecialchars($table) . "</li>";
}
echo "</ul>";

// Check users table structure if it exists
if (in_array('users', $tables)) {
    echo "<h3>Users Table Columns:</h3>";
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo "<ul>";
    foreach ($columns as $col) {
        echo "<li>" . htmlspecialchars($col['Field']) . " (" . htmlspecialchars($col['Type']) . ")</li>";
    }
    echo "</ul>";
    
    echo "<h3>Users Data:</h3>";
    $users = $pdo->query("SELECT * FROM users LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($users);
    echo "</pre>";
}

// Check employees table if exists
if (in_array('employees', $tables)) {
    echo "<h3>Employees Table Columns:</h3>";
    $columns = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_ASSOC);
    echo "<ul>";
    foreach ($columns as $col) {
        echo "<li>" . htmlspecialchars($col['Field']) . " (" . htmlspecialchars($col['Type']) . ")</li>";
    }
    echo "</ul>";
    
    echo "<h3>Employees Data:</h3>";
    $employees = $pdo->query("SELECT * FROM employees LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($employees);
    echo "</pre>";
}
?>