<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== POST-FIX: Test get_workflow_fields_by_type PDO errors? =======\n";
$tests = [['umrah','4'],['work_visa','6'],['booking','3'],['bus_flight_bookings','bus_flight_bookings'],['passport_transactions','2'],['hajj','7'],['postal_services','8'],['crm','crm'],['visa','5'],['family_visit','family_visit']];
$all = get_all_workflow_fields();
$totalMissing = 0;
foreach ($tests as $t) {
    $errors = [];
    foreach ($t as $type) {
        $f = @get_workflow_fields_by_type($type);
        $err = error_get_last();
        $hasErr = (is_array($err) && strpos($err['message'] ?? '', 'PDO error') !== false);
        $cnt = count($f);
        $status = $hasErr ? "? ERR" : "? OK ($cnt fields)";
        echo "  '$type': $status\n";
        // Also find missing whitelist fields
        $missing = [];
        foreach (array_keys($f) as $k) {
            $v = $f[$k];
            if (trim($v) === '?' || trim($v) === '' || is_null($v)) $missing[] = $k;
        }
        if (count($missing) > 0) echo "    LABELS EMPTY/?: " . implode(', ', $missing) . "\n";
    }
}

echo "\n=== WHITELIST FIELDS MISSING IN DB =======\n";
$whitelist = [
    'general'=>['batch_no','reject_reason','attachments_count'],
    'umrah'=>['umrah_visa_no','mahram_name','mahram_relation','package_type','hotel_makkah','hotel_madinah','flight_number_outbound','flight_date_outbound','flight_number_inbound','flight_date_inbound'],
    'work_visa'=>['work_permit_no','iqama_no','profession','employer_name','contract_start_date','contract_end_date','sponsor_transfer_date'],
    'passport'=>['passport_no','passport_issue_date','passport_expiry_date','passport_issue_place','delivery_date_embassy','receipt_date_office','mofa_number','border_number'],
    'family_visit'=>['visa_no','visa_number','visa_issue_date','visa_expiry_date','sponsor_name','visitor_name','relation','duration_days','embassy_exit_date'],
    'booking'=>['booking_ref','ticket_no','ticket_issue_date','departure_date','arrival_date','airline','pnr','seat_no','transport_delivery_date','arrival_office_date','embassy_exit_date'],
];
$missingByGroup = [];
$alreadyExists = 0;
foreach ($whitelist as $g => $keys) {
    $missingByGroup[$g] = [];
    foreach ($keys as $k) {
        if (!isset($all[$k])) {
            $missingByGroup[$g][] = $k;
            $totalMissing++;
        } else $alreadyExists++;
    }
}
echo "Exist in DB: $alreadyExists, Missing: $totalMissing\n";
foreach ($missingByGroup as $g => $ks) {
    if (count($ks) > 0) echo "  $g (group): " . implode(', ', $ks) . "\n";
}
echo "\nDone!\n";
