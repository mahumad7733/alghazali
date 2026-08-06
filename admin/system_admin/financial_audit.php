<?php
$page_title = "سجل العمليات المالية - التدقيق الشامل (قبل/بعد)";
require_once __DIR__ . '/../header.php';

if (!$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، هذه الصفحة للمدير فقط.</div></div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

ensure_system_admin_tables();

global $pdo;
$filters = [];
$params = [];
$where = ['1=1'];

if (!empty($_GET['type'])) { $where[] = 'transaction_type = ?'; $params[] = $_GET['type']; }
if (!empty($_GET['user_id']) && ctype_digit($_GET['user_id'])) { $where[] = 'fta.user_id = ?'; $params[] = (int)$_GET['user_id']; }
if (!empty($_GET['invoice_id']) && ctype_digit($_GET['invoice_id'])) { $where[] = 'fta.invoice_id = ?'; $params[] = (int)$_GET['invoice_id']; }
if (!empty($_GET['from_date'])) { $where[] = 'DATE(fta.occurred_at) >= ?'; $params[] = $_GET['from_date']; }
if (!empty($_GET['to_date'])) { $where[] = 'DATE(fta.occurred_at) <= ?'; $params[] = $_GET['to_date']; }
if (!empty($_GET['reviewed']) && in_array($_GET['reviewed'], ['0','1'])) { $where[] = 'fta.is_reviewed = ?'; $params[] = (int)$_GET['reviewed']; }

$whereSql = implode(' AND ', $where);
$logs = [];
$summary = ['total'=>0, 'reviewed'=>0, 'pending'=>0, 'cancelled'=>0, 'refunded'=>0];

if ($pdo) {
    try {
        $q = $pdo->prepare("SELECT fta.*, u.username, u.full_name
            FROM financial_transaction_audit fta
            LEFT JOIN users u ON u.id = fta.user_id
            WHERE $whereSql
            ORDER BY fta.occurred_at DESC
            LIMIT 300");
        $q->execute($params);
        $logs = $q->fetchAll(PDO::FETCH_ASSOC);

        $sq = $pdo->query("SELECT COUNT(*) c, SUM(is_reviewed=1) rv, SUM(is_reviewed=0) pd
            FROM financial_transaction_audit WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch(PDO::FETCH_ASSOC);
        $summary['total'] = (int)($sq['c'] ?? 0);
        $summary['reviewed'] = (int)($sq['rv'] ?? 0);
        $summary['pending'] = (int)($sq['pd'] ?? 0);
        $summary['cancelled'] = (int)($pdo->query("SELECT COUNT(*) FROM financial_transaction_audit WHERE transaction_type='invoice_cancel' AND occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn() ?: 0);
        $summary['refunded'] = (int)($pdo->query("SELECT COUNT(*) FROM financial_transaction_audit WHERE transaction_type='refund' AND occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn() ?: 0);
    } catch (\Throwable $e) {  }
}

$typeNames = [
    'invoice_create'=>'إنشاء فاتورة', 'invoice_update'=>'تعديل فاتورة', 'invoice_cancel'=>'إلغاء فاتورة', 'invoice_posted'=>'ترحيل فاتورة',
    'receipt'=>'قبض', 'payment'=>'صرف', 'exchange_rate_change'=>'تغيير سعر صرف', 'journal_line_create'=>'إنشاء قيد يومية',
    'journal_line_update'=>'تعديل قيد يومية', 'refund'=>'استرجاع', 'financial_unpost'=>'إلغاء ترحيل', 'other'=>'عملية أخرى'
];
$typeColors = [
    'invoice_create'=>'success','invoice_update'=>'primary','invoice_cancel'=>'danger','invoice_posted'=>'info',
    'receipt'=>'success','payment'=>'warning','exchange_rate_change'=>'dark','journal_line_create'=>'secondary',
    'journal_line_update'=>'primary','refund'=>'danger','financial_unpost'=>'warning','other'=>'secondary'
];

$viewId = !empty($_GET['view']) && ctype_digit($_GET['view']) ? (int)$_GET['view'] : null;
$viewRow = null;
if ($viewId && $pdo) {
    try {
        $q = $pdo->prepare("SELECT fta.*, u.username, u.full_name FROM financial_transaction_audit fta LEFT JOIN users u ON u.id=fta.user_id WHERE fta.id=?");
        $q->execute([$viewId]);
        $viewRow = $q->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {  }
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['mark_reviewed']) && !empty($_POST['id']) && ctype_digit($_POST['id'])) {
    if ($pdo) try {
        $uid = $_SESSION['admin_id'] ?? ($_SESSION['user_id'] ?? null);
        $pdo->prepare("UPDATE financial_transaction_audit SET is_reviewed = 1, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")->execute([$uid, (int)$_POST['id']]);
        $msg = "<div class='alert alert-success rounded-4'>تم تمييز السجل #{$_POST['id']} كمُراجَع.</div>";
    } catch (\Throwable $e) {  }
}
?>

<div class="container-fluid py-4">
    <?php if ($msg) echo $msg; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-to-r from-success to-info text-white p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-4">
                <i class="fas fa-sack-dollar fa-3x opacity-75"></i>
                <div>
                    <h2 class="fw-bold mb-1">سجل العمليات المالية - التدقيق</h2>
                    <p class="mb-0 opacity-75">لقطات قبل/بعد للفواتير، القبض، الصرف، الإلغاء، الاسترجاع، وتغيير أسعار الصرف</p>
                </div>
            </div>
            <span class="badge bg-white text-success rounded-pill px-4 py-2">
                <i class="fas fa-spinner fa-spin me-2 text-warning"></i> قيد المراجعة: <?= $summary['pending'] ?>
            </span>
        </div>
    </div>

    <div class="row mb-4">
        <?php
        $kpis = [
            ['إجمالي التعديلات (30يوم)', $summary['total'], 'fas fa-file-invoice-dollar', 'primary'],
            ['تمت مراجعتها', $summary['reviewed'], 'fas fa-circle-check', 'success'],
            ['قيد المراجعة', $summary['pending'], 'fas fa-hourglass-half', 'warning'],
            ['فواتير ملغاة', $summary['cancelled'], 'fas fa-file-circle-xmark', 'danger'],
            ['استردادات', $summary['refunded'], 'fas fa-money-bill-transfer', 'dark'],
        ];
        foreach ($kpis as $k): ?>
            <div class="col-md-4 col-xl mb-3">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body p-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="p-3 rounded-4 bg-<?= $k[3] ?>-subtle text-<?= $k[3] ?>"><i class="fs-4 <?= $k[2] ?>"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold"><?= $k[0] ?></div>
                                <h3 class="mb-0 fw-bold display-6"><?= $k[1] ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <form method="GET" class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">نوع العملية</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        <?php foreach ($typeNames as $k=>$v): ?>
                            <option value="<?= $k ?>" === (@$_GET['type'] === (string)$k ? 'selected' : '') ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">المستخدم</label>
                    <input type="number" name="user_id" class="form-control form-control-sm" value="<?= htmlspecialchars((string)@$_GET['user_id']) ?>" placeholder="ID المستخدم">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">رقم الفاتورة</label>
                    <input type="number" name="invoice_id" class="form-control form-control-sm" value="<?= htmlspecialchars((string)@$_GET['invoice_id']) ?>" placeholder="# الفاتورة">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">المراجعة</label>
                    <select name="reviewed" class="form-select form-select-sm">
                        <option value="">الكل</option>
                        <option value="0" === ((@$_GET['reviewed'] === '0') ? 'selected' : '')>قيد المراجعة</option>
                        <option value="1" === ((@$_GET['reviewed'] === '1') ? 'selected' : '')>تمت مراجعتها</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control form-control-sm" value="<?= htmlspecialchars((string)@$_GET['from_date']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control form-control-sm" value="<?= htmlspecialchars((string)@$_GET['to_date']) ?>">
                </div>
                <div class="col-md-12 text-end">
                    <button class="btn btn-sm btn-primary"><i class="fas fa-search me-1"></i> بحث</button>
                    <a href="financial_audit.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-rotate me-1"></i> إعادة</a>
                </div>
            </div>
        </div>
    </form>

    <?php if ($viewRow):
        $before = json_decode($viewRow['before_json'] ?? '[]', true);
        $after = json_decode($viewRow['after_json'] ?? '[]', true);
    ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4 border-primary border-3">
            <div class="card-header bg-primary-subtle d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-eye me-2"></i> عرض تفصيلي للسجل #<?= $viewRow['id'] ?> - <?= $typeNames[$viewRow['transaction_type']] ?? $viewRow['transaction_type'] ?></h5>
                <div class="d-flex gap-2">
                    <a href="financial_audit.php" class="btn btn-sm btn-outline-primary"><i class="fas fa-xmark me-1"></i> إغلاق</a>
                    <?php if (empty($viewRow['is_reviewed'])): ?>
                        <form method="POST" class="d-inline"><input type="hidden" name="mark_reviewed" value="1"><input type="hidden" name="id" value="<?= $viewRow['id'] ?>"><button class="btn btn-sm btn-success"><i class="fas fa-check me-1"></i> اعتماد كمراجعة</button></form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-3"><div class="small text-muted">المستخدم</div><div class="fw-bold"><?= htmlspecialchars((string)($viewRow['full_name'] ?? $viewRow['username'] ?? '-')) ?></div></div>
                    <div class="col-md-3"><div class="small text-muted">الوقت</div><div class="fw-bold"><?= htmlspecialchars($viewRow['occurred_at']) ?></div></div>
                    <div class="col-md-2"><div class="small text-muted">IP</div><div class="fw-bold"><code><?= htmlspecialchars((string)($viewRow['user_ip'] ?? '')) ?></code></div></div>
                    <div class="col-md-2"><div class="small text-muted">الفاتورة</div><div class="fw-bold"><?= (int)$viewRow['invoice_id'] > 0 ? '#' . (int)$viewRow['invoice_id'] : '-' ?></div></div>
                    <div class="col-md-2"><div class="small text-muted">المبلغ قبل/بعد</div><div class="fw-bold"><?= (float)$viewRow['amount_before'] ?> → <span class="text-success"><?= (float)$viewRow['amount_after'] ?></span></div></div>
                </div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="card border-0 rounded-4 bg-danger-subtle">
                            <div class="card-header border-0 bg-danger text-white"><i class="fas fa-arrow-left me-2"></i> قبل التعديل</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-secondary"><tr><th>الحقل</th><th>القيمة</th></tr></thead>
                                        <tbody>
                                            <?php if (is_array($before) && count($before) > 0): foreach ($before as $k=>$v): ?>
                                                <tr><td class="fw-semibold"><?= htmlspecialchars((string)$k) ?></td><td><?= is_array($v) ? htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE)) : htmlspecialchars((string)($v ?? '')) ?></td></tr>
                                            <?php endforeach; else: ?><tr><td colspan="2" class="text-center py-3 text-muted">لا توجد بيانات</td></tr><?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-0 rounded-4 bg-success-subtle">
                            <div class="card-header border-0 bg-success text-white"><i class="fas fa-arrow-right me-2"></i> بعد التعديل</div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-secondary"><tr><th>الحقل</th><th>القيمة</th></tr></thead>
                                        <tbody>
                                            <?php if (is_array($after) && count($after) > 0): foreach ($after as $k=>$v): ?>
                                                <tr><td class="fw-semibold"><?= htmlspecialchars((string)$k) ?></td><td><?= is_array($v) ? htmlspecialchars(json_encode($v, JSON_UNESCAPED_UNICODE)) : htmlspecialchars((string)($v ?? '')) ?></td></tr>
                                            <?php endforeach; else: ?><tr><td colspan="2" class="text-center py-3 text-muted">لا توجد بيانات</td></tr><?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (!empty($viewRow['change_reason'])): ?>
                    <div class="mt-3"><strong>سبب التغيير:</strong> <span class="text-muted"><?= htmlspecialchars($viewRow['change_reason']) ?></span></div>
                <?php endif; ?>
                <?php if (!empty($viewRow['affected_fields_csv'])): ?>
                    <div class="mt-2 small"><strong>الحقول المتأثرة:</strong> <code><?= htmlspecialchars($viewRow['affected_fields_csv']) ?></code></div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>ID / الوقت</th>
                    <th>النوع</th>
                    <th>المستخدم</th>
                    <th>الفاتورة/الحساب</th>
                    <th>المبلغ قبل → بعد</th>
                    <th>الحقول المتأثرة</th>
                    <th>المراجعة</th>
                    <th>إجراء</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-book-open fs-1 mb-2 text-success"></i><p>لا توجد سجلات مطابقة.</p><p class="small">سيظهر السجل هنا تلقائياً عند تنفيذ عمليات مالية (تعديل/إلغاء/استرجاع/تغيير سعر صرف) بعد تفعيل الربط بالصفحات المالية.</p></td></tr>
                    <?php else: foreach ($logs as $l):
                        $cls = $typeColors[$l['transaction_type']] ?? 'secondary';
                    ?>
                        <tr>
                            <td class="small"><div class="fw-bold">#<?= $l['id'] ?></div><div class="text-muted"><?= htmlspecialchars($l['occurred_at']) ?></div></td>
                            <td><span class="badge bg-<?= $cls ?>"><?= $typeNames[$l['transaction_type']] ?? $l['transaction_type'] ?></span></td>
                            <td><div class="fw-semibold small"><?= htmlspecialchars((string)($l['full_name'] ?? ($l['username'] ?? ''))) ?></div><div class="small text-muted">IP: <code><?= htmlspecialchars((string)($l['user_ip'] ?? '')) ?></code></div></td>
                            <td class="small"><?= (int)$l['invoice_id'] > 0 ? 'فاتورة #'.(int)$l['invoice_id'] : '<span class="text-muted">-</span>' ?><?= (int)$l['affected_account_id'] > 0 ? '<div class="text-muted">حساب: '.(int)$l['affected_account_id'].'</div>' : '' ?></td>
                            <td class="small fw-semibold">
                                <?php if ((float)$l['amount_before'] || (float)$l['amount_after']): ?>
                                    <span class="text-danger"><?= (float)$l['amount_before'] ?></span> → <span class="text-success"><?= (float)$l['amount_after'] ?></span>
                                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                            </td>
                            <td class="small text-truncate" style="max-width:180px;" title="<?= htmlspecialchars((string)($l['affected_fields_csv'] ?? '')) ?>"><?= $l['affected_fields_csv'] ? '<code>'.htmlspecialchars($l['affected_fields_csv']).'</code>' : '<span class="text-muted">-</span>' ?></td>
                            <td>
                                <?php if (!empty($l['is_reviewed'])): ?>
                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i> تمت المراجعة</span>
                                    <div class="small text-muted">من قبل U:<?= (int)($l['reviewed_by'] ?? 0) ?></div>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i> قيد المراجعة</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="financial_audit.php?<?= $_SERVER['QUERY_STRING'] ?><?= $_SERVER['QUERY_STRING'] ? '&' : '' ?>view=<?= $l['id'] ?>"><i class="fas fa-eye me-1"></i> عرض قبل/بعد</a>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
