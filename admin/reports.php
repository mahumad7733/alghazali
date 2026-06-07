<?php
require_once 'header.php';

// التحقق من الصلاحية
if (!$is_admin && !in_array($user_role, ['accountant', 'branch_manager', 'agent', 'branch_user'])) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// جلب الفروع والوكلاء والعملات للفلاتر
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL")->fetchAll();
$agents = $pdo->query("SELECT id, agent_name FROM agents WHERE deleted_at IS NULL")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name FROM currencies")->fetchAll();

$report_type = $_GET['type'] ?? 'transactions';
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');
$branch_id = $_GET['branch_id'] ?? '';
$agent_id = $_GET['agent_id'] ?? '';
$currency_id = $_GET['currency_id'] ?? '';

// بناء الاستعلام بناءً على نوع التقرير
$data = [];
$summary = [];

if ($report_type === 'transactions') {
    $where = "WHERE DATE(st.created_at) BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($branch_id) {
        $where .= " AND p.branch_id = ?";
        $params[] = $branch_id;
    }
    if ($agent_id) {
        $where .= " AND st.supplier_id = ?";
        $params[] = $agent_id;
    }
    if ($currency_id) {
        $where .= " AND st.currency_id = ?";
        $params[] = $currency_id;
    }

    $query = "
        SELECT st.*, c.currency_name, c.currency_symbol,
               CASE WHEN st.transaction_type = 'Passport' THEN (SELECT full_name FROM passports WHERE id = st.transaction_id)
                    WHEN st.transaction_type = 'تذاكر طيران وبصات' THEN (SELECT customer_name FROM bus_flight_bookings WHERE id = st.transaction_id)
                    ELSE 'غير معروف' END as customer_name,
               CASE WHEN st.transaction_type = 'Passport' THEN (SELECT passport_number FROM passports WHERE id = st.transaction_id)
                    ELSE '---' END as reference_number,
               (SELECT branch_name FROM branches b JOIN passports p ON b.id = p.branch_id WHERE p.id = st.transaction_id AND st.transaction_type = 'Passport' LIMIT 1) as branch_name,
               (SELECT agent_name FROM agents a WHERE a.id = st.supplier_id LIMIT 1) as agent_name
        FROM service_transactions st
        JOIN currencies c ON st.currency_id = c.id
        $where
        ORDER BY st.created_at DESC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    // خلاصة التقرير
    $stmt_sum = $pdo->prepare("
        SELECT SUM(total_sale) as total_sale, SUM(total_cost) as total_cost, SUM(total_sale - total_cost) as total_profit
        FROM service_transactions st
        $where
    ");
    $stmt_sum->execute($params);
    $summary = $stmt_sum->fetch();
} elseif ($report_type === 'receipts' || $report_type === 'payments') {
    $doc_type = ($report_type === 'receipts') ? 'Receipt_Voucher' : 'Payment_Voucher';
    $where = "WHERE d.document_type = ? AND d.document_date BETWEEN ? AND ?";
    $params = [$doc_type, $from_date, $to_date];

    if ($currency_id) {
        $where .= " AND d.currency_id = ?";
        $params[] = $currency_id;
    }

    $stmt = $pdo->prepare("
        SELECT d.*, c.currency_name, c.currency_symbol, u.username as creator_name,
               CASE
                   WHEN d.party_type = 'agent' THEN (SELECT agent_name FROM agents WHERE id = d.party_id)
                   WHEN d.party_type = 'branch' THEN (SELECT branch_name FROM branches WHERE id = d.party_id)
                   WHEN d.party_type = 'customer' THEN (SELECT full_name FROM customers WHERE id = d.party_id)
                   WHEN d.party_type = 'supplier' THEN (SELECT supplier_name FROM suppliers WHERE id = d.party_id)
                   WHEN d.party_type = 'expense' THEN (SELECT account_name_ar FROM unified_accounts WHERE id = d.party_id)
                   ELSE 'آخر' END as party_name
        FROM documents d
        LEFT JOIN currencies c ON d.currency_id = c.id
        LEFT JOIN users u ON d.created_by = u.id
        $where
        ORDER BY d.document_date DESC
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll();
} elseif ($report_type === 'expenses') {
    $where = "WHERE d.document_type = 'Payment_Voucher' AND d.party_type = 'expense' AND d.document_date BETWEEN ? AND ?";
    $params = [$from_date, $to_date];

    if ($currency_id) {
        $where .= " AND d.currency_id = ?";
        $params[] = $currency_id;
    }

    $stmt = $pdo->prepare("
        SELECT d.*, c.currency_name, c.currency_symbol, coa.account_name_ar as expense_name
        FROM documents d
        JOIN currencies c ON d.currency_id = c.id
        JOIN unified_accounts coa ON d.party_id = coa.id
        $where
        ORDER BY d.document_date DESC
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll();
}
?>

<div class="container-fluid px-4 py-4 no-print">
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-4">التقارير المالية (النظام الموحد)</h5>
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">نوع التقرير</label>
                    <select name="type" class="form-select border-0 bg-light" onchange="if(this.value=='attendance') location.href='attendance_report.php';">
                        <option value="transactions" <?php echo $report_type === 'transactions' ? 'selected' : ''; ?>>تقرير الخدمات الموحدة</option>
                        <option value="receipts" <?php echo $report_type === 'receipts' ? 'selected' : ''; ?>>تقرير سندات القبض</option>
                        <option value="payments" <?php echo $report_type === 'payments' ? 'selected' : ''; ?>>تقرير سندات الصرف</option>
                        <option value="expenses" <?php echo $report_type === 'expenses' ? 'selected' : ''; ?>>تقرير المصروفات</option>
                        <option value="attendance">تقرير سجل الدوام</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">من تاريخ</label>
                    <input type="date" name="from_date" class="form-control border-0 bg-light" value="<?php echo $from_date; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">إلى تاريخ</label>
                    <input type="date" name="to_date" class="form-control border-0 bg-light" value="<?php echo $to_date; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">العملة</label>
                    <select name="currency_id" class="form-select border-0 bg-light">
                        <option value="">كل العملات</option>
                        <?php foreach ($currencies as $c): ?>
                            <option value="<?php echo $c['id']; ?>" <?php echo $currency_id == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['currency_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill shadow-sm fw-bold">تحديث التقرير</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($report_type === 'transactions'): ?>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white border-start border-primary border-4">
                    <div class="small text-muted">إجمالي المبيعات</div>
                    <div class="h4 fw-bold mb-0 text-primary"><?php echo number_format($summary['total_sale'] ?? 0, 2); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white border-start border-danger border-4">
                    <div class="small text-muted">إجمالي التكلفة</div>
                    <div class="h4 fw-bold mb-0 text-danger"><?php echo number_format($summary['total_cost'] ?? 0, 2); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm rounded-4 p-3 bg-white border-start border-success border-4">
                    <div class="small text-muted">إجمالي الأرباح المتوقعة</div>
                    <div class="h4 fw-bold mb-0 text-success"><?php echo number_format($summary['total_profit'] ?? 0, 2); ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white border-0 pt-4 px-4 d-flex justify-content-between align-items-center">
            <span class="fw-bold"><i class="fas fa-list me-2"></i> نتائج التقرير</span>
            <button class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="window.print()">
                <i class="fas fa-print me-1"></i> طباعة التقرير
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <?php if ($report_type === 'transactions'): ?>
                            <tr>
                                <th class="px-4 py-3 border-0">التاريخ</th>
                                <th class="border-0">المسافر / الخدمة</th>
                                <th class="border-0">الفرع / المورد</th>
                                <th class="border-0 text-end">سعر البيع</th>
                                <th class="border-0 text-end">التكلفة</th>
                                <th class="border-0 text-end">الربح</th>
                                <th class="border-0 text-center">الحالة</th>
                            </tr>
                        <?php elseif ($report_type === 'receipts' || $report_type === 'payments'): ?>
                            <tr>
                                <th class="px-4 py-3 border-0">رقم السند</th>
                                <th class="border-0">التاريخ</th>
                                <th class="border-0"><?php echo $report_type === 'receipts' ? 'الدافع' : 'المستفيد'; ?></th>
                                <th class="border-0 text-end">المبلغ</th>
                                <th class="border-0 text-center">العملة</th>
                                <th class="border-0">بواسطة</th>
                            </tr>
                        <?php elseif ($report_type === 'expenses'): ?>
                            <tr>
                                <th class="px-4 py-3 border-0">رقم السند</th>
                                <th class="border-0">التاريخ</th>
                                <th class="border-0">حساب المصروف</th>
                                <th class="border-0 text-end">المبلغ</th>
                                <th class="border-0 text-center">العملة</th>
                                <th class="border-0">البيان</th>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php if (empty($data)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">لا توجد بيانات تطابق الفلاتر المختارة</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($data as $row): ?>
                                <?php if ($report_type === 'transactions'): ?>
                                    <tr>
                                        <td class="px-4 py-3 small"><?php echo date('Y-m-d', strtotime($row['created_at'])); ?></td>
                                        <td>
                                            <div class="fw-bold"><?php echo htmlspecialchars($row['customer_name']); ?></div>
                                            <small class="text-muted"><?php echo $row['transaction_type']; ?> #<?php echo $row['transaction_id']; ?></small>
                                        </td>
                                        <td class="small">
                                            <div><?php echo htmlspecialchars($row['branch_name'] ?: '---'); ?></div>
                                            <div class="text-muted"><?php echo htmlspecialchars($row['agent_name'] ?: '---'); ?></div>
                                        </td>
                                        <td class="text-end fw-bold text-primary"><?php echo number_format($row['total_sale'], 2); ?></td>
                                        <td class="text-end text-danger"><?php echo number_format($row['total_cost'], 2); ?></td>
                                        <td class="text-end text-success fw-bold"><?php echo number_format($row['total_sale'] - $row['total_cost'], 2); ?></td>
                                        <td class="text-center"><span class="badge bg-light text-dark rounded-pill"><?php echo $row['financial_status']; ?></span></td>
                                    </tr>
                                <?php elseif ($report_type === 'receipts' || $report_type === 'payments'): ?>
                                    <tr>
                                        <td class="px-4 py-3"><code><?php echo htmlspecialchars($row['document_number']); ?></code></td>
                                        <td><?php echo $row['document_date']; ?></td>
                                        <td><span class="fw-bold"><?php echo htmlspecialchars($row['party_name']); ?></span> <small class="text-muted">(<?php echo $row['party_type']; ?>)</small></td>
                                        <td class="text-end fw-bold <?php echo $report_type === 'receipts' ? 'text-success' : 'text-danger'; ?>"><?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td class="text-center small"><?php echo $row['currency_symbol']; ?></td>
                                        <td class="small"><?php echo htmlspecialchars($row['creator_name']); ?></td>
                                    </tr>
                                <?php elseif ($report_type === 'expenses'): ?>
                                    <tr>
                                        <td class="px-4 py-3"><code><?php echo htmlspecialchars($row['document_number']); ?></code></td>
                                        <td><?php echo $row['document_date']; ?></td>
                                        <td><span class="fw-bold"><?php echo htmlspecialchars($row['expense_name']); ?></span></td>
                                        <td class="text-end fw-bold text-danger"><?php echo number_format($row['total_amount'], 2); ?></td>
                                        <td class="text-center small"><?php echo $row['currency_symbol']; ?></td>
                                        <td class="small"><?php echo htmlspecialchars($row['description']); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
