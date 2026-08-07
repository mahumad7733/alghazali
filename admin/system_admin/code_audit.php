<?php
$page_title = 'إدارة وتنظيف الأكواد';
require_once __DIR__ . '/../header.php';

if (!$is_admin) {
    echo "<div class='container py-5'><div class='alert alert-danger'>هذه الصفحة متاحة للمدير فقط.</div></div>";
    require_once __DIR__ . '/../footer.php';
    exit;
}

$root = realpath(dirname(__DIR__, 2));
$excluded = ['.git', 'vendor', 'node_modules', 'storage', 'sessions', 'uploads', 'cache'];
$extensions = ['php', 'js', 'css', 'sql', 'html', 'htaccess', 'bat'];
$files = [];
$sourceContents = [];
$auditMessage = '';
if (empty($_SESSION['code_audit_token'])) $_SESSION['code_audit_token'] = bin2hex(random_bytes(24));
$auditToken = $_SESSION['code_audit_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quarantine_file') {
    if (!hash_equals($auditToken, (string)($_POST['token'] ?? ''))) {
        $auditMessage = 'رمز حماية غير صالح.';
    } else {
        $requested = str_replace('\\', '/', trim((string)($_POST['file'] ?? '')));
        $target = realpath($root . '/' . $requested);
        $isAllowedName = (bool)preg_match('/(^|[_.\\/-])(test|debug|check|fix|verify|diag|schema|quick|tmp|restore|migrate)([_.\\/-]|$)/i', $requested);
        if (!$target || strpos(str_replace('\\', '/', $target), str_replace('\\', '/', $root) . '/') !== 0 || !$isAllowedName || str_starts_with($requested, 'admin/system_admin/')) {
            $auditMessage = 'لا يمكن عزل هذا الملف تلقائيًا؛ يلزم مراجعة يدوية.';
        } else {
            $quarantineDir = $root . '/storage/system_admin_quarantine/' . date('Ymd_His');
            @mkdir($quarantineDir, 0755, true);
            $destination = $quarantineDir . '/' . basename($target);
            $auditMessage = @rename($target, $destination)
                ? 'تم نقل الملف إلى العزل القابل للاسترجاع: ' . $destination
                : 'تعذر نقل الملف إلى مجلد العزل.';
        }
    }
}

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) continue;
    $path = $fileInfo->getPathname();
    $relative = str_replace('\\', '/', ltrim(str_replace($root, '', $path), '\\/'));
    $parts = explode('/', $relative);
    if (array_intersect($parts, $excluded)) continue;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, $extensions, true)) continue;
    $content = @file_get_contents($path);
    if ($content === false) continue;
    $files[$relative] = ['path' => $path, 'size' => $fileInfo->getSize(), 'modified' => $fileInfo->getMTime(), 'ext' => $ext];
    $sourceContents[$relative] = $content;
}

$testFiles = [];
$unusedCandidates = [];
$markers = ['test', 'debug', 'check', 'fix', 'verify', 'diag', 'schema', 'quick', 'tmp', 'restore', 'migrate'];
foreach ($files as $relative => $meta) {
    $name = strtolower(pathinfo($relative, PATHINFO_FILENAME));
    $isTest = false;
    foreach ($markers as $marker) {
        if (preg_match('/(^|[_.\\/-])' . preg_quote($marker, '/') . '([_.\\/-]|$)/i', $relative) || str_starts_with($name, '_' . $marker)) { $isTest = true; break; }
    }
    if ($isTest) {
        $testFiles[] = ['file' => $relative, 'reason' => 'اسم الملف أو المسار يشير إلى اختبار/تصحيح/ترحيل', 'meta' => $meta];
    }
    if ($meta['ext'] === 'php' && !str_starts_with($relative, 'admin/system_admin/')) {
        $base = basename($relative);
        $references = 0;
        foreach ($sourceContents as $other => $body) {
            if ($other === $relative) continue;
            $references += substr_count($body, $base);
        }
        if ($references === 0 && !in_array(strtolower($relative), ['index.php', 'config.php'], true)) {
            $unusedCandidates[] = ['file' => $relative, 'reason' => 'لم يتم العثور على مرجع نصي للاسم داخل ملفات المشروع', 'references' => $references, 'meta' => $meta];
        }
    }
}

