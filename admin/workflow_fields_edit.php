<?php
require_once 'header.php';

// التحقق من الصلاحية
if (!$is_admin && !has_permission('edit_workflow')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$page_title = "تعديل حقل";

if (!isset($_GET['id'])) {
    echo "<script>location.href='workflow_fields.php';</script>";
    exit();
}

$id = (int)$_GET['id'];
$field = $pdo->prepare("SELECT * FROM workflow_fields WHERE id = ?");
$field->execute([$id]);
$f = $field->fetch();

if (!$f) {
    echo "<script>location.href='workflow_fields.php';</script>";
    exit();
}

$page_title = "تعديل حقل: " . $f['field_label'];

// جلب المجموعات المرتبطة حالياً
$current_groups = $pdo->prepare("SELECT group_id FROM workflow_field_group_mappings WHERE field_id = ?");
$current_groups->execute([$id]);
$linked_groups = $current_groups->fetchAll(PDO::FETCH_COLUMN);

// معالجة التحديث
if (isset($_POST['update_field'])) {
    try {
        $pdo->beginTransaction();

        $field_label = $_POST['field_label'];
        $field_type = $_POST['field_type'];
        $field_options = !empty($_POST['field_options']) ? $_POST['field_options'] : null;
        $placeholder = $_POST['placeholder'];
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $sort_order = $_POST['sort_order'] ?: 0;
        $groups = isset($_POST['groups']) ? $_POST['groups'] : [];

        // تحديث الحقل
        $stmt = $pdo->prepare("UPDATE workflow_fields SET
            field_label = ?, field_type = ?, field_options = ?, placeholder = ?,
            is_required = ?, is_active = ?, sort_order = ?
            WHERE id = ?");
        $stmt->execute([$field_label, $field_type, $field_options, $placeholder, $is_required, $is_active, $sort_order, $id]);

        // تحديث المجموعات (حذف القديم وإضافة الجديد)
        $pdo->prepare("DELETE FROM workflow_field_group_mappings WHERE field_id = ?")->execute([$id]);
        if (!empty($groups)) {
            $stmt_mapping = $pdo->prepare("INSERT INTO workflow_field_group_mappings (field_id, group_id) VALUES (?, ?)");
            foreach ($groups as $group_id) {
                $stmt_mapping->execute([$id, $group_id]);
            }
        }

        $pdo->commit();
        header('Location: workflow_fields.php?success=updated');
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "خطأ في التحديث: " . $e->getMessage();
    }
}
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="fw-bold mb-0"><i class="fas fa-edit text-warning me-2"></i> تعديل حقل: <?= htmlspecialchars($f['field_label']) ?></h5>
                </div>
                <div class="card-body p-4">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger rounded-4 border-0 mb-4"><?= $error ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">المفتاح البرمجي (Field Key)</label>
                                <input type="text" class="form-control rounded-pill bg-light" value="<?= htmlspecialchars($f['field_key']) ?>" readonly disabled>
                                <div class="form-text extra-small text-danger">المفتاح البرمجي لا يمكن تعديله لضمان سلامة الكود.</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold">التسمية (Field Label) <span class="text-danger">*</span></label>
                                <input type="text" name="field_label" class="form-control rounded-pill"
                                    value="<?= htmlspecialchars($f['field_label']) ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold">نوع الحقل</label>
                                <select name="field_type" class="form-select rounded-pill" id="field_type">
                                    <option value="text" <?= $f['field_type'] == 'text' ? 'selected' : '' ?>>نص قصير (Text)</option>
                                    <option value="number" <?= $f['field_type'] == 'number' ? 'selected' : '' ?>>رقم (Number)</option>
                                    <option value="date" <?= $f['field_type'] == 'date' ? 'selected' : '' ?>>تاريخ (Date)</option>
                                    <option value="datetime" <?= $f['field_type'] == 'datetime' ? 'selected' : '' ?>>تاريخ ووقت</option>
                                    <option value="textarea" <?= $f['field_type'] == 'textarea' ? 'selected' : '' ?>>نص طويل (Textarea)</option>
                                    <option value="select" <?= $f['field_type'] == 'select' ? 'selected' : '' ?>>قائمة منسدلة (Select)</option>
                                    <option value="checkbox" <?= $f['field_type'] == 'checkbox' ? 'selected' : '' ?>>خانة اختيار (Checkbox)</option>
                                    <option value="file" <?= $f['field_type'] == 'file' ? 'selected' : '' ?>>رفع ملف (File)</option>
                                </select>
                            </div>

                            <div class="col-md-8" id="options_div" style="<?= $f['field_type'] == 'select' ? '' : 'display:none;' ?>">
                                <label class="form-label fw-bold">خيارات القائمة (JSON Format)</label>
                                <textarea name="field_options" class="form-control rounded-4" rows="3"
                                    placeholder='{"options": ["خيار1", "خيار2", "خيار3"]}'><?= htmlspecialchars($f['field_options'] ?? '') ?></textarea>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold">نص توضيحي (Placeholder)</label>
                                <input type="text" name="placeholder" class="form-control rounded-pill" value="<?= htmlspecialchars($f['placeholder'] ?? '') ?>">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label fw-bold">الترتيب</label>
                                <input type="number" name="sort_order" class="form-control rounded-pill" value="<?= $f['sort_order'] ?>">
                            </div>

                            <div class="col-md-3">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input type="checkbox" name="is_required" class="form-check-input" id="is_required" <?= $f['is_required'] ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="is_required">حقل إجباري</label>
                                </div>
                            </div>

                            <div class="col-md-3">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input type="checkbox" name="is_active" class="form-check-input" id="is_active" <?= $f['is_active'] ? 'checked' : '' ?>>
                                    <label class="form-check-label fw-bold" for="is_active">نشط</label>
                                </div>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="mb-4">
                            <h6 class="fw-bold mb-3"><i class="fas fa-layer-group text-primary me-2"></i> ربط الحقل بمجموعات الخدمات</h6>
                            <div class="row">
                                <?php
                                $groups = $pdo->query("SELECT * FROM workflow_field_groups WHERE is_active = 1")->fetchAll();
                                foreach ($groups as $group):
                                    $is_linked = in_array($group['id'], $linked_groups);
                                ?>
                                    <div class="col-md-3 mb-2">
                                        <div class="form-check card-checkbox p-3 border rounded-4 text-center <?= $is_linked ? 'selected' : '' ?>">
                                            <input type="checkbox" name="groups[]" value="<?= $group['id'] ?>"
                                                class="form-check-input d-none" id="group_<?= $group['id'] ?>" <?= $is_linked ? 'checked' : '' ?>>
                                            <label class="form-check-label w-100" for="group_<?= $group['id'] ?>">
                                                <div class="fw-bold"><?= htmlspecialchars($group['group_name']) ?></div>
                                                <small class="text-muted"><?= $group['group_key'] ?></small>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between mt-5">
                            <a href="workflow_fields.php" class="btn btn-light rounded-pill px-4">إلغاء</a>
                            <button type="submit" name="update_field" class="btn btn-warning rounded-pill px-5 shadow">تحديث الحقل</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .extra-small {
        font-size: 0.75rem;
    }

    .card-checkbox {
        cursor: pointer;
        transition: all 0.2s;
    }

    .card-checkbox:hover {
        background-color: #f8f9fa;
        border-color: #ffc107;
    }

    .form-check-input:checked+.form-check-label {
        color: #ffc107;
    }

    .form-check-input:checked~label .fw-bold {
        color: #ffc107;
    }

    .form-check:has(.form-check-input:checked) {
        background-color: rgba(255, 193, 7, 0.05);
        border-color: #ffc107 !important;
    }
</style>

<script>
    document.getElementById('field_type').addEventListener('change', function() {
        const optionsDiv = document.getElementById('options_div');
        if (this.value === 'select') {
            optionsDiv.style.display = 'block';
        } else {
            optionsDiv.style.display = 'none';
        }
    });
</script>

<?php require_once 'footer.php'; ?>
