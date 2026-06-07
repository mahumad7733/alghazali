<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_GET['id'])) {
    die("معرف الموظف غير موجود");
}

$id = $_GET['id'];
$stmt = $pdo->prepare("
    SELECT e.*, b.branch_name, jt.title_name as job_title_name
    FROM employees e
    LEFT JOIN branches b ON e.branch_id = b.id
    LEFT JOIN job_titles jt ON e.job_title_id = jt.id
    WHERE e.id = ? AND e.deleted_at IS NULL
");
$stmt->execute([$id]);
$emp = $stmt->fetch();

if (!$emp) {
    die("الموظف غير موجود");
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>بطاقة موظف - <?php echo htmlspecialchars($emp['full_name']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap');
        body {
            font-family: 'Tajawal', sans-serif;
            background-color: #f8f9fa;
        }
        .card-container {
            width: 350px;
            height: 550px;
            margin: 50px auto;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            position: relative;
            border: 1px solid #eee;
        }
        .card-header {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            height: 150px;
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: white;
            padding-bottom: 40px;
        }
        .card-header h4 {
            margin: 0;
            font-weight: 700;
            letter-spacing: 1px;
        }
        .card-header p {
            font-size: 0.8rem;
            opacity: 0.8;
            margin: 5px 0 0 0;
        }
        .photo-container {
            width: 130px;
            height: 130px;
            background: white;
            border-radius: 50%;
            padding: 5px;
            position: absolute;
            top: 85px;
            left: 50%;
            transform: translateX(-50%);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            z-index: 2;
        }
        .photo-container img {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
        }
        .photo-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: #f0f2f5;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #adb5bd;
            font-size: 3rem;
        }
        .card-body {
            padding-top: 80px;
            text-align: center;
        }
        .emp-name {
            font-size: 1.4rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }
        .emp-title {
            font-size: 1rem;
            color: #0d6efd;
            font-weight: 600;
            margin-bottom: 20px;
        }
        .info-list {
            text-align: right;
            padding: 0 30px;
        }
        .info-item {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            font-size: 0.9rem;
            color: #555;
        }
        .info-item i {
            width: 30px;
            color: #0d6efd;
            font-size: 1rem;
        }
        .card-footer {
            position: absolute;
            bottom: 0;
            width: 100%;
            height: 60px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: center;
            border-top: 1px solid #eee;
        }
        .barcode-container {
            position: absolute;
            bottom: 70px;
            left: 50%;
            transform: translateX(-50%);
            text-align: center;
        }
        #barcode {
            max-width: 200px;
            height: auto;
        }
        @media print {
            body { background: white; }
            .no-print { display: none; }
            .card-container { margin: 0; box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

    <div class="text-center mt-4 no-print">
        <button onclick="window.print()" class="btn btn-primary px-4 rounded-pill">
            <i class="fas fa-print me-2"></i> طباعة البطاقة
        </button>
        <button onclick="window.close()" class="btn btn-outline-secondary px-4 rounded-pill ms-2">
            إغلاق
        </button>
    </div>

    <div class="card-container">
        <div class="card-header">
            <h4>الغزالي للسفريات</h4>
            <p>Al-Ghazali Travel & Tourism</p>
        </div>
        
        <div class="photo-container">
            <?php if (!empty($emp['photo']) && file_exists("../" . $emp['photo'])): ?>
                <img src="../<?php echo htmlspecialchars($emp['photo']); ?>" alt="صورة الموظف">
            <?php else: ?>
                <div class="photo-placeholder">
                    <i class="fas fa-user"></i>
                </div>
            <?php endif; ?>
        </div>

        <div class="card-body">
            <div class="emp-name"><?php echo htmlspecialchars($emp['full_name']); ?></div>
            <div class="emp-title"><?php echo htmlspecialchars($emp['job_title_name'] ?: $emp['job_title']); ?></div>
            
            <div class="info-list">
                <div class="info-item">
                    <i class="fas fa-id-badge"></i>
                    <span>رقم الموظف: #<?php echo str_pad($emp['id'], 4, '0', STR_PAD_LEFT); ?></span>
                </div>
                <div class="info-item">
                    <i class="fas fa-phone"></i>
                    <span>الهاتف: <?php echo htmlspecialchars($emp['phone'] ?: '---'); ?></span>
                </div>
                <div class="info-item">
                    <i class="fas fa-building"></i>
                    <span>الفرع: <?php echo htmlspecialchars($emp['branch_name'] ?: 'المركز الرئيسي'); ?></span>
                </div>
                <div class="info-item">
                    <i class="fas fa-calendar-alt"></i>
                    <span>تاريخ التعيين: <?php echo htmlspecialchars($emp['hire_date'] ?: '---'); ?></span>
                </div>
            </div>

            <div class="barcode-container">
                <svg id="barcode"></svg>
            </div>
        </div>

        <div class="card-footer">
            <small class="text-muted">بطاقة تعريفية رسمية - صالحة لمدة عام</small>
        </div>
    </div>

    <script>
        // توليد الباركود
        JsBarcode("#barcode", "<?php echo str_pad($emp['id'], 6, '0', STR_PAD_LEFT); ?>", {
            format: "CODE128",
            lineColor: "#333",
            width: 1.5,
            height: 40,
            displayValue: true,
            fontSize: 12,
            font: "Tajawal"
        });

        // التوجيه للطباعة تلقائياً عند التحميل
        window.onload = function() {
            // window.print();
        };
    </script>
</body>
</html>