usort($testFiles, fn($a, $b) => strcmp($a['file'], $b['file']));
usort($unusedCandidates, fn($a, $b) => strcmp($a['file'], $b['file']));
$formatBytes = static function ($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
};
?>

<div class="container-fluid py-4">
    <div class="card border-0 shadow-sm rounded-4 mb-4 bg-gradient-to-r from-dark to-primary text-white p-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <h2 class="fw-bold mb-1"><i class="fas fa-code me-2"></i>إدارة وتنظيف الأكواد</h2>
                <p class="mb-0 opacity-75">تحليل آمن لملفات الاختبار والملفات المحتمل عدم استخدامها دون حذف تلقائي.</p>
            </div>
            <a href="index.php" class="btn btn-light text-primary rounded-pill">العودة للوحة النظام</a>
        </div>
    </div>

    <div class="alert alert-warning rounded-4">
        <strong>تنبيه:</strong> التصنيف آلي ومبدئي. عدم العثور على مرجع نصي لا يعني أن الملف غير مستخدم؛ قد يكون مستدعى ديناميكيًا أو عبر رابط خارجي. لم يتم حذف أي ملف.
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card border-0 shadow-sm rounded-4"><div class="card-body"><div class="text-muted small">إجمالي الملفات المفحوصة</div><div class="fs-3 fw-bold text-primary"><?= count($files) ?></div></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm rounded-4"><div class="card-body"><div class="text-muted small">ملفات الاختبار والتصحيح</div><div class="fs-3 fw-bold text-warning"><?= count($testFiles) ?></div></div></div></div>
        <div class="col-md-4"><div class="card border-0 shadow-sm rounded-4"><div class="card-body"><div class="text-muted small">مرشحون للمراجعة</div><div class="fs-3 fw-bold text-danger"><?= count($unusedCandidates) ?></div></div></div></div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white border-0 py-3"><h5 class="mb-0 fw-bold"><i class="fas fa-vial text-warning me-2"></i>ملفات الاختبار والتصحيح والترحيل</h5></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>الملف</th><th>السبب</th><th>الحجم</th><th>آخر تعديل</th></tr></thead><tbody>
        <?php if (!$testFiles): ?><tr><td colspan="4" class="text-center text-muted py-4">لا توجد ملفات مصنفة.</td></tr><?php endif; ?>
        <?php foreach ($testFiles as $item): ?><tr><td><code><?= htmlspecialchars($item['file']) ?></code></td><td><?= htmlspecialchars($item['reason']) ?></td><td><?= $formatBytes($item['meta']['size']) ?></td><td><?= date('Y-m-d H:i', $item['meta']['modified']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-header bg-white border-0 py-3"><h5 class="mb-0 fw-bold"><i class="fas fa-magnifying-glass text-danger me-2"></i>ملفات محتملة عدم الاستخدام</h5></div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>الملف</th><th>سبب الترشيح</th><th>المراجع النصية</th><th>الإجراء</th></tr></thead><tbody>
        <?php if (!$unusedCandidates): ?><tr><td colspan="4" class="text-center text-muted py-4">لم يتم العثور على مرشحين.</td></tr><?php endif; ?>
        <?php foreach ($unusedCandidates as $item): ?><tr><td><code><?= htmlspecialchars($item['file']) ?></code></td><td><?= htmlspecialchars($item['reason']) ?></td><td><?= (int)$item['references'] ?></td><td><span class="badge bg-warning text-dark">مراجعة يدوية مطلوبة</span></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </div>
</div>

<?php require_once __DIR__ . '/../footer.php'; ?>
