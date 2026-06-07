<?php
require_once 'header.php';

// التحقق من الصلاحية
$user_role = $_SESSION['role'] ?? 'editor';
if($user_role === 'editor' && !$settings['allow_editor_services']) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$upload_dir = '../assets/uploads/services/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// إضافة خدمة جديدة
if(isset($_POST['add_service'])) {
    $service_image = '';
    if(!empty($_FILES['service_image']['name'])) {
        $service_image = time() . '_' . basename($_FILES['service_image']['name']);
        move_uploaded_file($_FILES['service_image']['tmp_name'], $upload_dir . $service_image);
    }

    $revenue_account_id = !empty($_POST['revenue_account_id']) ? (int)$_POST['revenue_account_id'] : null;
    $cost_account_id = !empty($_POST['cost_account_id']) ? (int)$_POST['cost_account_id'] : null;
    $profit_account_id = !empty($_POST['profit_account_id']) ? (int)$_POST['profit_account_id'] : null;
    
    $stmt = $pdo->prepare("INSERT INTO services (service_name, service_image, price, currency_id, nights_count, hotel_name, makkah_days, madinah_days, quad_price, triple_price, double_price, print_terms, revenue_account_id, cost_account_id, profit_account_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $_POST['service_name'], $service_image, $_POST['price'], $_POST['currency_id'], $_POST['nights_count'], 
        $_POST['hotel_name'], $_POST['makkah_days'], $_POST['madinah_days'], 
        $_POST['quad_price'], $_POST['triple_price'], $_POST['double_price'],
        $_POST['print_terms'],
        $revenue_account_id, $cost_account_id, $profit_account_id
    ]);
    header("Location: services.php?success=1");
    exit;
}

