<?php
$page_title = "فحص صحة النظام - Health Check";
require_once __DIR__ . '/../header.php';

if (!$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، هذه الصفحة للمدير فقط.</div></div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

ensure_system_admin_tables();
require_once __DIR__ . '/../../includes/system_admin/HealthCheck.php';

$msg = '';
$result = null;
$userId = $_SESSION['admin_id'] ?? ($_SESSION['user_id'] ?? null);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run_health_check'])) {
    $result = AlGhazali_HealthCheck::runAll(true, $userId);
    $msg = "<div class='alert alert-success rounded-4'><i class='fas fa-check-circle me-2'></i> اكتمل الفحص الصحي بنجاح وحُفظ السجل في قاعدة البيانات.</div>";
} elseif (isset($_GET['quick'])) {
    $result = AlGhazali_HealthCheck::runAll(false, $userId);
}

$history = AlGhazali_HealthCheck::recentHistory(50);
?>

<div class="container-fluid py-4">
    <?php if ($msg) echo $msg; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-to-r from-danger to-success text-white p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-4">
                <i class="fas fa-heart-pulse fa-3x opacity-75"></i>
                <div>
                    <h2 class="fw-bold mb-1">فحص صحة النظام - Health Check</h2>
                    <p class="mb-0 opacity-75">قاعدة البيانات • PHP • Apache • الامتدادات • الصلاحيات • مساحة القرص • الجداول • الاتصالات</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <form method="GET" class="d-inline"><button class="btn btn-outline-light rounded-pill" name="quick" value="1"><i class="fas fa-bolt me-1"></i> فحص سريع (بدون حفظ)</button></form>
                <form method="POST" class="d-inline"><button class="btn btn-light text-success rounded-pill" name="run_health_check" value="1"><i class="fas fa-play me-1"></i> تشغيل فحص كامل + حفظ</button></form>
            </div>
        </div>
    </div>

    <?php if ($result):
        $overall = $result['overall'];
        $ovColor = $overall === 'healthy' ? 'success' : ($overall === 'warning' ? 'warning' : 'danger');
        $ovIcon = $overall === 'healthy' ? 'heart-circle-check' : ($overall === 'warning' ? 'heart-crack' : 'skull');
    ?>
        <div class="row mb-4">
            <div class="col-lg-4 mb-4">
                <div class="card border-0 shadow-sm rounded-4 h-100 bg-<?= $ovColor ?> text-white">
                    <div class="card-body p-5 text-center">
                        <i class="fas fa-<?= $ovIcon ?> display-1 opacity-85 mb-3"></i>
                        <h3 class="fw-bold mb-0">الحالة العامة: <?= $overall === 'healthy' ? 'سليمة' : ($overall === 'warning' ? 'تحذيرات' : 'مشاكل حرجة') ?></h3>
                        <p class="opacity-75 mb-0 mt-2">وقت التنفيذ: <?= date('Y-m-d H:i:s') ?></p>
                    </div>
                </div>
            </div>
            <div class="col-lg-8 mb-4">
                <div class="row g-3 h-100">
                    <?php foreach ($result['components'] as $c):
                        $st = $c['status'];
                        $color = $st === 'ok' ? 'success' : ($st === 'warn' ? 'warning' : ($st === 'fail' ? 'danger' : 'secondary'));
                        $icon = $st === 'ok' ? 'check-circle' : ($st === 'warn' ? 'triangle-exclamation' : ($st === 'fail' ? 'xmark-circle' : 'circle-info'));
                    ?>
                        <div class="col-md-6">
                            <div class="card border-0 shadow-sm rounded-4 h-100 border-<?= $color ?> border-2">
                                <div class="card-body p-4">
                                    <div class="d-flex gap-3 align-items-start">
                                        <div class="p-2 rounded-3 bg-<?= $color ?> text-white"><i class="fas fa-<?= $icon ?>"></i></div>
                                        <div class="flex-grow-1">
                                            <h6 class="fw-bold mb-1 d-flex justify-content-between"><span><?= match($c['component']) { 'db'=>'قاعدة البيانات','php'=>'PHP','apache'=>'Apache','extensions'=>'الامتدادات','disk_permissions'=>'أذونات القرص','disk_space'=>'مساحة القرص','tables'=>'فحص الجداول','backup_freshness'=>'حداثة النسخ الاحتياطي','external_connectivity'=>'الاتصالات الخارجية', default=>$c['component'] } ?></span><span class="badge bg-<?= $color ?>"><?= $st ?></span></h6>
                                            <p class="small mb-1 text-muted"><?= htmlspecialchars((string)($c['message'] ?? '')) ?></p>
                                            <?php if (!empty($c['recommendation'])): ?><div class="small p-2 bg-warning-subtle rounded-3 border-start border-4 border-warning text-warning-emphasis"><i class="fas fa-lightbulb me-1"></i> <?= htmlspecialchars($c['recommendation']) ?></div><?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-5 text-center text-muted">
                <i class="fas fa-heart-pulse fs-1 mb-3 text-secondary"></i>
                <h4>لم يتم تشغيل الفحص بعد</h4>
                <p>اضغط "تشغيل فحص كامل" أعلاه لبدء الفحص الصحي الشامل للنظام.</p>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-clock-rotate-left me-2 text-primary"></i> سجل عمليات الفحص الصحي السابقة (آخر 50)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الوقت</th>
                    <th>المنفذ</th>
                    <th>الحالة العامة</th>
                    <th>المكون</th>
                    <th>حالة المكون</th>
                    <th>الرسالة</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($history)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted small">لا توجد سجلات سابقة.</td></tr>
                    <?php else: foreach ($history as $h):
                        $ovC = match($h['overall_status']) { 'healthy'=>'success','warning'=>'warning','critical'=>'danger', default=>'secondary' };
                        $cc = match($h['component_status']) { 'ok'=>'success','warn'=>'warning','fail'=>'danger', default=>'secondary' };
                    ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars($h['executed_at']) ?></td>
                            <td class="small"><?= htmlspecialchars((string)($h['full_name'] ?? ($h['username'] ?? '-'))) ?></td>
                            <td><span class="badge bg-<?= $ovC ?> rounded-pill"><?= $h['overall_status'] ?></span></td>
                            <td class="small fw-semibold"><?= htmlspecialchars($h['component']) ?></td>
                            <td><span class="badge bg-<?= $cc ?>"><?= $h['component_status'] ?></span></td>
                            <td class="small text-muted text-truncate" style="max-width:400px;"><?= htmlspecialchars((string)($h['component_message'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const statusLabels = {ok: 'سليم', warn: 'تحذير', fail: 'فشل', healthy: 'سليم', warning: 'تحذيرات', critical: 'مشاكل حرجة'};
    document.querySelectorAll('.badge').forEach(function (badge) {
        const value = badge.textContent.trim().toLowerCase();
        if (statusLabels[value]) badge.textContent = statusLabels[value];
    });
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
