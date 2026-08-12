<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Step show_fields with labels check =======\n";
$steps = $pdo->query("SELECT ws.id, ws.workflow_id, ws.step_name, ws.show_fields FROM workflow_steps ws WHERE ws.show_fields IS NOT NULL AND ws.show_fields != ''")->fetchAll();
$all_fields = get_all_workflow_fields();

foreach ($steps as $st) {
    echo "\nStep [{$st['id']}]: {$st['step_name']} (WF: {$st['workflow_id']})\n";
    echo "  Raw show_fields: {$st['show_fields']}\n";
    $keys = explode(',', $st['show_fields']);
    $bad = 0; $good = 0;
    foreach ($keys as $k) {
        $k = trim($k);
        if (isset($all_fields[$k])) {
            $lab = $all_fields[$k];
            if (trim($lab) === '?' || trim($lab) === '') { echo "    ? $k => ?\n"; $bad++; }
            else { echo "    ? $k => $lab\n"; $good++; }
        } else { echo "    ? MISSING: $k (not in workflow_fields table)\n"; $bad++; }
    }
    echo "  Good: $good, Bad/Missing: $bad\n";
}

echo "\n=== Check workflow_step_fields.php manual mapping for transaction_types =======\n";
$wfs = $pdo->query("SELECT id, name, transaction_type FROM workflows ORDER BY id")->fetchAll();
foreach ($wfs as $w) {
    $tt = $w['transaction_type'];
    $is_supported_in_step_fields = false;
    if (in_array($tt, ['visa','5','family_visit'])) $is_supported_in_step_fields = true;
    elseif (in_array($tt, ['umrah','4'])) $is_supported_in_step_fields = true;
    elseif (in_array($tt, ['work_visa','6'])) $is_supported_in_step_fields = true;
    elseif ($tt === 'bus_flight_bookings' || $tt === 'booking' || $tt == '3' || $tt == '1' || $tt == 'bus_bookings' || $tt == 'flight_bookings') {
        // Does it check booking? Let's see source
    }
    $status = $is_supported_in_step_fields ? "SUPPORTED" : "NEEDS_CHECK";
    $fields = get_workflow_fields_by_type($tt);
    echo "WF {$w['id']} (type=$tt, $status): " . count($fields) . " fields\n";
}
