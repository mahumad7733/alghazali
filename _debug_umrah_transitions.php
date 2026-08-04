<?php

/**
 * تشخيص لماذا لا تظهر زرارات الانتقال في تبويب سير العمل للعمرة
 * يحاكي تماماً منطق ajax_umrah.php action=view_details
 * + يعرض نتائج كل فحص خطوة بخطوة
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='UTF-8'><title>🔍 تشخيص عدم ظهور الانتقالات</title>";
echo "<link rel='stylesheet' href='assets/css/bootstrap.min.css'>";
echo "<style>body{font-family:Tahoma;padding:15px;} .ok{color:#16a34a} .bad{color:#dc2626;font-weight:bold} .warn{color:#d97706;font-weight:bold}
.step{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:15px 18px;margin-bottom:10px;}
.small-json{max-height:200px;overflow:auto;font-size:12px;background:#111;color:#eee;padding:10px;border-radius:8px;direction:ltr;text-align:left;}
</style></head><body class='bg-light'>";
echo "<div class='container'>";
echo "<h4 class='mb-3 text-primary'><i class='fas fa-bug me-2'></i> تشخيص سبب عدم ظهور زرارات الانتقال في سير العمل للعمرة</h4>";
echo "<div class='alert alert-secondary small py-2'>هذا السكربت يحاكي بالضبط منطق الكود في <a target='_blank' class='fw-bold' href='admin/ajax_umrah.php'>ajax_umrah.php</a> (action=view_details) خطوة بخطوة</div>";

// --- الحصول على بيانات المستخدم الحالي (من الجلسة) ---
echo "<div class='step'><b class='text-info'>① بيانات الجلسة (المستخدم المتصل حالياً)</b><hr>";
$userId = (int)($_SESSION['admin_id'] ?? 0);
$userRole = $_SESSION['role'] ?? '';
$userRoleId = (int)($_SESSION['role_id'] ?? 0);
$userRoleLower = mb_strtolower((string)$userRole, 'UTF-8');
echo "<div class='row g-2 small'>";
echo "<div class='col-md-3'>👤 admin_id: <b class='text-dark'>$userId</b> " . ($userId > 0 ? '<span class="ok">✅ موجود</span>' : '<span class="bad">❌ مفقود - يجب تسجيل الدخول أولاً!</span>') . "</div>";
echo "<div class='col-md-3'>🎭 الدور (role): <b class='text-dark'>" . htmlspecialchars($userRole) . "</b></div>";
echo "<div class='col-md-3'>🔑 role_id: <b class='text-dark'>$userRoleId</b> " . ($userRoleId > 0 ? '<span class="ok">✅</span>' : '<span class="warn">⚠️ صفر / غير معروف</span>') . "</div>";
echo "<div class='col-md-3'>🔤 role_lowercase: <code>" . htmlspecialchars($userRoleLower) . "</code></div>";
echo "</div>";

$isAdminBypass = in_array($userRoleLower, ['admin', 'developer', 'مدير', 'مبرمج', 'مطور'], true);
echo "<div class='mt-3'><b>هل تجاوز صلاحيات الأدوار؟ (Bypass) — من قائمة المدير/المطور:</b> " . ($isAdminBypass ? '<span class="badge bg-success text-white ms-1">✅ نعم - يمكن تنفيذ أي انتقال</span>' : '<span class="badge bg-light text-dark border ms-1">لا - يخضع لفحص role_id في الانتقالات + فحص has_permission</span>') . "</div>";

echo "<div class='mt-3 small'><b>اختبار صلاحيات خاصة (has_permission):</b>";
$perms = ['umrah_edit_workflow', 'request_document_confirmation', 'umrah_view_history', 'umrah_view_workflow'];
echo "<ul class='list-group mt-2'>";
foreach ($perms as $pname) {
    try {
        $res = $userId > 0 ? has_permission($pname) : false;
    } catch (Throwable $e) {
        $res = null;
    }
    $cls = $res === true ? 'list-group-item-success' : ($res === false ? 'list-group-item-light text-muted' : 'list-group-item-warning');
    echo "<li class='list-group-item py-1 px-3 small $cls'>" . ($res ? '✅' : '❌') . " has_permission('<code>$pname</code>') → " . var_export($res, true) . "</li>";
}
echo "</ul></div>";
echo "</div>";

// --- اختيار معاملة عمرة عينة أو استخدام أول معاملة متاحة ---
echo "<div class='step'><b class='text-info'>② اختيار معاملة عمرة لاختبار سير العمل عليها</b><hr>";
$passport = null;
$passport_id = (int)($_GET['id'] ?? 0);
if ($passport_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM passports WHERE id=? AND transaction_type='umrah' AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$passport_id]);
    $passport = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$passport) echo "<div class='alert alert-danger small'>المعاملة المحددة #$passport_id غير موجودة!</div>";
}
if (!$passport) {
    $stmt = $pdo->query("SELECT * FROM passports WHERE transaction_type='umrah' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
    $passport = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$passport) {
        echo "<div class='alert alert-warning small'>⚠️ لا توجد أي معاملات عمرة حالياً! سيتم اختيار سير عمل افتراضي للفحص فقط...</div>";
    } else {
        $passport_id = (int)$passport['id'];
        echo "<div class='alert alert-info small py-1'>ℹ️ تم اختيار أحدث معاملة عمرة موجودة: <b>#$passport_id - " . htmlspecialchars($passport['full_name'] ?? 'بدون اسم') . "</b> (يمكنك تمرير ?id=XXX لاختيار معاملة أخرى)</div>";
    }
}
if ($passport) {
    echo "<div class='row g-2 small mt-2'>";
    echo "<div class='col-md-4'>🆔 المعاملة: <b>#{$passport['id']}</b></div>";
    echo "<div class='col-md-4'>👤 الاسم: <b>" . htmlspecialchars($passport['full_name'] ?? '') . "</b></div>";
    echo "<div class='col-md-4'>📘 جواز: <b>" . htmlspecialchars($passport['passport_number'] ?? '') . "</b></div>";
    echo "<div class='col-md-4'>🆔 status_id: <code>" . var_export($passport['status_id'], true) . "</code></div>";
    echo "<div class='col-md-4'>🆔 workflow_id: <code>" . var_export($passport['workflow_id'] ?? null, true) . "</code></div>";
    echo "<div class='col-md-4'>🆔 workflow_step_id: <code>" . var_export($passport['workflow_step_id'] ?? null, true) . "</code> " . (empty($passport['workflow_step_id']) ? '<span class="warn">⚠️ فارغ — سيتم البحث عبر status_id!</span>' : '<span class="ok">✅ متوفر مباشرة</span>') . "</div>";
    echo "</div>";
}
echo "</div>";

// --- تحميل سير العمل مثل ما يحدث في ajax_umrah.php ---
echo "<div class='step'><b class='text-info'>③ تحميل بيانات سير العمل (كود ajax_umrah.php lines 239-292)</b><hr>";
$workflowId = (int)(($passport['workflow_id'] ?? 0));
echo "<div>🔍 الخطوة 240: المعاملة تحتوي workflow_id = <b>$workflowId</b> ";
if ($workflowId <= 0) {
    echo "<span class='warn'>⚠️ 0! سيتم البحث عن سير عمل افتراضي للعمرة...</span>";
    $stmtWfDef = $pdo->prepare("SELECT id, default_status_id, name FROM workflows WHERE transaction_type IN ('umrah','all') ORDER BY transaction_type='umrah' DESC, id ASC LIMIT 1");
    $stmtWfDef->execute();
    $wfDef = $stmtWfDef->fetch(PDO::FETCH_ASSOC);
    echo "<br>↳ النتيجة: " . ($wfDef ? "<span class='ok'>✅ وجدنا WF#{$wfDef['id']} اسمها: <b>" . htmlspecialchars($wfDef['name']) . "</b> default_status_id={$wfDef['default_status_id']}</span>" : "<span class='bad'>❌ فشل! لا يوجد سير عمل.</span>");
    if ($wfDef) $workflowId = (int)$wfDef['id'];
}
echo "</div>";

$workflow = null;
$allSteps = [];
if ($workflowId > 0) {
    $stmtWf = $pdo->prepare("SELECT * FROM workflows WHERE id = ?");
    $stmtWf->execute([$workflowId]);
    $workflow = $stmtWf->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($workflow) echo "<div class='mt-2'>✅ تم تحميل سير العمل: <b>" . htmlspecialchars($workflow['name']) . "</b></div>";

    $stmtAllSteps = $pdo->prepare("SELECT ws.*, s.status_name, s.status_color FROM workflow_steps ws LEFT JOIN statuses s ON s.id = ws.status_id WHERE ws.workflow_id = ? ORDER BY ws.sort_order ASC, ws.id ASC");
    $stmtAllSteps->execute([$workflowId]);
    $allSteps = $stmtAllSteps->fetchAll(PDO::FETCH_ASSOC);
    echo "<div>🔹 عدد المراحل في السير: <b>" . count($allSteps) . "</b></div>";
}

// --- حل المرحلة الحالية ---
echo "<div class='mt-3 pt-3 border-top'><b>حل المرحلة الحالية (currentStepId):</b>";
$currentStepId = 0;
if (!empty($passport['workflow_step_id'])) {
    $currentStepId = (int)$passport['workflow_step_id'];
    echo "<div class='small'>✓ طريقة ① مباشرة من workflow_step_id → ID=$currentStepId</div>";
}
if ($currentStepId <= 0) {
    $curStatus = (int)($passport['status_id'] ?? 0);
    echo "<div class='small warn'>⚠️ طريقة ② مطابقة status_id = $curStatus على قائمة المراحل...</div>";
    foreach ($allSteps as $stp) {
        if ((int)$stp['status_id'] === $curStatus) {
            $currentStepId = (int)$stp['id'];
            echo "<div class='small ok'>✅ مطابقة! وجدنا المرحلة #$currentStepId اسمها: <b>" . htmlspecialchars($stp['step_name']) . "</b></div>";
            break;
        }
    }
    if ($currentStepId <= 0) echo "<div class='small bad'>❌ فشل! لا توجد مرحلة في سير العمل مرتبطة بـ status_id=$curStatus. <u>هذا أحد الأسباب الشائعة لعدم ظهور الانتقالات!</u></div>";
}
$currentStep = null;
foreach ($allSteps as $stp) if ((int)$stp['id'] === $currentStepId) {
    $currentStep = $stp;
    break;
}
echo "<div class='mt-2'><b>النتيجة النهائية:</b> المرحلة الحالية = <span class='badge bg-primary fs-6'>#$currentStepId " . htmlspecialchars($currentStep['step_name'] ?? '(غير محدد)') . "</span>";
if (!$currentStep) echo " <span class='bad'>❌ فارغ → سيعرض رسالة 'لا توجد سير عمل مخصص لهذه المعاملة'.</span>";
else echo " <span class='ok'>✅ صالحة للاستعلام عن الانتقالات منها.</span>";
echo "</div></div>";
echo "</div>";

// --- التحقق من canEditWf ---
echo "<div class='step'><b class='text-info'>④ فحص شرط <code>canEditWf</code> — الحاجز الذي يمنع عرض زرارات الانتقال حتى لو وجدت!</b><hr>";
try {
    $perm_edit = $userId > 0 ? has_permission('umrah_edit_workflow') : false;
} catch (Throwable $e) {
    $perm_edit = false;
}
try {
    $perm_doc  = $userId > 0 ? has_permission('request_document_confirmation') : false;
} catch (Throwable $e) {
    $perm_doc = false;
}
$cond_isAdmin = $isAdminBypass || ($userId > 0 && $perm_edit);
$cond_canEditWf_body = ($userId > 0 && ($perm_edit || $perm_doc));
$canEditWf = $cond_isAdmin || $cond_canEditWf_body;
echo "<table class='table table-sm small table-bordered mt-2'>";
echo "<tr class='bg-light'><th>الشرط</th><th>النتيجة</th><th>ملاحظة</th></tr>";
echo "<tr><td><code>\$isAdmin (تجاوز صلاحيات: مدير/مطور) OR has_permission('umrah_edit_workflow')</code></td><td>" . ($cond_isAdmin ? '<span class="ok">TRUE ✅</span>' : '<span class="bad">FALSE ❌</span>') . "</td><td>" . ($cond_isAdmin ? 'الدخول مفتوح' : 'أحد الشرطين غير مفعّل') . "</td></tr>";
echo "<tr><td><code>has_permission('umrah_edit_workflow') OR has_permission('request_document_confirmation')</code></td><td>" . ($cond_canEditWf_body ? '<span class="ok">TRUE ✅</span>' : '<span class="bad">FALSE ❌</span>') . "</td><td>هذا هو الشرط الشائع لعدم ظهور الأزرار للموظف العادي</td></tr>";
echo "<tr class='bg-info bg-opacity-10 fw-bold'><td>النتيجة النهائية → canEditWf</td><td>" . ($canEditWf ? '<span class="ok">TRUE ✅</span>' : '<span class="bad">FALSE ❌ (سيتم عرض رسالة "لا تملك صلاحية تعديل سير العمل لهذه المعاملة" ولا أزرار!)</span>') . "</td><td></td></tr>";
echo "</table>";
if (!$canEditWf) echo "<div class='alert alert-danger small py-2 mb-0'>🎯 <b>السبب الأكثر احتمالاً لعدم ظهور الأزرار:</b> canEditWf = FALSE! تأكد من إعطاء المستخدم صلاحية إما: <code>umrah_edit_workflow</code> أو <code>request_document_confirmation</code> أو أن يكون دوره = مدير/مطور/admin.</div>";
echo "</div>";

// --- استدعاء get_allowed_transitions() مباشرة ---
echo "<div class='step'><b class='text-info'>⑤ استدعاء <code>get_allowed_transitions(workflow_id=$workflowId, currentStepId=$currentStepId, roleId=$userRoleId, userId=$userId)</code> — نتائجه هي التي تظهر كأزرار.</b><hr>";
if ($currentStepId > 0) {
    $start = microtime(true);
    $transitions = get_allowed_transitions($workflowId, $currentStepId, $userRoleId, $userId);
    $duration = round((microtime(true) - $start) * 1000, 1);
    echo "<div>🔍 عُثر على <b>" . count($transitions) . " انتقال/انتقالات</b> (مدة الاستعلام: {$duration}ms)</div>";
    if (count($transitions) == 0) {
        echo "<div class='warn mt-2'>⚠️ لم يعُد أي انتقال! (رغم وجود " . count($allSteps) . " مراحل في DB). السبب المحتمل:</div>";
        echo "<ol class='small mt-1'>";
        if (!$isAdminBypass) echo "<li>role_id الخاص بك (<b>$userRoleId - " . htmlspecialchars($userRole) . "</b>) غير موجود في قائمة الأدوار في أي انتقال، والانتقالات الجديدة لديها role_id=NULL لكننا نتحقق منها الآن...</li>";
        echo "<li>أو أنك لست في قائمة تجاوز الأدوار (admin/مدير/مطور) ولا يوجد transition يناسب role_id=$userRoleId.</li>";
        echo "</ol>";
        // نعرض الاستعلام النفطي لنرى لماذا لا يرجع شيئاً
        echo "<div class='small mt-3'><b>اختبار يدوي لقاعدة البيانات للانتقالات الموجودة من المرحلة الحالية #$currentStepId:</b>";
        $rawStmt = $pdo->prepare("SELECT t.id,t.from_step_id,t.to_step_id,t.role_id,t.allow_by_user_id, fs.step_name as fn, ts.step_name as tn FROM workflow_transitions t LEFT JOIN workflow_steps fs ON t.from_step_id=fs.id LEFT JOIN workflow_steps ts ON t.to_step_id=ts.id WHERE t.workflow_id=? AND t.from_step_id=?");
        $rawStmt->execute([$workflowId, $currentStepId]);
        $rawRows = $rawStmt->fetchAll();
        echo "<div>عدد الصفوف في DB دون فحص صلاحيات: <b>" . count($rawRows) . "</b>";
        if (count($rawRows) == 0) echo " <span class='bad'>❌ يعني لا يوجد على الإطلاق transition من المرحلة $currentStepId!</span>";
        else echo " <span class='ok'>✅ قاعدة البيانات تحتوي على " . count($rawRows) . " انتقالات → المشكلة في <u>فحص الصلاحيات داخل get_allowed_transitions فقط</u>.</span>";
        echo "</div>";
        if (count($rawRows) > 0) {
            echo "<table class='table table-sm small mt-2'><thead class='bg-light'><tr><th>T ID</th><th>من → إلى</th><th>role_id في الانتقال</th><th>allow_by_user_id</th><th>هل يناسبك؟</th></tr></thead><tbody>";
            foreach ($rawRows as $r) {
                $roleEmpty = empty($r['role_id']);
                $userMatches = $r['allow_by_user_id'] === null || (int)$r['allow_by_user_id'] === $userId;
                $roleMatches = false;
                if ($roleEmpty) $roleMatches = true;
                else {
                    $roleList = array_map('intval', explode(',', $r['role_id']));
                    $roleMatches = in_array($userRoleId, $roleList, true);
                }
                $fits = $userMatches && ($roleMatches || $isAdminBypass);
                echo "<tr><td>#{$r['id']}</td><td><b>" . htmlspecialchars($r['fn']) . "</b> → <b>" . htmlspecialchars($r['tn']) . "</b></td><td><code>" . htmlspecialchars(var_export($r['role_id'], true)) . "</code> " . ($roleEmpty ? '<span class="badge bg-success ms-1">الجميع 🌐</span>' : '(الأدوار المسموحة: ' . htmlspecialchars($r['role_id']) . ')') . "</td><td>" . var_export($r['allow_by_user_id'], true) . "</td><td>" . ($fits ? '<span class="ok">✅ نعم يناسب → يجب أن يظهر!</span>' : '<span class="bad">❌ لا يناسب: userMatches=' . ($userMatches ? 'T' : 'F') . ', roleMatches=' . ($roleMatches ? 'T' : 'F') . ', bypass=' . ($isAdminBypass ? 'T' : 'F') . '</span>') . "</td></tr>";
            }
            echo "</tbody></table>";
            // الآن نعرض الكود الداخلي لـ get_allowed_transitions لنرى أين الفلترة الخطأ
            echo "<div class='mt-3 alert alert-secondary small py-2'><b>🧪 اختبار مباشر للاستعلام في دالة get_allowed_transitions مع بياناتك:</b></div>";
            echo "<div class='small-json'>SELECT wt.*, ws_to.step_name as to_step_name, ws_to.color, ws_to.step_key,
  ws_to.require_note, ws_to.require_reason, wt.require_approval
FROM workflow_transitions wt
JOIN workflow_steps ws_to ON wt.to_step_id = ws_to.id
WHERE wt.workflow_id = $workflowId
  AND wt.from_step_id = $currentStepId
  AND (
     wt.role_id IS NULL OR wt.role_id = ''
     OR FIND_IN_SET($userRoleId, wt.role_id)
  )
  AND (wt.allow_by_user_id IS NULL OR wt.allow_by_user_id = $userId)
ORDER BY wt.id;</div>";
        }
        echo "</div>";
    } else {
        echo "<table class='table table-sm small table-bordered mt-2'><thead class='bg-success bg-opacity-10'><tr><th>Transition ID</th><th>إلى المرحلة</th><th>اللون</th><th>ملاحظة؟</th><th>سبب؟</th><th>موافقة؟</th><th>الزر الذي سيظهر</th></tr></thead><tbody>";
        foreach ($transitions as $tr) {
            echo "<tr><td>#{$tr['transition_id']}</td><td><span class='badge px-2 py-1 text-white' style='background:" . ($tr['color'] ?? '#6c757d') . ";'>" . htmlspecialchars($tr['to_step_name']) . "</span></td><td><code>" . ($tr['color'] ?? '') . "</code></td><td class='text-center'>" . (!empty($tr['require_note']) ? '✅' : '-') . "</td><td class='text-center'>" . (!empty($tr['require_reason']) ? '✅' : '-') . "</td><td class='text-center'>" . (!empty($tr['require_approval']) ? '✅' : '-') . "</td><td><div class='btn btn-sm fw-bold text-white rounded-pill shadow-sm text-start' style='background:" . ($tr['color'] ?? '#6c757d') . ";border-color:" . ($tr['color'] ?? '#6c757d') . ";'>← تنفيذ: " . htmlspecialchars($tr['to_step_name']) . "</div></td></tr>";
        }
        echo "</tbody></table>";
        echo "<div class='alert alert-success small py-2 mt-2 mb-0'>✅ <b>النتيجة:</b> عند فتح هذه المعاملة في تبويب سير العمل، ستظهر هذه الأزرار <u>إذا فقط إذا</u> كان canEditWf = TRUE (الفحص في الخطوة ④).</div>";
    }
} else {
    echo "<div class='warn'>لا يتم استدعاء الدالة لأن currentStepId = 0 (المرحلة الحالية غير معروفة).</div>";
}
echo "</div>";

// --- ملخص الأسباب الأكثر احتمالاً ---
echo "<div class='step border-2 border-primary'><b class='text-primary'>🏁 ملخص وتوصيات الحل بناءً على التشخيص أعلاه</b><hr>";
echo "<ol class='list-group list-group-numbered small'>";
$issueCount = 0;
if (!$canEditWf) {
    $issueCount++;
    echo "<li class='list-group-item list-group-item-danger'>🚨 <b>مشكلة الصلاحية:</b> canEditWf=FALSE للمستخدم الحالي. <b>الحل:</b> من واجهة الإدارة → إدارة الصلاحيات → أعطِ للمستخدم/الدور صلاحية <code>umrah_edit_workflow</code> أو <code>request_document_confirmation</code>.";
    if ($userId == 0) echo "<br><span class='badge bg-warning'>⚠️ لا يوجد مستخدم مسجل! (admin_id=0) → هذه المشكلة تكون عند فتح السكربت مباشرة. افتحه من داخل النظام بعد تسجيل الدخول.</span>";
    echo "</li>";
}
if ($currentStepId <= 0) {
    $issueCount++;
    echo "<li class='list-group-item list-group-item-warning'>⚠️ <b>مشكلة المرحلة الحالية:</b> لم يتم حل currentStepId للمعاملة. <b>الحل:</b> إما أن تعيين <code>workflow_step_id</code> يدويًا في جدول passports، أو التأكد أن المعاملة لها <code>status_id</code> مطابق لـ <code>status_id</code> في أحد المراحل بجدول workflow_steps.</li>";
}
if ($currentStepId > 0 && $canEditWf && empty($transitions)) {
    $issueCount++;
    echo "<li class='list-group-item list-group-item-secondary'>🔍 <b>مشكلة في فحص role_id داخل الانتقالات:</b> قاعدة البيانات تحتوي انتقالات لكن فحص الصلاحيات يرفضها. <b>الحل الموصى به:</b> ضع role_id = NULL أو فارغ (يعني للجميع) في كل الانتقالات. أو أضف role_id الخاص بموظفك إلى عمود role_id (مفصول بفواصل) في جدول workflow_transitions. (المشكلة الأكثر شيوعاً: الانتقالات القديمة لما كانت تحتوي role_id لدور تم حذفه!)</li>";
}
if ($issueCount == 0) {
    echo "<li class='list-group-item list-group-item-success'>✅ لم يتم اكتشاف أي مشاكل! إذا ظل الأزرار لا تظهر، فالمشكلة تكون غالباً في <u>ذاكرة المتصفح (Cache)</u>: اضغط <kbd>Ctrl+Shift+R</kbd> أو افتح النافذة في وضع التصفح الخاص (Incognito).</li>";
}
echo "</ol>";
echo "</div>";

echo "<hr>";
echo "<div class='d-flex gap-2 flex-wrap'>";
echo "<a class='btn btn-primary rounded-pill' href='admin/umrah.php'><i class='fas fa-arrow-left me-1'></i> العودة لصفحة العمرة</a>";
echo "<a class='btn btn-outline-secondary rounded-pill' href='admin/workflow.php'><i class='fas fa-gear me-1'></i> فتح إدارة سير العمل</a>";
echo "<button class='btn btn-outline-info rounded-pill' onclick='location.reload()'><i class='fas fa-sync me-1'></i> إعادة تشغيل التشخيص</button>";
if ($passport_id > 0) echo "<a class='btn btn-outline-success rounded-pill' target='_blank' href='_verify_umrah_wf.php'><i class='fas fa-diagram-project me-1'></i> عرض خريطة الانتقالات البصرية</a>";
echo "</div>";
echo "</div></body></html>";
