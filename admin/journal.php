<?php
$page_title = "اليومية العامة";
require_once 'header.php';

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$branch_id = $_GET['branch_id'] ?? '';
$user_id = $_GET['user_id'] ?? '';

$where = "WHERE ft.transaction_date BETWEEN ? AND ? AND ft.status = 'posted'";
$params = [$date_from, $date_to];

if ($branch_id) {
    $where .= " AND ft.branch_id = ?";
    $params[] = $branch_id;
}
if ($user_id) {
    $where .= " AND ft.created_by = ?";
    $params[] = $user_id;
}

$stmt = $pdo->prepare("
    SELECT ft.id, ft.transaction_number as entry_number, ft.transaction_date as entry_date, ft.description,
           jl.debit as debit_amount, jl.credit as credit_amount,
           ua.account_name_ar as account_name, ua.account_code,
           CASE 
               WHEN ua.account_code LIKE '11101%' THEN '11101'
               WHEN ua.account_code LIKE '11102%' THEN '11102'
               WHEN ua.account_code LIKE '11201%' THEN '11201'
               WHEN ua.account_code LIKE '11202%' THEN '11202'
               WHEN ua.account_code LIKE '11203%' THEN '11203'
               WHEN ua.account_code LIKE '113%' THEN '113'
               WHEN ua.account_code LIKE '21101%' THEN '21101'
               WHEN ua.account_code LIKE '21102%' THEN '21102'
               WHEN ua.account_code LIKE '21103%' THEN '21103'
               WHEN ua.account_code LIKE '21104%' THEN '21104'
               WHEN ua.account_code LIKE '21105%' THEN '21105'
               WHEN ua.account_code LIKE '40101%' THEN '40101'
               WHEN ua.account_code LIKE '50101%' THEN '50101'
               WHEN ua.account_code LIKE '502%' THEN '502'
               ELSE ua.account_code
           END as display_account_code,
           CASE 
               WHEN ua.account_code LIKE '11101%' THEN 'الصناديق'
               WHEN ua.account_code LIKE '11102%' THEN 'البنوك'
               WHEN ua.account_code LIKE '11201%' THEN 'العملاء'
               WHEN ua.account_code LIKE '11202%' THEN 'حسابات الفروع'
               WHEN ua.account_code LIKE '11203%' THEN 'الوكلاء'
               WHEN ua.account_code LIKE '113%' THEN 'سلف وعهد الموظفين'
               WHEN ua.account_code LIKE '21101%' THEN 'الموردين'
               WHEN ua.account_code LIKE '21102%' THEN 'دفعات مقدمة من العملاء'
               WHEN ua.account_code LIKE '21103%' THEN 'مستحقات الموظفين'
               WHEN ua.account_code LIKE '21104%' THEN 'ضريبة القيمة المضافة'
               WHEN ua.account_code LIKE '21105%' THEN 'المصروفات المستحقة'
               WHEN ua.account_code LIKE '40101%' THEN 'إيرادات الخدمات'
               WHEN ua.account_code LIKE '50101%' THEN 'تكاليف الخدمات'
               WHEN ua.account_code LIKE '502%' THEN 'المصروفات الوظيفية'
               ELSE ua.account_name_ar
           END as display_account_name,
           ft.currency_id, c.currency_symbol, u.username as creator_name
    FROM financial_transactions ft
    JOIN journal_lines jl ON ft.id = jl.financial_transaction_id
    JOIN unified_accounts ua ON jl.account_id = ua.id
    LEFT JOIN currencies c ON ft.currency_id = c.id
    LEFT JOIN users u ON ft.created_by = u.id
    $where
    ORDER BY ft.transaction_date DESC, ft.id DESC, jl.id ASC
");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// جلب الفروع والمستخدمين للفلترة
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL")->fetchAll();
$users = $pdo->query("SELECT id, username FROM users WHERE status = 'active'")->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-book me-2 text-primary"></i>فلترة اليومية العامة</h5>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm d-print-none">
                <i class="fas fa-print me-1"></i>طباعة التقرير
            </button>
        </div>
        <div class="card-body d-print-none">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">من تاريخ</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">إلى تاريخ</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">الفرع</label>
                    <select name="branch_id" class="form-select">
                        <option value="">كل الفروع</option>
                        <?php foreach($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $branch_id == $b['id'] ? 'selected' : ''; ?>><?php echo $b['branch_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-bold">المستخدم</label>
                    <select name="user_id" class="form-select">
                        <option value="">كل المستخدمين</option>
                        <?php foreach($users as $u): ?>
                            <option value="<?php echo $u['id']; ?>" <?php echo $user_id == $u['id'] ? 'selected' : ''; ?>><?php echo $u['username']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i>عرض
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>التاريخ / رقم القيد</th>
                        <th>البيان / الحساب</th>
                        <th class="text-end">مدين</th>
                        <th class="text-end">دائن</th>
                        <th>العملة</th>
                        <th>المستخدم</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($transactions)): ?>
                        <tr><td colspan="6" class="text-center py-4">لا توجد قيود يومية في هذه الفترة</td></tr>
                    <?php else: ?>
                        <?php 
                        $current_entry_id = null;
                        foreach($transactions as $t): 
                            $is_new_entry = ($t['id'] !== $current_entry_id);
                            if ($is_new_entry) $current_entry_id = $t['id'];
                        ?>
                            <tr style="<?php echo $is_new_entry ? 'border-top: 2px solid #dee2e6;' : ''; ?>">
                                <td>
                                    <?php if($is_new_entry): ?>
                                        <div class="fw-bold"><?php echo $t['entry_date']; ?></div>
                                        <div class="small text-muted"><?php echo $t['entry_number']; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($is_new_entry): ?>
                                        <div class="small fw-bold text-dark mb-1"><?php echo htmlspecialchars($t['description']); ?></div>
                                    <?php endif; ?>
                                    <div class="ps-3">
                                        <?php 
                                            $acc_style = '';
                                            $acc_label = '';
                                            if (strpos($t['account_code'], '112') === 0) {
                                                $acc_style = 'color: #007aff; font-weight: bold;'; // أزرق للعملاء/المدينين
                                                $acc_label = '<span class="badge bg-primary-subtle text-primary extra-small me-1">مدينون</span>';
                                            } elseif (strpos($t['account_code'], '211') === 0) {
                                                $acc_style = 'color: #ff3b30; font-weight: bold;'; // أحمر للموردين/الدائنين
                                                $acc_label = '<span class="badge bg-danger-subtle text-danger extra-small me-1">دائنون</span>';
                                            }
                                        ?>
                                        <?php echo $acc_label; ?>
                                        <span class="small" style="<?php echo $acc_style; ?>"><?php echo htmlspecialchars($t['display_account_name']); ?></span>
                                        <span class="extra-small text-muted">(<?php echo $t['display_account_code']; ?>)</span>
                                    </div>
                                </td>
                                <td class="text-end fw-bold text-success">
                                    <?php echo $t['debit_amount'] > 0 ? number_format($t['debit_amount'], 2) : '-'; ?>
                                </td>
                                <td class="text-end fw-bold text-danger">
                                    <?php echo $t['credit_amount'] > 0 ? number_format($t['credit_amount'], 2) : '-'; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark"><?php echo $t['currency_symbol']; ?></span>
                                </td>
                                <td>
                                    <?php if($is_new_entry): ?>
                                        <div class="small"><?php echo htmlspecialchars($t['creator_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
