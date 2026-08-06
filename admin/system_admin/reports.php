<?php
$page_title = "مركز التقارير - التصدير PDF و Excel";
require_once __DIR__ . '/../header.php';

if (!$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، هذه الصفحة للمدير فقط.</div></div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

ensure_system_admin_tables();
require_once __DIR__ . '/../../includes/system_admin/ReportsEngine.php';

$filters = [
    'from_date' => !empty($_GET['from_date']) ? $_GET['from_date'] . ' 00:00:00' : null,
    'to_date' => !empty($_GET['to_date']) ? $_GET['to_date'] . ' 23:59:59' : null,
];

$msg = '';
$previewReport = null;
$reportKey = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $reportKey = $_POST['report_key'] ?? '';
    if (isset(AlGhazali_ReportsEngine::$AVAILABLE_REPORTS[$reportKey])) {
        if ($action === 'preview') {
            $previewReport = AlGhazali_ReportsEngine::buildReport($reportKey, $filters);
        } elseif ($action === 'export_csv') {
            $r = AlGhazali_ReportsEngine::buildReport($reportKey, $filters);
            $csv = AlGhazali_ReportsEngine::exportCsv($r, $reportKey);
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="report_' . $reportKey . '_' . date('Ymd_His') . '.csv"');
            echo $csv;
            exit;
        } elseif ($action === 'export_excel') {
            $r = AlGhazali_ReportsEngine::buildReport($reportKey, $filters);
            $html = AlGhazali_ReportsEngine::exportHtmlExcel($r);
            header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
            header('Content-Disposition: attachment; filename="report_' . $reportKey . '_' . date('Ymd_His') . '.xls"');
            echo $html;
            exit;
        }
    }
}
?>

