<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/db.php';
require_once '../includes/functions.php';

echo "<h2>Testing Service Price Config (service_id=4)</h2><pre>";

try {
    echo "Calling get_service_price_config with service_id=4...\n";
    $price = get_service_price_config($pdo, 4, null, null, null, null);
    echo "\nResult:\n";
    print_r($price);

    echo "\n\nCalling ajax_get_service_price logic...\n";
    $test = function_exists('get_service_price_config') ? 'Yes' : 'No';
    echo "Function exists: $test\n";
} catch (Exception $e) {
    echo "\nException: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
?>
