<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='utf-8'><title>تقرير تحقق سير العمل</title>";
echo "<link rel='stylesheet' href='assets/css/bootstrap.min.css'></head><body class='bg-light p-4'><div class='container'>";
echo "<h4 class='text-primary mb-3'><i class='fas fa-check-circle me-2'></i> تقرير التحقق النهائي من سير العمل للعمرة</h4>";

function check($label, $ok, $extra='') {
    $cls = $ok ? 'success' : 'danger';
    $ic = $ok ? 'fa-circle-check text-success' : 'fa-circle-xmark text-danger';
    echo "<div class='alert alert-$cls py-2 mb-2 d-flex align-items-center gap-2 small'><i class='fas $ic'></i><div><b>$label</b>".($extra?"<div class='text-muted mt-1'>$extra</div>":'')."</div></div>";
}

// 1) عمود workflow_step_id موجود؟
$cols = $pdo->query("SHOW COLUMNS FROM passports LIKE 'workflow_step_id'")->fetchAll();
check('١. عمود `workflow_step_id` في جدول passports موجود', count($cols)>0, count($cols)>0 ? "النوع: {$cols[0]['Type']}" : '');

// 2) لا يوجد تداخل في sort_order
$dup = $pdo->query("SELECT workflow_id, sort_order, COUNT(*) as cnt, GROUP_CONCAT(step_name SEPARATOR '  |  ') as names FROM workflow_steps WHERE workflow_id IN (SELECT id FROM workflows WHERE transaction_type IN ('umrah','all')) GROUP BY workflow_id, sort_order HAVING cnt>1")->fetchAll();
check('٢. لا يوجد تداخل في ترتيب المراحل (sort_order فريد)', count($dup)==0, count($dup)>0?json_encode($dup,JSON_UNESCAPED_UNICODE):'جميع المراحل لها ترتيب فريد');

// 3) عرض الترتيب الصحيح
$wfs = $pdo->query("SELECT * FROM workflows WHERE transaction_type IN ('umrah','all')")->fetchAll();
foreach ($wfs as $w) {
    $wid = $w['id'];
    echo "<div class='card mb-3 shadow-sm'><div class='card-header fw-bold'>🎯 سير عمل '{$w['name']}' (ID=$wid)</div><div class='card-body'>";

    // عرض المراحل
    echo "<h6 class='fw-bold mt-2 mb-2'><i class='fas fa-route me-2'></i> مسار المراحل (بالترتيب الصحيح):</h6>";
    $steps = $pdo->prepare("SELECT id, step_name, step_key, sort_order, is_initial, is_final, color FROM workflow_steps WHERE workflow_id=? ORDER BY sort_order ASC, id ASC");
    $steps->execute([$wid]);
    $steps = $steps->fetchAll();
    $allOk = true;
    foreach ($steps as $i=>$s) {
        $mark = $i === 0 ? '🏁' : ($i === count($steps)-1 ? '🏁' : '➡️');
        echo "<div class='mb-1 d-flex align-items-center gap-2'><span class='badge rounded-pill px-3 py-1 shadow-sm' style='background:{$s['color']};color:#fff;'>{$s['sort_order']} → {$s['step_name']}</span> <small class='text-muted'>(id={$s['id']}, key={$s['step_key']})</small> ";
        if ($s['is_initial']) echo "<span class='badge bg-info'>بداية</span>";
        if ($s['is_final'])   echo "<span class='badge bg-success'>نهاية</span>";
        echo "</div>";
    }

    // عرض الانتقالات
    echo "<h6 class='fw-bold mt-4 mb-2'><i class='fas fa-random me-2'></i> الانتقالات المسموحة (";
    $trs = $pdo->prepare("SELECT t.id, fs.step_name as fn, ts.step_name as tn, t.auto_action, t.role_id FROM workflow_transitions t LEFT JOIN workflow_steps fs ON t.from_step_id=fs.id LEFT JOIN workflow_steps ts ON t.to_step_id=ts.id WHERE t.workflow_id=? ORDER BY t.id");
    $trs->execute([$wid]);
    $trs = $trs->fetchAll();
    echo count($trs) . ")：</h6>";

    // التحقق من إمكانية الوصول لكل مرحلة
    $inMap = []; $outMap = [];
    foreach ($trs as $t) { $outMap[$t['fn']] = ($outMap[$t['fn']]??0)+1; $inMap[$t['tn']] = ($inMap[$t['tn']]??0)+1; }

    echo "<div class='row g-2'>";
    foreach ($trs as $t) {
        $badge = $t['auto_action'] ? " <span class='badge bg-secondary ms-1 extra-small'>⚙️ {$t['auto_action']}</span>" : '';
        $roles = !empty($t['role_id']) ? " <span class='badge bg-dark ms-1 extra-small'>🔒 {$t['role_id']}</span>" : " <span class='badge bg-light text-dark border ms-1 extra-small'>🌐 للجميع</span>";
        echo "<div class='col-md-6'><div class='alert alert-light border py-2 mb-0 small d-flex align-items-center justify-content-between'><span><b>{$t['fn']}</b> <i class='fas fa-arrow-left text-info mx-1'></i> <b class='text-primary'>{$t['tn']}</b> $badge</span> $roles</div></div>";
    }
    echo "</div>";

    echo "<h6 class='fw-bold mt-4 mb-2'><i class='fas fa-shield me-2'></i> فحص إمكانية الوصول للمراحل:</h6>";
    foreach ($steps as $s) {
        $in  = $inMap[$s['step_name']]??0;
        $out = $outMap[$s['step_name']]??0;
        $ok = true;
        $issues = [];
        if ($s['is_initial'] && $in>0) $issues[] = 'مرحلة بداية لكنها ليست نقطة دخول فعلية (لها وارد)';
        if (!$s['is_initial'] && !$s['is_final'] && $in==0) {$ok=false; $issues[] = '⚠️ لا يمكن الوصول إليها (لا يوجد مرحل يؤدي إليها)';}
        if (!$s['is_final'] && $out==0) {$ok=false; $issues[] = '⚠️ لا يوجد انتقال صادر (مسار مسدود)';}
        if ($s['is_final'] && $out>0) $issues[] = 'مرحلة نهائية لكنها ليست نهاية المسار (لها صادر)';
        $allOk = $allOk && $ok;
        $cls = $ok ? 'alert-light text-dark' : 'alert-warning';
        echo "<div class='alert $cls py-2 mb-1 small d-flex align-items-center gap-2'><span>".($ok?'✅':'⚠️')."</span><div><b>{$s['step_name']}</b> (وارد=$in ، صادر=$out) ".implode(' | ', $issues)."</div></div>";
    }

    echo '</div></div>';
}

check('٣. جميع المراحل قابلة للوصول وذات مسار مفتوح', $allOk??false, $allOk??false ? 'الجميع جاهزون' : 'يوجد مراحل بحاجة لاهتمام');

echo "<hr>";
echo "<div class='d-flex gap-3'>";
echo "<a class='btn btn-primary rounded-pill' href='admin/umrah.php'><i class='fas fa-arrow-left me-2'></i> العودة لصفحة العمرة</a>";
echo "<a class='btn btn-outline-secondary rounded-pill' href='admin/workflow.php'><i class='fas fa-gear me-2'></i> فتح إدارة سير العمل</a>";
echo "</div>";
echo "</div></body></html>";
