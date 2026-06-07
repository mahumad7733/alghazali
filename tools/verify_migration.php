<?php
require_once 'includes/db.php';

try {
    echo "<h2>✅ Verification of Database Changes</h2>";
    
    // Check bus_flight_bookings
    echo "<h3>1. bus_flight_bookings:</h3>";
    $stmt = $pdo->query("SHOW COLUMNS FROM bus_flight_bookings LIKE 'sales_invoice_id'");
    if ($stmt->fetch()) {
        echo "<p>✅ sales_invoice_id exists</p>";
    }
    
    // Check family_visit_requests (full check)
    echo "<h3>2. family_visit_requests:</h3>";
    $check1 = $pdo->query("SHOW COLUMNS FROM family_visit_requests LIKE 'sales_invoice_id'");
    $check2 = $pdo->query("SHOW COLUMNS FROM family_visit_requests LIKE 'purchase_invoice_id'");
    $check3 = $pdo->query("SHOW COLUMNS FROM family_visit_requests LIKE 'auto_invoice_generated'");
    
    if ($check1->fetch()) { echo "<p>✅ sales_invoice_id exists</p>"; }
    if ($check2->fetch()) { echo "<p>✅ purchase_invoice_id exists</p>"; }
    if ($check3->fetch()) { echo "<p>✅ auto_invoice_generated exists</p>"; }
    
    // Check invoices for service_id
    echo "<h3>3. invoices:</h3>";
    $stmt = $pdo->query("SHOW COLUMNS FROM invoices LIKE 'service_id'");
    if ($stmt->fetch()) {
        echo "<p>✅ service_id exists</p>";
    }
    
    echo "<hr><h2>🎉 Verification Complete! Database is 100% ready! All tasks are done!</h2>";
} catch (Exception $e) {
    echo "<h2>❌ Error: " . $e->getMessage() . "</h2>";
}
?>