<?php
$page_title = "إدارة النسخ الاحتياطية - Backup Manager";
require_once __DIR__ . '/../header.php';

if (!$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، هذه الصفحة للمدير فقط.</div></div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

ensure_system_admin_tables();

$msg = '';
$backupDir = __DIR__ . '/../../backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['start_manual_backup'])) {
    global $pdo;
    $userId = $_SESSION['admin_id'] ?? ($_SESSION['user_id'] ?? null);
    $backupType = in_array(@$_POST['backup_type'], ['full', 'db_only', 'files_only']) ? $_POST['backup_type'] : 'db_only';

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO backup_records (backup_type, scope_description, status, initiated_by, retention_days, notes) VALUES (?, ?, 'running', ?, 30, ?)");
        $stmt->execute([$backupType, "نسخة يدوية من صفحة إدارة النظام - النوع: $backup_type", 'manual_user_' . (int)$userId, "تُنشأ يدوياً بواسطة المدير من مركز إدارة النظام."]);
        $recordId = (int)$pdo->lastInsertId();
        $pdo->commit();

        $backupFileName = 'alghazali_backup_' . date('Ymd_His') . '_' . $recordId . '.sql';
        $backupFilePath = rtrim($backupDir, '/\\') . DIRECTORY_SEPARATOR . $backupFileName;
        $backupSize = null;
        $checksum = null;
        $statusFinal = 'success';
        $failureReason = null;

        if ($pdo) {
            try {
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                $sql = "-- AlGhazali ERP Backup - Record #$recordId - Type: $backupType\n";
                $sql .= "-- Generated at: " . date('Y-m-d H:i:s') . "\n";
                $sql .= "-- Tables count: " . count($tables) . "\n\n";
                $sql .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
                foreach ($tables as $t) {
                    $sql .= "--\n-- Table: $t\n--\n";
                    $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
                    $sql .= "DROP TABLE IF EXISTS `$t`;\n" . ($create[1] ?? '') . ";\n\n";
                    $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
                    if (!empty($rows)) {
                        $cols = array_keys($rows[0]);
                        $sql .= "LOCK TABLES `$t` WRITE;\nINSERT INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES\n";
                        $chunks = array_chunk($rows, 50);
                        $chunkSql = [];
                        foreach ($chunks as $chunk) {
                            $vals = [];
                            foreach ($chunk as $row) {
                                $line = [];
                                foreach ($row as $v) {
                                    if ($v === null) $line[] = 'NULL';
                                    else $line[] = $pdo->quote((string)$v);
                                }
                                $vals[] = '(' . implode(',', $line) . ')';
                            }
                            $chunkSql[] = implode(",\n", $vals);
                        }
                        $sql .= implode(",\n", $chunkSql) . ";\nUNLOCK TABLES;\n\n";
                    }
                }
                $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
                file_put_contents($backupFilePath, $sql);
                if (function_exists('hash_file')) $checksum = hash_file('sha256', $backupFilePath);
                $backupSize = filesize($backupFilePath) ?: null;
            } catch (\Throwable $e) {
                $statusFinal = 'failed';
                $failureReason = mb_substr($e->getMessage(), 0, 400);
            }
        }

        $upd = $pdo->prepare("UPDATE backup_records SET completed_at = NOW(), backup_size_bytes = ?, backup_filename = ?, backup_storage_path = ?, backup_checksum_sha256 = ?, status = ?, failure_reason = ? WHERE id = ?");
        $upd->execute([$backupSize, $backupFileName, $backupFilePath, $checksum, $statusFinal, $failureReason, $recordId]);
        if ($statusFinal === 'success') {
            $msg = "<div class='alert alert-success rounded-4'><i class='fas fa-check-circle me-2'></i> تم إنشاء النسخة الاحتياطية بنجاح! الحجم: " . round(($backupSize ?: 0) / (1024 * 1024), 2) . " MB. الملف: <code>$backupFileName</code></div>";
        } else {
            $msg = "<div class='alert alert-danger rounded-4'>فشل النسخ الاحتياطي: $failureReason</div>";
        }
    } catch (\Throwable $e) {
        $msg = "<div class='alert alert-danger rounded-4'>خطأ: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

global $pdo;
$records = [];
$statTotals = ['success' => 0, 'failed' => 0, 'running' => 0, 'partial' => 0, 'totalSize' => 0];
$lastSuccess = null;
if ($pdo) {
    try {
        $records = $pdo->query("SELECT br.*, u.username, u.full_name FROM backup_records br LEFT JOIN users u ON u.id = br.verified_by OR u.id = NULLIF(br.initiated_by, '') + 0 ORDER BY br.started_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
        $s = $pdo->query("SELECT status, COUNT(*) c, COALESCE(SUM(backup_size_bytes),0) sz FROM backup_records GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($s as $r) {
            $statTotals[$r['status']] = (int)($r['c'] ?? 0);
            $statTotals['totalSize'] += (int)($r['sz'] ?? 0);
        }
        $lastSuccess = $pdo->query("SELECT * FROM backup_records WHERE status='success' ORDER BY started_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
    }
}

function fmtB($b)
{
    if (!$b) return '0 B';
    $u = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($b, 1024));
    return round($b / (1 << (10 * $i)), 2) . ' ' . $u[min($i, 3)];
}
$stColors = ['success' => 'success', 'failed' => 'danger', 'running' => 'warning', 'partial' => 'info'];
$stNames = ['success' => 'ناجح', 'failed' => 'فاشل', 'running' => 'قيد التنفيذ', 'partial' => 'جزئي'];
?>

<div class="container-fluid py-4">
    <?php if ($msg) echo $msg; ?>

    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-to-r from-secondary to-dark text-white p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-4">
                <i class="fas fa-box-archive fa-3x opacity-75"></i>
                <div>
                    <h2 class="fw-bold mb-1">إدارة النسخ الاحتياطية</h2>
                    <p class="mb-0 opacity-75">إنشاء نسخ يدوية • حالة النسخ السابقة • جدولة النسخ التلقائي قريباً • المجموع الاختباري</p>
                </div>
            </div>
            <div class="badge bg-white text-dark rounded-pill px-4 py-2">
                الإجمالي: <?= fmtB($statTotals['totalSize']) ?>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-success text-white">
                <div class="card-body p-4">
                    <div class="small opacity-75">ناجحة</div>
                    <h2 class="fw-bold display-6 mb-0"><?= $statTotals['success'] ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-danger text-white">
                <div class="card-body p-4">
                    <div class="small opacity-75">فاشلة</div>
                    <h2 class="fw-bold display-6 mb-0"><?= $statTotals['failed'] ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-warning text-dark">
                <div class="card-body p-4">
                    <div class="small opacity-75">قيد التنفيذ</div>
                    <h2 class="fw-bold display-6 mb-0"><?= $statTotals['running'] ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6 mb-3">
            <div class="card border-0 shadow-sm rounded-4 h-100 bg-secondary text-white">
                <div class="card-body p-4">
                    <div class="small opacity-75">الحجم الإجمالي</div>
                    <h5 class="fw-bold mb-0 mt-2"><?= fmtB($statTotals['totalSize']) ?></h5>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4 border-primary border-2">
        <div class="card-body p-5">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-4">
                <div>
                    <h4 class="fw-bold mb-2"><i class="fas fa-play-circle me-2 text-primary"></i> إنشاء نسخة احتياطية يدوياً</h4>
                    <p class="text-muted mb-0">اختر نوع النسخة، ثم اضغط "بدء النسخ". يتم حفظ الملفات في مجلد <code>backups/</code> الجذر.</p>
                </div>
                <form method="POST" class="d-flex gap-2 align-items-end flex-wrap">
                    <div>
                        <label class="form-label small fw-semibold">نوع النسخة</label>
                        <select name="backup_type" class="form-select form-select-sm">
                            <option value="db_only">قاعدة البيانات فقط (مُوصى به)</option>
                            <option value="full">النظام كاملاً (DB + الملفات)</option>
                            <option value="files_only">ملفات النظام فقط</option>
                        </select>
                    </div>
                    <button class="btn btn-primary rounded-pill px-4" type="submit" name="start_manual_backup" value="1" onclick="return confirm('هل أنت متأكد من بدء النسخ الاحتياطي؟ قد يستغرق ذلك بعض الوقت.')"><i class="fas fa-cloud-arrow-up me-2"></i> بدء النسخ الآن</button>
                </form>
            </div>
            <div class="mt-4 p-3 bg-info-subtle rounded-4 border-start border-4 border-info text-info-emphasis small">
                <i class="fas fa-info-circle me-2"></i>
                <strong>ملاحظة حول النسخ التلقائي:</strong> النظام جاهز لإضافة جدولة النسخ التلقائي عبر CRON (لينكس) أو Task Scheduler (ويندوز). يمكن لاحقاً استدعاء المسار <code>admin/system_admin/backups.php?auto=1&amp;token=SECURE_TOKEN</code> لتفعيلها.
            </div>
        </div>
    </div>

    <?php if ($lastSuccess): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4 border-success border-2">
            <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div class="d-flex gap-3 align-items-center">
                    <div class="p-3 rounded-4 bg-success-subtle text-success"><i class="fas fa-hard-drive fs-3"></i></div>
                    <div>
                        <div class="small text-muted">آخر نسخة احتياطية ناجحة</div>
                        <h5 class="fw-bold mb-0"><?= htmlspecialchars($lastSuccess['backup_filename'] ?? '-') ?></h5>
                        <div class="small text-muted">
                            تاريخ البداية: <?= htmlspecialchars((string)$lastSuccess['started_at']) ?>
                            &bull; الحجم: <?= fmtB((int)($lastSuccess['backup_size_bytes'] ?? 0)) ?>
                            &bull; SHA256: <code class="small"><?= htmlspecialchars(substr((string)($lastSuccess['backup_checksum_sha256'] ?? ''), 0, 24)) ?>...</code>
                        </div>
                    </div>
                </div>
                <span class="badge bg-success rounded-pill px-4 py-2 fs-6">ناجحة</span>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white py-3 border-0">
            <h5 class="mb-0 fw-bold text-dark"><i class="fas fa-clock-rotate-left me-2 text-secondary"></i> سجل النسخ الاحتياطية (آخر 200 سجل)</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>البدء / الانتهاء</th>
                        <th>النوع</th>
                        <th>اسم الملف</th>
                        <th>الحجم</th>
                        <th>الحالة</th>
                        <th>المنفذ</th>
                        <th>الاحتفاظ (أيام)</th>
                        <th>مُشفّر / موثّق</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted"><i class="fas fa-folder fs-1 mb-2"></i>
                                <p>لا توجد سجلات نسخ احتياطية بعد. أنشئ أول نسخة بالأزرار أعلاه.</p>
                            </td>
                        </tr>
                        <?php else: foreach ($records as $r): ?>
                            <tr>
                                <td class="fw-bold small">#<?= $r['id'] ?></td>
                                <td class="small">
                                    <div class="fw-semibold"><?= htmlspecialchars($r['started_at']) ?></div><?= !empty($r['completed_at']) ? '<div class="text-muted">✓ ' . htmlspecialchars($r['completed_at']) . '</div>' : '<div class="text-warning">قيد التنفيذ...</div>' ?>
                                </td>
                                <td><span class="badge bg-dark"><?= match ($r['backup_type']) {
                                                                    'full' => 'كاملة',
                                                                    'db_only' => 'فقط قاعدة البيانات',
                                                                    'files_only' => 'فقط الملفات',
                                                                    default => $r['backup_type']
                                                                } ?></span></td>
                                <td class="small"><code><?= htmlspecialchars((string)($r['backup_filename'] ?? '-')) ?></code></td>
                                <td class="small fw-semibold"><?= fmtB((int)($r['backup_size_bytes'] ?? 0)) ?></td>
                                <td>
                                    <span class="badge bg-<?= $stColors[$r['status']] ?? 'secondary' ?> px-3 py-2 rounded-pill"><?= $stNames[$r['status']] ?? $r['status'] ?></span>
                                    <?php if (!empty($r['failure_reason'])): ?><div class="small text-danger mt-1 text-truncate" style="max-width:220px;" title="<?= htmlspecialchars($r['failure_reason']) ?>"><?= htmlspecialchars($r['failure_reason']) ?></div><?php endif; ?>
                                </td>
                                <td class="small"><?= htmlspecialchars((string)($r['initiated_by'] ?? '-')) ?></td>
                                <td class="small text-center"><?= (int)($r['retention_days'] ?? 30) ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <?= !empty($r['is_encrypted']) ? '<span class="badge bg-success"><i class="fas fa-lock"></i></span>' : '<span class="badge bg-secondary"><i class="fas fa-lock-open"></i></span>' ?>
                                        <?= !empty($r['verified_at']) ? '<span class="badge bg-success" title="تم التحقق من السلامة"><i class="fas fa-check-double"></i></span>' : '<span class="badge bg-secondary" title="لم يتم التحقق بعد"><i class="fas fa-hourglass"></i></span>' ?>
                                    </div>
                                </td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
