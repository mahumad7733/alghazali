<?php
$DB_HOST = '127.0.0.1';
$DB_PORT = 3307;
$DB_USER = 'root';
$DB_PASS = '738155';
$DB_NAME = 'alghazali';
$pdo = new PDO("mysql:host=$DB_HOST;port=$DB_PORT;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
header('Content-Type: text/plain; charset=utf-8');

echo "===== [1] جداول workflow في قاعدة البيانات =====\n";
$tbls = $pdo->query("SHOW TABLES LIKE '%workflow%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tbls as $t) echo "  - $t\n";

echo "\n===== [2] جداول ذات صلة =====\n";
$check_tbls = ['statuses', 'passports', 'document_requirements', 'transaction_status_logs', 'notifications', 'services', 'workflow_logs', 'workflow_checklist', 'umrah_hosts', 'umrah_guarantors', 'audit_logs'];
foreach ($check_tbls as $t) {
    try {
        $chk = $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
        echo "  - $t (موجود)\n";
    } catch (Throwable $e) {
        echo "  - $t (غير موجود)\n";
    }
}

echo "\n===== [3] بيانات سير العمل المرتبطة بالعمرة =====\n";
$wfs = $pdo->query("SELECT w.id,w.name,w.description,w.transaction_type,w.branch_id,w.default_status_id,w.created_at,b.branch_name,s.service_name FROM workflows w LEFT JOIN branches b ON w.branch_id=b.id LEFT JOIN services s ON CAST(w.transaction_type AS CHAR)=CAST(s.id AS CHAR) ORDER BY w.id")->fetchAll();
foreach ($wfs as $w) {
    echo "\n--- سير عمل #{$w['id']}: {$w['name']} ---\n";
    echo "    النوع: {$w['transaction_type']} | الفرع: " . ($w['branch_name'] ?? 'عام') . " | default_status_id=" . ($w['default_status_id'] ?? '-') . "\n";
    echo "    الوصف: {$w['description']}\n";

    echo "    المراحل (workflow_steps):\n";
    $steps = $pdo->prepare("SELECT ws.*, s.status_name, s.status_color FROM workflow_steps ws LEFT JOIN statuses s ON ws.status_id=s.id WHERE ws.workflow_id=? ORDER BY ws.sort_order, ws.id");
    $steps->execute([$w['id']]);
    $allSteps = $steps->fetchAll();
    $stepMap = [];
    foreach ($allSteps as $st) {
        $stepMap[$st['id']] = $st;
        $flags = [];
        if ($st['is_initial']) $flags[] = 'INITIAL';
        if ($st['is_final']) $flags[] = 'FINAL';
        if ($st['is_editable']) $flags[] = 'EDITABLE';
        if ($st['require_note']) $flags[] = 'REQ_NOTE';
        if ($st['require_reason']) $flags[] = 'REQ_REASON';
        if ($st['show_checklist']) $flags[] = 'CHECKLIST';
        echo "      [{$st['sort_order']}] #{$st['id']} {$st['step_name']} (key={$st['step_key']}, status_id={$st['status_id']}=>" . ($st['status_name'] ?? '-') . ") color={$st['color']} [" . implode(',', $flags) . "]\n";
        if (!empty($st['show_fields'])) echo "            عرض الحقول: {$st['show_fields']}\n";
    }

    echo "    الانتقالات (workflow_transitions):\n";
    $trans = $pdo->prepare("SELECT wt.*, fs.step_name as from_name, ts.step_name as to_name FROM workflow_transitions wt LEFT JOIN workflow_steps fs ON wt.from_step_id=fs.id LEFT JOIN workflow_steps ts ON wt.to_step_id=ts.id WHERE wt.workflow_id=? ORDER BY wt.id");
    $trans->execute([$w['id']]);
    foreach ($trans->fetchAll() as $t) {
        $perm = '';
        if (!empty($t['role_id'])) $perm .= " roles=[{$t['role_id']}]";
        if (!empty($t['allow_by_user_id'])) $perm .= " user={$t['allow_by_user_id']}";
        if ($t['require_approval']) $perm .= " [REQUIRE_APPROVAL]";
        if (!empty($t['auto_action'])) $perm .= " AUTO={$t['auto_action']}";
        echo "      #{$t['id']} {$t['from_name']}(#{$t['from_step_id']}) ==> {$t['to_name']}(#{$t['to_step_id']}){$perm}\n";
    }
}

echo "\n===== [4] متطلبات الوثائق (document_requirements إذا وجد) =====\n";
try {
    $docs = $pdo->query("SELECT * FROM document_requirements WHERE transaction_type='umrah' OR transaction_type='all' ORDER BY sort_order, id")->fetchAll();
    foreach ($docs as $d) {
        echo "  #{$d['id']} {$d['requirement_name']} [type={$d['requirement_type']}] required=" . ($d['is_required'] ? 'YES' : 'NO') . " active=" . ($d['is_active'] ? 'YES' : 'NO') . " gender={$d['gender']}\n";
        if (!empty($d['description'])) echo "      الوصف: {$d['description']}\n";
    }
} catch (Throwable $e) {
    echo "  (جدول غير موجود: {$e->getMessage()})\n";
}

echo "\n===== [5] أعمدة سير العمل في جدول passports =====\n";
$cols = $pdo->query("SHOW COLUMNS FROM passports WHERE Field IN ('id','transaction_type','status_id','workflow_id','workflow_step_id','status_changed_at','status_changed_by','closed_at','closed_by','parent_id','full_name','passport_number','sales_invoice_id','purchase_invoice_id','agent_id','branch_id','supplier_id','host_id','guarantor_id') OR Field LIKE '%date%' OR Field LIKE '%visa%' OR Field LIKE '%batch%' OR Field LIKE '%delivery%' OR Field LIKE '%received%' OR Field LIKE '%office%' OR Field LIKE '%reject%' OR Field LIKE '%cancel%'")->fetchAll();
foreach ($cols as $c) {
    echo "  {$c['Field']}: {$c['Type']} " . ($c['Null'] == 'YES' ? 'NULL' : 'NOT NULL') . " " . ($c['Default'] !== null ? "DEFAULT={$c['Default']}" : '') . " {$c['Key']}\n";
}

echo "\n===== [6] الحالات (statuses) =====\n";
$st = $pdo->query("SELECT id, status_name, status_color FROM statuses ORDER BY id")->fetchAll();
foreach ($st as $s) echo "  #{$s['id']} {$s['status_name']} [color={$s['status_color']}]\n";

echo "\n===== [7] عينة من معاملات العمرة (آخر 10) =====\n";
$sample = $pdo->query("SELECT p.id,p.full_name,p.passport_number,p.transaction_type,p.status_id,p.workflow_id,p.workflow_step_id,s.status_name FROM passports p LEFT JOIN statuses s ON p.status_id=s.id WHERE p.transaction_type='umrah' ORDER BY p.id DESC LIMIT 10")->fetchAll();
if (!$sample) echo "  (لا توجد معاملات عمرة حالياً)\n";
foreach ($sample as $p) {
    echo "  #{$p['id']} {$p['full_name']} جواز={$p['passport_number']} status_id={$p['status_id']}(" . ($p['status_name'] ?? '-') . ") wf_id=" . ($p['workflow_id'] ?? '-') . " step_id=" . ($p['workflow_step_id'] ?? '-') . "\n";
}

echo "\n===== [8] خدمات النظام (services) =====\n";
$svcs = $pdo->query("SELECT id, service_name, service_code FROM services ORDER BY id")->fetchAll();
foreach ($svcs as $s) echo "  #{$s['id']} {$s['service_name']} (code={$s['service_code']})\n";

echo "\n===== [9] الحقول في workflow_fields =====\n";
try {
    $wf_fields = $pdo->query("SELECT f.id, f.field_key, f.field_label, f.sort_order, f.is_active, GROUP_CONCAT(g.group_key SEPARATOR ',') as groups FROM workflow_fields f LEFT JOIN workflow_field_group_mappings gm ON f.id=gm.field_id LEFT JOIN workflow_field_groups g ON gm.group_id=g.id GROUP BY f.id ORDER BY f.sort_order, f.id")->fetchAll();
    foreach ($wf_fields as $f) echo "  #{$f['id']} key={$f['field_key']} label={$f['field_label']} groups=[" . ($f['groups'] ?? '-') . "]\n";
} catch (Throwable $e) {
    echo "  (خطأ: {$e->getMessage()})\n";
}

echo "\n===== [10] سجل تغييرات المعاملات العمرة (آخر 15) =====\n";
try {
    $logs = $pdo->query("SELECT tsl.id, tsl.transaction_id, tsl.changed_at, p.full_name, s1.status_name as old_status, s2.status_name as new_status, u.full_name as changer, tsl.notes FROM transaction_status_logs tsl LEFT JOIN passports p ON tsl.transaction_id=p.id LEFT JOIN statuses s1 ON tsl.old_status_id=s1.id LEFT JOIN statuses s2 ON tsl.new_status_id=s2.id LEFT JOIN users u ON tsl.changed_by=u.id WHERE p.transaction_type='umrah' ORDER BY tsl.id DESC LIMIT 15")->fetchAll();
    if (!$logs) echo "  (لا توجد سجلات)\n";
    foreach ($logs as $l) echo "  #{$l['id']} trx#{$l['transaction_id']} " . ($l['full_name'] ?? '') . ": {$l['old_status']}=>{$l['new_status']} by " . ($l['changer'] ?? '-') . " at {$l['changed_at']}" . ($l['notes'] ? " - {$l['notes']}" : '') . "\n";
} catch (Throwable $e) {
    echo "  (خطأ: {$e->getMessage()})\n";
}

echo "\n===== [11] مجموعات الحقول (workflow_field_groups) =====\n";
try {
    $grps = $pdo->query("SELECT * FROM workflow_field_groups ORDER BY id")->fetchAll();
    foreach ($grps as $g) echo "  #{$g['id']} key={$g['group_key']} name={$g['group_name']}\n";
} catch (Throwable $e) {
    echo "  (خطأ: {$e->getMessage()})\n";
}

echo "\n===== [12] الصلاحيات المتعلقة بالعمرة وسير العمل =====\n";
try {
    $perms = $pdo->query("SELECT id, permission_key, display_name FROM permissions WHERE permission_key LIKE '%umrah%' OR permission_key LIKE '%workflow%' OR permission_key LIKE '%document%' ORDER BY id")->fetchAll();
    foreach ($perms as $p) echo "  #{$p['id']} key={$p['permission_key']} => {$p['display_name']}\n";
} catch (Throwable $e) {
    echo "  (خطأ: {$e->getMessage()})\n";
}
