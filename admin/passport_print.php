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

// Fetch transaction details from the 'passports' table (singular)
$stmt = $pdo->prepare("
    SELECT p.*, 
           s.status_name, s.status_color,
           c.currency_name, c.currency_symbol,
           b.branch_name, b.address as branch_address, b.phone as branch_phone,
           u.full_name as created_by_name,
           ser.service_name
    FROM passports p
    LEFT JOIN statuses s ON p.status_id = s.id
    LEFT JOIN currencies c ON p.currency_id = c.id
    LEFT JOIN branches b ON p.branch_id = b.id
    LEFT JOIN users u ON p.user_id = u.id
    LEFT JOIN services ser ON p.transaction_type = ser.id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$trx = $stmt->fetch();

if (!$trx) {
    die("المعاملة غير موجودة في سجل الجوازات.");
}

$settings = getSettings($pdo);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>طباعة معاملة - <?php echo htmlspecialchars($trx['full_name']); ?></title>
    <link rel="stylesheet" href="../assets/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap');
        body { font-family: 'Cairo', sans-serif; background-color: #f8f9fa; }
        .print-container { max-width: 800px; margin: 30px auto; background: white; padding: 40px; border-radius: 15px; box-shadow: 0 0 20px rgba(0,0,0,0.05); }
        .header-logo { max-height: 100px; }
        .company-name { color: #007aff; font-weight: 700; margin-bottom: 5px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px; }
        .info-item { border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .info-label { color: #8e8e93; font-size: 0.9rem; font-weight: 600; }
        .info-value { color: #1d1d1f; font-weight: 700; font-size: 1.1rem; }
        .footer-note { margin-top: 50px; border-top: 2px solid #f5f5f7; padding-top: 20px; color: #8e8e93; font-size: 0.85rem; text-align: center; }
        @media print {
            body { background: white; }
            .print-container { margin: 0; box-shadow: none; max-width: 100%; width: 100%; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4 pb-4 border-bottom">
            <div>
                <h2 class="company-name"><?php echo htmlspecialchars($settings['site_name'] ?? 'مكتب الغزالي'); ?></h2>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($trx['branch_name'] ?? 'المركز الرئيسي'); ?></p>
                <small><i class="fas fa-phone me-1"></i> <?php echo htmlspecialchars($trx['branch_phone'] ?? ''); ?></small>
            </div>
            <div class="text-center">
                <?php if(!empty($settings['site_logo'])): ?>
                    <img src="../assets/uploads/<?php echo $settings['site_logo']; ?>" class="header-logo" alt="Logo">
                <?php else: ?>
                    <i class="fas fa-passport fa-4x text-primary"></i>
                <?php endif; ?>
            </div>
            <div class="text-end">
                <h4 class="fw-bold">قسيمة معاملة</h4>
                <p class="mb-0">رقم: <span class="text-primary fw-bold">#<?php echo $id; ?></span></p>
                <small>تاريخ: <?php echo date('Y/m/d', strtotime($trx['created_at'])); ?></small>
            </div>
        </div>

        <!-- Main Info -->
        <div class="alert alert-light border-0 shadow-sm mb-4">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="mb-1 text-muted small fw-bold">الاسم الكامل</h5>
                    <h3 class="fw-bold mb-0"><?php echo htmlspecialchars($trx['full_name']); ?></h3>
                </div>
                <div class="col-md-4 text-md-end">
                    <span class="badge rounded-pill px-3 py-2" style="background-color: <?php echo $trx['status_color'] ?? '#007aff'; ?>;">
                        <?php echo htmlspecialchars($trx['status_name'] ?? 'معلقة'); ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">نوع الخدمة</div>
                <div class="info-value"><?php echo htmlspecialchars($trx['service_name'] ?: ($trx['transaction_type'] ?: 'جوازات')); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">رقم الجواز / الهوية</div>
                <div class="info-value"><?php echo htmlspecialchars($trx['passport_number'] ?: '---'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">رقم الهاتف</div>
                <div class="info-value"><?php echo htmlspecialchars($trx['phone_number'] ?: '---'); ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">الجهة المستفيدة</div>
                <div class="info-value">
                    <?php 
                        if ($trx['customer_id']) echo "عميل مباشر";
                        elseif ($trx['agent_id']) echo "وكيل";
                        else echo "أخرى";
                    ?>
                </div>
            </div>
        </div>

        <div class="mt-5 p-4 rounded-4 bg-light">
            <h5 class="fw-bold mb-3 border-bottom pb-2"><i class="fas fa-coins me-2 text-warning"></i> التفاصيل المالية</h5>
            <div class="d-flex justify-content-between mb-2">
                <span>إجمالي المبلغ المستحق:</span>
                <span class="fw-bold fs-5 text-dark"><?php echo number_format($trx['sale_price'], 2); ?> <?php echo $trx['currency_symbol']; ?></span>
            </div>
            <div class="d-flex justify-content-between text-muted small">
                <span>بواسطة:</span>
                <span><?php echo htmlspecialchars($trx['created_by_name'] ?? 'النظام'); ?></span>
            </div>
        </div>

        <?php if(!empty($trx['description'])): ?>
        <div class="mt-4">
            <div class="info-label mb-2">ملاحظات إضافية</div>
            <div class="p-3 border rounded-3 bg-white">
                <?php echo nl2br(htmlspecialchars($trx['description'])); ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Footer -->
        <div class="footer-note">
            <p class="mb-1">تمت طباعة هذه القسيمة في <?php echo date('Y/m/d H:i'); ?></p>
            <p class="fw-bold"><?php echo htmlspecialchars($settings['site_name'] ?? 'مكتب الغزالي للسفريات'); ?> - شكراً لثقتكم بنا</p>
        </div>

        <div class="text-center mt-4 no-print">
            <button onclick="window.print()" class="btn btn-primary btn-lg rounded-pill px-5">
                <i class="fas fa-print me-2"></i> طباعة الآن
            </button>
            <button onclick="window.close()" class="btn btn-light btn-lg rounded-pill px-4 ms-2">
                إغلاق
            </button>
        </div>
    </div>
</body>
</html>