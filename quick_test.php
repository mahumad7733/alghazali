<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';

echo "<h1 style='color:blue'>🎉 Quick Test</h1>";
echo "<h2>Testing Users Table...</h2>";

try {
    $stmt = $pdo->query("SELECT id, username, full_name, user_type FROM users LIMIT 5");
    $users = $stmt->fetchAll();
    echo "<p style='color:green; font-weight:bold'>✅ USERS TABLE IS WORKING PERFECTLY!</p>";
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;'>";
    echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>User Type</th></tr>";
    foreach ($users as $u) {
        echo "<tr><td>{$u['id']}</td><td>{$u['username']}</td><td>{$u['full_name']}</td><td>{$u['user_type']}</td></tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    echo "<p style='color:red;'>❌ ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr><h3>Now try phpMyAdmin:</h3>";
echo "<p><a href='http://localhost:8000/phpmyadmin/index.php?route=/sql&db=ghazali&table=users&pos=0' target='_blank'>Open phpMyAdmin → ghazali → users table</a></p>";
?>
