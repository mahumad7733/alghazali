<?php
/**
 * إصلاحات حاسمة لسير العمل للعمرة
 * - إضافة عمود workflow_step_id المفقود في جدول passports
 * - تصحيح تداخل ترتيب المراحل (step الملغى يأخذ مرتبة نهاية)
 * - إضافة الانتقالات المفقودة للإلغاء من أي مرحلة (باستثناء النهائيات)
 * - إضافة 2 انتقالات رجوع أساسية عند الحاجة
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html dir='rtl'><head><meta charset='UTF-8'><title>إصلاح سير العمل للعمرة</title>";
echo "<link rel='stylesheet' href='../assets/css/bootstrap.min.css'></head><body class='bg-light p-4'><div class='container'>";
echo "<h3 class='mb-4'><i class='fas fa-wrench text-primary me-2'></i> تطبيق إصلاحات سير العمل للعمرة</h3><hr>";

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ----------------------------
// الإصلاح ١: إضافة عمود workflow_step_id
// ----------------------------
echo "<h6 class='fw-bold mt-4'>الإصلاح ١/٤ — إضافة عمود <code>workflow_step_id</code> إلى جدول passports</h6>";
try {
    $colExistsStmt = $pdo->query("SHOW COLUMNS FROM passports LIKE 'workflow_step_id'");
    if ($colExistsStmt->rowCount() > 0) {
        echo "<div class='alert alert-info py-2'>✅ العمود موجود مسبقاً (لا حاجة لإضافته).</div>";
    } else {
        $pdo->exec("ALTER TABLE passports
            ADD COLUMN workflow_step_id INT(11) NULL DEFAULT NULL
            COMMENT 'معرف المرحلة الحالية في سير العمل (ربط بـ workflow_steps.id)'
            AFTER workflow_id");
        try {
            $pdo->exec("ALTER TABLE passports ADD INDEX idx_workflow_step_id (workflow_step_id)");
        } catch (Throwable $e) { /* ignore if index exists */ }
        try {
            $pdo->exec("ALTER TABLE passports ADD CONSTRAINT fk_passports_workflow_step
                FOREIGN KEY (workflow_step_id) REFERENCES workflow_steps(id) ON DELETE SET NULL");
        } catch (Throwable $e) { /* ignore if FK exists */ }
        echo "<div class='alert alert-success py-2'>✅ تم إضافة العمود + INDEX + FOREIGN KEY بنجاح!</div>";

        // تعبئة القيم الأولية للمعاملات الموجودة مسبقاً (بناءً على status_id)
        echo "<div class='alert alert-warning py-2 small'>🔄 الآن نقوم بربط المعاملات القديمة بمراحلها الصحيحة...</div>";
        $wfInfo = $pdo->query("SELECT id FROM workflows WHERE transaction_type IN ('umrah','all') ORDER BY transaction_type='umrah' DESC, id ASC LIMIT 1")->fetch();
        if ($wfInfo) {
            $wid = (int)$wfInfo['id'];
            $stepsMapStmt = $pdo->prepare("SELECT status_id, id FROM workflow_steps WHERE workflow_id = ? AND status_id IS NOT NULL");
            $stepsMapStmt->execute([$wid]);
            $map = [];
            foreach ($stepsMapStmt->fetchAll() as $sm) $map[(int)$sm['status_id']] = (int)$sm['id'];
            $updateStmt = $pdo->prepare("UPDATE passports SET workflow_step_id = ? WHERE transaction_type='umrah' AND status_id = ? AND (workflow_step_id IS NULL OR workflow_step_id=0)");
            $updatedTotal = 0;
            foreach ($map as $sid => $stepId) {
                $updateStmt->execute([$stepId, $sid]);
                $updatedTotal += $updateStmt->rowCount();
            }
            // إضافة workflow_id أيضاً للمعاملات التي لا تملك
            $fixWfId = $pdo->prepare("UPDATE passports SET workflow_id = ? WHERE transaction_type='umrah' AND (workflow_id IS NULL OR workflow_id=0)");
            $fixWfId->execute([$wid]);
            echo "<div class='alert alert-success py-2 small'>✅ تم ربط <b>$updatedTotal</b> معاملة عمرة سابقة بمراحلها + ربط {$fixWfId->rowCount()} معاملة بمعرف سير العمل.</div>";
        }
    }
} catch (Throwable $e) {
    echo "<div class='alert alert-danger py-2'><b>خطأ:</b> {$e->getMessage()}<br><pre>{$e->getTraceAsString()}</pre></div>";
}

