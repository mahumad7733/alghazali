<?php
require_once 'includes/db.php';

$tablesToCheck = [
    'family_visit_requests',
    'family_visit_individuals',
    'bus_flight_bookings',
    'passport_transactions',
    'passports'
];

foreach ($tablesToCheck as $table) {
    echo "\n=== Checking table: $table ===\n";
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "Columns:\n";
        foreach ($columns as $col) {
            echo "- {$col['Field']} ({$col['Type']})\n";
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