// تحديث خدمة
if(isset($_POST['update_service'])) {
    $service_id = $_POST['service_id'];
    $service_image = $_POST['current_image'];

    if(!empty($_FILES['service_image']['name'])) {
        $service_image = time() . '_' . basename($_FILES['service_image']['name']);
        move_uploaded_file($_FILES['service_image']['tmp_name'], $upload_dir . $service_image);
    }

    $revenue_account_id = !empty($_POST['revenue_account_id']) ? (int)$_POST['revenue_account_id'] : null;
    $cost_account_id = !empty($_POST['cost_account_id']) ? (int)$_POST['cost_account_id'] : null;
    $profit_account_id = !empty($_POST['profit_account_id']) ? (int)$_POST['profit_account_id'] : null;
    
    $stmt = $pdo->prepare("UPDATE services SET 
        service_name = ?, service_image = ?, price = ?, currency_id = ?, nights_count = ?, 
        hotel_name = ?, makkah_days = ?, madinah_days = ?, 
        quad_price = ?, triple_price = ?, double_price = ?, print_terms = ?,
        revenue_account_id = ?, cost_account_id = ?, profit_account_id = ? 
        WHERE id = ?");
    $stmt->execute([
        $_POST['service_name'], $service_image, $_POST['price'], $_POST['currency_id'], $_POST['nights_count'], 
        $_POST['hotel_name'], $_POST['makkah_days'], $_POST['madinah_days'], 
        $_POST['quad_price'], $_POST['triple_price'], $_POST['double_price'],
        $_POST['print_terms'],
        $revenue_account_id, $cost_account_id, $profit_account_id,
        $service_id
    ]);
    header("Location: services.php?updated=1");
    exit;
}

// حذف خدمة
if(isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM services WHERE id = ?")->execute([$id]);
    header("Location: services.php?deleted=1");
    exit;
}

$services = $pdo->query("
    SELECT s.*, c.currency_name, c.currency_code 
    FROM services s 
    LEFT JOIN currencies c ON s.currency_id = c.id 
    ORDER BY s.created_at DESC
")->fetchAll();

$currencies = $pdo->query("SELECT * FROM currencies ORDER BY currency_name ASC")->fetchAll();
$accounts = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_status = 'active' ORDER BY account_code ASC")->fetchAll();
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3>إدارة الخدمات وبرامج العمرة</h3>
        <button class="btn btn-primary shadow-sm rounded-pill px-4" data-bs-toggle="modal" data-bs-target="#addServiceModal">
            <i class="fas fa-plus me-2"></i> إضافة خدمة جديدة
        </button>
    </div>

    <?php if(isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show border-0 shadow-sm rounded-3">
            تم إضافة الخدمة بنجاح!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['updated'])): ?>
        <div class="alert alert-info alert-dismissible fade show border-0 shadow-sm rounded-3">
            تم تحديث بيانات الخدمة بنجاح.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">الصورة</th>
                            <th>اسم الخدمة</th>
                            <th>الشروط الخاصة</th>
                            <th>السعر</th>
                            <th>الفندق</th>
                            <th>الليالي</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($services)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">لا توجد خدمات مضافة حالياً.</td></tr>
                        <?php endif; ?>
                        <?php foreach($services as $s): ?>
                        <tr>
                            <td class="px-4 py-3">
                                <?php if($s['service_image']): ?>
                                    <img src="../assets/uploads/services/<?php echo $s['service_image']; ?>" class="rounded-3 border shadow-sm" width="60" height="45" style="object-fit: cover;">
                                <?php else: ?>
                                    <div class="bg-light rounded-3 d-flex align-items-center justify-content-center text-muted" style="width: 60px; height: 45px;">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="fw-bold"><?php echo $s['service_name']; ?></td>
                            <td>
                                <div class="text-muted extra-small" style="max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($s['print_terms'] ?? ''); ?>">
                                    <?php echo $s['print_terms'] ?: '<span class="opacity-50">لا توجد شروط خاصة</span>'; ?>
                                </div>
                            </td>
                            <td>
                                <span class="fw-bold text-success">
                                    <?php echo number_format($s['price'], 2); ?> 
                                    <small class="text-muted"><?php echo $s['currency_code']; ?></small>
                                </span>
                            </td>
                            <td><?php echo $s['hotel_name'] ?: '<span class="text-muted small">غير محدد</span>'; ?></td>
                            <td>
                                <span class="badge bg-info bg-opacity-10 text-info px-3">
                                    <?php echo $s['nights_count'] ?: 0; ?> ليلة
                                </span>
                            </td>
                            <td class="text-center">
                                <button class="btn btn-sm btn-outline-primary rounded-pill px-3 me-1" data-bs-toggle="modal" data-bs-target="#editServiceModal<?php echo $s['id']; ?>">
                                    <i class="fas fa-edit me-1"></i> تعديل
                                </button>
                                <a href="?delete=<?php echo $s['id']; ?>" class="btn btn-sm btn-outline-danger rounded-pill px-3" onclick="return confirm('هل أنت متأكد من حذف هذه الخدمة؟')">
                                    <i class="fas fa-trash me-1"></i> حذف
                                </a>
                            </td>
                        </tr>

                        <!-- Modal تعديل الخدمة -->
                        <div class="modal fade" id="editServiceModal<?php echo $s['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-lg">
                                <div class="modal-content border-0 shadow">
                                    <form method="POST" enctype="multipart/form-data">
                                        <div class="modal-header bg-primary text-white py-3">
                                            <h5 class="modal-title"><i class="fas fa-edit me-2"></i> تعديل الخدمة: <?php echo $s['service_name']; ?></h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <input type="hidden" name="service_id" value="<?php echo $s['id']; ?>">
                                            <input type="hidden" name="current_image" value="<?php echo $s['service_image']; ?>">
                                            <div class="row">
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">اسم الخدمة</label>
                                                    <input type="text" name="service_name" class="form-control" value="<?php echo $s['service_name']; ?>" required>
                                                </div>
                                                <div class="col-md-3 mb-3">
                                                    <label class="form-label fw-bold">السعر الأساسي</label>
                                                    <input type="number" step="0.01" name="price" class="form-control" value="<?php echo $s['price']; ?>" required>
                                                </div>
                                                <div class="col-md-3 mb-3">
                                                    <label class="form-label fw-bold">العملة</label>
                                                    <select name="currency_id" class="form-select" required>
                                                        <?php foreach($currencies as $c): ?>
                                                            <option value="<?php echo $c['id']; ?>" <?php echo ($c['id'] == $s['currency_id']) ? 'selected' : ''; ?>>
                                                                <?php echo $c['currency_name']; ?> (<?php echo $c['currency_code']; ?>)
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-12 mb-3">
                                                    <label class="form-label fw-bold">صورة الخدمة</label>
                                                    <input type="file" name="service_image" class="form-control mb-2">
                                                    <?php if($s['service_image']): ?>
                                                        <img src="../assets/uploads/services/<?php echo $s['service_image']; ?>" class="img-thumbnail" width="120">
                                                    <?php endif; ?>
                                                </div>
                                                <div class="col-md-6 mb-3">
                                                    <label class="form-label fw-bold">اسم الفندق</label>
                                                    <input type="text" name="hotel_name" class="form-control" value="<?php echo $s['hotel_name']; ?>">
                                                </div>
                                                <div class="col-md-2 mb-3">
                                                    <label class="form-label fw-bold">إجمالي الليالي</label>
                                                    <input type="number" name="nights_count" class="form-control" value="<?php echo $s['nights_count']; ?>">
                                                </div>
                                                <div class="col-md-2 mb-3">
                                                    <label class="form-label fw-bold">أيام مكة</label>
                                                    <input type="number" name="makkah_days" class="form-control" value="<?php echo $s['makkah_days']; ?>">
                                                </div>
                                                <div class="col-md-2 mb-3">
                                                    <label class="form-label fw-bold">أيام المدينة</label>
                                                    <input type="number" name="madinah_days" class="form-control" value="<?php echo $s['madinah_days']; ?>">
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">سعر الغرفة الثنائية</label>
                                                    <input type="number" step="0.01" name="double_price" class="form-control" value="<?php echo $s['double_price']; ?>">
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">سعر الغرفة الثلاثية</label>
                                                    <input type="number" step="0.01" name="triple_price" class="form-control" value="<?php echo $s['triple_price']; ?>">
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">سعر الغرفة الرباعية</label>
                                                    <input type="number" step="0.01" name="quad_price" class="form-control" value="<?php echo $s['quad_price']; ?>">
                                                </div>
                                                <div class="col-12 mb-3">
                                                    <label class="form-label fw-bold">شروط وأحكام خاصة بهذه الخدمة (تظهر في السند)</label>
                                                    <textarea name="print_terms" class="form-control" rows="4" placeholder="مثال لفيزا العمل: المكتب غير مسؤول عن الرفض الطبي..."><?php echo htmlspecialchars($s['print_terms'] ?? ''); ?></textarea>
                                                    <div class="form-text small text-primary"><i class="fas fa-info-circle me-1"></i> هذه الشروط ستظهر في الجزء الجانبي من سند القبض عند اختيار هذه الخدمة.</div>
                                                </div>
                                                <hr>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">حساب الإيرادات (Revenue)</label>
                                                    <select name="revenue_account_id" class="form-select">
                                                        <option value="">اختر الحساب...</option>
                                                        <?php foreach($accounts as $acc): ?>
                                                            <option value="<?php echo $acc['id']; ?>" <?php echo $acc['id'] == $s['revenue_account_id'] ? 'selected' : ''; ?>>
                                                                <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">حساب التكلفة (Cost)</label>
                                                    <select name="cost_account_id" class="form-select">
                                                        <option value="">اختر الحساب...</option>
                                                        <?php foreach($accounts as $acc): ?>
                                                            <option value="<?php echo $acc['id']; ?>" <?php echo $acc['id'] == $s['cost_account_id'] ? 'selected' : ''; ?>>
                                                                <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-4 mb-3">
                                                    <label class="form-label fw-bold">حساب الأرباح (Profit)</label>
                                                    <select name="profit_account_id" class="form-select">
                                                        <option value="">اختر الحساب...</option>
                                                        <?php foreach($accounts as $acc): ?>
                                                            <option value="<?php echo $acc['id']; ?>" <?php echo $acc['id'] == $s['profit_account_id'] ? 'selected' : ''; ?>>
                                                                <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                            <button type="submit" name="update_service" class="btn btn-primary px-4">حفظ التغييرات</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة خدمة -->
<div class="modal fade" id="addServiceModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> إضافة برنامج عمرة / خدمة جديدة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم الخدمة</label>
                            <input type="text" name="service_name" class="form-control" placeholder="أدخل اسم البرنامج أو الخدمة" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">السعر الأساسي</label>
                            <input type="number" step="0.01" name="price" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-bold">العملة</label>
                            <select name="currency_id" class="form-select" required>
                                <option value="" disabled selected>اختر العملة</option>
                                <?php foreach($currencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>">
                                        <?php echo $c['currency_name']; ?> (<?php echo $c['currency_code']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label fw-bold">صورة الخدمة</label>
                            <input type="file" name="service_image" class="form-control">
                            <div class="form-text">يفضل استخدام صور عالية الجودة بنسبة عرض 4:3.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم الفندق</label>
                            <input type="text" name="hotel_name" class="form-control" placeholder="اسم الفندق المقترح">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label fw-bold">إجمالي الليالي</label>
                            <input type="number" name="nights_count" class="form-control" placeholder="0">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label fw-bold">أيام مكة</label>
                            <input type="number" name="makkah_days" class="form-control" placeholder="0">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label fw-bold">أيام المدينة</label>
                            <input type="number" name="madinah_days" class="form-control" placeholder="0">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-primary">سعر الغرفة الثنائية</label>
                            <input type="number" step="0.01" name="double_price" class="form-control border-primary border-opacity-25" placeholder="0.00">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-primary">سعر الغرفة الثلاثية</label>
                            <input type="number" step="0.01" name="triple_price" class="form-control border-primary border-opacity-25" placeholder="0.00">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold text-primary">سعر الغرفة الرباعية</label>
                            <input type="number" step="0.01" name="quad_price" class="form-control border-primary border-opacity-25" placeholder="0.00">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">شروط وأحكام خاصة بهذه الخدمة (تظهر في السند)</label>
                            <textarea name="print_terms" class="form-control" rows="4" placeholder="اكتب الشروط الخاصة بهذه الخدمة ليتم طباعتها في السند..."></textarea>
                            <div class="form-text small text-primary"><i class="fas fa-info-circle me-1"></i> هذه الشروط خاصة بكل خدمة على حدة وتظهر في المساحة الجانبية للسند.</div>
                        </div>
                        <hr>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">حساب الإيرادات (Revenue)</label>
                            <select name="revenue_account_id" class="form-select">
                                <option value="">اختر الحساب...</option>
                                <?php foreach($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">حساب التكلفة (Cost)</label>
                            <select name="cost_account_id" class="form-select">
                                <option value="">اختر الحساب...</option>
                                <?php foreach($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label fw-bold">حساب الأرباح (Profit)</label>
                            <select name="profit_account_id" class="form-select">
                                <option value="">اختر الحساب...</option>
                                <?php foreach($accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>">
                                        <?php echo $acc['account_code']; ?> - <?php echo $acc['account_name_ar']; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_service" class="btn btn-primary px-5 shadow-sm rounded-pill">حفظ البرنامج</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
