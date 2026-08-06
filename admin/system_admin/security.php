<?php
$page_title = "مراقبة الأمان - تدقيق الثغرات والأحداث";
require_once __DIR__ . '/../header.php';

if (!$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، هذه الصفحة للمدير فقط.</div></div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

ensure_system_admin_tables();
require_once __DIR__ . '/../../includes/system_admin/SecurityAudit.php';

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['run_passive_audit_now'])) {
        $c = AlGhazali_SecurityAudit::runPassiveAudit();
        $msg = "<div class='alert alert-success rounded-4'>تم تشغيل الفحص السلبي على البيانات الحالية وتم اكتشاف $c عنصراً مثيراً للشك (إن وجد).</div>";
    } elseif (!empty($_POST['finding_id']) && !empty($_POST['update_status'])) {
        $st = $_POST['new_status'];
        $res = $_POST['resolution_notes'] ?? null;
        $uid = $_SESSION['admin_id'] ?? ($_SESSION['user_id'] ?? null);
        if (in_array($st, ['open','in_progress','resolved','false_positive'])) {
            AlGhazali_SecurityAudit::updateFindingStatus((int)$_POST['finding_id'], $st, $uid, $res);
            $msg = "<div class='alert alert-success rounded-4'>تم تحديث حالة الثغرة.</div>";
        }
    }
}

$filters = [
    'type' => !empty($_GET['type']) ? $_GET['type'] : null,
    'severity' => !empty($_GET['severity']) ? $_GET['severity'] : null,
    'status' => !empty($_GET['status']) ? $_GET['status'] : null,
    'search' => !empty($_GET['q']) ? $_GET['q'] : null,
];
$findings = AlGhazali_SecurityAudit::listFindings($filters, 250, 0);

