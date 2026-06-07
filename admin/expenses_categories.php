<?php
require_once 'header.php';
require_once '../includes/accounting_functions.php';

// التحقق من الصلاحيات هنا إذا لزم الأمر

$available_accounts = get_available_accounts_for_entity('expense_category');

// إضافة فئة مصروف جديدة
if (isset($_POST['add_category'])) {
    $category_name_ar = $_POST['category_name_ar'];
    $category_name_en = $_POST['category_name_en'];
    $account_id = !empty($_POST['account_id']) ? $_POST['account_id'] : null;
    $description = $_POST['description'];
    $status = $_POST['status'];
    $created_by = $_SESSION['admin_id']; // افتراض وجود جلسة للمستخدم المسجل

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO expenses_categories (category_name_ar, category_name_en, account_id, description, status, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$category_name_ar, $category_name_en, $account_id, $description, $status, $created_by]);
        $category_id = $pdo->lastInsertId();

        // إنشاء حساب في شجرة الحسابات تلقائياً إذا لم يتم اختيار حساب
        if (!$account_id) {
            $parent_code = get_parent_account_code_by_entity('expense_category');
            $new_chart_account_id = create_sub_account($parent_code, "فئة مصروف: " . $category_name_ar, $category_id, 'expense_category');

            if ($new_chart_account_id) {
                $pdo->prepare("UPDATE expenses_categories SET account_id = ? WHERE id = ?")->execute([$new_chart_account_id, $category_id]);
            }
        }

        $pdo->commit();
        $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تمت إضافة فئة المصروف بنجاح.'];
        echo "<script>location.href='expenses_categories.php';</script>";
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = "حدث خطأ أثناء الإضافة: " . $e->getMessage();
    }
}

// تحديث فئة مصروف
if (isset($_POST['update_category'])) {
    $id = $_POST['id'];
    $category_name_ar = $_POST['category_name_ar'];
    $category_name_en = $_POST['category_name_en'];
    $account_id = !empty($_POST['account_id']) ? $_POST['account_id'] : null;
    $description = $_POST['description'];
    $status = $_POST['status'];

    try {
        $stmt = $pdo->prepare("UPDATE expenses_categories SET category_name_ar = ?, category_name_en = ?, account_id = ?, description = ?, status = ? WHERE id = ?");
        $stmt->execute([$category_name_ar, $category_name_en, $account_id, $description, $status, $id]);
        $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم تحديث فئة المصروف بنجاح.'];
        echo "<script>location.href='expenses_categories.php';</script>";
        exit();
    } catch (PDOException $e) {
        $error = "حدث خطأ أثناء التحديث: " . $e->getMessage();
    }
}

// حذف فئة مصروف (أرشفة)
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        // التحقق مما إذا كانت الفئة مرتبطة بمصروفات
        $check_expenses = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE category_id = ? AND deleted_at IS NULL");
        $check_expenses->execute([$id]);

        if ($check_expenses->fetchColumn() > 0) {
            $error = "لا يمكن حذف فئة المصروف لارتباطها بمصروفات موجودة. يمكنك تعطيلها بدلاً من ذلك.";
        } else {
            $stmt = $pdo->prepare("UPDATE expenses_categories SET deleted_at = NOW(), status = 'inactive' WHERE id = ?");
            $stmt->execute([$id]);
            $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم حذف فئة المصروف بنجاح.'];
            echo "<script>location.href='expenses_categories.php';</script>";
            exit();
        }
    } catch (PDOException $e) {
        $error = "حدث خطأ أثناء الحذف: " . $e->getMessage();
    }
}

