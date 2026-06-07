<?php
ob_start();
require_once 'header.php';

if (!isset($_GET['id'])) {
    header('Location: bus_flight_bookings.php');
    exit();
}

$id = (int)$_GET['id'];

// جلب تفاصيل الحجز مع الربط بالجداول الأخرى
$stmt = $pdo->prepare("
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
        curr.currency_symbol,
        bs.status_name AS booking_status_name,
        bs.status_color AS booking_status_color,
        cust.full_name AS customer_full_name,
        u.full_name AS created_by_user_full_name,
        s.supplier_name,
        cnt.country_name AS nationality_name,
        br.branch_name
    FROM bus_flight_bookings b
    LEFT JOIN cities c_from ON b.from_city_id = c_from.id
    LEFT JOIN cities c_to ON b.to_city_id = c_to.id
    LEFT JOIN invoices inv ON inv.id = b.invoice_id
    LEFT JOIN currencies curr ON inv.currency_id = curr.id
    LEFT JOIN statuses bs ON b.status_id = bs.id
    LEFT JOIN customers cust ON b.customer_id = cust.id
    LEFT JOIN users u ON b.created_by = u.id
    LEFT JOIN suppliers s ON b.supplier_id = s.id
    LEFT JOIN countries cnt ON b.nationality_id = cnt.id
    LEFT JOIN branches br ON b.branch_id = br.id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$b = $stmt->fetch();

if (!$b) {
    echo "خطأ: الحجز غير موجود.";
    exit();
}

$settings = getSettings($pdo);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة حجز - <?php echo htmlspecialchars($b['booking_number']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; }
            .container { width: 100% !important; max-width: 100% !important; }
        }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .print-card { background: white; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.1); padding: 40px; margin-top: 30px; border: 1px solid #dee2e6; }
        .print-header { border-bottom: 3px solid #0d6efd; padding-bottom: 20px; margin-bottom: 30px; }
        .info-label { font-weight: bold; color: #6c757d; min-width: 150px; display: inline-block; }
        .info-value { font-weight: bold; color: #333; }
        .section-title { background: #f8f9fa; padding: 10px 20px; border-radius: 10px; font-weight: bold; color: #0d6efd; margin-bottom: 20px; border-right: 5px solid #0d6efd; }
        .status-badge { padding: 5px 20px; border-radius: 20px; font-weight: bold; }
    </style>
</head>
<body>

<div class="container no-print mt-3 text-center">
    <button onclick="window.print()" class="btn btn-primary btn-lg rounded-pill px-5 shadow">
        <i class="fas fa-print me-2"></i> طباعة الآن
    </button>
    <button onclick="window.close()" class="btn btn-secondary btn-lg rounded-pill px-5 shadow ms-2">
        <i class="fas fa-times me-2"></i> إغلاق النافذة
    </button>
</div>

<div class="container">
    <div class="print-card mx-auto" style="max-width: 900px;">
        <!-- Header -->
        <div class="print-header">
            <div class="row align-items-center">
                <div class="col-6">
                    <h3 class="fw-bold text-primary mb-1"><?php echo htmlspecialchars($settings['site_name']); ?></h3>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($settings['header_address_1'] ?? ''); ?></p>
                    <p class="text-muted mb-0"><?php echo htmlspecialchars($settings['header_phone_1'] ?? ''); ?></p>
                </div>
                <div class="col-6 text-start">
                    <div class="bg-light p-3 rounded-3 d-inline-block">
                        <div class="mb-1">رقم الحجز: <strong class="text-dark"><?php echo $b['booking_number']; ?></strong></div>
                        <div class="mb-1">تاريخ الحجز: <strong class="text-dark"><?php echo $b['booking_date']; ?></strong></div>
                        <div>حالة الحجز: <span class="badge bg-primary"><?php echo htmlspecialchars($b['booking_status_name']); ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Passenger Info -->
        <div class="section-title">بيانات المسافر</div>
        <div class="row mb-4">
            <div class="col-md-6 mb-3">
                <span class="info-label">اسم المسافر:</span>
                <span class="info-value"><?php echo htmlspecialchars($b['traveler_name']); ?></span>
            </div>
            <div class="col-md-6 mb-3">
                <span class="info-label">الجنسية:</span>
                <span class="info-value"><?php echo htmlspecialchars($b['nationality_name'] ?: '---'); ?></span>
            </div>
            <div class="col-md-6 mb-3">
                <span class="info-label">رقم الجواز/الهوية:</span>
                <span class="info-value"><?php echo htmlspecialchars($b['id_number'] ?: '---'); ?></span>
            </div>
            <div class="col-md-6 mb-3">
                <span class="info-label">رقم الهاتف:</span>
                <span class="info-value"><?php echo htmlspecialchars($b['phone_number'] ?: '---'); ?></span>
            </div>
        </div>

        <!-- Trip Info -->
        <div class="section-title">تفاصيل الرحلة</div>
        <div class="row mb-4">
            <div class="col-md-6 mb-3">
                <span class="info-label">نوع الخدمة:</span>
                <span class="info-value"><?php echo $b['service_type'] == 'bus' ? 'باص' : 'طيران'; ?></span>
            </div>
            <div class="col-md-6 mb-3">
                <span class="info-label">تاريخ الرحلة:</span>
                <span class="info-value"><?php echo $b['departure_date']; ?></span>
            </div>
            <div class="col-md-6 mb-3">
                <span class="info-label">من:</span>
                <span class="info-value"><?php echo htmlspecialchars($b['from_city_name']); ?></span>
            </div>
            <div class="col-md-6 mb-3">
                <span class="info-label">إلى:</span>
                <span class="info-value"><?php echo htmlspecialchars($b['to_city_name']); ?></span>
            </div>
            <div class="col-md-6 mb-3">
                <span class="info-label">المورد/الشركة:</span>
                <span class="info-value"><?php echo htmlspecialchars($b['supplier_name'] ?: '---'); ?></span>
            </div>
            <div class="col-md-6 mb-3">
                <span class="info-label">رقم الرحلة/الباص:</span>
                <span class="info-value"><?php echo htmlspecialchars($b['bus_type'] ?: '---'); ?></span>
            </div>
        </div>

        <!-- Financial Info -->
        <div class="section-title">البيانات المالية</div>
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="p-3 border rounded-3 text-center">
                    <div class="small text-muted mb-1">سعر البيع</div>
                    <div class="h5 fw-bold mb-0"><?php echo number_format($b['sale_price'], 2); ?> <?php echo $b['currency_symbol']; ?></div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="p-3 border rounded-3 text-center bg-success bg-opacity-10">
                    <div class="small text-muted mb-1">المبلغ المدفوع</div>
                    <div class="h5 fw-bold mb-0 text-success"><?php echo number_format($b['amount_received'], 2); ?> <?php echo $b['currency_symbol']; ?></div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="p-3 border rounded-3 text-center bg-danger bg-opacity-10">
                    <div class="small text-muted mb-1">المبلغ المتبقي</div>
                    <div class="h5 fw-bold mb-0 text-danger"><?php echo number_format($b['remaining_amount'], 2); ?> <?php echo $b['currency_symbol']; ?></div>
                </div>
            </div>
        </div>

        <?php if (!empty($b['notes'])): ?>
        <div class="section-title">ملاحظات</div>
        <div class="p-3 bg-light rounded-3 mb-4">
            <?php echo nl2br(htmlspecialchars($b['notes'])); ?>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="mt-5 pt-3 border-top text-center text-muted small">
            طبع بواسطة: <?php echo $_SESSION['username'] ?? 'النظام'; ?> بتاريخ <?php echo date('Y-m-d H:i'); ?>
            <br>
            <?php echo $settings['copyright_text'] ?? 'جميع الحقوق محفوظة © ' . date('Y'); ?>
        </div>
    </div>
</div>

</body>
</html>
<?php ob_end_flush(); ?>
