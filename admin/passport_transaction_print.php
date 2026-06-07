<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

if (!isset($_GET['id'])) {
    die("معرف المعاملة غير موجود.");
}

$id = (int)$_GET['id'];

// Fetch transaction details
$stmt = $pdo->prepare("
    SELECT pt.*, 
           inv.total_amount AS sale_price,
           inv.cost_amount AS purchase_price,
           inv.amount_received AS amount_received,
           (inv.total_amount - inv.amount_received) AS remaining_amount,
           (inv.total_amount - inv.cost_amount) AS profit,
           inv.currency_id AS currency_id,
           inv.delivery_type AS payment_type,
           s.status_name,
           c.currency_name, c.currency_symbol,
           b.branch_name, b.address as branch_address, b.phone as branch_phone,
           u.full_name as created_by_name,
           fc.city_name as from_city_name,
           tc.city_name as to_city_name,
           ptt.type_name as transaction_type_name,
           ptt.print_terms as print_terms
    FROM passport_transactions pt
    LEFT JOIN invoices inv
        ON inv.source_type = 'passport_transaction'
       AND inv.source_id = pt.id
       AND inv.invoice_category = 'sales'
    LEFT JOIN statuses s ON pt.status_id = s.id
    LEFT JOIN currencies c ON inv.currency_id = c.id
    LEFT JOIN branches b ON pt.branch_id = b.id
    LEFT JOIN users u ON pt.created_by = u.id
    LEFT JOIN cities fc ON pt.from_city_id = fc.id
    LEFT JOIN cities tc ON pt.to_city_id = tc.id
    LEFT JOIN passport_transaction_types ptt ON pt.transaction_type_id = ptt.id
    WHERE pt.id = ?
");
$stmt->execute([$id]);
$trx = $stmt->fetch();

if (!$trx) {
    die("المعاملة غير موجودة.");
}

$settings = getSettings($pdo);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة معاملة - <?php echo $trx['transaction_number']; ?></title>
    <style>
        body { font-family: 'Arial', sans-serif; margin: 0; padding: 20px; color: #333; }
        .receipt-container { max-width: 800px; margin: auto; border: 1px solid #eee; padding: 30px; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #333; padding-bottom: 20px; margin-bottom: 30px; }
        .logo { font-size: 24px; font-weight: bold; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .info-item { margin-bottom: 10px; }
        .label { font-weight: bold; color: #666; font-size: 14px; }
        .value { font-size: 16px; margin-top: 5px; }
        .details-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .details-table th, .details-table td { border: 1px solid #ddd; padding: 12px; text-align: right; }
        .details-table th { background-color: #f8f9fa; }
        .footer { margin-top: 50px; display: flex; justify-content: space-between; border-top: 1px solid #eee; padding-top: 20px; }
        .total-box { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-top: 20px; }
        .total-row { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .total-row.grand { font-size: 20px; font-weight: bold; border-top: 1px solid #ccc; padding-top: 10px; margin-top: 10px; }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
            .receipt-container { border: none; }
        }
    </style>
</head>
<body>
    <div class="no-print" style="text-align: center; margin-bottom: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; cursor: pointer; background: #007bff; color: white; border: none; border-radius: 5px;">طباعة الآن</button>
        <button onclick="window.close()" style="padding: 10px 20px; cursor: pointer; background: #6c757d; color: white; border: none; border-radius: 5px;">إغلاق</button>
    </div>

    <div class="receipt-container">
        <div class="header">
            <div class="company-info">
                <div class="logo"><?php echo htmlspecialchars($settings['company_name'] ?? 'الغزالي للسفريات'); ?></div>
                <div style="font-size: 14px; color: #666; margin-top: 5px;"><?php echo htmlspecialchars($trx['branch_name']); ?></div>
            </div>
            <div class="transaction-info" style="text-align: left;">
                <div style="font-size: 20px; font-weight: bold;">سند معاملة جوازات</div>
                <div style="margin-top: 5px;">رقم: <?php echo $trx['transaction_number']; ?></div>
                <div style="margin-top: 5px;">التاريخ: <?php echo $trx['operation_date'] ?: date('Y-m-d', strtotime($trx['created_at'])); ?></div>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <div class="label">اسم المسافر</div>
                <div class="value"><?php echo htmlspecialchars($trx['full_name']); ?></div>
            </div>
            <div class="info-item">
                <div class="label">رقم الهاتف</div>
                <div class="value"><?php echo htmlspecialchars($trx['phone_number'] ?: '-'); ?></div>
            </div>
            <div class="info-item">
                <div class="label">خط السير</div>
                <div class="value"><?php echo htmlspecialchars($trx['from_city_name'] ?: '-'); ?> ← <?php echo htmlspecialchars($trx['to_city_name'] ?: '-'); ?></div>
            </div>
            <div class="info-item">
                <div class="label">نوع المعاملة</div>
                <div class="value">
                    <?php 
                        if ($trx['transaction_type_name']) {
                            echo htmlspecialchars($trx['transaction_type_name']);
                            $sub_types = ['both' => ' (بطاقة وجواز)', 'card_only' => ' (بطاقة فقط)', 'passport_only' => ' (جواز فقط)'];
                            echo $sub_types[$trx['transaction_type']] ?? '';
                        } else {
                            $types = ['both' => 'بطاقة وجواز', 'card_only' => 'بطاقة فقط', 'passport_only' => 'جواز فقط'];
                            echo $types[$trx['transaction_type']] ?? $trx['transaction_type'];
                        }
                    ?>
                </div>
            </div>
        </div>

        <table class="details-table">
            <thead>
                <tr>
                    <th>البيان</th>
                    <th>التفاصيل</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($trx['transaction_type'] != 'passport_only'): ?>
                <tr>
                    <td>بيانات البطاقة</td>
                    <td>
                        رقم المعاملة: <?php echo htmlspecialchars($trx['card_transaction_number'] ?: '-'); ?> | 
                        رقم البطاقة: <?php echo htmlspecialchars($trx['card_number'] ?: '-'); ?>
                    </td>
                </tr>
                <?php endif; ?>
                <?php if ($trx['transaction_type'] != 'card_only'): ?>
                <tr>
                    <td>بيانات الجواز</td>
                    <td>
                        رقم المعاملة: <?php echo htmlspecialchars($trx['passport_transaction_number'] ?: '-'); ?> | 
                        رقم الجواز: <?php echo htmlspecialchars($trx['passport_number'] ?: '-'); ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>المستلم</td>
                    <td><?php echo htmlspecialchars($trx['delivery_receiver_name'] ?: '-'); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="total-box">
            <div class="total-row">
                <span>إجمالي المبلغ:</span>
                <span><?php echo number_format((float)$trx['sale_price'], 2); ?> <?php echo htmlspecialchars($trx['currency_symbol'] ?: ''); ?></span>
            </div>
            <div class="total-row">
                <span>المبلغ المدفوع:</span>
                <span><?php echo number_format((float)$trx['amount_received'], 2); ?> <?php echo htmlspecialchars($trx['currency_symbol'] ?: ''); ?></span>
            </div>
            <div class="total-row grand">
                <span>المبلغ المتبقي:</span>
                <span><?php echo number_format((float)$trx['remaining_amount'], 2); ?> <?php echo htmlspecialchars($trx['currency_symbol'] ?: ''); ?></span>
            </div>
        </div>

        <div class="footer">
            <div style="text-align: center;">
                <p>توقيع الموظف</p>
                <div style="margin-top: 40px; border-top: 1px solid #ccc; width: 150px;"></div>
                <p style="font-size: 12px;"><?php echo htmlspecialchars($trx['created_by_name'] ?: ''); ?></p>
            </div>
            <div style="text-align: center;">
                <p>توقيع العميل</p>
                <div style="margin-top: 40px; border-top: 1px solid #ccc; width: 150px;"></div>
            </div>
        </div>

        <?php if (($settings['show_service_terms'] ?? 0) && !empty($settings['passport_service_terms'])): ?>
        <div style="margin-top: 30px; padding: 15px; border-top: 1px dashed #ccc;">
            <h6 style="margin: 0 0 10px 0; font-weight: bold;">الشروط والأحكام العامة:</h6>
            <p style="font-size: 12px; line-height: 1.6; margin: 0;"><?php echo nl2br(htmlspecialchars($settings['passport_service_terms'])); ?></p>
        </div>
        <?php endif; ?>
        
        <?php if ($trx['print_terms']): ?>
        <div style="margin-top: 20px; padding: 15px; border-top: 1px dashed #ccc;">
            <h6 style="margin: 0 0 10px 0; font-weight: bold;">الشروط الخاصة بهذا النوع:</h6>
            <p style="font-size: 12px; line-height: 1.6; margin: 0;"><?php echo nl2br(htmlspecialchars($trx['print_terms'])); ?></p>
        </div>
        <?php endif; ?>
        
        <div style="margin-top: 40px; text-align: center; font-size: 12px; color: #999;">
            <?php echo htmlspecialchars($trx['branch_address'] ?: ''); ?> | <?php echo htmlspecialchars($trx['branch_phone'] ?: ''); ?>
        </div>
    </div>

    <script>
        // Auto print when loaded
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>
