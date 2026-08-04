<?php
require_once 'header.php';

// التحقق من الصلاحية (نفس منطق workflow.php)
if (!$is_admin && !has_permission('view_workflow') && !has_permission('edit_workflow')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$page_title = "إدارة حقول سير العمل";

// معالجة الحذف
if (isset($_GET['delete_field'])) {
    $field_id = $_GET['delete_field'];
    $stmt = $pdo->prepare("DELETE FROM workflow_fields WHERE id = ?");
    $stmt->execute([$field_id]);
    echo "<script>location.href='workflow_fields.php?success=deleted';</script>";
}

// جلب جميع المجموعات للفلترة
$groups = $pdo->query("SELECT * FROM workflow_field_groups WHERE is_active = 1 ORDER BY group_name")->fetchAll();

// جلب جميع الحقول مع المجموعات المرتبطة بها
$fields_query = "
    SELECT f.*, 
           GROUP_CONCAT(g.group_name SEPARATOR ', ') as group_names,
           GROUP_CONCAT(g.id SEPARATOR ',') as group_ids
    FROM workflow_fields f
    LEFT JOIN workflow_field_group_mappings gm ON f.id = gm.field_id
    LEFT JOIN workflow_field_groups g ON gm.group_id = g.id
    GROUP BY f.id
    ORDER BY f.sort_order, f.id DESC
";
$fields = $pdo->query($fields_query)->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">إدارة حقول سير العمل</h4>
            <p class="text-muted small">تعريف الحقول الديناميكية التي تظهر في خطوات سير العمل</p>
        </div>
        <a href="workflow_fields_add.php" class="btn btn-primary rounded-pill px-4">
            <i class="fas fa-plus-circle me-2"></i> إضافة حقل جديد
        </a>
    </div>

    <!-- حقول البحث والفلترة -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="input-group shadow-sm rounded-pill overflow-hidden border-0">
                <span class="input-group-text bg-white border-0 ps-4"><i class="fas fa-search text-muted"></i></span>
                <input type="text" id="searchInput" class="form-control border-0 py-2" placeholder="ابحث عن حقل (بالتسمية أو المفتاح)...">
            </div>
        </div>
        <div class="col-md-6">
            <div class="input-group shadow-sm rounded-pill overflow-hidden border-0">
                <span class="input-group-text bg-white border-0 ps-4"><i class="fas fa-filter text-muted"></i></span>
                <select id="groupFilter" class="form-select border-0 py-2">
                    <option value="">جميع الخدمات (المجموعات)</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['group_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-check-circle me-2"></i>
            <?php 
            if ($_GET['success'] == 'added') echo "تم إضافة الحقل بنجاح.";
            if ($_GET['success'] == 'updated') echo "تم تحديث الحقل بنجاح.";
            if ($_GET['success'] == 'deleted') echo "تم حذف الحقل بنجاح.";
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr class="small text-muted text-uppercase">
                        <th class="border-0 text-center" style="width: 50px;">#</th>
                        <th class="border-0">الحقل (Label / Key)</th>
                        <th class="border-0">المجموعات المرتبطة</th>
                        <th class="border-0">النوع</th>
                        <th class="border-0 text-center">إلزامي</th>
                        <th class="border-0 text-center">نشط</th>
                        <th class="border-0 text-center">الترتيب</th>
                        <th class="border-0 text-end" style="width: 150px;">إجراءات</th>
                    </tr>
                </thead>
                <tbody id="fieldsTableBody">
                    <?php if (empty($fields)): ?>
                        <tr class="no-results">
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-3 d-block opacity-25"></i>
                                لا توجد حقول مضافة بعد
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($fields as $f): ?>
                            <tr class="field-row" data-groups="<?= $f['group_ids'] ?>">
                                <td class="text-center text-muted small"><?= $f['id'] ?></td>
                                <td class="searchable-cell">
                                    <div class="fw-bold text-dark label-text"><?= htmlspecialchars($f['field_label']) ?></div>
                                    <code class="extra-small text-primary key-text"><?= htmlspecialchars($f['field_key']) ?></code>
                                </td>
                                <td>
                                    <?php if ($f['group_names']): ?>
                                        <div class="small text-muted" style="max-width: 250px;">
                                            <i class="fas fa-layer-group me-1 opacity-50"></i> <?= htmlspecialchars($f['group_names']) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted extra-small italic">غير مرتبط بمجموعة</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border fw-normal">
                                        <i class="fas fa-tag me-1 text-muted"></i> <?= $f['field_type'] ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($f['is_required']): ?>
                                        <span class="badge bg-danger-soft text-danger">نعم</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted">لا</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($f['is_active']): ?>
                                        <span class="badge bg-success-soft text-success">نشط</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-soft text-secondary">معطل</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-light text-dark"><?= $f['sort_order'] ?></span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="workflow_fields_edit.php?id=<?= $f['id'] ?>" class="btn btn-sm btn-outline-primary border-0 rounded-circle me-1" title="تعديل">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="workflow_fields.php?delete_field=<?= $f['id'] ?>" class="btn btn-sm btn-outline-danger border-0 rounded-circle" 
                                           onclick="return confirm('هل أنت متأكد من حذف هذا الحقل؟ سيتم حذفه من جميع الخطوات والقيم المرتبطة به!')" title="حذف">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.bg-success-soft { background-color: rgba(40, 167, 69, 0.1); }
.bg-danger-soft { background-color: rgba(220, 53, 69, 0.1); }
.bg-secondary-soft { background-color: rgba(108, 117, 125, 0.1); }
.extra-small { font-size: 0.75rem; }
.italic { font-style: italic; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const groupFilter = document.getElementById('groupFilter');
    const rows = document.querySelectorAll('.field-row');
    const noResults = document.querySelector('.no-results');

    function filterTable() {
        const searchTerm = searchInput.value.toLowerCase();
        const groupTerm = groupFilter.value;
        let visibleCount = 0;

        rows.forEach(row => {
            const labelText = row.querySelector('.label-text').textContent.toLowerCase();
            const keyText = row.querySelector('.key-text').textContent.toLowerCase();
            const rowGroups = row.getAttribute('data-groups').split(',');

            const matchesSearch = labelText.includes(searchTerm) || keyText.includes(searchTerm);
            const matchesGroup = groupTerm === '' || rowGroups.includes(groupTerm);

            if (matchesSearch && matchesGroup) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        // إظهار رسالة "لا توجد نتائج" إذا تم إخفاء كل الصفوف
        if (visibleCount === 0 && searchTerm !== '' || groupTerm !== '') {
            if (!noResults) {
                const tr = document.createElement('tr');
                tr.className = 'no-results-dynamic';
                tr.innerHTML = `<td colspan="8" class="text-center py-5 text-muted">لا توجد نتائج تطابق بحثك</td>`;
                document.getElementById('fieldsTableBody').appendChild(tr);
            }
        } else {
            const dynamicNoResults = document.querySelector('.no-results-dynamic');
            if (dynamicNoResults) dynamicNoResults.remove();
        }
    }

    searchInput.addEventListener('input', filterTable);
    groupFilter.addEventListener('change', filterTable);
});
</script>

<?php require_once 'footer.php'; ?>
