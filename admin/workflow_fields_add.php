<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once '../includes/db.php';

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

// معالجة الحفظ
if (isset($_POST['add_field'])) {
    try {
        $pdo->beginTransaction();

        $field_key = $_POST['field_key'];
        $field_label = $_POST['field_label'];
        $field_type = $_POST['field_type'];
        $field_options = !empty($_POST['field_options']) ? $_POST['field_options'] : null;
        $placeholder = $_POST['placeholder'];
        $is_required = isset($_POST['is_required']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $sort_order = $_POST['sort_order'] ?: 0;
        $groups = isset($_POST['groups']) ? $_POST['groups'] : [];

        // إدخال الحقل
        $stmt = $pdo->prepare("INSERT INTO workflow_fields 
            (field_key, field_label, field_type, field_options, placeholder, is_required, is_active, sort_order) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$field_key, $field_label, $field_type, $field_options, $placeholder, $is_required, $is_active, $sort_order]);
        
        $field_id = $pdo->lastInsertId();

        // ربط الحقل بالمجموعات
        if (!empty($groups)) {
            $stmt_mapping = $pdo->prepare("INSERT INTO workflow_field_group_mappings (field_id, group_id) VALUES (?, ?)");
            foreach ($groups as $group_id) {
                $stmt_mapping->execute([$field_id, $group_id]);
            }
        }

        $pdo->commit();
        header('Location: workflow_fields.php?success=added');
        exit();

    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "خطأ في الإضافة: " . $e->getMessage();
    }
}

$page_title = "إضافة حقل جديد";
require_once 'header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
                <div class="card-header bg-white border-0 py-3">
                    <h5 class="fw-bold mb-0"><i class="fas fa-plus-circle text-primary me-2"></i> إضافة حقل جديد لسير العمل</h5>
                </div>
                <div class="card-body p-4">
                    <?php if (isset($error)): ?>
                        <div class="alert alert-danger rounded-4 border-0 mb-4"><?= $error ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold">المفتاح البرمجي (Field Key) <span class="text-danger">*</span></label>
                                <input type="text" name="field_key" id="field_key" class="form-control rounded-pill" 
                                       placeholder="مثال: visa_number, sponsor_name" required pattern="[a-zA-Z0-9_]+">
                                <div class="form-text extra-small">يستخدم داخلياً، يجب أن يكون بالإنجليزية وبدون مسافات.</div>
                            </div>
                            
                            <div class="col-md-6">
                                <label class="form-label fw-bold">التسمية (Field Label) <span class="text-danger">*</span></label>
                                <input type="text" name="field_label" id="field_label" class="form-control rounded-pill" 
                                       placeholder="مثال: رقم التأشيرة" required>
                                <div class="form-text extra-small">الاسم الذي سيظهر للمستخدم في النماذج.</div>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label fw-bold">نوع الحقل</label>
                                <select name="field_type" class="form-select rounded-pill" id="field_type">
                                    <option value="text">نص قصير (Text)</option>
                                    <option value="number">رقم (Number)</option>
                                    <option value="date">تاريخ (Date)</option>
                                    <option value="datetime">تاريخ ووقت</option>
                                    <option value="textarea">نص طويل (Textarea)</option>
                                    <option value="select">قائمة منسدلة (Select)</option>
                                    <option value="checkbox">خانة اختيار (Checkbox)</option>
                                    <option value="file">رفع ملف (File)</option>
                                </select>
                            </div>
                            
                            <div class="col-md-8" id="options_div" style="display:none;">
                                <label class="form-label fw-bold">خيارات القائمة (JSON Format)</label>
                                <textarea name="field_options" class="form-control rounded-4" rows="3" 
                                          placeholder='{"options": ["خيار1", "خيار2", "خيار3"]}'></textarea>
                                <div class="form-text extra-small">أدخل الخيارات بتنسيق JSON.</div>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label fw-bold">نص توضيحي (Placeholder)</label>
                                <input type="text" name="placeholder" class="form-control rounded-pill">
                            </div>

                            <div class="col-md-2">
                                <label class="form-label fw-bold">الترتيب</label>
                                <input type="number" name="sort_order" class="form-control rounded-pill" value="0">
                            </div>
                            
                            <div class="col-md-3">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input type="checkbox" name="is_required" class="form-check-input" id="is_required">
                                    <label class="form-check-label fw-bold" for="is_required">حقل إجباري</label>
                                </div>
                            </div>
                            
                            <div class="col-md-3">
                                <div class="form-check form-switch mt-4 pt-2">
                                    <input type="checkbox" name="is_active" class="form-check-input" id="is_active" checked>
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
                                ?>
                                <div class="col-md-3 mb-2">
                                    <div class="form-check card-checkbox p-3 border rounded-4 text-center">
                                        <input type="checkbox" name="groups[]" value="<?= $group['id'] ?>" 
                                               class="form-check-input d-none" id="group_<?= $group['id'] ?>">
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
                            <button type="submit" name="add_field" class="btn btn-primary rounded-pill px-5 shadow">حفظ الحقل الجديد</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.extra-small { font-size: 0.75rem; }
.card-checkbox { cursor: pointer; transition: all 0.2s; }
.card-checkbox:hover { background-color: #f8f9fa; border-color: #0d6efd; }
.form-check-input:checked + .form-check-label { color: #0d6efd; }
.form-check-input:checked ~ label .fw-bold { color: #0d6efd; }
.form-check-input:checked + label { border-color: #0d6efd; }
/* Style for selected state */
.form-check:has(.form-check-input:checked) {
    background-color: rgba(13, 110, 253, 0.05);
    border-color: #0d6efd !important;
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

// وظيفة لتوليد المفتاح البرمجي تلقائياً من التسمية
document.getElementById('field_label').addEventListener('input', function() {
    const label = this.value;
    const keyInput = document.getElementById('field_key');
    
    // خريطة بسيطة لتبديل الحروف العربية الشائعة لتوليد مفتاح إنجليزي مقترح
    const arabicToEng = {
        'ا': 'a', 'أ': 'a', 'إ': 'i', 'آ': 'a', 'ب': 'b', 'ت': 't', 'ث': 'th', 'ج': 'j', 'ح': 'h', 'خ': 'kh',
        'د': 'd', 'ذ': 'th', 'ر': 'r', 'ز': 'z', 'س': 's', 'ش': 'sh', 'ص': 's', 'ض': 'd', 'ط': 't', 'ظ': 'th',
        'ع': 'a', 'غ': 'gh', 'ف': 'f', 'ق': 'q', 'ك': 'k', 'ل': 'l', 'م': 'm', 'ن': 'n', 'ه': 'h', 'و': 'w',
        'ي': 'y', 'ى': 'a', 'ة': 'a', ' ': '_'
    };

    let key = label.toLowerCase().split('').map(char => arabicToEng[char] || char).join('');
    
    // تنظيف المفتاح (إزالة الرموز، المسافات الزائدة، إلخ)
    key = key.replace(/[^a-z0-9_]/g, '') // إبقاء الحروف الإنجليزية والأرقام والشرطة السفلية فقط
             .replace(/_+/g, '_')        // دمج الشرطات السفلية المتتالية
             .replace(/^_|_$/g, '');     // إزالة الشرطات السفلية في البداية والنهاية
             
    keyInput.value = key;
});
</script>

<?php require_once 'footer.php'; ?>
