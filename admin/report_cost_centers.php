<?php
require_once 'header.php';

// التحقق من الصلاحية
if (!$is_admin && !has_permission('view_cost_center_reports')) {
    die("غير مصرح لك بالوصول لهذه الصفحة.");
}

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$cost_center_id = $_GET['cost_center_id'] ?? 'all';

// جلب قائمة مراكز التكلفة للفلاتر
$centers = $pdo->query("SELECT * FROM cost_centers ORDER BY center_code")->fetchAll();

// استعلام التقرير الموحد لمراكز التكلفة
$where = "WHERE ft.status = 'posted' AND ft.transaction_date BETWEEN ? AND ?";
$params = [$date_from, $date_to];

if ($cost_center_id != 'all') {
    $where .= " AND jl.cost_center_id = ?";
    $params[] = $cost_center_id;
}

$query = "
    SELECT 
        cc.center_name_ar as center_name,
        cc.center_code,
        ua.account_name_ar as account_name,
        ua.account_type,
        SUM(jl.debit) as total_debit,
        SUM(jl.credit) as total_credit,
        (SUM(CASE WHEN ua.account_type IN ('income', 'equity', 'liability') THEN jl.credit - jl.debit ELSE jl.debit - jl.credit END)) as net_balance
    FROM journal_lines jl
    JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
    JOIN cost_centers cc ON jl.cost_center_id = cc.id
    JOIN unified_accounts ua ON jl.account_id = ua.id
    $where
    GROUP BY cc.id, ua.id
    ORDER BY cc.center_code, ua.account_type
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$report_data = $stmt->fetchAll();

// تجميع البيانات حسب المركز
$grouped_data = [];
foreach ($report_data as $row) {
    $grouped_data[$row['center_code']]['name'] = $row['center_name'];
    $grouped_data[$row['center_code']]['items'][] = $row;
    if (!isset($grouped_data[$row['center_code']]['total_income'])) $grouped_data[$row['center_code']]['total_income'] = 0;
    if (!isset($grouped_data[$row['center_code']]['total_expense'])) $grouped_data[$row['center_code']]['total_expense'] = 0;
    
    if ($row['account_type'] == 'income') $grouped_data[$row['center_code']]['total_income'] += $row['net_balance'];
    if ($row['account_type'] == 'expense') $grouped_data[$row['center_code']]['total_expense'] += $row['net_balance'];
}
?>

<div class="container-fluid py-4 text-end" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold"><i class="fas fa-chart-pie me-2 text-primary"></i> تقرير تحليل مراكز التكلفة</h2>
        <div class="d-flex gap-2">
            <button class="btn btn-outline-secondary rounded-pill px-3" onclick="window.print()">
                <i class="fas fa-print me-1"></i> طباعة
            </button>
        </div>
    </div>

    <!-- فلاتر البحث -->
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">من تاريخ</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">إلى تاريخ</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">مركز التكلفة</label>
                    <select name="cost_center_id" class="form-select">
                        <option value="all">كل المراكز</option>
                        <?php foreach ($centers as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $cost_center_id == $c['id'] ? 'selected' : ''; ?>>
                                <?php echo $c['center_code'] . ' - ' . $c['center_name_ar']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill">
                        <i class="fas fa-filter me-1"></i> عرض التقرير
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($grouped_data)): ?>
        <div class="alert alert-info border-0 shadow-sm rounded-4 text-center py-5">
            <i class="fas fa-search fa-3x mb-3 opacity-50"></i>
            <p class="mb-0">لا توجد بيانات لهذه الفترة أو لمركز التكلفة المختار</p>
        </div>
    <?php else: ?>
        <?php foreach ($grouped_data as $code => $data): ?>
            <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
                <div class="card-header bg-light py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold text-primary">
                        <i class="fas fa-folder me-2"></i> <?php echo $code . ' - ' . htmlspecialchars($data['name']); ?>
                    </h5>
                    <div class="d-flex gap-3">
                        <div class="text-success small">إجمالي الإيرادات: <strong><?php echo number_format($data['total_income'], 2); ?></strong></div>
                        <div class="text-danger small">إجمالي المصاريف: <strong><?php echo number_format($data['total_expense'], 2); ?></strong></div>
                        <div class="text-dark small border-start ps-3">صافي الربح: <strong class="<?php echo ($data['total_income'] - $data['total_expense'] >= 0) ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($data['total_income'] - $data['total_expense'], 2); ?></strong></div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4">الحساب المحاسبي</th>
                                <th>نوع الحساب</th>
                                <th class="text-center">مدين</th>
                                <th class="text-center">دائن</th>
                                <th class="text-end pe-4">الصافي</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['items'] as $item): ?>
                                <tr>
                                    <td class="ps-4"><?php echo htmlspecialchars($item['account_name']); ?></td>
                                    <td>
                                        <span class="badge bg-opacity-10 <?php echo $item['account_type'] == 'income' ? 'bg-success text-success' : 'bg-danger text-danger'; ?>">
                                            <?php echo $item['account_type'] == 'income' ? 'إيراد' : 'مصروف'; ?>
                                        </span>
                                    </td>
                                    <td class="text-center"><?php echo number_format($item['total_debit'], 2); ?></td>
                                    <td class="text-center"><?php echo number_format($item['total_credit'], 2); ?></td>
                                    <td class="text-end pe-4 fw-bold"><?php echo number_format($item['net_balance'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
