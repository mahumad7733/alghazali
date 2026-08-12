<?php
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Test 1: get_workflow_fields_by_type('bus_flight_bookings') =======\n";
$f = get_workflow_fields_by_type('bus_flight_bookings');
foreach ($f as $k => $v) {
    $display = (is_null($v) || $v === '') ? "[NULL/EMPTY ?]" : $v;
    $hasBadge = (trim($v) === '?' || trim($v) === '') ? " ??" : "";
    echo "  $k => $display $hasBadge\n";
}
echo "Total: " . count($f) . "\n\n";

echo "=== Test 2: get_workflow_fields_by_type(3) =======\n";
$f = get_workflow_fields_by_type('3');
foreach ($f as $k => $v) {
    $display = (is_null($v) || $v === '') ? "[NULL/EMPTY ?]" : $v;
    $hasBadge = (trim($v) === '?' || trim($v) === '') ? " ??" : "";
    echo "  $k => $display $hasBadge\n";
}
echo "Total: " . count($f) . "\n\n";

echo "=== Test 3: get_all_workflow_fields() sample =======\n";
$all = get_all_workflow_fields();
echo "Total fields: " . count($all) . "\n";
$badges = 0; $empty = 0;
foreach ($all as $k => $v) {
    if (trim($v) === '?') { $badges++; echo "  ? $k => ?\n"; }
    elseif (trim($v) === '' || is_null($v)) { $empty++; echo "  ? $k => [EMPTY]\n"; }
}
echo "Fields with '?': $badges\n";
echo "Fields with empty label: $empty\n";

echo "\n=== Test 4: Raw DB check for fields with empty/????? label =======\n";
$stmt = $pdo->query("SELECT id, field_key, field_label, HEX(field_label) as hex FROM workflow_fields WHERE field_label IS NULL OR field_label='' OR field_label='?' OR CHAR_LENGTH(field_label)=1 ORDER BY id");
foreach ($stmt->fetchAll() as $r) {
    echo "  ID {$r['id']}: {$r['field_key']} => [" . var_export($r['field_label'], true) . "] (HEX: {$r['hex']})\n";
}

echo "\n=== Test 5: Check general fields group in DB =======\n";
$gid = $pdo->query("SELECT id FROM workflow_field_groups WHERE group_key='general'")->fetchColumn();
echo "General group ID: $gid\n";
if ($gid) {
    $s = $pdo->prepare("SELECT f.field_key, f.field_label FROM workflow_fields f JOIN workflow_field_group_mappings m ON f.id=m.field_id WHERE m.group_id=? ORDER BY f.field_label");
    $s->execute([$gid]);
    foreach ($s->fetchAll() as $row) {
        $chk = (trim($row['field_label']) === '' || trim($row['field_label']) === '?') ? " ??" : "";
        echo "  {$row['field_key']} => {$row['field_label']} $chk\n";
    }
}
