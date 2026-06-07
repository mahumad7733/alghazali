<?php
echo "<h2>Testing ajax_get_service_price.php directly</h2>";
echo "<h3>Test with service_id=4 (حج وعمرة):</h3>";

// Simulate an AJAX call
$_GET['service_id'] = 4;
$_GET['branch_id'] = null;
$_GET['supplier_id'] = null;
$_GET['agent_id'] = null;
$_GET['customer_id'] = null;

require 'ajax/ajax_get_service_price.php';
?>