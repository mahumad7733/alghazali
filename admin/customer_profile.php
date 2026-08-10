<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/db.php';

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('يجب تسجيل الدخول.');
}

$passportId = (int)($_GET['passport_id'] ?? 0);
if ($passportId < 1) {
    http_response_code(400);
    exit('رقم ملف العميل غير صحيح.');
}

$customerStmt = $pdo->prepare('SELECT * FROM passports WHERE id = ? LIMIT 1');
$customerStmt->execute([$passportId]);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
if (!$customer) {
    http_response_code(404);
    exit('ملف العميل غير موجود.');
}

$historyStmt = $pdo->prepare('SELECT h.*, c.currency_name,
        u.full_name AS created_by_name, u.username AS created_by_username,
        b.branch_name
    FROM customer_service_history h
    LEFT JOIN currencies c ON c.id = h.currency_id
    LEFT JOIN users u ON u.id = h.created_by
    LEFT JOIN branches b ON b.id = h.branch_id
    WHERE h.passport_id = ?
    ORDER BY COALESCE(h.service_date, DATE(h.created_at)) DESC, h.id DESC');
$historyStmt->execute([$passportId]);
$history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);

$serviceNames = [
    'bus_booking' => 'حجز باص',
    'flight_booking' => 'حجز طيران',
    'bus_flight_booking' => 'حجز باص / طيران',
    'umrah' => 'العمرة',
    'hajj' => 'الحج',
    'passport_transaction' => 'معاملة جوازات',
    'work_visa' => 'تأشيرة عمل',
    'family_visit' => 'زيارة عائلية',
    'postal_services' => 'خدمات بريدية',
    'postal_service' => 'خدمة بريدية',
];

function profile_h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function profile_service_name(string $serviceType, array $serviceNames): string
{
    return $serviceNames[$serviceType] ?? ucwords(str_replace(['_', '-'], ' ', $serviceType));
}

$phone = $customer['phone_number'] ?: ($customer['mobile_number'] ?? '');
$serviceCount = count($history);
$totalAmount = 0.0;
foreach ($history as $row) {
    $totalAmount += (float)($row['amount'] ?? 0);
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ملف العميل - <?= profile_h($customer['full_name']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css">
    <style>
        body { background: #f1f5f9; color: #1e293b; }
        .profile-card, .history-card { border: 0; border-radius: 1rem; box-shadow: 0 8px 24px rgba(15, 23, 42, .08); }
        .profile-label { color: #64748b; font-size: .8rem; margin-bottom: .2rem; }
        .profile-value { font-weight: 700; min-height: 1.5rem; }
        .stat-card { border: 0; border-radius: .85rem; background: #fff; box-shadow: 0 5px 16px rgba(15, 23, 42, .06); }
        .service-badge { background: #e0f2fe; color: #0369a1; font-weight: 700; }
        .table thead th { white-space: nowrap; color: #475569; font-size: .82rem; }
        .table td { vertical-align: middle; }
        .photo-preview { max-height: 110px; max-width: 150px; object-fit: cover; }
    </style>
</head>
<body>
<main class="container-fluid container-xl py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="fas fa-folder-open text-primary me-2"></i>ملف العميل الموحد</h1>
            <div class="text-muted small">رقم الملف: #<?= $passportId ?></div>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-primary" href="customer_profiles.php"><i class="fas fa-search me-1"></i>بحث العملاء</a>
            <a class="btn btn-outline-secondary" href="javascript:history.back()">رجوع</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="stat-card p-3"><div class="text-muted small">عدد الخدمات</div><div class="h4 mb-0 text-primary"><?= $serviceCount ?></div></div></div>
        <div class="col-md-4"><div class="stat-card p-3"><div class="text-muted small">إجمالي المبالغ المسجلة</div><div class="h4 mb-0 text-success"><?= number_format($totalAmount, 2) ?></div></div></div>
        <div class="col-md-4"><div class="stat-card p-3"><div class="text-muted small">آخر خدمة</div><div class="fw-bold"><?= profile_h($history[0]['service_date'] ?? 'لا يوجد') ?></div></div></div>
    </div>

    <section class="card profile-card mb-4">
        <div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">بيانات العميل</h2></div>
        <div class="card-body px-4"><div class="row g-3">
            <?php foreach ([
                'full_name' => 'الاسم الكامل', 'phone' => 'رقم الجوال',
                'passport_number' => 'رقم الجواز', 'nationality' => 'الجنسية',
                'gender' => 'الجنس', 'date_of_birth' => 'تاريخ الميلاد',
                'id_type' => 'نوع الهوية', 'id_number' => 'رقم الهوية',
                'id_issue_place' => 'مكان إصدار الهوية', 'passport_issue_date' => 'إصدار الجواز',
                'passport_expiry_date' => 'انتهاء الجواز'
            ] as $key => $label): ?>
                <div class="col-sm-6 col-lg-3"><div class="profile-label"><?= $label ?></div><div class="profile-value"><?= profile_h($key === 'phone' ? $phone : ($customer[$key] ?? '—')) ?></div></div>
            <?php endforeach; ?>
            <?php foreach (['personal_photo' => 'الصورة الشخصية', 'passport_image' => 'صورة الجواز'] as $key => $label): if (!empty($customer[$key])): ?>
                <div class="col-sm-6 col-lg-3"><div class="profile-label"><?= $label ?></div><img src="<?= profile_h($customer[$key]) ?>" alt="<?= $label ?>" class="photo-preview rounded border"></div>
            <?php endif; endforeach; ?>
        </div></div>
    </section>

    <section class="card history-card">
        <div class="card-header bg-white border-0 pt-4 px-4"><h2 class="h5 mb-0">السجل التفصيلي للخدمات</h2></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr>
                    <th>الخدمة</th><th>رقم العملية</th><th>تاريخ الخدمة</th><th>المبلغ</th><th>العملة</th><th>الحالة</th><th>الفرع</th><th>الموظف</th><th>تاريخ التسجيل</th>
                </tr></thead>
                <tbody>
                <?php foreach ($history as $row): ?>
                    <?php $creator = $row['created_by_name'] ?: ($row['created_by_username'] ?: 'النظام'); ?>
                    <tr>
                        <td><span class="badge service-badge"><?= profile_h(profile_service_name((string)$row['service_type'], $serviceNames)) ?></span><div class="small text-muted mt-1"><?= profile_h($row['service_type']) ?></div></td>
                        <td class="fw-bold"><?= profile_h($row['service_number'] ?: $row['service_id']) ?></td>
                        <td><?= profile_h($row['service_date'] ?: '—') ?></td>
                        <td><?= $row['amount'] !== null ? number_format((float)$row['amount'], 2) : '—' ?></td>
                        <td><?= profile_h($row['currency_name'] ?: ($row['currency_id'] ?: '—')) ?></td>
                        <td><?= profile_h($row['status'] ?: '—') ?></td>
                        <td><?= profile_h($row['branch_name'] ?: ($row['branch_id'] ?: '—')) ?></td>
                        <td><?= profile_h($creator) ?></td>
                        <td><?= profile_h($row['created_at'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$history): ?><tr><td colspan="9" class="text-center text-muted py-4">لا توجد خدمات مسجلة لهذا العميل بعد.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>
</body>
</html>
