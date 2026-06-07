<?php
require_once '../includes/db.php';

// Test: Let's fetch all services and prices
echo "<h2>Services in Database</h2>";
$services = $pdo->query("SELECT * FROM services WHERE status = 'active'")->fetchAll();
echo "<pre>";
print_r($services);
echo "</pre>";

echo "<hr><h2>Service Prices in Database</h2>";
$prices = $pdo->query("SELECT * FROM service_prices")->fetchAll();
echo "<pre>";
print_r($prices);
echo "</pre>";
?>