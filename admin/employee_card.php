<?php
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/security.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("معرف الموظف غير صالح");
}

$id = $_GET['id'];
$stmt = $pdo->prepare("SELECT e.*, b.branch_name, jt.title_name as job_title_name, ws.shift_name, ws.shift_salary, c.currency_name
            FROM employees e
            LEFT JOIN branches b ON e.branch_id = b.id
            LEFT JOIN job_titles jt ON e.job_title_id = jt.id
            LEFT JOIN work_shifts ws ON e.shift_id = ws.id
            LEFT JOIN currencies c ON ws.currency_id = c.id
            WHERE e.id = ?");
$stmt->execute([$id]);
$emp = $stmt->fetch();

if (!$emp) {
    die("الموظف غير موجود");
}

// إنشاء بيانات QR Code
$qr_data = "الاسم: " . $emp['full_name'] . "\nالوظيفة: " . $emp['job_title_name'] . "\nالراتب: " . (isset($emp['shift_salary']) ? number_format($emp['shift_salary'], 2) . " " . ($emp['currency_name'] ?? "") : "غير محدد") . "\nالفرع: " . ($emp['branch_name'] ?? 'المركز الرئيسي') . "\nالهاتف: " . $emp['phone'];
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($qr_data);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>بطاقة الموظف - <?php echo htmlspecialchars($emp['full_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        @media print {
            body * {
                visibility: hidden;
            }
            .card-container, .card-container * {
                visibility: visible;
            }
            .card-container {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                display: flex;
                justify-content: center;
                align-items: center;
            }
            .employee-card {
                box-shadow: none !important;
                border: 1px solid #ccc !important;
                page-break-inside: avoid;
            }
            .no-print {
                display: none !important;
            }
        }
        .employee-card {
            width: 350px;
            margin: 20px auto;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            background: #fff;
            font-family: "Tajawal", sans-serif;
        }
        .card-header {
            background: linear-gradient(135deg, #0d6efd, #0a58ca);
            color: white;
            text-align: center;
            padding: 20px;
        }
        .card-header img.logo {
            max-height: 50px;
            margin-bottom: 10px;
        }
        .card-body {
            padding: 20px;
            text-align: center;
        }
        .employee-photo {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 4px solid #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-top: -60px;
            background: #f8f9fa;
        }
        .employee-name {
            font-size: 1.5rem;
            font-weight: bold;
            margin-top: 15px;
            color: #333;
        }
        .employee-title {
            color: #6c757d;
            font-size: 1.1rem;
            margin-bottom: 20px;
        }
        .card-details {
            text-align: right;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        .card-details p {
            margin-bottom: 5px;
            font-size: 0.95rem;
        }
        .qr-code {
            width: 100px;
            height: 100px;
            margin: 0 auto;
        }
        .card-footer {
            background: #f8f9fa;
            text-align: center;
            padding: 10px;
            font-size: 0.8rem;
            color: #6c757d;
            border-top: 1px solid #eee;
        }
    </style>

</head>
<body>
    <div>
        <div class="text-center mb-4 no-print">
            <button onclick="window.print()" class="btn btn-primary rounded-pill px-4 shadow-sm"><i class="fas fa-print me-2"></i> طباعة البطاقة</button>
            <button onclick="window.close()" class="btn btn-light rounded-pill px-4 shadow-sm ms-2">إغلاق</button>
        </div>
        
        <div class="id-card">
            <div class="id-card-header">
                <h4>الغزالي للسفريات والسياحة</h4>
                <p>بطاقة عمل - Employee ID</p>
            </div>
            <div class="id-card-body">
                <?php if (!empty($emp['photo']) && file_exists(dirname(__DIR__) . '/' . $emp['photo'])): ?>
                    <img src="../<?php echo htmlspecialchars($emp['photo']); ?>" class="emp-photo" alt="صورة الموظف">
                <?php else: ?>
                    <img src="https://ui-avatars.com/api/?name=<?php echo urlencode($emp['full_name']); ?>&background=0D8ABC&color=fff&size=128" class="emp-photo" alt="صورة الموظف">
                <?php endif; ?>
                
                <div class="emp-name"><?php echo htmlspecialchars($emp['full_name']); ?></div>
                <div class="emp-title"><?php echo htmlspecialchars($emp['job_title_name']); ?></div>
                <?php if (!empty($emp['shift_salary']) && !empty($emp['currency_name'])): ?>
                <div class="emp-salary" style="color: #6c757d; font-size: 0.9rem; margin-bottom: 15px;">الراتب: <?php echo number_format($emp['shift_salary'], 2) . " " . htmlspecialchars($emp['currency_name']); ?></div>
                <?php endif; ?>
                
                <div class="emp-details">
                    <div><strong>الرقم:</strong> <?php echo str_pad($emp['id'], 4, '0', STR_PAD_LEFT); ?></div>
                    <div><strong>القسم:</strong> <?php echo htmlspecialchars($emp['department']); ?></div>
                    <div><strong>الفرع:</strong> <?php echo htmlspecialchars($emp['branch_name'] ?? 'المركز الرئيسي'); ?></div>
                    <div><strong>الهاتف:</strong> <span dir="ltr"><?php echo htmlspecialchars($emp['phone']); ?></span></div>
                </div>
                
                <div class="qr-code">
                    <img src="<?php echo $qr_url; ?>" alt="QR Code">
                </div>
            </div>
            <div class="id-card-footer">
                هذه البطاقة ملك لشركة الغزالي للسفريات والسياحة. يرجى إعادتها عند انتهاء الخدمة.
            </div>
        </div>
    </div>
</body>
</html>
