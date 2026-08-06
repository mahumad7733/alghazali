<?php
$page_title = "سجل نشاط المستخدمين - المراقبة الكاملة";
require_once __DIR__ . '/../header.php';

if (!$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، هذه الصفحة للمدير فقط.</div></div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

require_once __DIR__ . '/../../includes/system_admin/UserActivityMonitor.php';

$sessionMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['session_action'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $sessionMessage = ['type' => 'danger', 'text' => 'رمز الحماية غير صالح. أعد تحميل الصفحة وحاول مرة أخرى.'];
    } else {
        $sessionId = (int)($_POST['session_id'] ?? 0);
        $sessionUserId = (int)($_POST['user_id'] ?? 0);
        $action = (string)$_POST['session_action'];
        if ($action === 'terminate' && $sessionId > 0) {
            $ok = terminateUserSession($sessionId);
            $sessionMessage = ['type' => $ok ? 'success' : 'warning', 'text' => $ok ? 'تم إنهاء الجلسة بنجاح.' : 'تعذر إنهاء الجلسة.'];
        } elseif ($action === 'terminate_user' && $sessionUserId > 0) {
            $ok = endAllUserSessions($sessionUserId);
            $sessionMessage = ['type' => $ok ? 'success' : 'warning', 'text' => $ok ? 'تم إنهاء جميع جلسات المستخدم.' : 'تعذر إنهاء جلسات المستخدم.'];
        }
    }
}

