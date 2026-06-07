<?php
require_once 'header.php';

// Check permissions
if (!has_permission('view_reports')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$service_type = $_GET['service_type'] ?? '';
$supplier_id = $_GET['supplier_id'] ?? '';
$status_id = $_GET['status_id'] ?? '';

// Build Query
$where = "WHERE b.booking_date BETWEEN ? AND ? AND b.deleted_at IS NULL";
$params = [$from_date, $to_date];

if ($service_type) {
    $where .= " AND b.service_type = ?";
    $params[] = $service_type;
}
if ($supplier_id) {
    $where .= " AND (b.agent_id = ? OR b.branch_id = ?)";
    $params[] = $supplier_id;
    $params[] = $supplier_id;
}
if ($status_id) {
    $where .= " AND b.status_id = ?";
    $params[] = $status_id;
}

$query = "
    SELECT 
        b.*, 
        inv.total_amount AS sale_price,
        inv.cost_amount AS purchase_price,
        inv.amount_received AS amount_received,
        (inv.total_amount - inv.amount_received) AS remaining_amount,
        (inv.total_amount - inv.cost_amount) AS profit,
        inv.currency_id AS currency_id,
        inv.delivery_type AS payment_type,
        c_from.city_name AS from_city_name, 
        c_to.city_name AS to_city_name,
        curr.currency_name,
        bs.status_name AS booking_status_name,
        bs.status_color AS booking_status_color,
        cust.full_name AS customer_full_name,
        ag.agent_name,
        br.branch_name
    FROM bus_flight_bookings b
    LEFT JOIN cities c_from ON b.from_city_id = c_from.id
    LEFT JOIN cities c_to ON b.to_city_id = c_to.id
    LEFT JOIN invoices inv ON inv.id = b.invoice_id
    LEFT JOIN currencies curr ON inv.currency_id = curr.id
    LEFT JOIN statuses bs ON b.status_id = bs.id
    LEFT JOIN customers cust ON b.customer_id = cust.id
    LEFT JOIN agents ag ON b.agent_id = ag.id
    LEFT JOIN branches br ON b.branch_id = br.id
    $where
    ORDER BY b.booking_date DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$report_data = $stmt->fetchAll();

// Totals
$total_sale = 0;
$total_purchase = 0;
$total_received = 0;
$total_profit = 0;
$total_count = count($report_data);

foreach ($report_data as $row) {
    $total_sale += $row['sale_price'];
    $total_purchase += $row['purchase_price'];
    $total_received += $row['amount_received'];
    $total_profit += $row['profit'];
}

$cities = $pdo->query("SELECT id, city_name FROM cities ORDER BY city_name ASC")->fetchAll();
$statuses = $pdo->query("SELECT id, status_name FROM statuses WHERE status_name IN ('حجز جديد', 'مؤكد', 'ملغي', 'معدل')")->fetchAll();
$agents = $pdo->query("SELECT id, agent_name FROM agents ORDER BY agent_name ASC")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC")->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary"><i class="fas fa-chart-pie me-2"></i> تقارير تذاكر طيران وبصات</h3>
        <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill px-4 no-print">
            <i class="fas fa-print me-1"></i> طباعة التقرير
        </button>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm border-0 rounded-4 mb-4 no-print">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label class="form-label small">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control" value="<?= $from_date ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control" value="<?= $to_date ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">نوع الخدمة</label>
                    <select name="service_type" class="form-select">
                        <option value="">الكل</option>
                        <option value="bus" <?= $service_type == 'bus' ? 'selected' : '' ?>>باص</option>
                        <option value="flight" <?= $service_type == 'flight' ? 'selected' : '' ?>>طيران</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">المورد</label>
                    <select name="supplier_id" class="form-select">
                        <option value="">الكل</option>
                        <optgroup label="الوكلاء">
                            <?php foreach($agents as $a): ?>
                                <option value="<?= $a['id'] ?>" <?= $supplier_id == $a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['agent_name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <optgroup label="الفروع">
                            <?php foreach($branches as $b): ?>
                                <option value="<?= $b['id'] ?>" <?= $supplier_id == $b['id'] ? 'selected' : '' ?>><?= htmlspecialchars($b['branch_name']) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">الحالة</label>
                    <select name="status_id" class="form-select">
                        <option value="">الكل</option>
                        <?php foreach($statuses as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $status_id == $s['id'] ? 'selected' : '' ?>><?= htmlspecialchars($s['status_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 rounded-3"><i class="fas fa-search"></i></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stats Summary -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 bg-primary text-white">
                <div class="card-body p-4">
                    <div class="small opacity-75 mb-1">إجمالي المبيعات</div>
                    <h3 class="fw-bold mb-0"><?= number_format($total_sale, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 bg-success text-white">
                <div class="card-body p-4">
                    <div class="small opacity-75 mb-1">إجمالي الأرباح</div>
                    <h3 class="fw-bold mb-0"><?= number_format($total_profit, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 bg-info text-white">
                <div class="card-body p-4">
                    <div class="small opacity-75 mb-1">إجمالي المحصل</div>
                    <h3 class="fw-bold mb-0"><?= number_format($total_received, 2) ?></h3>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 bg-warning text-white">
                <div class="card-body p-4">
                    <div class="small opacity-75 mb-1">عدد الحجوزات</div>
                    <h3 class="fw-bold mb-0"><?= $total_count ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- Report Table -->
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead class="bg-light">
                        <tr>
                            <th class="py-3">رقم الحجز</th>
                            <th>التاريخ</th>
                            <th>المسافر</th>
                            <th>الرحلة</th>
                            <th>المورد</th>
                            <th>سعر البيع</th>
                            <th>سعر الشراء</th>
                            <th>الربح</th>
                            <th>المحصل</th>
                            <th>الحالة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($report_data as $row): ?>
                        <tr>
                            <td class="fw-bold"><?= $row['booking_number'] ?></td>
                            <td class="small"><?= $row['booking_date'] ?></td>
                            <td class="text-end px-3">
                                <div class="fw-bold"><?= htmlspecialchars($row['traveler_name']) ?></div>
                                <div class="extra-small text-muted"><?= $row['service_type'] == 'bus' ? 'باص' : 'طيران' ?></div>
                            </td>
                            <td class="small">
                                <?= htmlspecialchars($row['from_city_name']) ?> <i class="fas fa-arrow-left mx-1 text-muted"></i> <?= htmlspecialchars($row['to_city_name']) ?>
                            </td>
                            <td class="small"><?= htmlspecialchars($row['agent_name'] ?: $row['branch_name']) ?></td>
                            <td class="fw-bold"><?= number_format($row['sale_price'], 2) ?></td>
                            <td class="text-muted small"><?= number_format($row['purchase_price'], 2) ?></td>
                            <td class="text-success fw-bold"><?= number_format($row['profit'], 2) ?></td>
                            <td class="text-primary"><?= number_format($row['amount_received'], 2) ?></td>
                            <td>
                                <span class="badge bg-<?= $row['booking_status_color'] ?> rounded-pill px-3"><?= $row['booking_status_name'] ?></span>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($report_data)): ?>
                        <tr><td colspan="10" class="py-5 text-muted">لا توجد بيانات لهذا البحث</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    @media print {
        .no-print { display: none !important; }
        .container-fluid { width: 100%; padding: 0 !important; }
        .card { box-shadow: none !important; border: 1px solid #eee !important; }
        .bg-primary, .bg-success, .bg-info, .bg-warning { 
            background-color: #fff !important; 
            color: #000 !important; 
            border: 1px solid #ddd !important;
        }
        .bg-primary .opacity-75, .bg-success .opacity-75, .bg-info .opacity-75, .bg-warning .opacity-75 {
            color: #666 !important;
        }
    }
</style>

<?php require_once 'footer.php'; ?>
