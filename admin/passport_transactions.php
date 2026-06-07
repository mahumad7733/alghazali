<?php
ob_start();
require_once 'header.php';

// Check permissions
if (!has_permission('passport_transactions_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// Check if module is enabled
if (!get_module_status($pdo, 'enable_passport_transactions')) {
    $_SESSION['flash_message'] = ['type' => 'warning', 'title' => 'تنبيه', 'body' => 'وحدة معاملات الجوازات معطلة حالياً.'];
    header('Location: index.php');
    exit();
}

$page_title = "إدارة معاملات الجوازات";
$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];

// Fetch filter data
$cities = $pdo->query("SELECT id, city_name FROM cities ORDER BY city_name ASC")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name FROM currencies WHERE is_active = 1 ORDER BY currency_name ASC")->fetchAll();
$statuses = $pdo->query("SELECT id, status_name, status_color FROM statuses WHERE status_name IN (SELECT status_name FROM statuses) ORDER BY id ASC")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL ORDER BY branch_name ASC")->fetchAll();
$agents = $pdo->query("SELECT id, agent_name FROM agents WHERE deleted_at IS NULL ORDER BY agent_name ASC")->fetchAll();

// Build dynamic query
$where_clauses = [];
$params = [];

// Data scope isolation
$entity_filter = get_entity_filter('pt', 'branch_id', 'agent_id', null, 'created_by');
if ($entity_filter['clause'] !== '1=1') {
    $where_clauses[] = $entity_filter['clause'];
    $params = array_merge($params, $entity_filter['params']);
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $where_clauses[] = "(pt.transaction_number LIKE ? OR pt.full_name LIKE ? OR pt.phone_number LIKE ? OR pt.passport_number LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

if (isset($_GET['status_id']) && !empty($_GET['status_id'])) {
    $where_clauses[] = "pt.status_id = ?";
    $params[] = $_GET['status_id'];
}

if (isset($_GET['branch_id']) && !empty($_GET['branch_id'])) {
    $where_clauses[] = "pt.branch_id = ?";
    $params[] = $_GET['branch_id'];
}

if (isset($_GET['from_date']) && !empty($_GET['from_date'])) {
    $where_clauses[] = "pt.operation_date >= ?";
    $params[] = $_GET['from_date'];
}

if (isset($_GET['to_date']) && !empty($_GET['to_date'])) {
    $where_clauses[] = "pt.operation_date <= ?";
    $params[] = $_GET['to_date'];
}

$query = "
    SELECT pt.*,
           -- بيانات فاتورة البيع
           inv.id AS sales_invoice_id,
           inv.invoice_number AS sales_invoice_number,
           inv.total_amount AS sales_total_amount,
           inv.discount AS sales_discount,
           inv.net_amount AS sale_price,
           inv.amount_received AS sales_received,
           inv.invoice_status AS sales_status,
           inv.delivery_type AS sales_delivery_type,
           
           -- بيانات فاتورة الشراء
           pur.id AS purchase_invoice_id,
           pur.invoice_number AS purchase_invoice_number,
           pur.total_amount AS purchase_total_amount,
           pur.amount_received AS purchase_received,
           pur.invoice_status AS purchase_status,
           
           -- الحسابات المالية الدقيقة (مطابقة لـ invoices.php)
           (inv.net_amount - inv.amount_received) AS remaining_amount,
           (inv.net_amount - 
            COALESCE(
                (pur.total_amount * IFNULL(cpur.exchange_rate_buy, 1) / IFNULL(c.exchange_rate_buy, 1)),
                inv.cost_amount, 0
            )
           ) as profit,
           
           inv.currency_id AS currency_id,
           s.status_name, s.status_color,
           c.currency_name, c.currency_symbol,
           b.branch_name,
           a.agent_name,
           u.full_name as created_by_name,
           fc.city_name as from_city_name,
           tc.city_name as to_city_name,
           serv.service_name
    FROM passport_transactions pt
    LEFT JOIN invoices inv
        ON (inv.id = pt.sales_invoice_id OR (
            inv.source_type = 'passport_transaction' AND inv.source_id = pt.id AND inv.invoice_category = 'sales'
        ))
    LEFT JOIN invoices pur
        ON (pur.id = pt.purchase_invoice_id OR (
            pur.source_type = 'passport_transaction' AND pur.source_id = pt.id AND pur.invoice_category = 'purchase'
        ))
    LEFT JOIN statuses s ON pt.status_id = s.id
    LEFT JOIN currencies c ON inv.currency_id = c.id
    LEFT JOIN currencies cpur ON pur.currency_id = cpur.id
    LEFT JOIN branches b ON pt.branch_id = b.id
    LEFT JOIN agents a ON pt.agent_id = a.id
    LEFT JOIN users u ON pt.created_by = u.id
    LEFT JOIN cities fc ON pt.from_city_id = fc.id
    LEFT JOIN cities tc ON pt.to_city_id = tc.id
    LEFT JOIN services serv ON pt.service_id = serv.id
";

if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(" AND ", $where_clauses);
}

$query .= " ORDER BY pt.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Statistics
$stats = [
    'total' => count($transactions),
    'completed' => 0,
    'pending' => 0,
    'total_sales' => 0,
    'total_profit' => 0
];

foreach ($transactions as $t) {
    if (strpos($t['status_name'], 'تم تسليم') !== false || $t['status_name'] == 'مكتمل') {
        $stats['completed']++;
    } else {
        $stats['pending']++;
    }
    $stats['total_sales'] += $t['sale_price'];
    $stats['total_profit'] += $t['profit'];
}

// Flash message
if (isset($_SESSION['flash_message'])) {
    $msg = $_SESSION['flash_message'];
    echo sprintf(
        '<div class="alert alert-%s border-0 shadow-sm rounded-4 p-3 mb-4 d-flex align-items-center alert-dismissible fade show" role="alert">
            <div class="bg-%s bg-opacity-10 p-2 rounded-circle me-3">
                <i class="fas %s fs-4"></i>
            </div>
            <div>
                <h6 class="mb-0 fw-bold">%s</h6>
                <small>%s</small>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>',
        $msg['type'],
        $msg['type'],
        $msg['type'] === 'success' ? 'fa-check-circle text-success' : 'fa-exclamation-triangle text-danger',
        htmlspecialchars($msg['title']),
        htmlspecialchars($msg['body'])
    );
    unset($_SESSION['flash_message']);
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-passport me-2"></i> إدارة معاملات الجوازات</h3>
            <p class="text-muted small mb-0">عرض وإدارة جميع معاملات الجوازات والبطائق</p>
        </div>
        <div class="d-flex gap-2">
            <?php if (has_permission('passport_transactions_create')): ?>
                <a href="passport_transaction_add.php" class="btn btn-primary rounded-pill px-4 shadow-sm">
                    <i class="fas fa-plus me-1"></i> إضافة معاملة جديدة
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3">
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 p-3 rounded-4 me-3">
                        <i class="fas fa-list-ul text-primary fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted small mb-1">إجمالي المعاملات</h6>
                        <h4 class="fw-bold mb-0"><?php echo $stats['total']; ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3">
                <div class="d-flex align-items-center">
                    <div class="bg-success bg-opacity-10 p-3 rounded-4 me-3">
                        <i class="fas fa-check-circle text-success fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted small mb-1">معاملات منتهية</h6>
                        <h4 class="fw-bold mb-0"><?php echo $stats['completed']; ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3">
                <div class="d-flex align-items-center">
                    <div class="bg-warning bg-opacity-10 p-3 rounded-4 me-3">
                        <i class="fas fa-clock text-warning fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted small mb-1">قيد المعالجة</h6>
                        <h4 class="fw-bold mb-0"><?php echo $stats['pending']; ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 p-3">
                <div class="d-flex align-items-center">
                    <div class="bg-info bg-opacity-10 p-3 rounded-4 me-3">
                        <i class="fas fa-dollar-sign text-info fs-4"></i>
                    </div>
                    <div>
                        <h6 class="text-muted small mb-1">إجمالي المبيعات</h6>
                        <h4 class="fw-bold mb-0"><?php echo number_format($stats['total_sales'], 2); ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body">
            <form method="GET" action="passport_transactions.php" id="filterForm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="search" class="form-label small fw-bold text-primary"><i class="fas fa-search me-1"></i> بحث شامل</label>
                        <input type="text" class="form-control rounded-3" id="search" name="search" placeholder="رقم المعاملة، الاسم، الهاتف، الجواز..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="status_id" class="form-label small text-muted">الحالة</label>
                        <select class="form-select select2-financial rounded-3" id="status_id" name="status_id">
                            <option value="">الكل</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo $status['id']; ?>" <?php echo (h($_GET['status_id'] ?? '') == $status['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status['status_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="from_date" class="form-label small text-muted">من تاريخ</label>
                        <input type="date" class="form-control rounded-3" id="from_date" name="from_date" value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="to_date" class="form-label small text-muted">إلى تاريخ</label>
                        <input type="date" class="form-control rounded-3" id="to_date" name="to_date" value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary rounded-3 w-100 shadow-sm"><i class="fas fa-filter me-1"></i> تصفية</button>
                        <a href="passport_transactions.php" class="btn btn-light rounded-3 border" title="إعادة تعيين"><i class="fas fa-redo"></i></a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3 border-0 text-secondary small text-uppercase fw-bold">المعاملة</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">العميل / المسافر</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">نوع المعاملة</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">خط السير</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">البيانات المالية</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">الحالة</th>
                            <th class="text-center border-0 text-secondary small text-uppercase fw-bold">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-folder-open fa-3x mb-3 d-block"></i>
                                    لا توجد معاملات حالياً
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td class="px-4">
                                        <div class="fw-bold text-primary small"><?php echo htmlspecialchars($t['transaction_number']); ?></div>
                                        <div class="text-muted extra-small"><?php echo date('Y-m-d', strtotime($t['created_at'])); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-bold small"><?php echo htmlspecialchars($t['full_name']); ?></div>
                                        <div class="text-muted extra-small"><i class="fas fa-phone-alt me-1"></i> <?php echo htmlspecialchars($t['phone_number']); ?></div>
                                        <?php if ($t['passport_number']): ?>
                                            <div class="text-muted extra-small"><i class="fas fa-id-card me-1"></i> <?php echo htmlspecialchars($t['passport_number']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $type_labels = [
                                                'both' => 'بطاقة وجواز',
                                                'card_only' => 'بطاقة فقط',
                                                'passport_only' => 'جواز فقط'
                                            ];
                                            $type_label = $type_labels[$t['transaction_type']] ?? $t['transaction_type'];
                                        ?>
                                        <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill small"><?php echo $type_label; ?></span>
                                    </td>
                                    <td>
                                        <div class="extra-small">
                                            <span class="text-muted"><?php echo htmlspecialchars($t['from_city_name']); ?></span>
                                            <i class="fas fa-long-arrow-alt-left mx-1 text-primary opacity-50"></i>
                                            <span class="fw-bold"><?php echo htmlspecialchars($t['to_city_name']); ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="fw-bold small text-dark mb-1">
                                            <?php echo number_format($t['sale_price'], 2); ?> <small><?php echo $t['currency_symbol']; ?></small>
                                            <span class="badge bg-success-subtle text-success ms-1" style="font-size: 10px;">بيع</span>
                                        </div>
                                        <?php if ($t['purchase_invoice_id']): ?>
                                            <div class="extra-small text-danger mb-1">
                                                <?php echo number_format($t['purchase_total_amount'], 2); ?> <small><?php echo $t['currency_symbol']; ?></small>
                                                <span class="badge bg-danger-subtle text-danger ms-1" style="font-size: 10px;">شراء</span>
                                            </div>
                                        <?php endif; ?>
                                        <div class="extra-small text-muted border-top pt-1 mt-1 mb-1">
                                            المتبقي: <span class="fw-bold text-<?php echo $t['remaining_amount'] > 0 ? 'danger' : 'success'; ?>"><?php echo number_format($t['remaining_amount'], 2); ?> <small><?php echo $t['currency_symbol']; ?></small></span>
                                        </div>
                                        <?php if ($t['sales_status'] == 'posted'): ?>
                                            <?php 
                                            $payment_status = '';
                                            $payment_status_class = '';
                                            if ($t['sales_received'] >= $t['sale_price'] && $t['sale_price'] > 0):
                                                $payment_status = 'مدفوع بالكامل';
                                                $payment_status_class = 'bg-success';
                                            elseif ($t['sales_received'] > 0):
                                                $payment_status = 'مدفوع جزئياً';
                                                $payment_status_class = 'bg-warning text-dark';
                                            else:
                                                $payment_status = 'غير مدفوع';
                                                $payment_status_class = 'bg-danger';
                                            endif;
                                            ?>
                                            <span class="badge <?php echo $payment_status_class; ?> rounded-pill extra-small w-100">
                                                <?php echo $payment_status; ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1 align-items-center">
                                            <!-- حالة المعاملة -->
                                            <span class="badge bg-<?php echo $t['status_color'] ?: 'primary'; ?> rounded-pill extra-small w-100">
                                                <?php echo htmlspecialchars($t['status_name']); ?>
                                            </span>

                                            <!-- حالة فاتورة البيع -->
                                            <?php if ($t['sales_invoice_id']): ?>
                                                <?php
                                                $s_status_class = 'bg-warning text-dark';
                                                $s_status_text = 'مسودة';
                                                if ($t['sales_status'] == 'posted') {
                                                    $s_status_class = 'bg-success';
                                                    $s_status_text = 'مرحل';
                                                } elseif ($t['sales_status'] == 'cancelled') {
                                                    $s_status_class = 'bg-danger';
                                                    $s_status_text = 'ملغي';
                                                }
                                                ?>
                                                <div class="d-flex align-items-center gap-1 w-100">
                                                    <span class="badge <?php echo $s_status_class; ?> extra-small flex-grow-1" style="font-size: 9px;">البيع: <?php echo $s_status_text; ?></span>
                                                    <?php if ($t['sales_status'] == 'posted'): ?>
                                                        <?php if ($t['sales_received'] >= $t['sale_price'] && $t['sale_price'] > 0): ?>
                                                            <i class="fas fa-check-circle text-success fs-xs" title="مدفوع بالكامل"></i>
                                                        <?php elseif ($t['sales_received'] > 0): ?>
                                                            <i class="fas fa-adjust text-info fs-xs" title="مدفوع جزئياً"></i>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>

                                            <!-- حالة فاتورة الشراء -->
                                            <?php if ($t['purchase_invoice_id']): ?>
                                                <?php
                                                $p_status_class = 'bg-warning text-dark';
                                                $p_status_text = 'مسودة';
                                                if ($t['purchase_status'] == 'posted') {
                                                    $p_status_class = 'bg-success';
                                                    $p_status_text = 'مرحل';
                                                } elseif ($t['purchase_status'] == 'cancelled') {
                                                    $p_status_class = 'bg-danger';
                                                    $p_status_text = 'ملغي';
                                                }
                                                ?>
                                                <div class="d-flex align-items-center gap-1 w-100">
                                                    <span class="badge <?php echo $p_status_class; ?> extra-small flex-grow-1" style="font-size: 9px;">الشراء: <?php echo $p_status_text; ?></span>
                                                    <?php if ($t['purchase_status'] == 'posted'): ?>
                                                        <?php if ($t['purchase_received'] >= $t['purchase_total_amount'] && $t['purchase_total_amount'] > 0): ?>
                                                            <i class="fas fa-check-circle text-success fs-xs" title="مسدد للمورد"></i>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-1">
                                            <?php if (has_permission('passport_transactions_view_details')): ?>
                                                <a href="passport_transaction_view.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-light rounded-circle shadow-sm" title="عرض التفاصيل">
                                                    <i class="fas fa-eye text-info"></i>
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if (has_permission('passport_transactions_edit')): ?>
                                                <a href="passport_transaction_edit.php?id=<?php echo $t['id']; ?>" class="btn btn-sm btn-light rounded-circle shadow-sm" title="تعديل">
                                                    <i class="fas fa-edit text-primary"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if (has_permission('passport_transactions_collect_payment') && $t['remaining_amount'] > 0 && $t['sales_invoice_id']): ?>
                                                <button class="btn btn-sm btn-light rounded-circle shadow-sm" data-bs-toggle="modal" data-bs-target="#paymentModal<?php echo $t['sales_invoice_id']; ?>" title="تسجيل دفعة">
                                                    <i class="fas fa-money-bill-wave text-success"></i>
                                                </button>
                                            <?php endif; ?>

                                            <!-- ترحيل (قائمة منسدلة) -->
                                            <?php if (($t['sales_status'] != 'posted') || ($t['purchase_invoice_id'] && $t['purchase_status'] != 'posted')): ?>
                                                <div class="dropdown d-inline-block">
                                                    <button class="btn btn-sm btn-light rounded-circle shadow-sm" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-check-double text-success"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                        <li><h6 class="dropdown-header small fw-bold text-muted">ترحيل محاسبي</h6></li>
                                                        <?php if ($t['sales_status'] != 'posted' && $t['purchase_invoice_id'] && $t['purchase_status'] != 'posted'): ?>
                                                            <li><a class="dropdown-item py-2 small" href="invoices.php?post_all=<?php echo $t['sales_invoice_id']; ?>&return_to=passport_transactions.php" onclick="return confirm('ترحيل البيع والشراء معاً؟')"><i class="fas fa-check-double me-2 text-success"></i>ترحيل الكل</a></li>
                                                            <li><hr class="dropdown-divider"></li>
                                                        <?php endif; ?>

                                                        <?php if ($t['sales_status'] != 'posted'): ?>
                                                            <li><a class="dropdown-item py-2 small" href="invoices.php?post_invoice=<?php echo $t['sales_invoice_id']; ?>&return_to=passport_transactions.php" onclick="return confirm('ترحيل فاتورة البيع؟')"><i class="fas fa-file-invoice-dollar me-2 text-primary"></i>ترحيل البيع</a></li>
                                                        <?php endif; ?>

                                                        <?php if ($t['purchase_invoice_id'] && $t['purchase_status'] != 'posted'): ?>
                                                            <li><a class="dropdown-item py-2 small" href="invoices.php?post_invoice=<?php echo $t['purchase_invoice_id']; ?>&return_to=passport_transactions.php" onclick="return confirm('ترحيل فاتورة الشراء؟')"><i class="fas fa-file-invoice me-2 text-warning"></i>ترحيل الشراء</a></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>

                                            <!-- إلغاء ترحيل (قائمة منسدلة) -->
                                            <?php if (($t['sales_status'] == 'posted') || ($t['purchase_invoice_id'] && $t['purchase_status'] == 'posted')): ?>
                                                <div class="dropdown d-inline-block">
                                                    <button class="btn btn-sm btn-light rounded-circle shadow-sm" type="button" data-bs-toggle="dropdown">
                                                        <i class="fas fa-undo text-warning"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                        <li><h6 class="dropdown-header small fw-bold text-muted">إعادة لمسودة</h6></li>
                                                        <?php if ($t['sales_status'] == 'posted' && $t['purchase_invoice_id'] && $t['purchase_status'] == 'posted'): ?>
                                                            <li><a class="dropdown-item py-2 small" href="invoices.php?reset_invoice=<?php echo $t['sales_invoice_id']; ?>&reset_type=all&return_to=passport_transactions.php" onclick="return confirm('إلغاء ترحيل الكل؟')"><i class="fas fa-sync me-2 text-danger"></i>إلغاء ترحيل الكل</a></li>
                                                            <li><hr class="dropdown-divider"></li>
                                                        <?php endif; ?>

                                                        <?php if ($t['sales_status'] == 'posted'): ?>
                                                            <li><a class="dropdown-item py-2 small" href="invoices.php?reset_invoice=<?php echo $t['sales_invoice_id']; ?>&reset_type=sales&return_to=passport_transactions.php" onclick="return confirm('إلغاء ترحيل فاتورة البيع؟')"><i class="fas fa-undo me-2 text-warning"></i>إلغاء ترحيل البيع</a></li>
                                                        <?php endif; ?>

                                                        <?php if ($t['purchase_invoice_id'] && $t['purchase_status'] == 'posted'): ?>
                                                            <li><a class="dropdown-item py-2 small" href="invoices.php?reset_invoice=<?php echo $t['purchase_invoice_id']; ?>&reset_type=purchase&return_to=passport_transactions.php" onclick="return confirm('إلغاء ترحيل فاتورة الشراء؟')"><i class="fas fa-history me-2 text-secondary"></i>إلغاء ترحيل الشراء</a></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (has_permission('passport_transactions_print')): ?>
                                                <a href="passport_transaction_print.php?id=<?php echo $t['id']; ?>" target="_blank" class="btn btn-sm btn-light rounded-circle shadow-sm" title="طباعة">
                                                    <i class="fas fa-print text-secondary"></i>
                                                </a>
                                            <?php endif; ?>

                                            <!-- حذف (قائمة منسدلة) - مطابقة لـ invoices.php -->
                                            <?php if (($t['sales_status'] != 'posted') || ($t['purchase_invoice_id'] && $t['purchase_status'] != 'posted')): ?>
                                                <div class="dropdown d-inline-block">
                                                    <button class="btn btn-sm btn-light rounded-circle shadow-sm" type="button" data-bs-toggle="dropdown" title="حذف">
                                                        <i class="fas fa-trash text-danger"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                                                        <li><h6 class="dropdown-header small fw-bold text-muted">حذف الفاتورة</h6></li>
                                                        <?php if ($t['sales_status'] != 'posted' && $t['purchase_invoice_id'] && $t['purchase_status'] != 'posted'): ?>
                                                            <li><a class="dropdown-item py-2 small text-danger" href="invoices.php?delete_invoice=<?php echo $t['sales_invoice_id']; ?>&delete_both=<?php echo $t['purchase_invoice_id']; ?>&return_to=passport_transactions.php" onclick="return confirm('حذف البيع والشراء معاً؟')"><i class="fas fa-trash-alt me-2"></i>حذف الكل</a></li>
                                                            <li><hr class="dropdown-divider"></li>
                                                        <?php endif; ?>

                                                        <?php if ($t['sales_status'] != 'posted'): ?>
                                                            <li><a class="dropdown-item py-2 small" href="invoices.php?delete_invoice=<?php echo $t['sales_invoice_id']; ?>&confirm_linked=1&return_to=passport_transactions.php" onclick="return confirm('حذف فاتورة البيع؟')"><i class="fas fa-trash me-2"></i>حذف البيع</a></li>
                                                        <?php endif; ?>

                                                        <?php if ($t['purchase_invoice_id'] && $t['purchase_status'] != 'posted'): ?>
                                                            <li><a class="dropdown-item py-2 small" href="invoices.php?delete_invoice=<?php echo $t['purchase_invoice_id']; ?>&confirm_linked=1&return_to=passport_transactions.php" onclick="return confirm('حذف فاتورة الشراء؟')"><i class="fas fa-trash me-2 text-warning"></i>حذف الشراء</a></li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Payment Modals -->
<?php foreach ($transactions as $t): ?>
    <?php if ($t['remaining_amount'] > 0 && $t['sales_invoice_id']): ?>
    <div class="modal fade" id="paymentModal<?php echo $t['sales_invoice_id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <div class="modal-header bg-success text-white py-3 rounded-top-4">
                    <h5 class="modal-title fw-bold">تسجيل دفعة مالية</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form action="process_passport_transaction.php" method="POST">
                    <input type="hidden" name="action" value="collect_payment">
                    <input type="hidden" name="invoice_id" value="<?php echo $t['sales_invoice_id']; ?>">
                    <input type="hidden" name="transaction_id" value="<?php echo $t['id']; ?>">
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted d-block mb-1">المبلغ المتبقي</label>
                            <div class="fs-4 fw-bold text-danger"><?php echo number_format($t['remaining_amount'], 2); ?> <?php echo $t['currency_symbol']; ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">المبلغ المدفوع</label>
                            <div class="input-group">
                                <input type="number" step="0.01" class="form-control fw-bold fs-5" name="amount" max="<?php echo $t['remaining_amount']; ?>" required>
                                <span class="input-group-text"><?php echo $t['currency_symbol']; ?></span>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">تاريخ الدفع</label>
                            <input type="date" class="form-control" name="payment_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">طريقة الدفع</label>
                            <select class="form-select select2-financial" name="payment_type" required>
                                <option value="cash">نقداً</option>
                                <option value="bank_transfer">تحويل بنكي</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">الحساب المالي</label>
                            <select class="form-select select2-financial" name="account_id" required>
                                <option value="">اختر الحساب...</option>
                                <?php
                                // جلب الصناديق والبنوك
                                $accounts = $pdo->query("SELECT id, account_name_ar FROM unified_accounts WHERE is_active = 1 AND (account_code LIKE '1111%' OR account_code LIKE '1112%')")->fetchAll();
                                foreach ($accounts as $acc) {
                                    echo '<option value="' . $acc['id'] . '">' . $acc['account_name_ar'] . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold">ملاحظات</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0 py-3 rounded-bottom-4">
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" class="btn btn-success rounded-pill px-4 shadow-sm">تسجيل الدفعة</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if ($.fn.select2) {
        $('.select2-financial').select2({
            width: '100%',
            dropdownAutoWidth: true
        });
    }
});
</script>

<?php require_once 'footer.php'; ?>