global $pdo;
$eventStats = [];
$loginStats = [];
$unauthorizedCount = 0;
if ($pdo) {
    try {
        $eventStats = $pdo->query("SELECT event_type, severity, COUNT(*) as cnt FROM security_events WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY event_type, severity ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
        $loginStats = $pdo->query("SELECT outcome, COUNT(*) as cnt FROM login_attempts WHERE attempted_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY outcome")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $unauthorizedCount = (int)$pdo->query("SELECT COUNT(*) FROM security_events WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND event_type IN ('unauthorized_access','brute_force','permission_change','sensitive_data_change')")->fetchColumn();
    } catch (\Throwable $e) {  }
}

$sevColors = ['low'=>'success','medium'=>'warning','high'=>'danger','critical'=>'dark'];
$typeNames = ['sql_injection'=>'حقن SQL','xss'=>'XSS','csrf'=>'تزوير الطلب','session_problem'=>'مشاكل جلسة','upload_problem'=>'مشاكل رفع','password_issue'=>'قوة كلمة مرور','permission_misconfig'=>'صلاحيات خاطئة','data_exposure'=>'تسريب بيانات','other'=>'أخرى'];
$stNames = ['open'=>'مفتوحة','in_progress'=>'قيد المعالجة','resolved'=>'مُعالجة','false_positive'=>'إيجابية كاذبة'];
?>

<div class="container-fluid py-4">
    <?php if ($msg) echo $msg; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-to-r from-warning to-danger text-white p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-4">
                <i class="fas fa-shield-halved fa-3x opacity-75"></i>
                <div>
                    <h2 class="fw-bold mb-1">مركز مراقبة الأمان</h2>
                    <p class="mb-0 opacity-75">كشف الثغرات • تتبع محاولات الاختراق • صلاحيات المستخدمين • عمليات مالية حساسة</p>
                </div>
            </div>
            <form method="POST" class="d-inline">
                <button class="btn btn-light text-danger rounded-pill" type="submit" name="run_passive_audit_now" value="1"><i class="fas fa-play me-1"></i> تشغيل فحص فوري</button>
            </form>
        </div>
    </div>

    <div class="row mb-4">
        <?php
        $sevCounts = ['low'=>0,'medium'=>0,'high'=>0,'critical'=>0];
        foreach ($findings as $f) { if (isset($sevCounts[$f['severity']])) $sevCounts[$f['severity']]++; }
        $cards = [
            ['الثغرات الحرجة', $sevCounts['critical'], 'fas fa-fire', 'bg-dark text-white', 'critical'],
            ['الثغرات عالية الخطورة', $sevCounts['high'], 'fas fa-skull', 'bg-danger text-white', 'high'],
            ['الثغرات متوسطة', $sevCounts['medium'], 'fas fa-triangle-exclamation', 'bg-warning text-dark', 'medium'],
            ['الثغرات منخفضة', $sevCounts['low'], 'fas fa-info-circle', 'bg-success text-white', 'low'],
            ['الأحداث المريبة (24س)', $unauthorizedCount, 'fas fa-user-lock', 'bg-secondary text-white', ''],
            ['محاولات دخول فاشلة (7 أيام)', (int)($loginStats['failure'] ?? 0), 'fas fa-right-to-bracket', 'bg-danger-subtle text-dark', ''],
        ];
        foreach ($cards as $c): ?>
            <div class="col-md-4 col-xl-2 col-sm-6 mb-3">
                <a href="security.php<?= $c[4] ? '?severity=' . $c[4] : '' ?>" class="text-decoration-none">
                    <div class="card border-0 shadow-sm rounded-4 h-100 card-hover">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="p-3 rounded-4 <?= $c[3] ?>"><i class="fs-4 <?= $c[2] ?>"></i></div>
                                <div>
                                    <div class="text-muted small fw-semibold"><?= $c[0] ?></div>
                                    <h3 class="mb-0 fw-bold display-6"><?= $c[1] ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="row mb-4">
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-bolt me-2 text-danger"></i> الأحداث الأمنية (آخر 7 أيام)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($eventStats)): ?>
                        <div class="text-center py-4 text-muted"><i class="fas fa-shield-heart fs-1 mb-2 text-success"></i> لا توجد أحداث مسجلة.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light"><tr><th>نوع الحدث</th><th>الخطورة</th><th class="text-end">العدد</th></tr></thead>
                                <tbody>
                                    <?php foreach ($eventStats as $ev):
                                        $sev = $sevColors[$ev['severity']] ?? 'secondary';
                                    ?>
                                        <tr>
                                            <td class="fw-semibold small"><?= htmlspecialchars($ev['event_type']) ?></td>
                                            <td><span class="badge bg-<?= $sev ?>"><?= htmlspecialchars($ev['severity']) ?></span></td>
                                            <td class="text-end fw-bold"><?= $ev['cnt'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-fingerprint me-2 text-primary"></i> سجل محاولات الدخول (آخر 7 أيام)</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($loginStats)): ?>
                        <div class="text-center py-4 text-muted">لا توجد محاولات مسجلة.</div>
                    <?php else:
                        $outNames = ['success'=>'ناجحة','failure'=>'فاشلة','locked'=>'حساب مُقفل','blocked_device'=>'جهاز محظور'];
                        $outColors = ['success'=>'success','failure'=>'danger','locked'=>'warning','blocked_device'=>'dark'];
                        foreach ($loginStats as $k => $v):
                    ?>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom border-dashed last-border-none">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-<?= $outColors[$k] ?? 'secondary' ?>"><?= $outNames[$k] ?? $k ?></span>
                                <span class="small text-muted"><?= $k ?></span>
                            </div>
                            <h6 class="mb-0 fw-bold"><?= $v ?></h6>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>

    <form method="GET" class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">نوع الثغرة</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        <?php foreach ($typeNames as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= (string)$filters['type'] === (string)$k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">الخطورة</label>
                    <select name="severity" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        <?php foreach ($sevColors as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= (string)$filters['severity'] === (string)$k ? 'selected' : '' ?>><?= $k ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">الحالة</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        <?php foreach ($stNames as $k=>$v): ?>
                            <option value="<?= $k ?>" <?= (string)$filters['status'] === (string)$k ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">بحث</label>
                    <div class="input-group input-group-sm">
                        <input name="q" class="form-control" value="<?= htmlspecialchars((string)$filters['search']) ?>" placeholder="بحث في العنوان والوصف...">
                        <button class="btn btn-primary"><i class="fas fa-search"></i></button>
                        <a href="security.php" class="btn btn-outline-secondary"><i class="fas fa-rotate"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-list-check me-2 text-primary"></i> سجل الثغرات المكتشفة (security_vulnerabilities)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID / الوقت</th>
                        <th>النوع</th>
                        <th>الخطورة</th>
                        <th>الحالة</th>
                        <th>العنوان / التفاصيل</th>
                        <th>المصدر</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($findings)): ?>
                        <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-shield-virus fs-1 mb-2 text-success"></i><p>لا توجد ثغرات مطابقة.</p><p class="small">اضغط "تشغيل فحص فوري" أعلاه لتسجيل الفحوصات.</p></td></tr>
                    <?php else: foreach ($findings as $f):
                        $sevC = $sevColors[$f['severity']] ?? 'secondary';
                    ?>
                        <tr>
                            <td class="small">
                                <div class="fw-bold">#<?= $f['id'] ?></div>
                                <div class="text-muted"><?= htmlspecialchars($f['created_at']) ?></div>
                            </td>
                            <td><span class="badge bg-dark"><?= $typeNames[$f['vulnerability_type']] ?? $f['vulnerability_type'] ?></span></td>
                            <td><span class="badge bg-<?= $sevC ?>"><?= $f['severity'] ?></span> <small class="text-muted d-block">ثقة <?= (int)$f['confidence'] ?>%</small></td>
                            <td>
                                <form method="POST" class="d-inline-flex gap-1 align-items-center" onchange="this.submit()">
                                    <input type="hidden" name="finding_id" value="<?= $f['id'] ?>">
                                    <input type="hidden" name="update_status" value="1">
                                    <select class="form-select form-select-sm w-auto" name="new_status">
                                        <?php foreach ($stNames as $k=>$v): ?>
                                            <option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $v ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </td>
                            <td>
                                <div class="fw-semibold"><?= htmlspecialchars($f['title']) ?></div>
                                <?php if (!empty($f['payload_sample'])): ?><code class="small text-danger d-block mt-1 text-truncate" style="max-width:420px;"><?= htmlspecialchars($f['payload_sample']) ?></code><?php endif; ?>
                                <?php if (!empty($f['description'])): ?><div class="small text-muted mt-1"><?= htmlspecialchars($f['description']) ?></div><?php endif; ?>
                            </td>
                            <td class="small">
                                <?php if (!empty($f['affected_url'])): ?><div class="text-truncate" style="max-width:160px;" title="<?= htmlspecialchars($f['affected_url']) ?>">URL: <?= htmlspecialchars($f['affected_url']) ?></div><?php endif; ?>
                                <?php if (!empty($f['ip_address'])): ?><div>IP: <?= htmlspecialchars($f['ip_address']) ?></div><?php endif; ?>
                                <?php if (!empty($f['user_id'])): ?><div>U: <?= (int)$f['user_id'] ?></div><?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($f['evidence_json'])): ?>
                                    <button class="btn btn-sm btn-outline-secondary" onclick='showEvidence(<?= json_encode($f["evidence_json"], JSON_HEX_TAG) ?>)'><i class="fas fa-search"></i></button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="evidenceModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content rounded-4">
    <div class="modal-header"><h5 class="modal-title fw-bold">تفاصيل الأدلة</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><pre id="evidenceBody" class="bg-light p-3 rounded-3 border" style="white-space:pre-wrap;"></pre></div>
</div></div></div>

<script>
function showEvidence(ev){
    try {
        const obj = typeof ev === 'string' ? JSON.parse(ev) : ev;
        document.getElementById('evidenceBody').textContent = JSON.stringify(obj, null, 2);
    } catch(e){ document.getElementById('evidenceBody').textContent = String(ev); }
    (new bootstrap.Modal(document.getElementById('evidenceModal'))).show();
}
</script>

<?php require_once __DIR__ . '/../footer.php'; ?>
