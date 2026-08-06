<?php
$page_title = "مراقبة الأداء - أبطأ الصفحات والاستعلامات";
require_once __DIR__ . '/../header.php';

if (!$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، هذه الصفحة للمدير فقط.</div></div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

ensure_system_admin_tables();
require_once __DIR__ . '/../../includes/system_admin/PerformanceMonitor.php';

$hours = (int)($_GET['hours'] ?? 24);
$hours = in_array($hours, [6,12,24,72,168]) ? $hours : 24;

$slowPages = AlGhazali_PerformanceMonitor::slowestPages($hours, 30);
$slowQueries = AlGhazali_PerformanceMonitor::slowestQueries($hours, 30);
$memoryStats = AlGhazali_PerformanceMonitor::memoryStats($hours);

global $pdo;
$overview = ['requests'=>0,'avg_ms'=>0,'max_ms'=>0,'avg_mem'=>0];
if ($pdo) {
    try {
        $q = $pdo->prepare("SELECT COUNT(*) as requests, AVG(total_execution_ms) as avg_ms, MAX(total_execution_ms) as max_ms, AVG(memory_peak_bytes) as avg_mem
            FROM system_performance_logs WHERE timestamp >= DATE_SUB(NOW(), INTERVAL $hours HOUR)");
        $q->execute();
        $overview = $q->fetch(PDO::FETCH_ASSOC) ?: $overview;
    } catch (\Throwable $e) {  }
}

function fmtMS($ms){
    $ms = (int)$ms;
    if ($ms > 1000) return round($ms/1000,2) . ' ث';
    return $ms . ' مللي ثانية';
}
function fmtMB($b){ if (!$b) return '0 MB'; return round($b/(1024*1024),2) . ' MB'; }
function colorMS($ms){
    $ms = (int)$ms;
    if ($ms > 5000) return 'danger';
    if ($ms > 1500) return 'warning';
    if ($ms > 500) return 'primary';
    return 'success';
}
?>

<div class="container-fluid py-4">
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-to-r from-info to-primary text-white p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-4">
                <i class="fas fa-tachometer-alt fa-3x opacity-75"></i>
                <div>
                    <h2 class="fw-bold mb-1">مراقبة الأداء</h2>
                    <p class="mb-0 opacity-75">أبطأ الصفحات • أبطأ الاستعلامات • استهلاك الذاكرة والمعالج</p>
                </div>
            </div>
            <div class="d-flex gap-2">
                <?php foreach ([6,12,24,72,168] as $h): ?>
                    <a href="performance.php?hours=<?= $h ?>" class="btn btn-sm <?= $hours === $h ? 'btn-light text-primary' : 'btn-outline-light' ?> rounded-pill"><?= $h < 48 ? $h.'س' : ($h/24).'ي' ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <?php
        $kpis = [
            ['إجمالي الطلبات', (int)($overview['requests'] ?? 0), 'fas fa-file-circle-check', 'primary'],
            ['متوسط وقت التنفيذ', fmtMS((int)($overview['avg_ms'] ?? 0)), 'fas fa-stopwatch', 'warning'],
            ['أقصى وقت تنفيذ', fmtMS((int)($overview['max_ms'] ?? 0)), 'fas fa-bolt', 'danger'],
            ['متوسط الذاكرة الذروية', fmtMB((int)($overview['avg_mem'] ?? 0)), 'fas fa-memory', 'info'],
        ];
        foreach ($kpis as $k): ?>
            <div class="col-md-3 col-sm-6 mb-3">
                <div class="card border-0 shadow-sm rounded-4 h-100">
                    <div class="card-body p-4">
                        <div class="d-flex gap-3 align-items-center">
                            <div class="p-3 rounded-4 bg-<?= $k[3] ?>-subtle text-<?= $k[3] ?>"><i class="fs-4 <?= $k[2] ?>"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold"><?= $k[0] ?></div>
                                <h4 class="mb-0 fw-bold"><?= $k[1] ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white py-3 border-0"><h5 class="mb-0 fw-bold"><i class="fas fa-clock me-2 text-warning"></i> أبطأ 30 صفحة (متوسط وقت التنفيذ)</h5></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الصفحة</th>
                    <th class="text-center">عدد الطلبات</th>
                    <th class="text-center">المتوسط</th>
                    <th class="text-center">الأقصى</th>
                    <th class="text-center">المجموع</th>
                    <th>مؤشر بصري</th>
                </tr></thead>
                <tbody>
                    <?php if (empty($slowPages)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-gauge-high fs-1 mb-2 text-info"></i><p>لا توجد بيانات أداء كافية بعد.</p><p class="small">قم بزيارة بعض صفحات النظام لتسجيل سجل الأداء.</p></td></tr>
                    <?php else:
                        $max = (int)($slowPages[0]['sum_ms'] ?? 1);
                        foreach ($slowPages as $p):
                            $pct = min(100, round(((int)($p['sum_ms'] ?? 0) / $max) * 100));
                            $avgC = colorMS($p['avg_ms'] ?? 0);
                    ?>
                        <tr>
                            <td><code class="fw-semibold"><?= htmlspecialchars((string)$p['script_path']) ?></code></td>
                            <td class="text-center fw-bold"><?= (int)($p['requests'] ?? 0) ?></td>
                            <td class="text-center"><span class="badge bg-<?= $avgC ?>"><?= fmtMS((int)($p['avg_ms'] ?? 0)) ?></span></td>
                            <td class="text-center text-danger fw-bold"><?= fmtMS((int)($p['max_ms'] ?? 0)) ?></td>
                            <td class="text-end fw-semibold"><?= fmtMS((int)($p['sum_ms'] ?? 0)) ?></td>
                            <td style="width:220px;"><div class="progress" style="height:12px;"><div class="progress-bar bg-<?= $avgC ?>" style="width:<?= $pct ?>%"></div></div></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0"><h5 class="mb-0 fw-bold"><i class="fas fa-database me-2 text-primary"></i> أبطأ 30 استعلام (أكثر من 500 مللي ثانية)</h5></div>
                <div class="card-body p-0">
                    <?php if (empty($slowQueries)): ?>
                        <div class="text-center py-5 text-muted"><i class="fas fa-server fs-1 mb-2 text-success"></i><p>لا توجد استعلامات بطيئة مسجلة.</p></div>
                    <?php else: ?>
                        <div class="list-group list-group-flush">
                            <?php foreach ($slowQueries as $q):
                                $cls = colorMS($q['ms'] ?? 0);
                            ?>
                                <div class="list-group-item list-group-item-action border-0 border-bottom py-3">
                                    <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
                                        <code class="text-truncate small" style="max-width:70%;"><?= htmlspecialchars((string)($q['sql'] ?? '')) ?></code>
                                        <span class="badge bg-<?= $cls ?> flex-shrink-0"><?= (int)($q['ms'] ?? 0) ?> مللي ثانية</span>
                                    </div>
                                    <div class="small text-muted d-flex gap-3 flex-wrap">
                                        <span><i class="fas fa-file-code me-1"></i> <?= htmlspecialchars(basename((string)($q['script_path'] ?? ''))) ?></span>
                                        <span><i class="fas fa-clock me-1"></i> <?= htmlspecialchars((string)($q['timestamp'] ?? '')) ?></span>
                                        <span><i class="fas fa-hashtag me-1"></i> PARAMS: <?= (int)($q['params_count'] ?? 0) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white py-3 border-0"><h5 class="mb-0 fw-bold"><i class="fas fa-memory me-2 text-success"></i> أعلى 20 صفحة استهلاكاً للذاكرة</h5></div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="table-light"><tr><th>الصفحة</th><th class="text-end">الذاكرة (متوسط)</th><th class="text-end">الذاكرة (الذروة)</th></tr></thead>
                        <tbody>
                            <?php if (empty($memoryStats)): ?>
                                <tr><td colspan="3" class="text-center py-4 text-muted">لا توجد بيانات ذاكرة كافية.</td></tr>
                            <?php else:
                                foreach ($memoryStats as $m):
                                    $maxMB = round(((int)($m['max_bytes'] ?? 0))/(1024*1024), 2);
                                    $cls = $maxMB > 128 ? 'danger' : ($maxMB > 64 ? 'warning' : 'success');
                            ?>
                                <tr>
                                    <td><code class="small"><?= htmlspecialchars((string)$m['script_path']) ?></code></td>
                                    <td class="text-end"><?= fmtMB((int)($m['avg_bytes'] ?? 0)) ?></td>
                                    <td class="text-end"><span class="badge bg-<?= $cls ?>"><?= fmtMB((int)($m['max_bytes'] ?? 0)) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
