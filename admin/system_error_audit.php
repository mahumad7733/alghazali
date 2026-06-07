<?php
$page_title = "سجل أخطاء النظام";
require_once 'header.php';

if (!$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، هذه الصفحة للمدير فقط.</div></div>";
    require_once 'footer.php';
    exit;
}

// تأكد من وجود الجدول
ensure_system_error_audit_table($pdo);

$where = [];
$params = [];

$level = trim((string)($_GET['level'] ?? ''));
if ($level !== '') {
    $where[] = 'level = ?';
    $params[] = $level;
}

$user_id = trim((string)($_GET['user_id'] ?? ''));
if ($user_id !== '' && ctype_digit($user_id)) {
    $where[] = 'user_id = ?';
    $params[] = (int)$user_id;
}

$date_from = trim((string)($_GET['date_from'] ?? ''));
if ($date_from !== '') {
    $where[] = 'DATE(created_at) >= ?';
    $params[] = $date_from;
}

$date_to = trim((string)($_GET['date_to'] ?? ''));
if ($date_to !== '') {
    $where[] = 'DATE(created_at) <= ?';
    $params[] = $date_to;
}

$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '') {
    $where[] = '(message LIKE ? OR file LIKE ? OR url LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$limit = (int)($_GET['limit'] ?? 200);
$limit = max(50, min(1000, $limit));

$stmt = $pdo->prepare("SELECT * FROM system_error_audit $whereSql ORDER BY id DESC LIMIT $limit");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$levels = $pdo->query("SELECT DISTINCT level FROM system_error_audit ORDER BY level")->fetchAll(PDO::FETCH_COLUMN);
$users = $pdo->query("SELECT id, COALESCE(NULLIF(full_name,''), username) AS name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="container-fluid py-4">
    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h3 class="fw-bold text-danger mb-1"><i class="fas fa-bug me-2"></i> سجل أخطاء النظام</h3>
                <p class="text-muted mb-0">يسجل تلقائياً التحذيرات/الأخطاء/الاستثناءات داخل لوحة التحكم.</p>
            </div>
            <a href="audit_log.php" class="btn btn-outline-secondary rounded-pill"><i class="fas fa-history me-1"></i> سجل التدقيق</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-3">
        <div class="card-body p-3">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small">النوع</label>
                    <select name="level" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        <?php foreach ($levels as $lv): ?>
                            <option value="<?php echo htmlspecialchars($lv); ?>" <?php echo ($level === $lv) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($lv); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">المستخدم</label>
                    <select name="user_id" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?php echo (int)$u['id']; ?>" <?php echo ($user_id !== '' && (int)$user_id === (int)$u['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($u['name'] . ' #' . $u['id']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">من تاريخ</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">إلى تاريخ</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_to); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">بحث</label>
                    <input type="text" name="q" class="form-control form-control-sm" value="<?php echo htmlspecialchars($search); ?>" placeholder="نص الخطأ / الملف / الرابط">
                </div>
                <div class="col-md-1">
                    <label class="form-label small">الحد</label>
                    <select name="limit" class="form-select form-select-sm">
                        <?php foreach ([50,100,200,500,1000] as $l): ?>
                            <option value="<?php echo $l; ?>" <?php echo ($limit === $l) ? 'selected' : ''; ?>><?php echo $l; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button class="btn btn-danger btn-sm rounded-pill px-3" type="submit"><i class="fas fa-search me-1"></i> عرض</button>
                    <a class="btn btn-outline-secondary btn-sm rounded-pill px-3" href="system_error_audit.php"><i class="fas fa-times me-1"></i> مسح</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0 overflow-auto" style="max-height: 700px;">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light sticky-top" style="top:0; z-index:1;">
                    <tr>
                        <th>#</th>
                        <th>الوقت</th>
                        <th>النوع</th>
                        <th>الرسالة</th>
                        <th>الملف</th>
                        <th>الرابط</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr><td colspan="6" class="text-center text-muted py-5">لا توجد أخطاء مسجلة.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-muted small"><?php echo (int)$r['id']; ?></td>
                            <td class="small fw-bold"><?php echo htmlspecialchars((string)$r['created_at']); ?></td>
                            <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?php echo htmlspecialchars((string)$r['level']); ?></span></td>
                            <td style="max-width: 520px;">
                                <div class="small text-truncate" title="<?php echo htmlspecialchars((string)$r['message']); ?>"><?php echo htmlspecialchars((string)$r['message']); ?></div>
                                <?php if (!empty($r['trace'])): ?>
                                    <details class="mt-1">
                                        <summary class="small text-primary">عرض التتبع</summary>
                                        <pre class="small p-2 bg-light rounded" style="white-space: pre-wrap;"><?php echo htmlspecialchars((string)$r['trace']); ?></pre>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td class="small" style="max-width: 260px;">
                                <div class="text-truncate" title="<?php echo htmlspecialchars((string)$r['file']); ?>">
                                    <?php echo htmlspecialchars((string)$r['file']); ?><?php echo $r['line'] ? ':' . (int)$r['line'] : ''; ?>
                                </div>
                            </td>
                            <td class="small" style="max-width: 260px;">
                                <div class="text-truncate" title="<?php echo htmlspecialchars((string)$r['url']); ?>"><?php echo htmlspecialchars((string)$r['url']); ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>

