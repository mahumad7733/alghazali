<?php
$page_title = "حركات الحسابات";
require_once 'header.php';

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$currency_id = $_GET['currency_id'] ?? 1; // الافتراضي ريال يمني

$stmt = $pdo->prepare("
    SELECT
        ua.id, ua.account_code, ua.account_name_ar,
        (SELECT SUM(jl.debit) FROM journal_lines jl
            JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
            WHERE jl.account_id = ua.id
              AND jl.currency_id = ?
              AND ft.transaction_date BETWEEN ? AND ?
              AND ft.status = 'posted') as total_debit,
        (SELECT SUM(jl.credit) FROM journal_lines jl
            JOIN financial_transactions ft ON jl.financial_transaction_id = ft.id
            WHERE jl.account_id = ua.id
              AND jl.currency_id = ?
              AND ft.transaction_date BETWEEN ? AND ?
              AND ft.status = 'posted') as total_credit
    FROM unified_accounts ua
    WHERE ua.is_active = 1
      AND ua.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    HAVING total_debit > 0 OR total_credit > 0
    ORDER BY ua.account_code ASC
");
$stmt->execute([$currency_id, $date_from, $date_to, $currency_id, $date_from, $date_to]);
$movements = $stmt->fetchAll();

$currencies = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies")->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="card shadow-sm mb-4 d-print-none">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0"><i class="fas fa-random me-2 text-primary"></i>فلترة حركات الحسابات</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-bold">العملة</label>
                    <select name="currency_id" class="form-select" required>
                        <?php foreach ($currencies as $curr): ?>
                            <option value="<?php echo $curr['id']; ?>" <?php echo $currency_id == $curr['id'] ? 'selected' : ''; ?>><?php echo $curr['currency_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">من تاريخ</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold">إلى تاريخ</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-1"></i>عرض التقرير
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm overflow-hidden">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="mb-0 fw-bold">حركات الحسابات خلال الفترة</h5>
            <div class="text-end">
                <div class="small text-muted">من <?php echo $date_from; ?> إلى <?php echo $date_to; ?></div>
                <button onclick="window.print()" class="btn btn-sm btn-outline-secondary d-print-none mt-1">
                    <i class="fas fa-print me-1"></i>طباعة
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0 text-center">
                <thead class="bg-light">
                    <tr>
                        <th width="120">كود الحساب</th>
                        <th class="text-start">اسم الحساب</th>
                        <th width="150">إجمالي مدين (عليه)</th>
                        <th width="150">إجمالي دائن (له)</th>
                        <th width="150">صافي الحركة</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $grand_debit = 0;
                    $grand_credit = 0;
                    foreach ($movements as $m):
                        $debit = (float)$m['total_debit'];
                        $credit = (float)$m['total_credit'];
                        $net = $debit - $credit;
                        $grand_debit += $debit;
                        $grand_credit += $credit;
                    ?>
                        <tr>
                            <td class="extra-small text-muted"><?php echo $m['account_code']; ?></td>
                            <td class="text-start fw-bold"><?php echo htmlspecialchars($m['account_name_ar']); ?></td>
                            <td class="text-end text-success"><?php echo $debit > 0 ? number_format($debit, 2) : '-'; ?></td>
                            <td class="text-end text-danger"><?php echo $credit > 0 ? number_format($credit, 2) : '-'; ?></td>
                            <td class="text-end fw-bold <?php echo $net >= 0 ? 'text-primary' : 'text-danger'; ?>">
                                <?php echo number_format($net, 2); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($movements)): ?>
                        <tr>
                            <td colspan="5" class="py-4">لا توجد حركات في هذه الفترة</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot class="bg-light fw-bold">
                    <tr>
                        <td colspan="2" class="text-center">الإجمالي العام للحركة</td>
                        <td class="text-end text-success"><?php echo number_format($grand_debit, 2); ?></td>
                        <td class="text-end text-danger"><?php echo number_format($grand_credit, 2); ?></td>
                        <td class="text-end <?php echo ($grand_debit - $grand_credit) >= 0 ? 'text-primary' : 'text-danger'; ?>">
                            <?php echo number_format($grand_debit - $grand_credit, 2); ?>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
