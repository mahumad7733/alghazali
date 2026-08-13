<?php
ob_start();
require_once 'header.php';

// العرض الموحد للتذكرة الرقمية أصبح من خلال صفحة طباعة الحجز.
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
    header('Location: bus_flight_bookings_print.php?id=' . $id);
    exit();
}

$id = $_GET['id'] ?? null;
if (!$id) {
    echo "خطأ: لم يتم تحديد الحجز.";
    exit();
}

// جلب بيانات الحجز بالتفصيل
$stmt = $pdo->prepare("
    SELECT b.*, 
           inv.total_amount AS sale_price,
           inv.cost_amount AS purchase_price,
           inv.amount_received AS amount_received,
           (inv.total_amount - inv.amount_received) AS remaining_amount,
           (inv.total_amount - inv.cost_amount) AS profit,
           inv.currency_id AS currency_id,
           inv.delivery_type AS payment_type,
           s.status_name as booking_status_name,
           c1.city_name as from_city,
           c2.city_name as to_city,
           curr.currency_name,
           sup.supplier_name,
           u.full_name as creator_name,
           br.branch_name,
           cust.full_name as customer_name,
           nat.country_name as nationality
    FROM bus_flight_bookings b
    LEFT JOIN statuses s ON b.status_id = s.id
    LEFT JOIN cities c1 ON b.from_city_id = c1.id
    LEFT JOIN cities c2 ON b.to_city_id = c2.id
    LEFT JOIN invoices inv ON inv.id = b.invoice_id
    LEFT JOIN currencies curr ON inv.currency_id = curr.id
    LEFT JOIN suppliers sup ON b.supplier_id = sup.id
    LEFT JOIN users u ON b.created_by = u.id
    LEFT JOIN branches br ON b.branch_id = br.id
    LEFT JOIN customers cust ON b.customer_id = cust.id
    LEFT JOIN countries nat ON b.nationality_id = nat.id
    WHERE b.id = ?
");
$stmt->execute([$id]);
$booking = $stmt->fetch();

if (!$booking) {
    echo "خطأ: الحجز غير موجود.";
    exit();
}

// التأكد من أن الحجز "مؤكد" للطباعة (أو حسب رغبة المستخدم)
// if ($booking['booking_status_name'] !== 'مؤكد') {
//     echo "<div class='alert alert-warning'>لا يمكن طباعة التذكرة إلا للحجوزات المؤكدة.</div>";
//     exit();
// }

$settings = getSettings($pdo);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تذكرة حجز - <?php echo htmlspecialchars($booking['traveler_name']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; }
            .ticket-container { box-shadow: none !important; border: 1px solid #000 !important; margin: 0 !important; width: 100% !important; }
        }
        body { background-color: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; direction: rtl; }
        .ticket-container {
            max-width: 1100px;
            margin: 20px auto;
            background: white;
            border: 1px solid #000;
            display: flex;
            flex-direction: row;
            overflow: hidden;
        }
        .ticket-side-info {
            width: 140px;
            border-left: 1px solid #000;
            padding: 10px 5px;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            background: #fff;
        }
        .ticket-main {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .ticket-table {
            width: 100%;
            border-collapse: collapse;
            border: none;
        }
        .ticket-table td {
            border: 1px solid #000;
            padding: 4px 8px;
            vertical-align: middle;
            font-size: 13px;
            height: 35px;
        }
        .label-cell {
            background-color: #fff;
            font-weight: bold;
            width: 90px;
            text-align: center;
            border-right: 1px solid #000 !important;
        }
        .value-cell {
            font-weight: bold;
            text-align: center;
            color: #000;
        }
        .qr-code {
            width: 80px;
            height: 80px;
            margin-bottom: 15px;
        }
        .logo-img {
            max-width: 70px;
            max-height: 50px;
            margin-bottom: 5px;
        }
        .ticket-footer-text {
            font-size: 10px;
            padding: 5px 10px;
            border-bottom: 1px solid #000;
            line-height: 1.3;
            text-align: justify;
            background: #fff;
        }
        .address-bar {
            font-size: 12px;
            padding: 6px;
            text-align: center;
            background: #fff;
            font-weight: normal;
        }
        .side-text {
            font-size: 11px;
            margin-top: 5px;
            line-height: 1.2;
        }
    </style>
</head>
<body>

<div class="container no-print mt-3 text-center">
    <button onclick="window.print()" class="btn btn-primary px-5 rounded-pill shadow">
        <i class="fas fa-print me-2"></i> طباعة التذكرة
    </button>
    <button onclick="window.close()" class="btn btn-secondary px-5 rounded-pill shadow ms-2">
        <i class="fas fa-times me-2"></i> إغلاق
    </button>
</div>

<div class="ticket-container shadow-sm">
    <!-- الجانب الأيمن (QR والشعار) -->
    <div class="ticket-side-info">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=<?php echo urlencode($booking['booking_number']); ?>" class="qr-code" alt="QR">
        
        <?php if (!empty($settings['site_logo'])): ?>
            <img src="../assets/uploads/<?php echo $settings['site_logo']; ?>" class="logo-img" alt="Logo">
        <?php endif; ?>
        
        <div class="fw-bold mt-1" style="font-size: 14px; color: #333;"><?php echo htmlspecialchars($settings['header_company_name'] ?? 'المهاجرين'); ?></div>
        
        <div class="mt-auto side-text">
            <div><?php echo htmlspecialchars($booking['creator_name']); ?></div>
            <div><?php echo date('h:i', strtotime($booking['created_at'])); ?> <?php echo (date('H', strtotime($booking['created_at'])) >= 12) ? 'مساءً' : 'صباحاً'; ?></div>
            <div><?php echo date('d/m/Y', strtotime($booking['created_at'])); ?></div>
            <div class="fw-bold mt-1"><?php echo htmlspecialchars($booking['branch_name']); ?></div>
        </div>
    </div>

    <!-- الجزء الرئيسي (الجدول) -->
    <div class="ticket-main">
        <table class="ticket-table">
            <tr>
                <td class="value-cell" style="width: 25%;"><?php echo htmlspecialchars($booking['id_number'] ?: '---'); ?></td>
                <td class="label-cell" style="width: 15%;">رقم الجواز</td>
                <td class="value-cell" colspan="2" style="width: 45%;"><?php echo htmlspecialchars($booking['booking_number']); ?></td>
                <td class="label-cell" style="width: 15%;">رقم التذكرة</td>
            </tr>
            <tr>
                <td class="value-cell"><?php echo htmlspecialchars($booking['from_city']); ?>-<?php echo htmlspecialchars($booking['to_city']); ?></td>
                <td class="label-cell">الرحلة</td>
                <td class="value-cell"><?php echo htmlspecialchars($booking['bus_type'] ?: '---'); ?></td>
                <td class="value-cell"><?php echo htmlspecialchars($booking['traveler_name']); ?></td>
                <td class="label-cell">اسم المسافر</td>
            </tr>
            <tr>
                <td class="value-cell">07:00 مساءً</td>
                <td class="value-cell"><?php echo date('d/m/Y', strtotime($booking['departure_date'])); ?></td>
                <td class="label-cell">تاريخ الرحلة</td>
                <td class="value-cell"><?php echo htmlspecialchars($booking['notes'] ?: '0'); ?></td>
                <td class="label-cell">المقعد</td>
                <td class="value-cell"><?php echo htmlspecialchars($booking['date_of_birth'] ?: '---'); ?></td>
                <td class="label-cell">تاريخ الميلاد</td>
            </tr>
            <tr>
                <td class="value-cell">
                    <?php 
                        $days = ['Sunday'=>'الأحد','Monday'=>'الاثنين','Tuesday'=>'الثلاثاء','Wednesday'=>'الأربعاء','Thursday'=>'الخميس','Friday'=>'الجمعة','Saturday'=>'السبت'];
                        echo $days[date('l', strtotime($booking['departure_date']))] ?? date('l', strtotime($booking['departure_date']));
                    ?>
                </td>
                <td class="label-cell">اليوم</td>
                <td class="value-cell" colspan="2"><?php echo htmlspecialchars($booking['supplier_name'] ?: '---'); ?></td>
                <td class="label-cell">الوكيل</td>
                <td class="value-cell"><?php echo date('h:i', strtotime($booking['created_at'])); ?> <?php echo (date('H', strtotime($booking['created_at'])) >= 12) ? 'مساءً' : 'صباحاً'; ?></td>
                <td class="label-cell">وقت الاصدار</td>
            </tr>
            <tr>
                <td class="value-cell" colspan="2">الاثنين - 06:00 مساءً</td>
                <td class="label-cell">وقت الحضور</td>
                <td class="value-cell"><?php echo htmlspecialchars($booking['creator_name']); ?></td>
                <td class="label-cell">مستخدم</td>
                <td class="value-cell"><?php echo htmlspecialchars($booking['branch_name']); ?></td>
                <td class="label-cell">فرع</td>
            </tr>
            <tr>
                <td class="value-cell" colspan="2"><?php echo number_format($booking['sale_price'], 2); ?></td>
                <td class="label-cell">السعر</td>
                <td class="value-cell" colspan="3">---</td>
                <td class="label-cell">ملاحظات</td>
            </tr>
        </table>
        
        <div class="ticket-footer-text">
            1- يُمنع التدخين داخل الحافلات. 2- يُرجى الحضور قبل موعد الرحلة بساعة. 3- المقاعد الأمامية مخصصة للعائلات فقط. 4- يُسمح لكل مسافر بحقيبة واحدة لا يتجاوز وزنها 30 كيلوجراماً. 5- في حال إلغاء السفر تُطبق غرامة وفق سياسة الشركة. 6- الشركة غير مسؤولة عن فقدان أي ضياع أي أمتعة تخص المسافرين.
        </div>
        
        <div class="address-bar">
            محطة صنعاء شارع الستين الجنوبي : امام مطعم الطاووس - 782947771 - 784587770 جده حي الرويس الشرقيه 966550801995 - الطائف مكتب الاخوين
        </div>
    </div>
</div>
    
    <?php if ($booking['booking_status_name'] === 'مؤكد'): ?>
        <div class="status-stamp">CONFIRMED</div>
    <?php endif; ?>
</div>

</body>
</html>
<?php ob_end_flush(); ?>
