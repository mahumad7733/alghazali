<?php
$page_title = "سلة المحذوفات الذكية";
require_once 'header.php';

// التحقق من تفعيل سلة المحذوفات في الإعدادات
if (empty($settings['enable_recycle_bin'])) {
    echo "<script>location.href='index.php';</script>";
    exit;
}

// التحقق من صلاحية العرض
if (!has_permission('recycle_bin_view') && !$is_admin) {
    echo "<div class='container py-5 text-center'><div class='alert alert-danger rounded-4 shadow-sm p-5'><i class='fas fa-lock fs-1 mb-3 d-block'></i> عذراً، ليس لديك صلاحية للوصول إلى سلة المحذوفات.</div></div>";
    require_once 'footer.php';
    exit;
}

$success_msg = "";
$error_msg = "";

/**
 * استعادة صف محذوف من JSON المحفوظ في audit_logs (بدون إجراء مخزن — التزامن مع بنية الجداول الفعلية).
 */
function restore_deleted_row_from_audit(PDO $pdo, int $auditId): void
{
    $allowed_tables = [
        'invoices', 'financial_transactions', 'customers', 'suppliers',
        'agents', 'users', 'branches', 'unified_accounts',
    ];

    $stmt = $pdo->prepare("SELECT id, table_name, record_id, old_values, action FROM audit_logs WHERE id = ?");
    $stmt->execute([$auditId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception('سجل التدقيق غير موجود.');
    }

    $actionNorm = strtolower(trim((string)$row['action']));
    $isDelete = in_array($actionNorm, ['delete', 'حذف'], true) || $row['action'] === 'DELETE';
    if (!$isDelete) {
        throw new Exception('هذا السجل لا يمثل عملية حذف يمكن استعادتها.');
    }

    $table = $row['table_name'];
    if (!in_array($table, $allowed_tables, true)) {
        throw new Exception('استعادة هذا الجدول غير مسموحة لأسباب أمنية: ' . $table);
    }

    $data = json_decode($row['old_values'] ?? '', true);
    if (!is_array($data) || empty($data)) {
        throw new Exception('لا توجد بيانات قديمة (old_values) محفوظة للاستعادة.');
    }

    $colStmt = $pdo->prepare("
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          AND EXTRA NOT LIKE '%GENERATED%'
    ");
    $colStmt->execute([$table]);
    $validCols = array_flip($colStmt->fetchAll(PDO::FETCH_COLUMN));

    $insertCols = [];
    $insertVals = [];
    foreach ($data as $k => $v) {
        if (!isset($validCols[$k])) {
            continue;
        }
        $insertCols[] = '`' . str_replace('`', '``', $k) . '`';
        $insertVals[] = $v;
    }
    if (empty($insertCols)) {
        throw new Exception('لا توجد أعمدة مطابقة لبنية الجدول الحالية للاستعادة.');
    }

    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $sql = 'INSERT INTO `' . str_replace('`', '``', $table) . '` (' . implode(',', $insertCols) . ') VALUES (' . $placeholders . ')';
    $ins = $pdo->prepare($sql);
    $ins->execute($insertVals);
}

// معالجة استعادة السجل
if (isset($_POST['restore_id'])) {
    // التحقق من صلاحية الاستعادة
    if (!has_permission('recycle_bin_restore') && !$is_admin) {
        $error_msg = "عذراً، ليس لديك صلاحية لاستعادة السجلات المحذوفة.";
    } else {
        try {
            $audit_id = (int)$_POST['restore_id'];
            restore_deleted_row_from_audit($pdo, $audit_id);
            $success_msg = "تم استعادة السجل بنجاح إلى جدوله الأصلي.";
        } catch (PDOException $e) {
            if ((int)$e->getCode() === 23000) {
                $error_msg = "تعذر الاستعادة: يبدو أن السجل موجوداً مسبقاً أو تعارض في المفتاح.";
            } else {
                $error_msg = "خطأ في الاستعادة: " . $e->getMessage();
            }
        } catch (Exception $e) {
            $error_msg = "خطأ في الاستعادة: " . $e->getMessage();
        }
    }
}

// جلب السجلات المحذوفة فقط
$query = "
    SELECT l.*, u.full_name as user_name, u.username
    FROM audit_logs l
    LEFT JOIN users u ON l.user_id = u.id
    WHERE l.action IN ('delete', 'DELETE', 'حذف')
    ORDER BY l.created_at DESC
";
$logs = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);

// ترجمة أسماء الجداول للعربية
$table_translations = [
    'invoices' => 'الفواتير',
    'financial_transactions' => 'السندات والقيود',
    'customers' => 'العملاء',
    'suppliers' => 'الموردين',
    'agents' => 'الوكلاء',
    'users' => 'المستخدمين',
    'branches' => 'الفروع',
    'unified_accounts' => 'الحسابات'
];

// ترجمة أسماء الحقول للعربية
$field_translations = [
    'id' => 'المعرف',
    'invoice_number' => 'رقم الفاتورة',
    'transaction_number' => 'رقم السند',
    'invoice_date' => 'تاريخ الفاتورة',
    'transaction_date' => 'تاريخ السند',
    'total_amount' => 'المبلغ الإجمالي',
    'amount' => 'المبلغ',
    'description' => 'البيان/الوصف',
    'customer_id' => 'العميل',
    'supplier_id' => 'المورد',
    'agent_id' => 'الوكيل',
    'created_at' => 'تاريخ الإنشاء',
    'created_by' => 'بواسطة',
    'invoice_status' => 'حالة الفاتورة',
    'status' => 'الحالة',
    'currency_id' => 'العملة',
    'branch_id' => 'الفرع'
];

function renderDeletedData($value) {
    global $field_translations;
    if (empty($value)) return '-';
    $decoded = json_decode($value, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $html = '<div class="deleted-data-viewer small p-2 rounded" style="max-height: 150px; overflow-y: auto;">';
        $html .= '<table class="table table-sm table-borderless mb-0">';
        foreach ($decoded as $k => $v) {
            if (!is_array($v) && !empty($v)) {
                $label = $field_translations[$k] ?? $k;
                $html .= "<tr><td class='text-muted' style='width: 40%; font-size: 0.75rem;'>$label:</td><td class='fw-bold' style='font-size: 0.8rem;'>" . htmlspecialchars($v) . "</td></tr>";
            }
        }
        $html .= '</table></div>';
        return $html;
    }
    return htmlspecialchars(substr($value, 0, 50)) . '...';
}
?>

<style>
    .deleted-data-viewer::-webkit-scrollbar { width: 4px; }
    .deleted-data-viewer::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
    .apple-card { background: white; border-radius: 20px; border: 1px solid rgba(0,0,0,0.05); box-shadow: 0 4px 20px rgba(0,0,0,0.03); overflow: hidden; }
    .btn-restore { background: #007aff; color: white; border: none; padding: 0.5rem 1.2rem; border-radius: 40px; font-weight: 600; transition: all 0.2s; font-size: 0.8rem; }
    .btn-restore:hover { background: #0056b3; transform: scale(1.05); color: white; }
    .badge-table { padding: 5px 10px; border-radius: 8px; font-weight: 600; font-size: 0.75rem; background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb; }
</style>

<div class="container-fluid py-4 apple-container">
    <div class="apple-card mb-4 border-0 shadow-sm" style="background: linear-gradient(135deg, #007aff, #00c7ff);">
        <div class="card-body p-4 text-white">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h3 class="fw-bold mb-1"><i class="fas fa-trash-restore-alt me-2"></i> سلة المحذوفات الذكية</h3>
                    <p class="mb-0 opacity-75">استعادة البيانات المالية والإدارية التي تم حذفها بالخطأ بالاعتماد على سجلات التدقيق الموحدة.</p>
                </div>
                <div class="bg-white bg-opacity-20 p-3 rounded-4 text-center" style="min-width: 120px;">
                    <h4 class="mb-0 fw-bold"><?php echo count($logs); ?></h4>
                    <small class="opacity-75">سجل محذوف</small>
                </div>
            </div>
        </div>
    </div>

    <?php if ($success_msg): ?>
        <div class="alert alert-success rounded-4 shadow-sm border-0 mb-4 p-3"><i class="fas fa-check-circle me-2"></i> <?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger rounded-4 shadow-sm border-0 mb-4 p-3"><i class="fas fa-exclamation-circle me-2"></i> <?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="apple-card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">التوقيت</th>
                        <th>نوع السجل</th>
                        <th>المعرف</th>
                        <th style="width: 40%;">محتوى البيانات (كامل)</th>
                        <th>المستخدم المسؤول</th>
                        <th class="text-center pe-4">الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-inbox fs-1 d-block mb-3 opacity-25"></i> لا توجد محذوفات في الأرشيف حالياً.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td class="ps-4">
                                    <div class="fw-bold small"><?php echo date('Y/m/d', strtotime($l['created_at'])); ?></div>
                                    <div class="extra-small text-muted"><?php echo str_replace(['AM', 'PM'], ['صباحاً', 'مساءً'], date('h:i A', strtotime($l['created_at']))); ?></div>
                                </td>
                                <td><span class="badge-table"><?php echo $table_translations[$l['table_name']] ?? $l['table_name']; ?></span></td>
                                <td class="fw-bold text-primary">#<?php echo $l['record_id']; ?></td>
                                <td>
                                    <?php echo renderDeletedData($l['old_values']); ?>
                                    <button class="btn btn-link btn-sm p-0 extra-small mt-1 text-primary" onclick='showFullData(<?php echo json_encode($l); ?>)'>
                                        عرض التفاصيل كاملة
                                    </button>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="bg-light rounded-circle p-2 me-2 text-center" style="width: 32px; height: 32px;"><i class="fas fa-user small text-muted"></i></div>
                                        <span class="small fw-bold"><?php echo htmlspecialchars($l['user_name'] ?: $l['username']); ?></span>
                                    </div>
                                </td>
                                <td class="text-center pe-4">
                                    <form method="POST" onsubmit="return confirm('تنبيه: سيتم إعادة هذا السجل لجدوله الأصلي. هل تريد الاستمرار؟');">
                                        <input type="hidden" name="restore_id" value="<?php echo $l['id']; ?>">
                                        <button type="submit" class="btn-restore">
                                            <i class="fas fa-undo me-1"></i> استعادة البيانات
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal لعرض البيانات الكاملة -->
<div class="modal fade" id="dataModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary text-white border-0 rounded-top-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-eye me-2"></i> تفاصيل البيانات المحذوفة</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="modalDataContent">
                <!-- سيتم ملؤه بواسطة JS -->
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">إغلاق</button>
            </div>
        </div>
    </div>
</div>

<script>
function showFullData(log) {
    const tableTranslations = <?php echo json_encode($table_translations); ?>;
    const fieldTranslations = <?php echo json_encode($field_translations); ?>;
    const data = JSON.parse(log.old_values);
    
    let html = `
        <div class="mb-4">
            <span class="badge bg-primary-subtle text-primary p-2 px-3 rounded-pill">
                ${tableTranslations[log.table_name] || log.table_name} - سجل رقم #${log.record_id}
            </span>
            <span class="text-muted small ms-2">حذف بواسطة: ${log.user_name || log.username}</span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered rounded-3 overflow-hidden">
                <thead class="bg-light">
                    <tr>
                        <th style="width: 35%;">الحقل</th>
                        <th>القيمة</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    for (const [key, value] of Object.entries(data)) {
        if (value !== null && value !== '') {
            html += `
                <tr>
                    <td class="bg-light fw-bold small">${fieldTranslations[key] || key}</td>
                    <td class="small">${value}</td>
                </tr>
            `;
        }
    }
    
    html += `
                </tbody>
            </table>
        </div>
    `;
    
    $('#modalDataContent').html(html);
    $('#dataModal').modal('show');
}
</script>

<?php require_once 'footer.php'; ?>