// جلب فئات المصاريف
$categories_stmt = $pdo->prepare("
    SELECT ec.*, coa.account_name_ar as coa_name, coa.account_code as coa_code
    FROM expenses_categories ec
    LEFT JOIN unified_accounts coa ON ec.account_id = coa.id
    WHERE ec.deleted_at IS NULL
    ORDER BY ec.category_name_ar
");
$categories_stmt->execute();
$categories = $categories_stmt->fetchAll();

$page_title = "إدارة فئات المصاريف";
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary"><i class="fas fa-tags me-2"></i> إدارة فئات المصاريف</h3>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
            <i class="fas fa-plus-circle me-1"></i> إضافة فئة جديدة
        </button>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show rounded-4 shadow-sm border-0">
            <?php echo $_SESSION['flash_message']['body']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 shadow-sm border-0">
            <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-3">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3">اسم الفئة (عربي)</th>
                            <th>اسم الفئة (إنجليزي)</th>
                            <th>الحساب المحاسبي</th>
                            <th>الحالة</th>
                            <th class="text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $category): ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <span class="fw-bold"><?php echo htmlspecialchars($category['category_name_ar']); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($category['category_name_en']); ?></td>
                                <td>
                                    <?php if ($category['coa_name']): ?>
                                        <div class="small fw-bold text-primary"><?php echo htmlspecialchars($category['coa_name']); ?></div>
                                        <div class="small text-muted"><?php echo htmlspecialchars($category['coa_code']); ?></div>
                                    <?php else: ?>
                                        <span class="text-muted small">غير مرتبط</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($category['status'] == 'active'): ?>
                                        <span class="badge bg-success">نشط</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">معطل</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary me-1 edit-category-btn" data-id="<?php echo $category['id']; ?>">
                                        <i class="fas fa-edit"></i> تعديل
                                    </button>
                                    <a href="expenses_categories.php?delete=<?php echo $category['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('هل أنت متأكد من حذف هذه الفئة؟')">
                                        <i class="fas fa-trash"></i> حذف
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="5" class="text-center py-5 text-muted">لا توجد فئات مصاريف مسجلة.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة فئة -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i> إضافة فئة مصروف جديدة</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم الفئة (عربي)</label>
                            <input type="text" name="category_name_ar" class="form-control" placeholder="مثلاً: إيجار" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم الفئة (إنجليزي)</label>
                            <input type="text" name="category_name_en" class="form-control" placeholder="مثلاً: Rent">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحساب المحاسبي</label>
                            <select name="account_id" class="form-select">
                                <option value="">-- إنشاء حساب تلقائياً --</option>
                                <?php foreach ($available_accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>"><?php echo $account['account_code'] . ' - ' . $account['account_name_ar']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحالة</label>
                            <select name="status" class="form-select">
                                <option value="active">نشط</option>
                                <option value="inactive">معطل</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">الوصف</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="وصف موجز للفئة"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_category" class="btn btn-primary px-4">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal تعديل فئة (ديناميكي) -->
<div class="modal fade" id="editCategoryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-edit me-2"></i> تعديل فئة المصروف</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4" id="editCategoryModalContent">
                    <input type="hidden" name="id" id="edit_category_id">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم الفئة (عربي)</label>
                            <input type="text" name="category_name_ar" id="edit_category_name_ar" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">اسم الفئة (إنجليزي)</label>
                            <input type="text" name="category_name_en" id="edit_category_name_en" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحساب المحاسبي</label>
                            <select name="account_id" id="edit_category_account_id" class="form-select">
                                <option value="">-- إنشاء حساب تلقائياً --</option>
                                <?php foreach ($available_accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>"><?php echo $account['account_code'] . ' - ' . $account['account_name_ar']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold">الحالة</label>
                            <select name="status" id="edit_category_status" class="form-select">
                                <option value="active">نشط</option>
                                <option value="inactive">معطل</option>
                            </select>
                        </div>
                        <div class="col-12 mb-3">
                            <label class="form-label fw-bold">الوصف</label>
                            <textarea name="description" id="edit_category_description" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="update_category" class="btn btn-primary px-4">حفظ التغييرات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>

<script>
    $(document).ready(function() {
        $('.edit-category-btn').on('click', function() {
            var categoryId = $(this).data('id');
            $.ajax({
                url: 'ajax_get_expense_category.php', // ستقوم بإنشاء هذا الملف لاحقاً
                type: 'GET',
                data: {
                    id: categoryId
                },
                dataType: 'json',
                success: function(category) {
                    if (category) {
                        $('#edit_category_id').val(category.id);
                        $('#edit_category_name_ar').val(category.category_name_ar);
                        $('#edit_category_name_en').val(category.category_name_en);
                        $('#edit_category_account_id').val(category.account_id);
                        $('#edit_category_description').val(category.description);
                        $('#edit_category_status').val(category.status);
                        $('#editCategoryModal').modal('show');
                    } else {
                        alert('الفئة غير موجودة.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error(xhr.responseText);
                    alert('حدث خطأ أثناء جلب بيانات الفئة.');
                }
            });
        });
    });
</script>
