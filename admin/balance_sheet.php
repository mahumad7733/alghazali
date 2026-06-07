<?php
$page_title = "الميزانية العمومية";
require_once 'header.php';

// حساب الأصول (1)
$assets_stmt = $pdo->query("
    SELECT coa.account_code, coa.account_name_ar, 
           (SELECT SUM(current_balance) FROM account_balances_unified WHERE account_id = coa.id) as current_balance, 
           coa.normal_balance
    FROM unified_accounts coa
    WHERE coa.account_code LIKE '1%' 
    AND coa.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    ORDER BY coa.account_code ASC
");
$assets = $assets_stmt->fetchAll();

// حساب الخصوم (2)
$liabilities_stmt = $pdo->query("
    SELECT coa.account_code, coa.account_name_ar, 
           (SELECT SUM(current_balance) FROM account_balances_unified WHERE account_id = coa.id) as current_balance, 
           coa.normal_balance
    FROM unified_accounts coa
    WHERE coa.account_code LIKE '2%' 
    AND coa.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    ORDER BY coa.account_code ASC
");
$liabilities = $liabilities_stmt->fetchAll();

// حساب حقوق الملكية (3)
$equity_stmt = $pdo->query("
    SELECT coa.account_code, coa.account_name_ar, 
           (SELECT SUM(current_balance) FROM account_balances_unified WHERE account_id = coa.id) as current_balance, 
           coa.normal_balance
    FROM unified_accounts coa
    WHERE coa.account_code LIKE '3%' 
    AND coa.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
    ORDER BY coa.account_code ASC
");
$equity = $equity_stmt->fetchAll();

$total_assets = 0;
$total_liabilities = 0;
$total_equity = 0;
?>

<div class="content-body">
    <div class="row">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-success text-white py-3 border-0">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-plus-circle me-2"></i> الأصول (Assets)</h5>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead class="bg-light small">
                            <tr>
                                <th>الكود</th>
                                <th>الحساب</th>
                                <th class="text-end">الرصيد (YER)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($assets as $row): 
                                $bal = ($row['normal_balance'] == 'debit') ? $row['current_balance'] : -$row['current_balance'];
                                $total_assets += $bal;
                            ?>
                            <tr class="small">
                                <td><?php echo $row['account_code']; ?></td>
                                <td><?php echo htmlspecialchars($row['account_name_ar']); ?></td>
                                <td class="text-end fw-bold"><?php echo number_format($bal, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-light fw-bold">
                            <tr>
                                <td colspan="2">إجمالي الأصول</td>
                                <td class="text-end text-success"><?php echo number_format($total_assets, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-danger text-white py-3 border-0">
                    <h5 class="mb-0 fw-bold"><i class="fas fa-minus-circle me-2"></i> الخصوم وحقوق الملكية</h5>
                </div>
                <div class="card-body p-0">
                    <h6 class="p-3 bg-light border-bottom mb-0 fw-bold small text-muted text-uppercase">الخصوم (Liabilities)</h6>
                    <table class="table table-hover mb-0">
                        <tbody>
                            <?php foreach($liabilities as $row): 
                                $bal = ($row['normal_balance'] == 'credit') ? $row['current_balance'] : -$row['current_balance'];
                                $total_liabilities += $bal;
                            ?>
                            <tr class="small">
                                <td width="20%"><?php echo $row['account_code']; ?></td>
                                <td><?php echo htmlspecialchars($row['account_name_ar']); ?></td>
                                <td class="text-end fw-bold" width="30%"><?php echo number_format($bal, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <h6 class="p-3 bg-light border-bottom mb-0 fw-bold small text-muted text-uppercase">حقوق الملكية (Equity)</h6>
                    <table class="table table-hover mb-0">
                        <tbody>
                            <?php foreach($equity as $row): 
                                $bal = ($row['normal_balance'] == 'credit') ? $row['current_balance'] : -$row['current_balance'];
                                $total_equity += $bal;
                            ?>
                            <tr class="small">
                                <td width="20%"><?php echo $row['account_code']; ?></td>
                                <td><?php echo htmlspecialchars($row['account_name_ar']); ?></td>
                                <td class="text-end fw-bold" width="30%"><?php echo number_format($bal, 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="bg-light fw-bold">
                            <tr>
                                <td colspan="2">إجمالي الخصوم وحقوق الملكية</td>
                                <td class="text-end text-danger"><?php echo number_format($total_liabilities + $total_equity, 2); ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4 <?php echo (round($total_assets, 2) == round($total_liabilities + $total_equity, 2)) ? 'bg-success' : 'bg-danger'; ?> text-white">
                <div class="card-body py-3 d-flex justify-content-between align-items-center">
                    <h5 class="mb-0 fw-bold">الفرق (Balance):</h5>
                    <h4 class="mb-0 fw-bold"><?php echo number_format($total_assets - ($total_liabilities + $total_equity), 2); ?> YER</h4>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