// ----------------------------
// الإصلاح ٢: إزالة تداخل ترتيب المراحل (الملغى step#16 → ترتيب 6 للنهاية)
// ----------------------------
echo "<h6 class='fw-bold mt-4'>الإصلاح ٢/٤ — تصحيح تداخل ترتيب المراحل</h6>";
try {
    $fix = $pdo->prepare("
        UPDATE workflow_steps
        SET sort_order = CASE
            WHEN id = 16 THEN 6
            WHEN id = 4  THEN 5
            ELSE sort_order
        END
        WHERE id IN (16, 4) AND workflow_id IN (SELECT id FROM (SELECT id FROM workflows WHERE transaction_type IN ('umrah','all')) as wf)
    ");
    $fix->execute();
    $n = $fix->rowCount();
    echo "<div class='alert alert-success py-2 small'>✅ تم تعديل {$n} مرحلة. الآن الترتيب النهائي للمسار هو:</div>";
    $showStmt = $pdo->query("
        SELECT id, step_name, step_key, sort_order, color, is_initial, is_final
        FROM workflow_steps
        WHERE workflow_id IN (SELECT id FROM workflows WHERE transaction_type IN ('umrah','all'))
        ORDER BY sort_order ASC, id ASC
    ");
    echo "<ol class='small alert alert-light border'>";
    foreach ($showStmt->fetchAll() as $s) {
        echo "<li><b>".htmlspecialchars($s['step_name'])."</b> (sort={$s['sort_order']}, id={$s['id']}, key=".htmlspecialchars($s['step_key']).")";
        if ($s['is_initial']) echo " — <span class='badge bg-info'>بداية</span>";
        if ($s['is_final']) echo " — <span class='badge bg-success'>نهائية</span>";
        echo "</li>";
    }
    echo "</ol>";
} catch (Throwable $e) {
    echo "<div class='alert alert-danger py-2'><b>خطأ:</b> {$e->getMessage()}</div>";
}

// ----------------------------
// الإصلاح ٣: إضافة انتقالات الإلغاء (من أي مرحلة غير نهائية → ملغى)
// ----------------------------
echo "<h6 class='fw-bold mt-4'>الإصلاح ٣/٤ — إضافة الانتقالات المفقودة للإلغاء</h6>";
try {
    $wfId = (int)($pdo->query("SELECT id FROM workflows WHERE transaction_type='umrah' ORDER BY id LIMIT 1")->fetchColumn());
    $cancelledStepId = 16; // Step ID of "ملغى"
    $sourceSteps = $pdo->query("SELECT id, step_name FROM workflow_steps WHERE workflow_id=$wfId AND is_final=0 AND id != $cancelledStepId ORDER BY sort_order")->fetchAll();
    $added = 0;
    foreach ($sourceSteps as $src) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM workflow_transitions WHERE workflow_id=? AND from_step_id=? AND to_step_id=?");
        $chk->execute([$wfId, (int)$src['id'], $cancelledStepId]);
        if ((int)$chk->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO workflow_transitions (workflow_id, from_step_id, to_step_id, role_id, require_approval, auto_action) VALUES (?,?,?, NULL, 0, 'reverse_invoices')")
                ->execute([$wfId, (int)$src['id'], $cancelledStepId]);
            $added++;
            echo "<div class='small text-success'><i class='fas fa-plus'></i> إضافة انتقال: <b>".htmlspecialchars($src['step_name'])."</b> → <span class='badge bg-secondary'>ملغى</span> (مع auto_action: reverse_invoices لعكس الفواتير تلقائياً)</div>";
        }
    }
    echo "<div class='alert alert-success py-2'>✅ تم إضافة <b>{$added}</b> انتقالات إلغاء جديدة. (التكرارات تم تجاهلها).</div>";
} catch (Throwable $e) {
    echo "<div class='alert alert-danger py-2'><b>خطأ:</b> {$e->getMessage()}</div>";
}

// ----------------------------
// الإصلاح ٤: إضافة انتقالات رجوع أساسية (لتصحيح الأخطاء)
// ----------------------------
echo "<h6 class='fw-bold mt-4'>الإصلاح ٤/٤ — إضافة انتقالات رجوع أساسية</h6>";
$backSteps = [
    // [from_step_id, to_step_id]
    [3, 14],    // تم إصدار التأشيرة → رجوع لـ "تم رفع ملفات للسفارة" (في حال اكتشاف خطأ قبل التسليم)
    [18, 3],    // جاهز للاستلام → رجوع لـ "تم إصدار التأشيرة" (في حال اكتشاف خطأ في الحقول)
    [18, 14],   // جاهز للاستلام → رجوع مباشر للسفارة
];
$addedB = 0;
$wfId = (int)($pdo->query("SELECT id FROM workflows WHERE transaction_type='umrah' ORDER BY id LIMIT 1")->fetchColumn());
try {
    foreach ($backSteps as [$from, $to]) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM workflow_transitions WHERE workflow_id=? AND from_step_id=? AND to_step_id=?");
        $chk->execute([$wfId, $from, $to]);
        if ((int)$chk->fetchColumn() === 0) {
            $fromName = $pdo->query("SELECT step_name FROM workflow_steps WHERE id=$from")->fetchColumn();
            $toName   = $pdo->query("SELECT step_name FROM workflow_steps WHERE id=$to")->fetchColumn();
            $pdo->prepare("INSERT INTO workflow_transitions (workflow_id, from_step_id, to_step_id, role_id, require_approval, auto_action) VALUES (?,?,?, NULL, 0, NULL)")
                ->execute([$wfId, $from, $to]);
            $addedB++;
            echo "<div class='small text-info'><i class='fas fa-rotate-left'></i> إضافة انتقال عكسي: <b>".htmlspecialchars($fromName)."</b> ← <b>".htmlspecialchars($toName)."</b></div>";
        }
    }
    echo "<div class='alert alert-success py-2'>✅ تم إضافة <b>{$addedB}</b> انتقالات عكسية جديدة.</div>";
} catch (Throwable $e) {
    echo "<div class='alert alert-danger py-2'><b>خطأ:</b> {$e->getMessage()}</div>";
}

echo "<hr><h6 class='fw-bold mt-4'>🎯 ملاحظات مهمة بعد الإصلاح:</h6>";
echo "<div class='alert alert-primary small'><ul class='mb-0'>";
echo "<li>لرؤية الإصلاحات في صفحة العمرة: <b>قم بتحديث الصفحة (Ctrl+F5)</b> ثم افتح تبويب سير العمل.</li>";
echo "<li>من الآن فصاعداً ستظهر مرحلة <b>الملغى</b> كزر للإلغاء في كل المراحل غير النهائية.</li>";
echo "<li>عند الإلغاء سيتم تشغيل <code>reverse_invoices</code> لعكس فواتير البيع والشراء تلقائياً.</li>";
echo "<li>الترتيب الآن صحيح: جديد → رفع للسفارة → إصدار التأشيرة → جاهز للاستلام → تم التسليم (والملغى في مسار منفصل).</li>";
echo "</ul></div>";

echo "</div></body></html>";
