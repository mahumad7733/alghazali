<?php
require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='utf-8'><title>فحص statuses</title>";
echo "<link rel='stylesheet' href='assets/css/bootstrap.min.css'></head><body class='bg-light p-4'><div class='container'>";
echo "<h4 class='text-primary mb-3'>🔍 تحليل خريطة الحالات بين جدول statuses و workflow_steps</h4>";

echo "<div class='card mb-4 shadow-sm'><div class='card-header fw-bold'>١. جميع حالات statuses المتوفرة في النظام</div><div class='card-body'><div class='row g-2'>";
$allStatus = $pdo->query("SELECT * FROM statuses ORDER BY id ASC")->fetchAll();
foreach ($allStatus as $s) {
  echo "<div class='col-md-3 col-sm-6'><div class='card card-body border-0 shadow-sm p-3' style='border-right:4px solid {$s['status_color']};'><div class='d-flex align-items-center justify-content-between'><div class='small text-muted'>status #{$s['id']}</div><div class='badge px-3 py-1 rounded-pill shadow-sm' style='background:{$s['status_color']};color:#fff;'>".htmlspecialchars($s['status_name'])."</div></div></div></div>";
}
echo "</div></div></div>";

echo "<div class='card mb-4 shadow-sm'><div class='card-header fw-bold'>٢. المراحل في سير العمل للعمرة (workflow_steps) وارتباطها بـ status_id + المقارنة</div><div class='card-body'>";
$steps = $pdo->query("SELECT ws.id, ws.step_name, ws.step_key, ws.status_id, ws.color, s.status_name
                     FROM workflow_steps ws
                     LEFT JOIN statuses s ON s.id = ws.status_id
                     WHERE ws.workflow_id IN (SELECT id FROM workflows WHERE transaction_type IN ('umrah','all'))
                     ORDER BY ws.sort_order, ws.id")->fetchAll();
echo "<table class='table table-sm small table-bordered'><thead class='bg-light'><tr>
<th>Step ID</th><th>اسم المرحلة</th><th>step_key</th><th>status_id المحفوظ</th><th>الحالة المقابلة (إن وجدت)</th><th>النتيجة</th></tr></thead><tbody>";
foreach ($steps as $st) {
    $match = $st['status_name'] !== null;
    echo "<tr>";
    echo "<td>#{$st['id']}</td>";
    echo "<td><span class='badge text-white px-3 py-1' style='background:{$st['color']};'>".htmlspecialchars($st['step_name'])."</span></td>";
    echo "<td><code>".htmlspecialchars($st['step_key'])."</code></td>";
    echo "<td class='text-center'>".var_export($st['status_id'],true)."</td>";
    echo "<td>".($match ? "<b>".htmlspecialchars($st['status_name'])."</b>" : '<span class="text-muted">-</span>')."</td>";
    echo "<td class='text-center'>".($match?'<span class="badge bg-success">✅ مطابقة</span>':'<span class="badge bg-danger">❌ غير مرتبط! السستم لن يستطيع حل المرحلة via status_id</span>')."</td>";
    echo "</tr>";
}
echo "</tbody></table></div></div>";

echo "<div class='card mb-4 shadow-sm'><div class='card-header fw-bold'>٣. قيمة status_id المستخدمة فعلياً في جدول passports (أحدث 20 معاملة عمرة + التكرار)</div><div class='card-body'>";
$byStatusCnt = $pdo->query("SELECT status_id, COUNT(*) as cnt, GROUP_CONCAT(id ORDER BY id DESC SEPARATOR ',') as ids
                            FROM passports WHERE transaction_type='umrah' AND deleted_at IS NULL
                            GROUP BY status_id ORDER BY cnt DESC")->fetchAll();
echo "<table class='table table-sm small table-bordered'><thead class='bg-light'><tr><th>status_id</th><th>عدد المعاملات</th><th>ماذا يعني في statuses؟</th><th>موجودة في workflow_steps؟</th><th>النتيجة</th></tr></thead><tbody>";
$totalUnmatched = 0;
$unmatchedIds = [];
foreach ($byStatusCnt as $b) {
    $sid = (int)$b['status_id'];
    $statusName = $pdo->prepare("SELECT status_name FROM statuses WHERE id=?")->fetchAll();
    $statusNameStmt = $pdo->prepare("SELECT status_name FROM statuses WHERE id=?"); $statusNameStmt->execute([$sid]); $statusName = $statusNameStmt->fetchColumn()?:null;
    $stepMatchStmt = $pdo->prepare("SELECT id, step_name FROM workflow_steps WHERE status_id=? AND workflow_id IN (SELECT id FROM workflows WHERE transaction_type IN ('umrah','all')) LIMIT 1"); $stepMatchStmt->execute([$sid]); $stepMatch = $stepMatchStmt->fetch();
    $inSteps = $stepMatch!==false;
    $totalRows = (int)$b['cnt'];
    $statusText = $statusName ? "<span class='fw-bold text-dark'>".htmlspecialchars($statusName)."</span>" : "<span class='badge bg-secondary'>ID غير موجود في statuses!</span>";
    $stepsText = $inSteps ? "<span class='badge bg-success text-white'>موجود: Step#{$stepMatch['id']} → ".htmlspecialchars($stepMatch['step_name'])."</span>" : "<span class='badge bg-danger'>❌ غير موجود!</span>";
    $ok = $inSteps;
    if (!$ok) { $totalUnmatched += $totalRows; $unmatchedIds[] = $sid; }
    echo "<tr><td class='fw-bold text-center'>#$sid</td><td class='text-center'>$totalRows معاملة  <small class='text-muted'>(IDs: ".htmlspecialchars(mb_substr($b['ids'],0,40))."...)</small></td><td>$statusText</td><td>$stepsText</td><td>" . ($ok?'<span class="ok">صالح</span>':'<span class="bad">🚨 غير صالح → لن تظهر الانتقالات!</span>') . "</td></tr>";
}
echo "</tbody></table>";
if ($totalUnmatched>0) {
    echo "<div class='alert alert-danger small py-2 mt-2'><b>🚨 إجمالي المعاملات التي status_id غير متوافق مع المراحل: $totalUnmatched معاملة!</b><br>سأقوم بإنشاء خريطة تطابق قريبة لحلها في الإصلاح الشامل.</div>";

    echo "<hr><div class='mt-3 small'><b>اقتراح خريطة ذكية للربط (بناءً على الاسم/المفتاح):</b><br>";
    // توليد خريطة تطابق بين كل status_id غير المطابق و workflow_step الأقرب (بالاسم/مفتاح مشابه)
    $allSteps = $pdo->query("SELECT id, step_name, step_key, status_id FROM workflow_steps WHERE workflow_id IN (SELECT id FROM workflows WHERE transaction_type IN ('umrah','all'))")->fetchAll();
    $allStats = $pdo->query("SELECT id, status_name FROM statuses")->fetchAll();
    $statMap = []; foreach ($allStats as $s) $statMap[(int)$s['id']]=$s['status_name'];
    foreach ($unmatchedIds as $sid) {
        $statusNameForMatch = $statMap[$sid] ?? '';
        // البحث في المراحل عن تطابق جزئي في الاسم
        $bestStep = null; $bestScore = 0;
        foreach ($allSteps as $sp) {
            $sim = 0;
            similar_text($statusNameForMatch, $sp['step_name'], $pct); if ($pct>$bestScore) {$bestScore=$pct; $bestStep=$sp;}
            if (stripos($sp['step_name'] ?? '', (string)$statusNameForMatch)!==false || stripos((string)$statusNameForMatch, $sp['step_name'] ?? '')!==false) {$bestScore=100; $bestStep=$sp; break;}
            if (!empty($sp['step_key']) && (string)$sp['step_key'] === (string)$sid) {$bestScore=100; $bestStep=$sp; break;}
        }
        echo "<div> status #$sid (<b>".htmlspecialchars($statusNameForMatch)."</b>) → اقرب مرحلة: Step#{$bestStep['id']} <b>".htmlspecialchars($bestStep['step_name']??'?')."</b> (التشابه: ".round($bestScore)."%) → يجب ربطها ب status_id=$sid أو تعيين مرحلة افتراضية لها.</div>";
    }
    echo "</div>";
}
echo "</div></div>";

echo "<div class='card mb-4 shadow-sm'><div class='card-header fw-bold'>٤. الأدوار المستخدمة في النظام (لإصلاح فحص canEditWf)</div><div class='card-body'>";
$roles = $pdo->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();
echo "<div class='row g-2 mb-2'>";
foreach ($roles as $r) {
    $cntUsersStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role_id=? AND deleted_at IS NULL"); $cntUsersStmt->execute([(int)$r['id']]); $cntUsers = $cntUsersStmt->fetchColumn();
    echo "<div class='col-md-4'><div class='alert alert-light border py-2 mb-0 small d-flex justify-content-between'><div><span class='badge bg-primary rounded-pill me-2'>Role#{$r['id']}</span><b>".htmlspecialchars($r['name'])."</b> <small class='text-muted'>(".htmlspecialchars($r['display_name'] ?? '').")</small></div><span class='badge bg-light text-dark border'>$cntUsers مستخدم</span></div></div>";
}
echo "</div>";
echo "<div class='small alert alert-secondary py-2 mb-0'><b>ملاحظة:</b> الشرط الحالي ل canEditWf يسمح فقط بـ: <code>admin|developer|مدير|مبرمج|مطور</code> أو صلاحيتين محددتين. إذا كان هناك دور مثل: 'موظف عمرة' أو 'موظف تأشيرات' فلن يتمكن من تعديل سير العمل حتى ولو كان دوره مسموحًا به في الانتقالات!</div>";
echo "</div></div>";

echo "</div></body></html>";