<div class="container-fluid py-4">
    <?php if ($msg) echo $msg; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-to-r from-primary to-success text-white p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-4">
                <i class="fas fa-chart-column fa-3x opacity-75"></i>
                <div>
                    <h2 class="fw-bold mb-1">مركز التقارير</h2>
                    <p class="mb-0 opacity-75">تصدير 6 أنواع تقارير بصيغة CSV (Excel) و Excel HTML. دعم كامل للغة العربية.</p>
                </div>
            </div>
            <div class="badge bg-white text-success rounded-pill px-4 py-2 fs-6"><i class="fas fa-file-export me-2"></i> <?= count(AlGhazali_ReportsEngine::$AVAILABLE_REPORTS) ?> أنواع تقارير متاحة</div>
        </div>
    </div>

    <form method="GET" class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">الفترة - من تاريخ</label>
                    <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars(substr((string)($filters['from_date'] ?? ''),0,10) ?: date('Y-m-d', strtotime('-30 days'))) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">الفترة - إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars(substr((string)($filters['to_date'] ?? ''),0,10) ?: date('Y-m-d')) ?>">
                </div>
                <div class="col-md-4 text-end">
                    <button class="btn btn-outline-primary rounded-pill"><i class="fas fa-filter me-2"></i> تحديث الفترة</button>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-4 mb-4">
        <?php
        $i = 0;
        $colors = ['danger','warning','primary','info','success','secondary'];
        $icons = ['fa-bug','fa-shield-halved','fa-tachometer-alt','fa-users','fa-sack-dollar','fa-heart-pulse'];
        foreach (AlGhazali_ReportsEngine::$AVAILABLE_REPORTS as $key => $meta):
            $c = $colors[$i % count($colors)];
            $ic = $icons[$i % count($icons)];
        ?>
            <div class="col-lg-6">
                <div class="card border-0 shadow-sm rounded-4 h-100 border-<?= $c ?> border-2">
                    <div class="card-body p-5">
                        <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                            <div class="d-flex align-items-center gap-4">
                                <div class="p-4 rounded-4 bg-<?= $c ?> text-white shadow-sm">
                                    <i class="fas <?= $ic ?> fs-2"></i>
                                </div>
                                <div>
                                    <h4 class="fw-bold mb-1"><?= htmlspecialchars($meta['label']) ?></h4>
                                    <div class="small text-muted">نوع المفتاح: <code><?= htmlspecialchars($key) ?></code></div>
                                </div>
                            </div>
                        </div>
                        <form method="POST" class="row g-2">
                            <input type="hidden" name="report_key" value="<?= htmlspecialchars($key) ?>">
                            <div class="col-md-4">
                                <button class="btn btn-<?= $c ?> w-100 rounded-pill" type="submit" name="action" value="preview"><i class="fas fa-eye me-1"></i> معاينة</button>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-outline-success w-100 rounded-pill" type="submit" name="action" value="export_csv"><i class="fas fa-file-csv me-1"></i> CSV / Excel</button>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-outline-dark w-100 rounded-pill" type="submit" name="action" value="export_excel"><i class="fas fa-file-excel me-1"></i> Excel (.xls)</button>
                            </div>
                        </form>
                        <div class="small mt-4 p-3 bg-light rounded-4 border border-light"><i class="fas fa-lightbulb text-warning me-2"></i>
                            <strong>تنويه:</strong> تصدير PDF سيُضاف في الإصدارات القادمة عبر مكتبة TCPDF أو mPDF. حالياً يتم التصدير بصيغ CSV/XLS المدعومة من قبل جميع إصدارات Excel.
                        </div>
                    </div>
                </div>
            </div>
        <?php $i++; endforeach; ?>
    </div>

    <?php if ($previewReport && !empty($previewReport['success'])): ?>
        <div class="card border-0 shadow-sm rounded-4 border-primary border-3">
            <div class="card-header bg-primary-subtle d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0 fw-bold text-primary"><i class="fas fa-eye me-2"></i> معاينة التقرير: <?= htmlspecialchars($previewReport['title']) ?></h5>
                <div class="small"><i class="fas fa-clock me-1"></i> تم إنشاؤه: <?= htmlspecialchars($previewReport['generated_at']) ?></div>
            </div>
            <div class="card-body p-4">
                <?php foreach ($previewReport['sections'] as $section): ?>
                    <div class="mb-5">
                        <h5 class="fw-bold mb-3 text-dark border-bottom pb-2"><i class="fas fa-folder-open me-2 text-primary"></i> <?= htmlspecialchars($section['title'] ?? '') ?></h5>
                        <?php if (!empty($section['summary']) && is_array($section['summary'])): ?>
                            <div class="row g-2 mb-3">
                                <?php foreach ($section['summary'] as $k => $v):
                                    if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                                ?>
                                    <div class="col-md-4 col-sm-6">
                                        <div class="p-3 bg-light rounded-3 border border-light">
                                            <div class="small text-muted mb-1"><?= htmlspecialchars((string)$k) ?></div>
                                            <div class="fw-semibold"><?= htmlspecialchars((string)$v) ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($section['rows']) && is_array($section['rows']) && isset($section['rows'][0]) && is_array($section['rows'][0])): ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-hover align-middle mb-0">
                                    <thead class="table-dark"><tr>
                                        <?php foreach (array_keys($section['rows'][0]) as $col): ?>
                                            <th class="small"><?= htmlspecialchars((string)$col) ?></th>
                                        <?php endforeach; ?>
                                    </tr></thead>
                                    <tbody>
                                        <?php foreach (array_slice($section['rows'], 0, 25) as $row): ?>
                                            <tr>
                                                <?php foreach ($row as $v):
                                                    if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE);
                                                ?>
                                                    <td class="small text-truncate" style="max-width:280px;"><?= htmlspecialchars((string)($v ?? '')) ?></td>
                                                <?php endforeach; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if (count($section['rows']) > 25): ?><div class="small text-muted mt-2"><i class="fas fa-info-circle me-1"></i> تم عرض 25 سجل فقط من أصل <?= count($section['rows']) ?> سجل (للمعاينة). قم بالتصدير للحصول على السجلات كاملة.</div><?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
