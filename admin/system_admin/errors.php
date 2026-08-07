<?php
$page_title = "إدارة الأخطاء - نظام تتبع Enterprise";
require_once __DIR__ . '/../header.php';

if (!$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، هذه الصفحة للمدير فقط.</div></div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

ensure_system_admin_tables();
require_once __DIR__ . '/../../includes/system_admin/ErrorTracking.php';
require_once __DIR__ . '/../../includes/system_admin/ErrorAiAnalyzer.php';

$message = '';
$analysis = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action'])) {
    $action = $_POST['action'];
    $ids = array_map('intval', $_POST['ids'] ?? []);
    $status = $_POST['new_status'] ?? '';
    $notes = trim((string)($_POST['notes'] ?? ''));
    $userId = $_SESSION['admin_id'] ?? ($_SESSION['user_id'] ?? null);
    if ($action === 'auto_fix' && !empty($_POST['error_id'])) {
        $result = AlGhazali_ErrorTracking::attemptSafeAutoFix((int)$_POST['error_id'], $userId);
        $message = '<div class="alert alert-' . ($result['success'] ? 'success' : 'warning') . ' rounded-4">' . htmlspecialchars($result['message']) . '</div>';
    }
    if ($action === 'ai_analyze' && !empty($_POST['error_id'])) {
        $analysis = AlGhazali_ErrorAiAnalyzer::analyzeById((int)$_POST['error_id']);
        if (is_array($analysis)) $analysis['error_id'] = (int)$_POST['error_id'];
    }
    if ($action !== 'auto_fix' && $action === 'bulk_status' && !empty($ids) && in_array($status, ['new','investigating','resolved','ignored'])) {
        $c = AlGhazali_ErrorTracking::updateErrorStatus($ids, $status, $userId, $notes ?: null);
        $message = "<div class='alert alert-success rounded-4'>تم تحديث $c سجل بنجاح إلى حالة: $status.</div>";
    } elseif ($action === 'add_note' && !empty($_POST['error_id']) && !empty($notes)) {
        AlGhazali_ErrorTracking::addRepairNote((int)$_POST['error_id'], $notes, $userId);
        $message = "<div class='alert alert-info rounded-4'>تمت إضافة ملاحظة الإصلاح.</div>";
    }
}

$filters = [
    'level' => !empty($_GET['level']) ? (array)$_GET['level'] : [],
    'status' => !empty($_GET['status']) ? $_GET['status'] : null,
    'priority' => !empty($_GET['priority']) ? $_GET['priority'] : null,
    'from_date' => !empty($_GET['from_date']) ? $_GET['from_date'] : null,
    'to_date' => !empty($_GET['to_date']) ? $_GET['to_date'] : null,
    'search' => !empty($_GET['q']) ? $_GET['q'] : null,
];
// Hide resolved fixes from the warning list by default. They remain available
// when the administrator explicitly selects a status filter.
if (empty($_GET['status'])) $filters['exclude_resolved'] = true;
$grouped = AlGhazali_ErrorTracking::listGrouped($filters);

$levelsMap = ['ERROR','CRITICAL','EMERGENCY','WARNING','NOTICE','DEPRECATED'];
$statusMap = ['new'=>'جديد','investigating'=>'قيد التحقيق','resolved'=>'محلول','ignored'=>'متجاهل'];
$priorityMap = ['low'=>'منخفض','medium'=>'متوسط','high'=>'مرتفع','critical'=>'حرج'];

$fingerprintToView = null;
$detailRows = [];
if (!empty($_GET['fp'])) {
    $fingerprintToView = $_GET['fp'];
    $detailRows = AlGhazali_ErrorTracking::listErrorsForFingerprint($fingerprintToView, 100);
}
?>

<style>
    .system-error-page { direction: rtl; text-align: right; }
    .system-error-page .card { overflow: hidden; }
    .system-error-page .table { min-width: 980px; }
    .system-error-page .table th, .system-error-page .table td { vertical-align: middle; }
    .system-error-page code { direction: ltr; display: inline-block; text-align: left; }
    .system-error-page .error-toolbar { gap: .75rem; }
    .system-error-page .error-actions { min-width: 170px; }
    .system-error-page .error-actions .btn { white-space: nowrap; }
    .system-error-page .analysis-card { border-right: 5px solid var(--bs-info); }
    @media (max-width: 768px) {
        .system-error-page { padding-left: .5rem !important; padding-right: .5rem !important; }
        .system-error-page .display-title { font-size: 1.25rem; }
        .system-error-page .error-toolbar > * { width: 100% !important; }
        .system-error-page .error-actions { min-width: 145px; }
    }
