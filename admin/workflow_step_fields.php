<?php
require_once 'header.php';

// التحقق من الصلاحية
if (!$is_admin && !has_permission('edit_workflow')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$page_title = "ربط الحقول بخطوات سير العمل";

try {
    // معالجة تحديثات AJAX لتغيير حالة الحقول
    if (isset($_POST['ajax_action'])) {
        $step_id = $_POST['step_id'];
        $field_id = $_POST['field_id'];
        $action = $_POST['ajax_action'];

        // التأكد من وجود السجل أو إنشاؤه
        $check = $pdo->prepare("SELECT id, is_visible, is_editable, is_required FROM workflow_step_fields WHERE step_id = ? AND field_id = ?");
        $check->execute([$step_id, $field_id]);
        $mapping = $check->fetch();

        if (!$mapping) {
            $pdo->prepare("INSERT INTO workflow_step_fields (step_id, field_id, is_visible, is_editable, is_required) VALUES (?, ?, 0, 0, 0)")
                ->execute([$step_id, $field_id]);
            $mapping = ['is_visible' => 0, 'is_editable' => 0, 'is_required' => 0];
        }

        $new_val = 0;
        if ($action == 'toggle_visible') {
            $new_val = $mapping['is_visible'] ? 0 : 1;
            $pdo->prepare("UPDATE workflow_step_fields SET is_visible = ? WHERE step_id = ? AND field_id = ?")->execute([$new_val, $step_id, $field_id]);
        } elseif ($action == 'toggle_editable') {
            $new_val = $mapping['is_editable'] ? 0 : 1;
            $pdo->prepare("UPDATE workflow_step_fields SET is_editable = ? WHERE step_id = ? AND field_id = ?")->execute([$new_val, $step_id, $field_id]);
        } elseif ($action == 'toggle_required') {
            $new_val = $mapping['is_required'] ? 0 : 1;
            $pdo->prepare("UPDATE workflow_step_fields SET is_required = ? WHERE step_id = ? AND field_id = ?")->execute([$new_val, $step_id, $field_id]);
        }

        echo json_encode(['status' => 'success', 'new_val' => $new_val]);
        exit();
    }

    // جلب جميع خطوات سير العمل مجمعة حسب المسار
    $steps = $pdo->query("
        SELECT w.name as workflow_name, ws.*, w.transaction_type
        FROM workflow_steps ws
        JOIN workflows w ON ws.workflow_id = w.id
        ORDER BY w.name, ws.sort_order
    ")->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("<div class='alert alert-danger'>خطأ: " . htmlspecialchars($e->getMessage()) . "</div>");
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">إدارة حقول خطوات سير العمل</h4>
            <p class="text-muted small">حدد الحقول التي تظهر وتكون قابلة للتعديل في كل مرحلة</p>
        </div>
        <div class="btn-group">
            <a href="workflow_fields.php" class="btn btn-outline-primary rounded-pill px-4 me-2">إدارة الحقول</a>
            <a href="workflow.php" class="btn btn-primary rounded-pill px-4">إدارة المسارات</a>
        </div>
    </div>

    <?php foreach ($steps as $workflow_name => $wf_steps): ?>
        <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden">
            <div class="card-header bg-primary text-white py-3">
                <h6 class="mb-0 fw-bold"><i class="fas fa-route me-2"></i> المسار: <?= htmlspecialchars($workflow_name) ?></h6>
            </div>
            <div class="card-body p-0">
                <div class="accordion accordion-flush" id="acc_<?= md5($workflow_name) ?>">
                    <?php foreach ($wf_steps as $step): ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header">
                                <button class="accordion-button collapsed py-3 workflow-step-fields-header" type="button" data-bs-toggle="collapse" data-bs-target="#step_<?= $step['id'] ?>" id="step_<?= $step['id'] ?>_header">
                                    <span class="badge rounded-circle bg-light text-dark me-2"><?= $step['sort_order'] ?></span>
                                    <span class="fw-bold"><?= htmlspecialchars($step['step_name']) ?></span>
                                    <small class="text-muted ms-2">(<?= $step['step_key'] ?>)</small>
                                </button>
                            </h2>
                            <div id="step_<?= $step['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#acc_<?= md5($workflow_name) ?>">
                                <div class="accordion-body bg-light-50">
                                    <div class="row g-3">
                                        <?php
                                        // جلب الحقول المتاحة لهذا النوع من المعاملات
                                        // نحاول مطابقة transaction_type الخاص بالمسار مع group_key الخاص بمجموعات الحقول
                                        $transaction_type = $step['transaction_type'];
                                        // توحيد المسميات لضمان مطابقة المجموعات (group_key)
                                        $mapped_types = [$transaction_type];
                                        // الربط بين أنواع المعاملات ومفاتيح المجموعات (group_key)
                                        // دعم النصوص والـ IDs من جدول الخدمات
                                        if ($transaction_type === 'visa' || $transaction_type == '5') $mapped_types[] = 'family_visit';
                                        if ($transaction_type === 'passport_transactions' || $transaction_type == '2') $mapped_types[] = 'passport';
                                        if ($transaction_type === 'bus_flight_bookings' || $transaction_type === 'booking' || $transaction_type == '3') $mapped_types[] = 'booking';
                                        if ($transaction_type === 'umrah' || $transaction_type == '4') {
                                            $mapped_types[] = 'umrah';
                                            $mapped_types[] = 'hajj';
                                        }
                                        if ($transaction_type === 'work_visa' || $transaction_type == '6') $mapped_types[] = 'work_visa';

                                        // إضافة 'general' لكل الأنواع لظهور الحقول المشتركة
                                        $mapped_types[] = 'general';
                                        $mapped_types = array_unique($mapped_types);

                                        $placeholders = implode(',', array_fill(0, count($mapped_types), '?'));

                                        $available_fields = $pdo->prepare("
                                            SELECT f.id, f.field_key, f.field_label, f.field_type, f.sort_order,
                                                   MAX(sf.is_visible) as linked_visible,
                                                   MAX(sf.is_editable) as linked_editable,
                                                   MAX(sf.is_required) as linked_required
                                            FROM workflow_fields f
                                            JOIN workflow_field_group_mappings gm ON gm.field_id = f.id
                                            JOIN workflow_field_groups g ON gm.group_id = g.id
                                            LEFT JOIN workflow_step_fields sf ON sf.field_id = f.id AND sf.step_id = ?
                                            WHERE f.is_active = 1
                                            AND g.group_key IN ($placeholders)
                                            GROUP BY f.id, f.field_key, f.field_label, f.field_type, f.sort_order
                                            ORDER BY f.sort_order, f.field_label
                                        ");
                                        $available_fields->execute(array_merge([$step['id']], $mapped_types));
                                        $fields = $available_fields->fetchAll();

                                        foreach ($fields as $field):
                                        ?>
                                            <div class="col-md-6 col-lg-4">
                                                <div class="field-card p-3 bg-white border rounded-4 shadow-xs h-100">
                                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                                        <div>
                                                            <div class="fw-bold small"><?= htmlspecialchars($field['field_label']) ?></div>
                                                            <code class="extra-small"><?= $field['field_key'] ?></code>
                                                        </div>
                                                        <span class="badge bg-light text-muted extra-small"><?= $field['field_type'] ?></span>
                                                    </div>

                                                    <div class="d-flex gap-1 mt-3">
                                                        <button class="btn btn-sm flex-fill btn-toggle <?= $field['linked_visible'] ? 'btn-success' : 'btn-outline-secondary' ?>"
                                                            onclick="toggleField(<?= $step['id'] ?>, <?= $field['id'] ?>, 'toggle_visible', this)" title="مرئي">
                                                            <i class="fas fa-eye me-1"></i> عرض
                                                        </button>
                                                        <button class="btn btn-sm flex-fill btn-toggle <?= $field['linked_editable'] ? 'btn-warning' : 'btn-outline-secondary' ?>"
                                                            onclick="toggleField(<?= $step['id'] ?>, <?= $field['id'] ?>, 'toggle_editable', this)" title="قابل للتعديل">
                                                            <i class="fas fa-edit me-1"></i> تعديل
                                                        </button>
                                                        <button class="btn btn-sm flex-fill btn-toggle <?= $field['linked_required'] ? 'btn-danger' : 'btn-outline-secondary' ?>"
                                                            onclick="toggleField(<?= $step['id'] ?>, <?= $field['id'] ?>, 'toggle_required', this)" title="إلزامي">
                                                            <i class="fas fa-asterisk me-1"></i> إلزامي
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<style>
    .bg-light-50 {
        background-color: rgba(248, 249, 250, 0.5);
    }

    .field-card {
        transition: transform 0.2s, box-shadow 0.2s;
    }

    .field-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    }

    .btn-toggle {
        font-size: 0.7rem;
        padding: 0.4rem 0.2rem;
        border-radius: 8px;
    }

    .extra-small {
        font-size: 0.7rem;
    }

    .shadow-xs {
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
    }
</style>

<script>
    (function() {
        const hash = (window.location.hash || '').replace(/^#/, '');
        if (hash && hash.startsWith('step_')) {
            const header = document.getElementById(hash + '_header');
            if (header) {
                window.setTimeout(function() {
                    header.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    header.classList.add('bg-primary-subtle');
                    try {
                        header.click();
                    } catch (e) {}
                }, 120);
            }
        }
    })();

    function toggleField(stepId, fieldId, action, btn) {
        const formData = new FormData();
        formData.append('ajax_action', action);
        formData.append('step_id', stepId);
        formData.append('field_id', fieldId);

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        fetch('workflow_step_fields.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    // تحديث شكل الزر بناءً على النتيجة
                    if (action === 'toggle_visible') {
                        btn.className = 'btn btn-sm flex-fill btn-toggle ' + (data.new_val ? 'btn-success' : 'btn-outline-secondary');
                        btn.innerHTML = '<i class="fas fa-eye me-1"></i> عرض';
                    } else if (action === 'toggle_editable') {
                        btn.className = 'btn btn-sm flex-fill btn-toggle ' + (data.new_val ? 'btn-warning' : 'btn-outline-secondary');
                        btn.innerHTML = '<i class="fas fa-edit me-1"></i> تعديل';
                    } else if (action === 'toggle_required') {
                        btn.className = 'btn btn-sm flex-fill btn-toggle ' + (data.new_val ? 'btn-danger' : 'btn-outline-secondary');
                        btn.innerHTML = '<i class="fas fa-asterisk me-1"></i> إلزامي';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('حدث خطأ أثناء التحديث');
            })
            .finally(() => {
                btn.disabled = false;
            });
    }
</script>

<?php require_once 'footer.php'; ?>
