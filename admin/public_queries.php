<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// Pagination
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count
$total_stmt = $pdo->query("SELECT COUNT(*) FROM public_queries");
$total = $total_stmt->fetchColumn();
$total_pages = ceil($total / $per_page);

// Get queries
$queries_stmt = $pdo->prepare("
    SELECT * FROM public_queries 
    ORDER BY created_at DESC 
    LIMIT ? OFFSET ?
");
$queries_stmt->execute([$per_page, $offset]);
$queries = $queries_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "سجل الاستعلامات العامة";
require_once 'header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-search me-2"></i> سجل الاستعلامات العامة</h3>
            <p class="text-muted small mb-0">عرض جميع الاستعلامات التي تم إجراؤها من قبل المستخدمين عبر صفحة تتبع الحالة</p>
        </div>
    </div>

    <!-- Stats -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-4">
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 rounded-3 p-3 me-3">
                        <i class="fas fa-search text-primary fa-2x"></i>
                    </div>
                    <div>
                        <h5 class="mb-0 fw-bold text-primary"><?php echo number_format($total); ?></h5>
                        <small class="text-muted">إجمالي الاستعلامات</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-4">
                <div class="d-flex align-items-center">
                    <div class="bg-success bg-opacity-10 rounded-3 p-3 me-3">
                        <i class="fas fa-check-circle text-success fa-2x"></i>
                    </div>
                    <?php
                    $found_stmt = $pdo->query("SELECT COUNT(*) FROM public_queries WHERE found = 1");
                    $found = $found_stmt->fetchColumn();
                    ?>
                    <div>
                        <h5 class="mb-0 fw-bold text-success"><?php echo number_format($found); ?></h5>
                        <small class="text-muted">تم العثور على نتيجة</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-4">
                <div class="d-flex align-items-center">
                    <div class="bg-danger bg-opacity-10 rounded-3 p-3 me-3">
                        <i class="fas fa-times-circle text-danger fa-2x"></i>
                    </div>
                    <?php
                    $not_found_stmt = $pdo->query("SELECT COUNT(*) FROM public_queries WHERE found = 0");
                    $not_found = $not_found_stmt->fetchColumn();
                    ?>
                    <div>
                        <h5 class="mb-0 fw-bold text-danger"><?php echo number_format($not_found); ?></h5>
                        <small class="text-muted">لم يتم العثور على نتيجة</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-4">
                <div class="d-flex align-items-center">
                    <div class="bg-info bg-opacity-10 rounded-3 p-3 me-3">
                        <i class="fas fa-calendar-day text-info fa-2x"></i>
                    </div>
                    <?php
                    $today_stmt = $pdo->prepare("SELECT COUNT(*) FROM public_queries WHERE DATE(created_at) = CURDATE()");
                    $today_stmt->execute();
                    $today = $today_stmt->fetchColumn();
                    ?>
                    <div>
                        <h5 class="mb-0 fw-bold text-info"><?php echo number_format($today); ?></h5>
                        <small class="text-muted">استعلامات اليوم</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4">#</th>
                            <th>رقم الاستعلام</th>
                            <th class="text-center">النتيجة</th>
                            <th>الجدول</th>
                            <th>IP المستخدم</th>
                            <th>وقت الاستعلام</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($queries)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                    لا توجد استعلامات حتى الآن
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($queries as $idx => $q): ?>
                                <tr>
                                    <td class="px-4 fw-bold text-muted"><?php echo $offset + $idx + 1; ?></td>
                                    <td class="fw-bold"><?php echo htmlspecialchars($q['query_number']); ?></td>
                                    <td class="text-center">
                                        <?php if ($q['found']): ?>
                                            <span class="badge bg-success rounded-pill px-3">تم العثور</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger rounded-pill px-3">لم يتم العثور</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($q['result_table']): ?>
                                            <span class="badge bg-info bg-opacity-10 text-info rounded-pill px-3">
                                                <?php echo htmlspecialchars($q['result_table']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">---</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-muted"><?php echo htmlspecialchars($q['user_ip'] ?? '---'); ?></span>
                                    </td>
                                    <td>
                                        <?php echo date('Y-m-d H:i:s', strtotime($q['created_at'])); ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($q['found'] && $q['result_table'] && $q['result_id']): ?>
                                            <?php if ($q['result_table'] === 'passports'): ?>
                                                <a href="passports.php" class="btn btn-sm btn-outline-primary" title="عرض في passports.php">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            <?php elseif ($q['result_table'] === 'passport_transactions'): ?>
                                                <a href="passport_transactions.php" class="btn btn-sm btn-outline-primary" title="عرض في passport_transactions.php">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-white border-top-0 p-4">
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center mb-0">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo ($i === $page) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'footer.php'; ?>