</style>
<div class="container-fluid py-4 system-error-page">
    <?php if ($message) echo $message; ?>

    <?php if (is_array($analysis)): ?>
        <div class="card analysis-card border-0 shadow-sm rounded-4 mb-4 border-info border-2">
            <div class="card-header bg-info-subtle d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0 fw-bold text-info-emphasis"><i class="fas fa-brain me-2"></i><?= htmlspecialchars((string)($analysis['title'] ?? 'تحليل ذكي')) ?></h5>
                <span class="badge bg-<?= (($analysis['confidence'] ?? 0) >= 80) ? 'success' : 'warning' ?>">الثقة: <?= (int)($analysis['confidence'] ?? 0) ?>%</span>
            </div>
            <div class="card-body">
                <?php if (!empty($analysis['success'])): ?>
                    <div class="small text-muted mb-2">المسار: <code><?= htmlspecialchars((string)$analysis['file']) ?>:<?= (int)$analysis['line'] ?></code> — الخطورة: <?= htmlspecialchars((string)$analysis['severity']) ?></div>
                    <p class="mb-2"><strong>الملخص:</strong> <?= htmlspecialchars((string)$analysis['summary']) ?></p>
                    <p class="mb-2"><strong>السبب المحتمل:</strong> <?= htmlspecialchars((string)$analysis['cause']) ?></p>
                    <p class="mb-2"><strong>التوصية:</strong> <?= htmlspecialchars((string)$analysis['recommendation']) ?></p>
                    <div class="alert <?= !empty($analysis['auto_fix_available']) ? 'alert-success' : 'alert-secondary' ?> mb-0"><?= htmlspecialchars((string)($analysis['next_step'] ?? $analysis['auto_fix_reason'] ?? '')) ?></div>
                    <?php if (!empty($analysis['auto_fix_available']) && !empty($analysis['error_id'])): ?>
                        <form method="POST" class="mt-3" onsubmit="return confirm('سيتم إنشاء نسخة احتياطية ثم تطبيق الإصلاح الآمن. هل تريد المتابعة؟');">
                            <input type="hidden" name="action" value="auto_fix">
                            <input type="hidden" name="error_id" value="<?= (int)$analysis['error_id'] ?>">
                            <button class="btn btn-success rounded-pill"><i class="fas fa-wand-magic-sparkles me-1"></i> تطبيق الإصلاح الآن</button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <div class="alert alert-warning mb-0"><?= htmlspecialchars((string)($analysis['message'] ?? 'تعذر تحليل الخطأ.')) ?></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-to-r from-danger to-warning text-white p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-4">
                <i class="fas fa-bug fa-3x opacity-75"></i>
                <div>
                    <h2 class="fw-bold mb-1">إدارة الأخطاء - نظام التتبع المتقدم</h2>
                    <p class="mb-0 opacity-75">Error Fingerprinting • نظام الحالات • ملاحظات الإصلاح • تجميع متكرر</p>
                </div>
            </div>
            <a href="index.php" class="btn btn-light text-danger rounded-pill"><i class="fas fa-arrow-right me-1"></i> عودة للوحة</a>
        </div>
    </div>

    <form method="GET" class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">مستوى الخطأ</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($levelsMap as $lv): ?>
                            <label class="form-check me-3">
                                <input class="form-check-input" type="checkbox" name="level[]" value="<?= $lv ?>"
                                    <?= in_array($lv, (array)$filters['level']) ? 'checked' : '' ?>>
                                <span class="form-check-label small"><?= $lv ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">الحالة</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        <?php foreach ($statusMap as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= (string)$filters['status'] === (string)$k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">الأولوية</label>
                    <select name="priority" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        <?php foreach ($priorityMap as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= (string)$filters['priority'] === (string)$k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars((string)$filters['from_date']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars((string)$filters['to_date']) ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label small fw-semibold">بحث نصي (الرسالة / الملف / الرابط)</label>
                    <div class="input-group input-group-sm">
                        <input name="q" class="form-control" value="<?= htmlspecialchars((string)$filters['search']) ?>" placeholder="مثال: Undefined index / PDO / login.php">
                        <button class="btn btn-outline-primary" type="submit"><i class="fas fa-search me-1"></i> بحث</button>
                        <a href="errors.php" class="btn btn-outline-secondary"><i class="fas fa-rotate"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <form method="POST" id="bulkForm">
        <input type="hidden" name="action" value="bulk_status">
        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-3 bg-light rounded-top-4 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex gap-2 align-items-center flex-wrap error-toolbar">
                    <span class="fw-semibold small">عمليات مجمعة على المحدد:</span>
                    <select class="form-select form-select-sm w-auto" name="new_status" id="bulkStatus">
                        <option value="">— اختر الحالة —</option>
                        <?php foreach ($statusMap as $k=>$v): ?>
                            <option value="<?= $k ?>"><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="notes" class="form-control form-control-sm w-auto" style="min-width:220px;" placeholder="ملاحظة عامة (اختياري)">
                    <button class="btn btn-sm btn-primary" onclick="return confirmBulk()"><i class="fas fa-check me-1"></i> تطبيق</button>
                </div>
                <a href="../system_error_audit.php" class="btn btn-sm btn-outline-secondary" target="_blank"><i class="fas fa-external-link-alt me-1"></i> عرض السجل التفصيلي القديم</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px;"><input class="form-check-input" onclick="toggleAllCheckboxes(this)" type="checkbox"></th>
                            <th style="width:90px;">عدد التكرارات</th>
                            <th style="width:90px;">المستوى</th>
                            <th style="width:90px;">الأولوية</th>
                            <th style="width:100px;">الحالة</th>
                            <th>ملف:سطر - نموذج الرسالة</th>
                            <th style="width:170px;">أول / آخر ظهور</th>
                            <th style="width:110px;">إجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grouped)): ?>
                            <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-clipboard-check fs-1 mb-2 text-success"></i><p>لا توجد أخطاء مطابقة لمعايير البحث.</p></td></tr>
                        <?php else: foreach ($grouped as $g):
                            $lvCls = in_array(strtoupper((string)$g['level']), ['CRITICAL','EMERGENCY']) ? 'danger' : (in_array(strtoupper((string)$g['level']),['ERROR','WARNING']) ? 'warning' : 'secondary');
                            $prCls = $g['priority'] === 'critical' ? 'danger' : ($g['priority'] === 'high' ? 'warning' : ($g['priority'] === 'medium' ? 'primary' : 'secondary'));
                            $stCls = $g['status'] === 'resolved' ? 'success' : ($g['status'] === 'investigating' ? 'primary' : ($g['status'] === 'ignored' ? 'secondary' : 'dark'));
                        ?>
                            <tr>
                                <td><input class="form-check-input row-check" type="checkbox" name="ids[]" value="<?= (int)$g['representative_id'] ?>" data-fp="<?= htmlspecialchars($g['error_fingerprint']) ?>"></td>
                                <td><span class="badge bg-<?= $lvCls ?> rounded-pill px-3 py-2"><?= htmlspecialchars((string)$g['group_count']) ?></span></td>
                                <td><span class="badge bg-<?= $lvCls ?>"><?= htmlspecialchars($g['level']) ?></span></td>
                                <td><span class="badge bg-<?= $prCls ?>"><?= $priorityMap[$g['priority']] ?? $g['priority'] ?></span></td>
                                <td><span class="badge bg-<?= $stCls ?>"><?= $statusMap[$g['status']] ?? $g['status'] ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                                        <code class="text-danger fw-bold"><?= htmlspecialchars(basename((string)$g['file']) . ':' . (int)$g['line']) ?></code>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('سيتم إنشاء نسخة احتياطية ثم تطبيق إصلاح آمن. هل تريد المتابعة؟');">
                                            <input type="hidden" name="action" value="auto_fix">
                                            <input type="hidden" name="error_id" value="<?= (int)$g['representative_id'] ?>">
                                            <button class="btn btn-sm btn-outline-success py-0 px-2" type="submit" title="إصلاح تلقائي آمن">
                                                <i class="fas fa-wand-magic-sparkles me-1"></i> إصلاح
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="action" value="ai_analyze">
                                            <input type="hidden" name="error_id" value="<?= (int)$g['representative_id'] ?>">
                                            <button class="btn btn-sm btn-outline-info py-0 px-2" type="submit" title="تحليل ذكي للخطأ"><i class="fas fa-brain me-1"></i> تحليل ذكي</button>
                                        </form>
                                    </div>
                                    <div class="small text-muted text-truncate" style="max-width:500px;"><?= htmlspecialchars((string)$g['sample_message']) ?></div>
                                </td>
                                <td>
                                    <div class="small"><span class="text-muted">أول:</span> <?= htmlspecialchars((string)$g['first_seen_at']) ?></div>
                                    <div class="small"><span class="text-muted">آخر:</span> <span class="fw-semibold"><?= htmlspecialchars((string)$g['last_seen_at']) ?></span></div>
                                </td>
                                <td>
                                    <a class="btn btn-sm btn-outline-primary" href="errors.php?fp=<?= urlencode($g['error_fingerprint']) ?>"><i class="fas fa-list me-1"></i> تفاصيل</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <?php if ($fingerprintToView): ?>
        <div class="card border-0 shadow-sm rounded-4 border-primary border-3">
            <div class="card-header bg-primary-subtle d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-fingerprint me-2"></i> أحداث البصمة: <code><?= htmlspecialchars(substr($fingerprintToView,0,16)) ?>...</code></h5>
                <a href="errors.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-xmark me-1"></i> إغلاق</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>الوقت</th>
                                <th>المستوى</th>
                                <th>الرسالة</th>
                                <th>المستخدم</th>
                                <th>الصفحة</th>
                                <th>IP</th>
                                <th>ملاحظات الإصلاح</th>
                                <th>إجراء</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detailRows as $r): ?>
                                <tr>
                                    <td class="small fw-semibold">#<?= $r['id'] ?></td>
                                    <td class="small"><?= htmlspecialchars($r['created_at']) ?></td>
                                    <td><span class="badge bg-warning"><?= htmlspecialchars($r['level']) ?></span></td>
                                    <td class="small text-truncate" style="max-width:260px;" title="<?= htmlspecialchars($r['message']) ?>"><?= htmlspecialchars($r['message']) ?></td>
                                    <td class="small"><?= (int)$r['user_id'] > 0 ? 'U:'.$r['user_id'] : '<span class="text-muted">-</span>' ?></td>
                                    <td class="small text-truncate" style="max-width:160px;"><?= htmlspecialchars((string)($r['url'] ?? '')) ?></td>
                                    <td class="small"><?= htmlspecialchars((string)($r['ip_address'] ?? '')) ?></td>
                                    <td class="small text-muted" style="max-width:180px;"><?= $r['repair_notes'] ? nl2br(htmlspecialchars($r['repair_notes'])) : '<span class="text-muted">-</span>' ?></td>
                                    <td>
                                        <div class="d-flex gap-1">
                                            <button class="btn btn-sm btn-outline-primary" onclick="openNoteModal(<?= (int)$r['id'] ?>)"><i class="fas fa-note-sticky"></i></button>
                                            <form method="POST" onsubmit="return confirm('A backup will be created before applying a safe fix. Continue?');">
                                                <input type="hidden" name="action" value="auto_fix">
                                                <input type="hidden" name="error_id" value="<?= (int)$r['id'] ?>">
                                                <button class="btn btn-sm btn-outline-success" type="submit" title="Safe automatic fix"><i class="fas fa-wand-magic-sparkles"></i></button>
                                            </form>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="ai_analyze">
                                                <input type="hidden" name="error_id" value="<?= (int)$r['id'] ?>">
                                                <button class="btn btn-sm btn-outline-info" type="submit" title="تحليل ذكي"><i class="fas fa-brain"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="noteModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content rounded-4">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-note-sticky me-2 text-primary"></i> إضافة ملاحظة إصلاح</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="add_note">
                <input type="hidden" name="error_id" id="noteErrorId">
                <label class="form-label fw-semibold small">نص الملاحظة</label>
                <textarea name="notes" class="form-control" rows="4" required placeholder="اكتب خطوات الإصلاح أو سبب تجاهل الخطأ..."></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                <button class="btn btn-primary" type="submit">حفظ الملاحظة</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAllCheckboxes(cb){ document.querySelectorAll('.row-check').forEach(x=>x.checked=cb.checked); }
function confirmBulk(){
    const sel = document.getElementById('bulkStatus').value;
    if(!sel){ alert('اختر الحالة أولاً'); return false; }
    const any = document.querySelector('.row-check:checked');
    if(!any){ alert('اختر سجلاً واحداً على الأقل'); return false; }
    return true;
}
function openNoteModal(id){ document.getElementById('noteErrorId').value=id; (new bootstrap.Modal(document.getElementById('noteModal'))).show(); }
document.addEventListener('DOMContentLoaded', () => {
    const idsToFp = {};
    document.querySelectorAll('.row-check').forEach(x => idsToFp[x.value] = x.dataset.fp);
});
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
