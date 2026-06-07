<?php
ob_start();
require_once 'header.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) {
    echo "<script>alert('معرف السند غير صحيح'); history.back();</script>";
    exit;
}

// جلب بيانات السند
$stmt = $pdo->prepare("
    SELECT t.*,
           ua_cash.account_name_ar as cash_account_name, ua_cash.account_code as cash_account_code,
           ua_party.account_name_ar as party_name,
           c.currency_name, c.currency_symbol,
           u_create.full_name as creator_name,
           u_post.full_name as poster_name
    FROM financial_transactions t
    LEFT JOIN unified_accounts ua_cash ON t.cash_bank_account_id = ua_cash.id
    LEFT JOIN unified_accounts ua_party ON t.party_account_id = ua_party.id
    LEFT JOIN currencies c ON t.currency_id = c.id
    LEFT JOIN users u_create ON t.created_by = u_create.id
    LEFT JOIN users u_post ON t.posted_by = u_post.id
    WHERE t.id = ? AND t.transaction_type = 'receipt'
");
$stmt->execute([$id]);
$voucher = $stmt->fetch();

if (!$voucher) {
    echo "<script>alert('السند غير موجود'); history.back();</script>";
    exit;
}

// Check if a custom template exists for receipt vouchers
$custom_template = null;
try {
    $stmt = $pdo->prepare("SELECT html_content FROM voucher_templates WHERE template_type = 'receipt' AND is_default = 1 LIMIT 1");
    $stmt->execute();
    $custom_template = $stmt->fetchColumn();
} catch (Exception $e) {}

// جلب التخصيصات للفواتير
$stmt_alloc = $pdo->prepare("
    SELECT pa.*, i.invoice_number, i.invoice_date, i.net_amount
    FROM payment_allocations pa
    JOIN invoices i ON pa.invoice_id = i.id
    WHERE pa.financial_transaction_id = ?
");
$stmt_alloc->execute([$id]);
$allocations = $stmt_alloc->fetchAll();

// جلب إعدادات الشركة
$settings = [];
try {
    $st = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('company_name','company_name_ar','company_phone','company_address','company_logo')");
    foreach ($st->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

// جلب شعار الطباعة من system_settings
$system_settings = [];
try {
    $st = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('print_logo', 'site_logo')");
    foreach ($st->fetchAll() as $row) {
        $system_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {}

$company_name = $settings['company_name_ar'] ?? $settings['company_name'] ?? 'وكالة الغزالي للسفريات';
$company_phone = $settings['company_phone'] ?? '';
$company_address = $settings['company_address'] ?? '';
$print_logo = !empty($system_settings['print_logo']) ? $system_settings['print_logo'] : (!empty($system_settings['site_logo']) ? $system_settings['site_logo'] : '');

$type_map = [
    'customer'  => 'عميل',
    'agent'     => 'وكيل',
    'supplier'  => 'مورد',
    'employee'  => 'موظف',
    'branch'    => 'فرع',
    'expense'   => 'حساب إيراد/آخر',
];
$entity_label = $type_map[$voucher['entity_type']] ?? $voucher['entity_type'];

// تافقيت
function simple_tafqeet($n) {
    $n = floatval($n);
    if ($n == 0) return 'صفر';
    $int_part = floor($n);
    $dec_part = round(($n - $int_part) * 100);
    $ones = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة',
             'عشرة', 'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر',
             'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];
    $tens = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];
    $hundreds = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];

    function convert_part($num, $ones, $tens, $hundreds) {
        $h = intdiv($num, 100);
        $t = intdiv($num % 100, 10);
        $o = $num % 10;
        $s = '';
        if ($h > 0) $s .= $hundreds[$h] . ($num % 100 > 0 ? ' و' : '');
        if ($t > 1) $s .= ($o > 0 ? $ones[$o] . ' و' : '') . $tens[$t];
        else $s .= $ones[$num % 100];
        return $s;
    }

    $result = '';
    $n2 = $int_part;
    if ($n2 >= 1000000) { $m = intdiv($n2, 1000000); $result .= ($m == 1 ? 'مليون' : convert_part($m, $ones, $tens, $hundreds) . ' مليون'); $n2 %= 1000000; if ($n2 > 0) $result .= ' و'; }
    if ($n2 >= 1000)    { $k = intdiv($n2, 1000);    $result .= ($k == 1 ? 'ألف' : ($k == 2 ? 'ألفان' : convert_part($k, $ones, $tens, $hundreds) . ' ألف')); $n2 %= 1000; if ($n2 > 0) $result .= ' و'; }
    if ($n2 > 0)        { $result .= convert_part($n2, $ones, $tens, $hundreds); }

    $result = 'فقط ' . $result . ' ريال';
    if ($dec_part > 0) $result .= ' و' . convert_part($dec_part, $ones, $tens, $hundreds) . ' هللة';
    return $result . ' لا غير';
}

$status_labels = ['draft' => 'مسودة', 'posted' => 'مرحّل', 'cancelled' => 'ملغي'];
$status_colors = ['draft' => '#856404', 'posted' => '#155724', 'cancelled' => '#721c24'];
$status_bg     = ['draft' => '#fff3cd', 'posted' => '#d4edda', 'cancelled' => '#f8d7da'];

if ($custom_template) {
    // If a custom template is found, process it and output
    $html = $custom_template;
    $html = str_replace('{{receipt_no}}', $voucher['transaction_number'], $html);
    $html = str_replace('{{customer_name}}', $voucher['party_name'] ?? '', $html);
    $html = str_replace('{{amount}}', number_format($voucher['amount'], 2) . ' ' . $voucher['currency_symbol'], $html);
    $html = str_replace('{{receipt_date}}', $voucher['transaction_date'], $html);
    $html = str_replace('{{description}}', $voucher['description'], $html);
    
    // Logo processing
    if ($print_logo) {
        $html = str_replace('{{logo}}', '<img src="../assets/uploads/'.$print_logo.'" style="max-width:100%; max-height:100%; object-fit:contain;">', $html);
    } else {
        $html = str_replace('{{logo}}', '<h1>'.$company_name.'</h1>', $html);
    }
    
    echo '<!DOCTYPE html><html dir="rtl"><head><title>طباعة سند قبض</title></head><body onload="window.print()">';
    echo $html;
    echo '</body></html>';
    $content = ob_get_clean();
    echo $content;
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>طباعة سند قبض - <?= htmlspecialchars($voucher['transaction_number']) ?></title>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+Arabic:wght@300;400;500;600;700&display=swap');

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'Noto Sans Arabic', 'Segoe UI', Arial, sans-serif;
        background: #f0f2f5;
        direction: rtl;
        font-size: 14px;
        color: #1a1a2e;
    }

    .print-wrapper {
        max-width: 800px;
        margin: 20px auto;
        background: white;
        border-radius: 16px;
        overflow: hidden;
        box-shadow: 0 8px 32px rgba(0,0,0,0.12);
    }

    /* رأس السند */
    .voucher-header {
        background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #1565c0 100%);
        color: white;
        padding: 28px 35px;
        position: relative;
        overflow: hidden;
    }
    .voucher-header::before {
        content: '';
        position: absolute;
        top: -50px; right: -50px;
        width: 200px; height: 200px;
        border-radius: 50%;
        background: rgba(255,255,255,0.05);
    }
    .voucher-header::after {
        content: '';
        position: absolute;
        bottom: -30px; left: -30px;
        width: 150px; height: 150px;
        border-radius: 50%;
        background: rgba(255,255,255,0.05);
    }

    .header-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
        position: relative;
        z-index: 1;
    }
    
    .company-logo {
        max-height: 70px;
        max-width: 200px;
        object-fit: contain;
        margin-left: 15px;
        background: white;
        padding: 5px;
        border-radius: 8px;
    }

    .company-info {
        display: flex;
        align-items: center;
    }

    .company-info h1 {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: 4px;
    }
    .company-info p { font-size: 0.85rem; opacity: 0.85; margin: 2px 0; }

    .voucher-badge {
        text-align: center;
        background: rgba(255,255,255,0.15);
        border: 2px solid rgba(255,255,255,0.3);
        border-radius: 12px;
        padding: 12px 20px;
        backdrop-filter: blur(10px);
    }
    .voucher-badge .type { font-size: 1rem; font-weight: 600; margin-bottom: 4px; }
    .voucher-badge .number { font-size: 1.4rem; font-weight: 700; letter-spacing: 1px; }

    /* جسم السند */
    .voucher-body { padding: 28px 35px; }

    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
        margin-bottom: 24px;
    }

    .info-item {
        background: #f8f9ff;
        border: 1px solid #e8ecf8;
        border-radius: 10px;
        padding: 12px 16px;
        border-right: 4px solid #2a5298;
    }
    .info-item .label {
        font-size: 0.75rem;
        color: #6b7280;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 5px;
    }
    .info-item .value {
        font-size: 0.95rem;
        font-weight: 600;
        color: #1a1a2e;
    }
    .info-item.amount-box {
        grid-column: 1 / -1;
        background: linear-gradient(135deg, #e8f4fd, #dbeafe);
        border-color: #93c5fd;
        border-right-color: #2563eb;
        text-align: center;
        padding: 18px;
    }
    .info-item.amount-box .value {
        font-size: 1.8rem;
        color: #1d4ed8;
    }
    .amount-text {
        font-size: 0.85rem;
        color: #4b5563;
        margin-top: 6px;
        font-style: italic;
    }

    /* حالة السند */
    .status-badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 700;
    }

    /* جدول الفواتير */
    .section-title {
        font-size: 1rem;
        font-weight: 700;
        color: #1e3c72;
        margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e8ecf8;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .invoices-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 20px;
        font-size: 0.88rem;
    }
    .invoices-table th {
        background: #1e3c72;
        color: white;
        padding: 10px 12px;
        text-align: right;
        font-weight: 600;
    }
    .invoices-table td {
        padding: 9px 12px;
        border-bottom: 1px solid #f0f2f5;
    }
    .invoices-table tr:last-child td { border-bottom: none; }
    .invoices-table tr:nth-child(even) td { background: #f8f9ff; }
    .invoices-table tfoot td {
        background: #e8f0fe;
        font-weight: 700;
        color: #1e3c72;
        border-top: 2px solid #93c5fd;
    }

    /* توقيعات */
    .signatures {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 20px;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 2px dashed #e8ecf8;
    }
    .signature-box {
        text-align: center;
    }
    .signature-box .sig-label {
        font-size: 0.8rem;
        color: #6b7280;
        font-weight: 600;
        margin-bottom: 40px;
    }
    .signature-box .sig-line {
        border-top: 1px solid #374151;
        padding-top: 6px;
        font-size: 0.8rem;
        color: #374151;
    }

    /* تذييل */
    .voucher-footer {
        background: #f8f9ff;
        border-top: 1px solid #e8ecf8;
        padding: 12px 35px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.78rem;
        color: #6b7280;
    }

    /* أزرار الطباعة */
    .action-buttons {
        text-align: center;
        padding: 20px;
        background: white;
        border-top: 1px solid #e8ecf8;
        display: flex;
        gap: 12px;
        justify-content: center;
    }
    .btn-print {
        background: linear-gradient(135deg, #1e3c72, #2a5298);
        color: white;
        border: none;
        padding: 10px 28px;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        font-family: inherit;
    }
    .btn-back {
        background: #f3f4f6;
        color: #374151;
        border: 1px solid #d1d5db;
        padding: 10px 28px;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 600;
        cursor: pointer;
        font-family: inherit;
        text-decoration: none;
    }

    /* طباعة */
    @media print {
        body { background: white; font-size: 12px; }
        .action-buttons { display: none; }
        .print-wrapper { box-shadow: none; border-radius: 0; margin: 0; }
    }

    .watermark {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%) rotate(-30deg);
        font-size: 5rem;
        font-weight: 900;
        opacity: 0.04;
        color: #000;
        pointer-events: none;
        white-space: nowrap;
    }
</style>
</head>
<body>

<div class="print-wrapper" style="position:relative;">
    <?php if ($voucher['status'] === 'posted'): ?>
    <div class="watermark">مرحّل</div>
    <?php elseif ($voucher['status'] === 'cancelled'): ?>
    <div class="watermark">ملغي</div>
    <?php endif; ?>

    <!-- رأس السند -->
    <div class="voucher-header">
        <div class="header-content">
            <div class="company-info">
                <?php if ($print_logo): ?>
                    <img src="../assets/uploads/<?= htmlspecialchars($print_logo) ?>" alt="Logo" class="company-logo">
                <?php endif; ?>
                <div>
                    <h1><?= htmlspecialchars($company_name) ?></h1>
                    <?php if ($company_phone): ?><p>📞 <?= htmlspecialchars($company_phone) ?></p><?php endif; ?>
                    <?php if ($company_address): ?><p>📍 <?= htmlspecialchars($company_address) ?></p><?php endif; ?>
                    <p style="margin-top:8px;opacity:0.7;font-size:0.8rem;">تاريخ الطباعة: <?= date('Y/m/d H:i') ?></p>
                </div>
            </div>
            <div class="voucher-badge">
                <div class="type">🧾 سند قبض</div>
                <div class="number"><?= htmlspecialchars($voucher['transaction_number']) ?></div>
            </div>
        </div>
    </div>

    <!-- جسم السند -->
    <div class="voucher-body">

        <!-- معلومات أساسية -->
        <div class="info-grid">
            <!-- المبلغ -->
            <div class="info-item amount-box">
                <div class="label">المبلغ الإجمالي</div>
                <div class="value">
                    <?= number_format($voucher['amount'], 2) ?> <?= htmlspecialchars($voucher['currency_symbol'] ?? '') ?>
                </div>
                <div class="amount-text"><?= simple_tafqeet($voucher['amount']) ?></div>
            </div>

            <div class="info-item">
                <div class="label">التاريخ</div>
                <div class="value">📅 <?= htmlspecialchars($voucher['transaction_date']) ?></div>
            </div>

            <div class="info-item">
                <div class="label">الحالة</div>
                <div class="value">
                    <span class="status-badge" style="background:<?= $status_bg[$voucher['status']] ?? '#e9ecef' ?>;color:<?= $status_colors[$voucher['status']] ?? '#333' ?>;">
                        <?= $status_labels[$voucher['status']] ?? $voucher['status'] ?>
                    </span>
                </div>
            </div>

            <div class="info-item">
                <div class="label">الدافع (<?= $entity_label ?>)</div>
                <div class="value">👤 <?= htmlspecialchars($voucher['party_name'] ?? '---') ?></div>
            </div>

            <div class="info-item">
                <div class="label">الحساب المستلِم</div>
                <div class="value">🏦 <?= htmlspecialchars($voucher['cash_account_name'] ?? '---') ?></div>
            </div>

            <?php if ($voucher['description']): ?>
            <div class="info-item" style="grid-column: 1 / -1;">
                <div class="label">البيان / الوصف</div>
                <div class="value">📝 <?= htmlspecialchars($voucher['description']) ?></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- الفواتير المخصصة -->
        <?php if (!empty($allocations)): ?>
        <div class="section-title">
            <span>📄</span>
            <span>تفصيل تسديد الفواتير</span>
        </div>
        <table class="invoices-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>رقم الفاتورة</th>
                    <th>تاريخ الفاتورة</th>
                    <th>قيمة الفاتورة</th>
                    <th>المبلغ المخصص</th>
                </tr>
            </thead>
            <tbody>
                <?php $total_alloc = 0; foreach ($allocations as $i => $alloc): $total_alloc += $alloc['allocated_amount']; ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><strong><?= htmlspecialchars($alloc['invoice_number']) ?></strong></td>
                    <td><?= htmlspecialchars($alloc['invoice_date']) ?></td>
                    <td><?= number_format($alloc['net_amount'], 2) ?></td>
                    <td style="color:#1d4ed8;font-weight:700;"><?= number_format($alloc['allocated_amount'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right;">إجمالي المبالغ المخصصة:</td>
                    <td style="color:#1d4ed8;"><?= number_format($total_alloc, 2) ?> <?= htmlspecialchars($voucher['currency_symbol'] ?? '') ?></td>
                </tr>
                <?php if ($voucher['amount'] - $total_alloc > 0.01): ?>
                <tr>
                    <td colspan="4" style="text-align:right;">المتبقي (رصيد في الحساب):</td>
                    <td style="color:#059669;"><?= number_format($voucher['amount'] - $total_alloc, 2) ?> <?= htmlspecialchars($voucher['currency_symbol'] ?? '') ?></td>
                </tr>
                <?php endif; ?>
            </tfoot>
        </table>
        <?php else: ?>
        <div style="background:#e8f4fd;border:1px solid #93c5fd;border-radius:8px;padding:12px 16px;margin-bottom:20px;color:#1d4ed8;">
            <strong>ℹ️</strong> هذا السند دفعة على الحساب (غير مخصص لفواتير محددة).
        </div>
        <?php endif; ?>

        <!-- معلومات الترحيل -->
        <?php if ($voucher['status'] === 'posted' && $voucher['poster_name']): ?>
        <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:10px 16px;margin-bottom:20px;font-size:0.85rem;color:#155724;">
            ✅ تم الترحيل بواسطة: <strong><?= htmlspecialchars($voucher['poster_name']) ?></strong>
            <?php if ($voucher['posted_at']): ?> في <?= $voucher['posted_at'] ?><?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- توقيعات -->
        <div class="signatures">
            <div class="signature-box">
                <div class="sig-label">المحاسب</div>
                <div class="sig-line"><?= htmlspecialchars($voucher['creator_name'] ?? '') ?></div>
            </div>
            <div class="signature-box">
                <div class="sig-label">المدير المالي</div>
                <div class="sig-line">...</div>
            </div>
            <div class="signature-box">
                <div class="sig-label">توقيع الدافع</div>
                <div class="sig-line">...</div>
            </div>
        </div>
    </div>

    <!-- تذييل -->
    <div class="voucher-footer">
        <span>النظام: وكالة الغزالي للسفريات والسياحة</span>
        <span>رقم السند: <?= htmlspecialchars($voucher['transaction_number']) ?> | <?= htmlspecialchars($voucher['transaction_date']) ?></span>
        <span>طُبع في: <?= date('Y/m/d H:i') ?></span>
    </div>

    <!-- أزرار -->
    <div class="action-buttons">
        <button class="btn-print" onclick="window.print()">🖨️ طباعة</button>
        <a href="receipts.php" class="btn-back">↩️ رجوع</a>
    </div>
</div>

</body>
</html>
<?php
$content = ob_get_clean();
// لا نريد header.php في صفحة الطباعة، نطبع مباشرة
echo $content;
