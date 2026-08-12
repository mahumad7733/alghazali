<?php
require_once __DIR__ . '/../../includes/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function fg($pdo, $k) { $s=$pdo->prepare("SELECT id FROM workflow_field_groups WHERE group_key=?"); $s->execute([$k]); return $s->fetchColumn(); }
function ff($pdo, $k) { $s=$pdo->prepare("SELECT id FROM workflow_fields WHERE field_key=?"); $s->execute([$k]); return $s->fetchColumn(); }
function af($pdo, $k, $n, $t='text') {
    if (ff($pdo,$k)!==false) return ff($pdo,$k);
    $pdo->prepare("INSERT INTO workflow_fields (field_key, field_label, field_type, is_active, created_at) VALUES (?,?,?,1,NOW())")->execute([$k,$n,$t]);
    return $pdo->lastInsertId();
}
function am($pdo, $f, $g) {
    $s=$pdo->prepare("SELECT COUNT(*) FROM workflow_field_group_mappings WHERE field_id=? AND group_id=?"); $s->execute([$f,$g]);
    if ($s->fetchColumn()==0) $pdo->prepare("INSERT INTO workflow_field_group_mappings (field_id, group_id) VALUES (?,?)")->execute([$f,$g]);
}

$ggid = fg($pdo,'general');
$ugid = fg($pdo,'umrah');
$wgid = fg($pdo,'work_visa');
$pgid = fg($pdo,'passport');
$fgid = fg($pdo,'family_visit');
$bgid = fg($pdo,'booking');
echo "Group IDs: general=$ggid, umrah=$ugid, work_visa=$wgid, passport=$pgid, family_visit=$fgid, booking=$bgid\n\n";

echo "=== GENERAL ===\n";
$fields = [
    ['attachments_count','⁄œœ «·„—›ﬁ« ','number'],
];
foreach ($fields as $f) { $fid = af($pdo,$f[0],$f[1],$f[2]); am($pdo,$fid,$ggid); echo "+ $f[0]\n"; }

echo "\n=== UMRAH ===\n";
$fields = [
    ['umrah_visa_no','—ﬁ„  √‘Ì—… «·⁄„—…','text'],
    ['flight_number_outbound','—ﬁ„ —Õ·… «·–Â«»','text'],
    ['flight_date_outbound',' «—ÌŒ —Õ·… «·–Â«»','date'],
    ['flight_number_inbound','—ﬁ„ —Õ·… «·⁄Êœ…','text'],
    ['flight_date_inbound',' «—ÌŒ —Õ·… «·⁄Êœ…','date'],
];
foreach ($fields as $f) { $fid = af($pdo,$f[0],$f[1],$f[2]); am($pdo,$fid,$ugid); echo "+ $f[0]\n"; }

echo "\n=== WORK VISA ===\n";
$fields = [
    ['work_permit_no','—ﬁ„  ’—ÌÕ «·⁄„·','text'],
    ['iqama_no','—ﬁ„ «·≈ﬁ«„…','text'],
    ['employer_name','«”„ ’«Õ» «·⁄„·','text'],
    ['contract_start_date',' «—ÌŒ »œ«Ì… «·⁄ﬁœ','date'],
    ['contract_end_date',' «—ÌŒ ‰Â«Ì… «·⁄ﬁœ','date'],
    ['sponsor_transfer_date',' «—ÌŒ ‰ﬁ· «·ﬂ›«·…','date'],
];
foreach ($fields as $f) { $fid = af($pdo,$f[0],$f[1],$f[2]); am($pdo,$fid,$wgid); echo "+ $f[0]\n"; }

echo "\n=== PASSPORT ===\n";
$fields = [
    ['passport_no','—ﬁ„ ÃÊ«“ «·”›—','text'],
    ['passport_expiry_date',' «—ÌŒ «‰ Â«¡ ÃÊ«“ «·”›—','date'],
    ['passport_issue_place','„ﬂ«‰ ≈’œ«— ÃÊ«“ «·”›—','text'],
    ['delivery_date_embassy',' «—ÌŒ «” ·«„Â „‰ «·”›«—…','date'],
    ['receipt_date_office',' «—ÌŒ  ”·„Â ··„ﬂ »','date'],
    ['mofa_number','—ﬁ„ Ê“«—… «·Œ«—ÃÌ…','text'],
    ['border_number','—ﬁ„ «·ÕœÊœ','text'],
];
foreach ($fields as $f) { $fid = af($pdo,$f[0],$f[1],$f[2]); am($pdo,$fid,$pgid); echo "+ $f[0]\n"; }

echo "\n=== FAMILY VISIT ===\n";
$fields = [
    ['visitor_name','«”„ «·“«∆—','text'],
    ['relation','’·… «·ﬁ—«»…','text'],
    ['duration_days','„œ… «· √‘Ì—… (√Ì«„)','number'],
];
foreach ($fields as $f) { $fid = af($pdo,$f[0],$f[1],$f[2]); am($pdo,$fid,$fgid); echo "+ $f[0]\n"; }

echo "\n=== BOOKING ===\n";
$fields = [
    ['booking_ref','—ﬁ„ „—Ã⁄ «·ÕÃ“','text'],
    ['ticket_no','—ﬁ„ «· –ﬂ—…','text'],
    ['ticket_issue_date',' «—ÌŒ ≈’œ«— «· –ﬂ—…','date'],
    ['departure_date',' «—ÌŒ «·„€«œ—…','date'],
    ['arrival_date',' «—ÌŒ «·Ê’Ê·','date'],
    ['airline','‘—ﬂ… «·ÿÌ—«‰','text'],
    ['pnr','—ﬁ„ PNR','text'],
    ['seat_no','—ﬁ„ «·„ﬁ⁄œ','text'],
];
foreach ($fields as $f) { $fid = af($pdo,$f[0],$f[1],$f[2]); am($pdo,$fid,$bgid); echo "+ $f[0]\n"; }

echo "\n=== VERIFY NOW ===\n";
require_once __DIR__ . '/../../includes/functions.php';
$all = get_all_workflow_fields();
echo "Total fields now: " . count($all) . "\n";
$chk = ['umrah_visa_no','attachments_count','work_permit_no','passport_no','visitor_name','booking_ref','ticket_no','iqama_no','mofa_number','airline','pnr','relation','duration_days'];
$miss = 0;
foreach ($chk as $k) { if (!isset($all[$k])) { echo "MISSING: $k\n"; $miss++; } }
echo $miss==0 ? "All checked fields present ?\n" : "Missing: $miss ?\n";
echo "\nDone!\n";
