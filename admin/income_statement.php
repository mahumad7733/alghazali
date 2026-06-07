<?php
require_once 'header.php';

// التحقق من الصلاحية
if (!has_permission('financial_reports_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-01-01');
$end_date = $_GET['end_date'] ?? date('Y-12-31');

// جلب العملة الأساسية
$stmt_base = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies WHERE is_default = 1 LIMIT 1");
$base_currency = $stmt_base->fetch();

// 1. استعلام الإيرادات (Accounts starting with 4) من النظام الموحد
$query_rev = "
    SELECT coa.account_name_ar as account_name, 
           SUM(jl.credit * ft.exchange_rate) - SUM(jl.debit * ft.exchange_rate) as amount
    FROM unified_accounts coa
    LEFT JOIN journal_lines jl ON coa.id = jl.account_id
    LEFT JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
    AND ft.transaction_date BETWEEN ? AND ?
    AND ft.status = 'posted'
    WHERE coa.account_code LIKE '4%' 
    AND coa.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    GROUP BY coa.id
    HAVING amount != 0
";
$stmt_rev = $pdo->prepare($query_rev);
$stmt_rev->execute([$start_date, $end_date]);
$revenues = $stmt_rev->fetchAll();

// 2. استعلام المصاريف والتكاليف (Accounts starting with 5) من النظام الموحد
$query_exp = "
    SELECT coa.account_name_ar as account_name, 
           SUM(jl.debit * ft.exchange_rate) - SUM(jl.credit * ft.exchange_rate) as amount
    FROM unified_accounts coa
    LEFT JOIN journal_lines jl ON coa.id = jl.account_id
    LEFT JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
    AND ft.transaction_date BETWEEN ? AND ?
    AND ft.status = 'posted'
    WHERE coa.account_code LIKE '5%' 
    AND coa.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    GROUP BY coa.id
    HAVING amount != 0
";
$stmt_exp = $pdo->prepare($query_exp);
$stmt_exp->execute([$start_date, $end_date]);
$expenses = $stmt_exp->fetchAll();

$total_revenue = array_sum(array_column($revenues, 'amount'));
$total_expense = array_sum(array_column($expenses, 'amount'));
$net_profit = $total_revenue - $total_expense;

?>

<div class="container-fluid">
    <div class="card shadow-sm border-0 rounded-3 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold"><i class="fas fa-chart-line text-success me-2"></i> قائمة الدخل (Income Statement)</h5>
            <div class="text-muted small">العملة الأساسية: <?php echo $base_currency['currency_name']; ?></div>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3 mb-5 no-print">
                <div class="col-md-3">
                    <label class="form-label">من تاريخ</label>
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">إلى تاريخ</label>
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-1"></i> تصفية</button>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="button" onclick="window.print()" class="btn btn-outline-secondary w-100"><i class="fas fa-print me-1"></i> طباعة</button>
                </div>
            </form>

            <div class="row justify-content-center">
                <div class="col-md-10">
                    <h4 class="text-center mb-4 fw-bold">بيان الربح والخسارة</h4>
                    <p class="text-center text-muted mb-5">للفترة من <?php echo $start_date; ?> إلى <?php echo $end_date; ?></p>
                    
                    <!-- الإيرادات -->
                    <table class="table table-borderless align-middle mb-4">
                        <thead class="border-bottom">
                            <tr>
                                <th class="text-primary fw-bold fs-5" colspan="2">الإيرادات التشغيلية والخدمية</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($revenues as $rev): ?>
                            <tr>
                                <td class="ps-4"><?php echo htmlspecialchars($rev['account_name']); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($rev['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="border-top bg-light">
                            <tr class="fw-bold">
                                <td class="ps-2 fs-5">إجمالي الإيرادات</td>
                                <td class="text-end fs-5 text-success"><?php echo number_format($total_revenue, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>

                    <!-- المصاريف -->
                    <table class="table table-borderless align-middle mb-5">
                        <thead class="border-bottom">
                            <tr>
                                <th class="text-danger fw-bold fs-5" colspan="2">المصروفات والتكاليف</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($expenses as $exp): ?>
                            <tr>
                                <td class="ps-4"><?php echo htmlspecialchars($exp['account_name']); ?></td>
                                <td class="text-end fw-bold">(<?php echo number_format($exp['amount'], 2); ?>)</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="border-top bg-light">
                            <tr class="fw-bold">
                                <td class="ps-2 fs-5">إجمالي المصروفات</td>
                                <td class="text-end fs-5 text-danger"><?php echo number_format($total_expense, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>

                    <!-- صافي الربح -->
                    <div class="card bg-<?php echo $net_profit >= 0 ? 'success' : 'danger'; ?> text-white border-0 shadow-sm p-4 rounded-4">
                        <div class="d-flex justify-content-between align-items-center">
                            <h3 class="mb-0 fw-bold"><?php echo $net_profit >= 0 ? 'صافي الربح (Net Profit)' : 'صافي الخسارة (Net Loss)'; ?></h3>
                            <h2 class="mb-0 fw-bold"><?php echo number_format($net_profit, 2); ?> <?php echo $base_currency['currency_symbol']; ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