ensureUserSessionTables();
$activeSessions = [];
$requestedSessionView = (string)($_GET['session_view'] ?? 'now');
$sessionView = in_array($requestedSessionView, ['now', 'recent'], true) ? $requestedSessionView : 'now';
if ($pdo) {
    try {
        $activeSessions = $pdo->query("SELECT us.id, us.user_id, us.ip_address, us.browser, us.operating_system, us.device_type, us.last_activity, us.started_at, u.username, u.full_name,
                (SELECT COUNT(*) FROM user_sessions us2 WHERE us2.user_id = us.user_id AND us2.status = 'active') AS active_session_count
            FROM user_sessions us LEFT JOIN users u ON u.id = us.user_id
            WHERE us.status = 'active'
              AND us.id = (SELECT us3.id FROM user_sessions us3
                           WHERE us3.user_id = us.user_id AND us3.status = 'active'
                           ORDER BY us3.last_activity DESC, us3.id DESC LIMIT 1)
            ORDER BY us.last_activity DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $activeSessions = []; }
}
// Only database-active sessions are eligible. Split them by recent activity
// so the administrator can distinguish currently active from stale sessions.
$sessionCutoff = time() - (15 * 60);
$activeSessions = array_values(array_filter($activeSessions, static function (array $session) use ($sessionView, $sessionCutoff): bool {
    $lastActivity = !empty($session['last_activity']) ? strtotime((string)$session['last_activity']) : 0;
    return $sessionView === 'recent' ? ($lastActivity > 0 && $lastActivity < $sessionCutoff) : $lastActivity >= $sessionCutoff;
}));

$filters = [
    'user_id' => !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null,
    'username' => !empty($_GET['username']) ? $_GET['username'] : null,
    'activity_type' => !empty($_GET['activity_type']) ? $_GET['activity_type'] : null,
    'from_date' => !empty($_GET['from_date']) ? $_GET['from_date'] : null,
    'to_date' => !empty($_GET['to_date']) ? $_GET['to_date'] : null,
    'ip_address' => !empty($_GET['ip']) ? $_GET['ip'] : null,
];
$logs = AlGhazali_UserActivityMonitor::listActivityLogs($filters, 300, 0);
$activeUsers = AlGhazali_UserActivityMonitor::activeUsers(15);
$typeCounts = AlGhazali_UserActivityMonitor::countByType(24);

global $pdo;
$usersList = [];
if ($pdo) { try {
    $usersList = $pdo->query("SELECT id, COALESCE(NULLIF(full_name,''), username) as name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {  } }

$typeColors = [
    'login'=>'success','logout'=>'secondary','page_view'=>'info','create'=>'primary','update'=>'warning','delete'=>'danger','export'=>'info','print'=>'secondary','view'=>'dark','search'=>'primary','financial'=>'success','permission_change'=>'warning','password_reset'=>'danger','other'=>'secondary'
];
?>

<div class="container-fluid py-4">
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div><strong>فلترة الجلسات:</strong> <span class="text-muted small">النشط الآن خلال آخر 15 دقيقة أو آخر نشاط سابق</span></div>
            <div class="btn-group" role="group">
                <a href="activity.php?session_view=now" class="btn btn-sm <?= $sessionView === 'now' ? 'btn-success' : 'btn-outline-success' ?>"><i class="fas fa-circle me-1"></i>نشط الآن</a>
                <a href="activity.php?session_view=recent" class="btn btn-sm <?= $sessionView === 'recent' ? 'btn-primary' : 'btn-outline-primary' ?>"><i class="fas fa-clock me-1"></i>آخر نشاط</a>
            </div>
        </div>
    </div>
    <?php if ($sessionMessage): ?><div class="alert alert-<?= htmlspecialchars($sessionMessage['type']) ?> rounded-4"><i class="fas fa-circle-info me-2"></i><?= htmlspecialchars($sessionMessage['text']) ?></div><?php endif; ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-to-r from-primary to-dark text-white p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-4">
                <i class="fas fa-users-gear fa-3x opacity-75"></i>
                <div>
                    <h2 class="fw-bold mb-1">سجل نشاط المستخدمين</h2>
                    <p class="mb-0 opacity-75">تتبع كامل للعمليات، عناوين IP، الأجهزة، المتصفحات والجلسات</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <span class="badge bg-white text-primary rounded-pill px-4 py-2"><i class="fas fa-circle text-success me-2"></i> نشط الآن: <?= count($activeUsers) ?> مستخدم</span>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0 fw-bold"><i class="fas fa-desktop me-2 text-success"></i>الجلسات النشطة والتحكم بها</h5>
            <a href="activity.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-sync-alt me-1"></i> تحديث</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>المستخدم</th><th>الجهاز والمتصفح</th><th>IP</th><th>آخر نشاط</th><th>الإجراءات</th></tr></thead>
                <tbody>
                <?php if (empty($activeSessions)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">لا توجد جلسات نشطة حالياً.</td></tr>
                <?php else: foreach ($activeSessions as $session):
                    $lastTs = !empty($session['last_activity']) ? strtotime((string)$session['last_activity']) : 0;
                    $isCurrentSession = $lastTs >= $sessionCutoff;
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string)($session['full_name'] ?: $session['username'] ?: 'غير معروف')) ?></strong><div class="small text-muted">@<?= htmlspecialchars((string)$session['username']) ?></div></td>
                        <td class="small"><?= htmlspecialchars((string)($session['device_type'] ?: 'غير محدد')) ?> / <?= htmlspecialchars((string)($session['browser'] ?: 'غير محدد')) ?><div class="text-muted"><?= htmlspecialchars((string)($session['operating_system'] ?: '')) ?></div></td>
                        <td><code><?= htmlspecialchars((string)($session['ip_address'] ?: '-')) ?></code></td>
                        <td class="small">
                            <span class="badge bg-<?= $isCurrentSession ? 'success' : 'secondary' ?>"><?= $isCurrentSession ? 'نشط الآن' : 'آخر نشاط' ?></span>
                            <div class="mt-1"><?= htmlspecialchars((string)($session['last_activity'] ?: $session['started_at'])) ?></div>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <form method="POST" onsubmit="return confirm('هل تريد إنهاء هذه الجلسة؟');">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="session_action" value="terminate"><input type="hidden" name="session_id" value="<?= (int)$session['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger" title="إنهاء الجلسة"><i class="fas fa-power-off me-1"></i>إنهاء</button>
                                </form>
                                <form method="POST" onsubmit="return confirm('هل تريد إنهاء جميع جلسات هذا المستخدم؟');">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="session_action" value="terminate_user"><input type="hidden" name="user_id" value="<?= (int)$session['user_id'] ?>">
                                    <button class="btn btn-sm btn-outline-secondary" title="إنهاء كل جلسات المستخدم"><i class="fas fa-user-slash me-1"></i>كل الجلسات</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-8 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0"><h5 class="mb-0 fw-bold"><i class="fas fa-circle-dot me-2 text-success"></i> المستخدمون النشطون (آخر 15 دقيقة)</h5></div>
                <div class="card-body p-0">
                    <?php if (empty($activeUsers)): ?>
                        <div class="text-center py-4 text-muted small"><i class="fas fa-user-clock mb-2 fs-2"></i><p>لا يوجد مستخدمون نشطون حالياً.</p></div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light"><tr>
                                    <th>المستخدم</th>
                                    <th>الدور</th>
                                    <th>الفرع</th>
                                    <th>الجهاز / المتصفح</th>
                                    <th>نظام التشغيل</th>
                                    <th>IP</th>
                                    <th>آخر نشاط</th>
                                </tr></thead>
                                <tbody>
                                    <?php foreach ($activeUsers as $u): ?>
                                        <tr>
                                            <td>
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="pulse-dot"></span>
                                                    <div>
                                                        <div class="fw-semibold"><?= htmlspecialchars((string)($u['full_name'] ?? $u['username'])) ?></div>
                                                        <div class="small text-muted">@<?= htmlspecialchars((string)($u['username'] ?? '')) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><span class="badge bg-info"><?= htmlspecialchars((string)($u['role_name'] ?? '-')) ?></span></td>
                                            <td class="small">-</td>
                                            <td class="small">
                                                <span class="badge bg-secondary"><i class="fas fa-<?= $u['device_type'] === 'mobile' ? 'mobile-screen' : 'laptop' ?> me-1"></i> <?= htmlspecialchars((string)($u['browser'] ?? '')) ?></span>
                                            </td>
                                            <td class="small"><?= htmlspecialchars((string)($u['operating_system'] ?? '')) ?></td>
                                            <td class="small"><code><?= htmlspecialchars((string)($u['ip_address'] ?? '')) ?></code></td>
                                            <td class="small fw-semibold"><?= htmlspecialchars((string)($u['last_activity'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0"><h5 class="mb-0 fw-bold"><i class="fas fa-chart-pie me-2 text-primary"></i> أنواع النشاط (آخر 24 ساعة)</h5></div>
                <div class="card-body">
                    <?php if (empty($typeCounts)): ?>
                        <div class="text-center py-4 text-muted small">لا توجد بيانات.</div>
                    <?php else:
                        arsort($typeCounts);
                        $max = max($typeCounts);
                        foreach ($typeCounts as $type => $cnt):
                            $cls = $typeColors[$type] ?? 'secondary';
                            $pct = round(($cnt / $max) * 100);
                    ?>
                        <div class="mb-3">
                            <div class="d-flex justify-content-between small mb-1">
                                <span class="fw-semibold"><?= htmlspecialchars($type) ?></span>
                                <span class="text-muted"><?= $cnt ?></span>
                            </div>
                            <div class="progress" style="height:10px;"><div class="progress-bar bg-<?= $cls ?>" style="width:<?= $pct ?>%"></div></div>
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
                    <label class="form-label small fw-semibold">المستخدم</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        <?php foreach ($usersList as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" <?= ((int)$filters['user_id'] === (int)$u['id']) ? 'selected' : '' ?>><?= htmlspecialchars($u['name']) ?> (<?= (int)$u['id'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">اسم المستخدم</label>
                    <input type="text" name="username" class="form-control form-control-sm" value="<?= htmlspecialchars((string)$filters['username']) ?>" placeholder="بحث بالاسم">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">نوع العملية</label>
                    <input type="text" name="activity_type" class="form-control form-control-sm" value="<?= htmlspecialchars((string)$filters['activity_type']) ?>" placeholder="مثال: login, create">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">عنوان IP</label>
                    <input type="text" name="ip" class="form-control form-control-sm" value="<?= htmlspecialchars((string)$filters['ip_address']) ?>" placeholder="192.168...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars((string)$filters['from_date']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars((string)$filters['to_date']) ?>">
                </div>
                <div class="col-md-12 d-flex gap-2 justify-content-end">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search me-1"></i> بحث</button>
                    <a href="activity.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-rotate me-1"></i> إعادة تعيين</a>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white py-3 border-0"><h5 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-secondary"></i> سجل العمليات (آخر 300 سجل)</h5></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الوقت</th>
                    <th>المستخدم</th>
                    <th>الدور</th>
                    <th>نوع العملية</th>
                    <th>تفاصيل العملية</th>
                    <th>جهاز / متصفح</th>
                    <th>IP</th>
                    <th>الصفحة</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-folder-open fs-1 mb-2"></i><p>لا توجد سجلات مطابقة.</p></td></tr>
                    <?php else: foreach ($logs as $l):
                        $activityType = strtolower((string)($l['activity_type'] ?? 'other'));
                        $typeMeta = getActivityTypeLabel($activityType);
                        $cls = $typeColors[$activityType] ?? 'secondary';
                        $ua = AlGhazali_UserActivityMonitor::parseUserAgent((string)($l['user_agent'] ?? ''));
                    ?>
                        <tr>
                            <td class="small text-muted"><?= htmlspecialchars($l['created_at']) ?></td>
                            <td>
                                <div class="fw-semibold small"><?= htmlspecialchars((string)($l['full_name'] ?? ($l['username'] ?? ''))) ?></div>
                                <div class="text-muted small">@<?= htmlspecialchars((string)($l['username'] ?? '')) ?></div>
                            </td>
                            <td><span class="badge bg-info"><?= htmlspecialchars((string)($l['role_name'] ?? '-')) ?></span></td>
                            <td><span class="badge bg-<?= $cls ?>"><?= htmlspecialchars((string)($typeMeta['label'] ?? $activityType)) ?></span></td>
                            <td class="small">
                                <?php if (!empty($l['activity_details'])): ?><div><?= htmlspecialchars((string)$l['activity_details']) ?></div><?php endif; ?>
                                <?php if (!empty($l['target_table']) || !empty($l['target_record_id'])): ?><div class="text-muted">Target: <?= htmlspecialchars((string)($l['target_table'] ?? '')) ?> #<?= htmlspecialchars((string)($l['target_record_id'] ?? '')) ?></div><?php endif; ?>
                            </td>
                            <td class="small">
                                <div><i class="fas fa-browser me-1"></i> <?= htmlspecialchars($ua['browser']) ?> <?= htmlspecialchars((string)($ua['browser_version'] ?? '')) ?></div>
                                <div class="text-muted"><i class="fas fa-<?= $ua['device_type'] === 'mobile' ? 'mobile' : 'desktop' ?> me-1"></i> <?= htmlspecialchars($ua['os']) ?></div>
                            </td>
                            <td><code class="small"><?= htmlspecialchars((string)($l['ip_address'] ?? '')) ?></code></td>
                            <td class="small text-truncate" style="max-width:160px;"><?= htmlspecialchars((string)($l['page_url'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.pulse-dot { width:10px; height:10px; border-radius:50%; background:#22c55e; position:relative; }
.pulse-dot::after { content:''; position:absolute; inset:0; border-radius:50%; background:#22c55e; animation:pulse-dot 1.2s infinite; }
@keyframes pulse-dot { 0% {transform:scale(1); opacity:.75;} 100% {transform:scale(2.4); opacity:0;} }
</style>

<?php require_once __DIR__ . '/../footer.php'; ?>
