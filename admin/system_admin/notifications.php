<?php
$page_title = "مركز الإشعارات - التنبيهات الهامة";
require_once __DIR__ . '/../header.php';

if (!$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، هذه الصفحة للمدير فقط.</div></div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

ensure_system_admin_tables();

global $pdo;
$today = date('Y-m-d 00:00:00');
$weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));

$items = [];
if ($pdo) {
    // Keep the legacy query block below disabled until its historical SQL is removed;
    // the normalized queries after it use valid MySQL CASE expressions.
    /*
    try {
        $crit = $pdo->query("SELECT id, created_at, level, priority, message, file, line, 'critical_error' as item_type, severity='critical'?1:0 as is_critical FROM system_error_audit WHERE created_at >= '$weekAgo' AND (level IN ('CRITICAL','EMERGENCY') OR priority='critical') ORDER BY created_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($crit as $c) $items[] = $c;

        $secHigh = $pdo->query("SELECT id, created_at, vulnerability_type, severity, title, affected_url, 'vulnerability' as item_type, (severity='critical' OR severity='high')?1:0 as is_critical FROM security_vulnerabilities WHERE created_at >= '$weekAgo' AND severity IN ('critical','high') ORDER BY created_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($secHigh as $c) $items[] = $c;

        $secEvents = $pdo->query("SELECT id, occurred_at as created_at, event_type as vulnerability_type, severity, CONCAT(event_type, ' - مصدر IP: ', COALESCE(source_ip,'غير محدد')) as title, '' as affected_url, 'security_event' as item_type, (severity='critical' OR severity='high')?1:0 as is_critical FROM security_events WHERE occurred_at >= '$weekAgo' AND severity IN ('critical','high') AND event_type IN ('brute_force','unauthorized_access','sensitive_data_change','session_hijack_attempt') ORDER BY occurred_at DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($secEvents as $c) $items[] = $c;

        $bkFail = $pdo->query("SELECT id, started_at as created_at, 'backup' as item_type, 1 as is_critical, 'backup_failed' as vulnerability_type, 'warning' as severity, CONCAT('فشل النسخ الاحتياطي #',id,' - السبب: ', COALESCE(failure_reason,'غير محدد')) as title, backup_storage_path as affected_url FROM backup_records WHERE status='failed' AND started_at >= '$weekAgo' ORDER BY started_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($bkFail as $c) $items[] = $c;

        $dbErr = $pdo->query("SELECT id, created_at, level as vulnerability_type, severity='CRITICAL'?1:0 as is_critical, 'db_error' as item_type, 'danger' as severity_level, message as title, file as affected_url FROM system_error_audit WHERE created_at >= '$weekAgo' AND (message LIKE '%PDO%' OR message LIKE '%SQLSTATE%' OR message LIKE '%database%' OR message LIKE '%connection%') ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dbErr as $c) $items[] = $c;

        $finFail = $pdo->query("SELECT id, occurred_at as created_at, transaction_type as vulnerability_type, 1 as is_critical, 'financial_issue' as item_type, 'warning' as severity_level, CONCAT('عملية مالية: ', transaction_type, ' - الحقول المتأثرة: ', COALESCE(affected_fields_csv,'-')) as title, CONCAT('فاتورة #', invoice_id) as affected_url FROM financial_transaction_audit WHERE occurred_at >= '$weekAgo' AND transaction_type IN ('invoice_cancel','refund','financial_unpost','exchange_rate_change') ORDER BY occurred_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($finFail as $c) $items[] = $c;

        usort($items, fn($a,$b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        $items = array_slice($items, 0, 100);
    } catch (\Throwable $e) {  }
    */

    try {
        $crit = $pdo->prepare("SELECT id, created_at, level, priority, message, file, line,
            'critical_error' AS item_type, 1 AS is_critical, level AS severity
            FROM system_error_audit
            WHERE created_at >= ? AND (level IN ('CRITICAL','EMERGENCY') OR priority = 'critical')
            ORDER BY created_at DESC LIMIT 25");
        $crit->execute([$weekAgo]);
        foreach ($crit->fetchAll(PDO::FETCH_ASSOC) as $c) $items[] = $c;

        $secHigh = $pdo->prepare("SELECT id, created_at, vulnerability_type, severity, title, affected_url,
            'vulnerability' AS item_type,
            CASE WHEN severity IN ('critical','high') THEN 1 ELSE 0 END AS is_critical
            FROM security_vulnerabilities
            WHERE created_at >= ? AND severity IN ('critical','high')
            ORDER BY created_at DESC LIMIT 25");
        $secHigh->execute([$weekAgo]);
        foreach ($secHigh->fetchAll(PDO::FETCH_ASSOC) as $c) $items[] = $c;

        $secEvents = $pdo->prepare("SELECT id, occurred_at AS created_at, event_type AS vulnerability_type, severity,
            CONCAT(event_type, ' - IP: ', COALESCE(source_ip, 'unknown')) AS title, '' AS affected_url,
            'security_event' AS item_type,
            CASE WHEN severity IN ('critical','high') THEN 1 ELSE 0 END AS is_critical
            FROM security_events
            WHERE occurred_at >= ? AND severity IN ('critical','high')
              AND event_type IN ('brute_force','unauthorized_access','sensitive_data_change','session_hijack_attempt')
            ORDER BY occurred_at DESC LIMIT 25");
        $secEvents->execute([$weekAgo]);
        foreach ($secEvents->fetchAll(PDO::FETCH_ASSOC) as $c) $items[] = $c;

        $bkFail = $pdo->prepare("SELECT id, started_at AS created_at, 'backup' AS item_type, 1 AS is_critical,
            'backup_failed' AS vulnerability_type, 'warning' AS severity,
            CONCAT('Backup failed #', id, ' - ', COALESCE(failure_reason, 'unknown')) AS title,
            backup_storage_path AS affected_url
            FROM backup_records WHERE status = 'failed' AND started_at >= ?
            ORDER BY started_at DESC LIMIT 10");
        $bkFail->execute([$weekAgo]);
        foreach ($bkFail->fetchAll(PDO::FETCH_ASSOC) as $c) $items[] = $c;

        $dbErr = $pdo->prepare("SELECT id, created_at, level AS vulnerability_type,
            CASE WHEN level IN ('CRITICAL','EMERGENCY') THEN 1 ELSE 0 END AS is_critical,
            'db_error' AS item_type, 'danger' AS severity_level, message AS title, file AS affected_url
            FROM system_error_audit
            WHERE created_at >= ? AND (message LIKE '%PDO%' OR message LIKE '%SQLSTATE%' OR message LIKE '%database%' OR message LIKE '%connection%')
            ORDER BY created_at DESC LIMIT 10");
        $dbErr->execute([$weekAgo]);
        foreach ($dbErr->fetchAll(PDO::FETCH_ASSOC) as $c) $items[] = $c;

        $finFail = $pdo->prepare("SELECT id, occurred_at AS created_at, transaction_type AS vulnerability_type,
            1 AS is_critical, 'financial_issue' AS item_type, 'warning' AS severity_level,
            CONCAT('Financial operation: ', transaction_type, ' - fields: ', COALESCE(affected_fields_csv, '-')) AS title,
            CONCAT('Invoice #', COALESCE(invoice_id, '-')) AS affected_url
            FROM financial_transaction_audit
            WHERE occurred_at >= ? AND transaction_type IN ('invoice_cancel','refund','financial_unpost','exchange_rate_change')
            ORDER BY occurred_at DESC LIMIT 20");
        $finFail->execute([$weekAgo]);
        foreach ($finFail->fetchAll(PDO::FETCH_ASSOC) as $c) $items[] = $c;

        usort($items, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        $items = array_slice($items, 0, 100);
    } catch (\Throwable $e) {
        $items = [];
    }
}

$counts = ['critical'=>0,'high'=>0,'medium'=>0];
foreach ($items as $it) {
    $sev = strtolower((string)($it['severity'] ?? ($it['severity_level'] ?? 'medium')));
    if (!empty($it['is_critical']) || $sev === 'critical') $counts['critical']++;
    elseif ($sev === 'high' || $sev === 'danger') $counts['high']++;
    else $counts['medium']++;
}

$typeLabels = [
    'critical_error'=>'خطأ حرج', 'vulnerability'=>'ثغرة أمنية', 'security_event'=>'حادث أمني',
    'backup'=>'مشكلة نسخ احتياطي', 'db_error'=>'مشكلة قاعدة بيانات', 'financial_issue'=>'عملية مالية حساسة'
];
$typeIcons = [
    'critical_error'=>'fa-skull-crossbones text-white', 'vulnerability'=>'fa-shield-halved text-white',
    'security_event'=>'fa-user-secret text-white', 'backup'=>'fa-hard-drive text-white',
    'db_error'=>'fa-database text-white', 'financial_issue'=>'fa-sack-dollar text-white'
];
$typeColors = [
    'critical_error'=>'bg-dark', 'vulnerability'=>'bg-danger', 'security_event'=>'bg-warning text-dark',
    'backup'=>'bg-secondary', 'db_error'=>'bg-info', 'financial_issue'=>'bg-primary'
];
?>

<div class="container-fluid py-4">
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-to-r from-warning to-danger text-white p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-4">
                <i class="fas fa-bell fa-3x opacity-75"></i>
                <div>
                    <h2 class="fw-bold mb-1">مركز إشعارات النظام</h2>
                    <p class="mb-0 opacity-75">تنبيهات الأخطاء الحرجة • المشاكل الأمنية • فشل النسخ الاحتياطي • مشاكل قاعدة البيانات • فشل العمليات المالية</p>
                </div>
            </div>
            <span class="badge bg-white text-danger rounded-pill px-5 py-3 fs-5 fw-bold"><i class="fas fa-bell me-2"></i> إجمالي التنبيهات (7 أيام): <?= count($items) ?></span>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-4 mb-3">
            <a href="#critical_list" class="text-decoration-none">
                <div class="card border-0 shadow-sm rounded-4 h-100 bg-dark text-white card-hover">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="small opacity-75">حرجة</div>
                                <h3 class="fw-bold display-5 mb-0"><?= $counts['critical'] ?></h3>
                            </div>
                            <i class="fas fa-skull fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4 mb-3">
            <a href="#high_list" class="text-decoration-none">
                <div class="card border-0 shadow-sm rounded-4 h-100 bg-danger text-white card-hover">
                    <div class="card-body p-4">
                        <div class="d-flex align-items-center justify-content-between">
                            <div>
                                <div class="small opacity-75">عالية الخطورة</div>
                                <h3 class="fw-bold display-5 mb-0"><?= $counts['high'] ?></h3>
                            </div>
                            <i class="fas fa-fire fa-3x opacity-50"></i>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4 mb-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-warning text-dark card-hover">
                <div class="card-body p-4">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="small opacity-75">متوسطة</div>
                            <h3 class="fw-bold display-5 mb-0"><?= $counts['medium'] ?></h3>
                        </div>
                        <i class="fas fa-triangle-exclamation fa-3x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-list-check me-2 text-primary"></i> سجل الإشعارات - آخر 100 تنبيه</h5>
        </div>
        <div class="list-group list-group-flush">
            <?php if (empty($items)): ?>
                <div class="list-group-item list-group-item-action border-0 text-center py-5 text-muted">
                    <i class="fas fa-bell-slash fs-1 mb-2 text-success"></i>
                    <p class="mb-0">لا توجد إشعارات في آخر 7 أيام. ممتاز!</p>
                </div>
            <?php else: foreach ($items as $idx => $it):
                $t = $it['item_type'] ?? 'other';
                $sevIcon = !empty($it['is_critical']) ? 'fa-circle-exclamation text-white' : 'fa-circle-info text-white';
                $sevBadge = !empty($it['is_critical']) ? 'bg-dark text-white' : (in_array(strtolower((string)($it['severity'] ?? ($it['severity_level'] ?? ''))), ['high','danger']) ? 'bg-danger text-white' : 'bg-warning text-dark');
            ?>
                <div class="list-group-item list-group-item-action border-0 py-4 border-bottom border-dashed <?= $idx === 0 ? 'bg-light' : '' ?>" id="<?= !empty($it['is_critical']) ? 'critical_list' : 'high_list' ?>">
                    <div class="d-flex gap-4 align-items-start">
                        <div class="p-3 rounded-4 <?= $typeColors[$t] ?? 'bg-secondary' ?> flex-shrink-0" style="min-width:56px; text-align:center;">
                            <i class="fas <?= $typeIcons[$t] ?? 'fa-bell text-white' ?> fs-5"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                                <h6 class="fw-bold mb-0 d-flex align-items-center gap-2">
                                    <span class="badge <?= $sevBadge ?> rounded-pill px-3 py-1"><i class="fas <?= $sevIcon ?> me-1"></i> <?= !empty($it['is_critical']) ? 'حرج' : 'تنبيه' ?></span>
                                    <span class="badge rounded-pill bg-info-subtle text-info-emphasis border border-info"><?= $typeLabels[$t] ?? $t ?></span>
                                </h6>
                                <small class="text-muted fw-semibold" style="min-width:140px; text-align:left;"><i class="fas fa-clock me-1"></i> <?= htmlspecialchars((string)($it['created_at'] ?? '')) ?></small>
                            </div>
                            <div class="mb-2">
                                <strong class="d-block mb-1">العنوان:</strong>
                                <div class="text-dark"><?= htmlspecialchars((string)($it['title'] ?? '')) ?></div>
                            </div>
                            <?php if (!empty($it['affected_url']) || !empty($it['file'])): ?>
                                <div class="small mb-2"><strong>المصدر:</strong> <code class="text-muted"><?= htmlspecialchars((string)($it['affected_url'] ?? ($it['file'] ?? ''))) ?><?= !empty($it['line']) ? ':'.(int)$it['line'] : '' ?></code></div>
                            <?php endif; ?>
                            <div class="d-flex gap-2 flex-wrap">
                                <?php if ($t === 'critical_error'): ?><a class="btn btn-sm btn-outline-dark" href="errors.php?level=<?= urlencode((string)($it['level'] ?? 'CRITICAL')) ?>"><i class="fas fa-arrow-right me-1"></i> عرض خطأ مشابه</a><?php endif; ?>
                                <?php if ($t === 'vulnerability' || $t === 'security_event'): ?><a class="btn btn-sm btn-outline-danger" href="security.php?severity=<?= !empty($it['is_critical']) ? 'critical' : 'high' ?>"><i class="fas fa-shield me-1"></i> فحص الأمان</a><?php endif; ?>
                                <?php if ($t === 'backup'): ?><a class="btn btn-sm btn-outline-secondary" href="backups.php"><i class="fas fa-hard-drive me-1"></i> إدارة النسخ</a><?php endif; ?>
                                <?php if ($t === 'financial_issue'): ?><a class="btn btn-sm btn-outline-primary" href="financial_audit.php"><i class="fas fa-coins me-1"></i> تدقيق مالي</a><?php endif; ?>
                                <?php if ($t === 'db_error'): ?><a class="btn btn-sm btn-outline-info" href="errors.php?q=<?= urlencode('PDO SQLSTATE') ?>"><i class="fas fa-database me-1"></i> تفاصيل قاعدة البيانات</a><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
