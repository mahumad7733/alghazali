<?php
ob_start();
require_once 'header.php';
require_once dirname(__DIR__) . '/includes/accounting_functions.php';

// التحديث التلقائي لقاعدة البيانات إذا لزم الأمر
try {
    $pdo->query("SELECT shift_id FROM employee_attendance LIMIT 1");
} catch (PDOException $e) {
    // العمود غير موجود، نقوم بإضافته
    $pdo->exec("ALTER TABLE employee_attendance ADD COLUMN shift_id INT NULL AFTER deduction_amount");
}

// التحقق من الصلاحية (نفترض وجود صلاحية لتقارير الموظفين)
if (!has_permission('employees_view')) {
    die("غير مصرح لك بالوصول لهذه الصفحة.");
}

// جلب الفروع والمركز الرئيسي
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL AND status = 'active'")->fetchAll();

// جلب الموظفين للفلاتر
$employees_list = $pdo->query("SELECT id, full_name FROM employees WHERE deleted_at IS NULL ORDER BY full_name ASC")->fetchAll();

// إعداد الفلاتر
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$employee_id = $_GET['employee_id'] ?? '';
$branch_id = $_GET['branch_id'] ?? '';

$where = "WHERE att.attendance_date BETWEEN ? AND ?";
$params = [$start_date, $end_date];

if (!empty($employee_id)) {
    $where .= " AND att.employee_id = ?";
    $params[] = $employee_id;
}

if (!empty($branch_id)) {
    $where .= " AND e.branch_id = ?";
    $params[] = $branch_id;
}

// جلب سجلات الحضور مع الراتب الإجمالي وتفاصيل فترات الدوام والموقع والجهاز
$query = "
    SELECT att.*, e.full_name, e.phone, e.job_title_id, b.branch_name, jt.title_name as job_title_name,
           ws.shift_name, ws.start_time as scheduled_in, ws.end_time as scheduled_out,
           (SELECT SUM(salary) FROM shift_job_title_salaries WHERE job_title_id = e.job_title_id) as total_salary
    FROM employee_attendance att
    JOIN employees e ON att.employee_id = e.id
    LEFT JOIN branches b ON e.branch_id = b.id
    LEFT JOIN job_titles jt ON e.job_title_id = jt.id
    LEFT JOIN work_shifts ws ON att.shift_id = ws.id
    $where
    ORDER BY att.attendance_date DESC, att.check_in DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$attendance_records = $stmt->fetchAll();

// جلب كافة فترات الدوام لكل مسمى وظيفي لعرضها في التقرير
$job_shifts_map = [];
$all_job_shifts = $pdo->query("
    SELECT sjs.job_title_id, ws.id, ws.shift_name, ws.start_time, ws.end_time 
    FROM shift_job_title_salaries sjs
    JOIN work_shifts ws ON sjs.shift_id = ws.id
")->fetchAll();

foreach ($all_job_shifts as $js) {
    $job_shifts_map[$js['job_title_id']][] = $js;
}

// حساب الإجماليات
$total_late_minutes = 0;
$total_deductions = 0;
$total_salaries_sum = 0;
$processed_employees = []; // لحساب الراتب مرة واحدة لكل موظف في التقرير

foreach ($attendance_records as $rec) {
    $total_late_minutes += $rec['late_minutes'];
    $total_deductions += $rec['deduction_amount'];
    
    // حساب إجمالي الرواتب للموظفين الظاهرين في التقرير (بدون تكرار)
    if (!isset($processed_employees[$rec['employee_id']])) {
        $total_salaries_sum += (float)$rec['total_salary'];
        $processed_employees[$rec['employee_id']] = true;
    }
}
?>

<div class="container-fluid py-4 text-end" dir="rtl">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold"><i class="fas fa-clipboard-list me-2 text-primary"></i> تقرير سجل الدوام</h2>
        <div>
            <button onclick="window.print()" class="btn btn-outline-secondary rounded-pill px-4 no-print">
                <i class="fas fa-print me-2"></i> طباعة التقرير
            </button>
        </div>
    </div>

    <!-- فلاتر البحث -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 no-print">
        <div class="card-body p-4">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">من تاريخ</label>
                    <input type="date" name="start_date" class="form-control rounded-pill" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">إلى تاريخ</label>
                    <input type="date" name="end_date" class="form-control rounded-pill" value="<?php echo $end_date; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">الموظف</label>
                    <select name="employee_id" class="form-select rounded-pill">
                        <option value="">كل الموظفين</option>
                        <?php foreach ($employees_list as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>" <?php echo $employee_id == $emp['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($emp['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">الفرع</label>
                    <select name="branch_id" class="form-select rounded-pill">
                        <option value="">كل الفروع</option>
                        <?php foreach ($branches as $b): ?>
                            <option value="<?php echo $b['id']; ?>" <?php echo $branch_id == $b['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($b['branch_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary rounded-pill w-100">
                        <i class="fas fa-filter me-2"></i> تصفية
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ملخص التقرير -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 bg-primary text-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">عدد السجلات</h6>
                            <h3 class="mb-0 fw-bold"><?php echo count($attendance_records); ?></h3>
                        </div>
                        <i class="fas fa-users fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 bg-success text-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">إجمالي الرواتب</h6>
                            <h3 class="mb-0 fw-bold"><?php echo number_format($total_salaries_sum, 2); ?></h3>
                        </div>
                        <i class="fas fa-hand-holding-usd fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 bg-warning text-dark">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">إجمالي التأخير</h6>
                            <h3 class="mb-0 fw-bold"><?php echo number_format($total_late_minutes); ?> د</h3>
                        </div>
                        <i class="fas fa-clock fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm rounded-4 bg-danger text-white">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h6 class="mb-1">إجمالي الخصومات</h6>
                            <h3 class="mb-0 fw-bold"><?php echo number_format($total_deductions, 2); ?></h3>
                        </div>
                        <i class="fas fa-money-bill-wave fa-2x opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول البيانات -->
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 border-bottom">
            <h5 class="mb-0 fw-bold">تفاصيل سجلات الدوام</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">التاريخ</th>
                            <th>الموظف</th>
                            <th>الفترات المقررة للمسمى</th>
                            <th>الفترة الحالية</th>
                            <th>الحضور (المقرر / الفعلي)</th>
                            <th>موقع الحضور والجهاز</th>
                            <th>الانصراف (المقرر / الفعلي)</th>
                            <th>موقع الانصراف والجهاز</th>
                            <th>التأخير (د)</th>
                            <th class="pe-4">صافي اليوم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($attendance_records)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-5 text-muted">لا توجد سجلات مطابقة للبحث</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($attendance_records as $rec): ?>
                                <tr>
                                    <td class="ps-4 fw-bold"><?php echo $rec['attendance_date']; ?></td>
                                    <td>
                                        <div class="fw-bold"><?php echo htmlspecialchars($rec['full_name']); ?></div>
                                        <small class="text-muted"><?php echo htmlspecialchars($rec['job_title_name'] ?: '---'); ?></small>
                                    </td>
                                    <td>
                                        <?php 
                                        $emp_job_shifts = $job_shifts_map[$rec['job_title_id']] ?? [];
                                        if (empty($emp_job_shifts)) {
                                            echo '<small class="text-muted">لا توجد فترات</small>';
                                        } else {
                                            foreach ($emp_job_shifts as $js) {
                                                $is_current = ($rec['shift_id'] == $js['id']);
                                                echo '<div class="extra-small mb-2 p-1 rounded ' . ($is_current ? 'bg-primary bg-opacity-10 border border-primary' : 'bg-light border') . '">';
                                                echo '<div class="d-flex justify-content-between align-items-center">';
                                                echo '<span class="fw-bold">' . htmlspecialchars($js['shift_name']) . '</span>';
                                                if ($is_current) echo '<span class="badge bg-primary" style="font-size: 0.6rem;">الفترة الحالية</span>';
                                                echo '</div>';
                                                echo '<span class="text-muted">' . str_replace(['AM', 'PM'], ['ص', 'م'], date('h:i A', strtotime($js['start_time']))) . ' - ' . str_replace(['AM', 'PM'], ['ص', 'م'], date('h:i A', strtotime($js['end_time']))) . '</span>';
                                                echo '</div>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div class="small text-muted mb-1"><i class="fas fa-clock me-1"></i> الموعد المقرر: <?php echo $rec['scheduled_in'] ? str_replace(['AM', 'PM'], ['صباحاً', 'مساءً'], date('h:i A', strtotime($rec['scheduled_in']))) : '---'; ?></div>
                                        <div class="fw-bold mb-1 <?php 
                                            if ($rec['check_in'] && $rec['scheduled_in']) {
                                                $diff = (strtotime($rec['check_in']) - strtotime($rec['scheduled_in'])) / 60;
                                                echo ($diff <= 15) ? 'text-success' : 'text-danger';
                                            } else {
                                                echo 'text-primary';
                                            }
                                        ?>">
                                            <i class="fas fa-sign-in-alt me-1"></i> الحضور الفعلي: <?php echo $rec['check_in'] ? str_replace(['AM', 'PM'], ['صباحاً', 'مساءً'], date('h:i A', strtotime($rec['check_in']))) : '---'; ?>
                                        </div>
                                        <?php 
                                        if ($rec['check_in'] && $rec['scheduled_in']) {
                                            if ($diff > 15) {
                                                echo '<span class="badge bg-danger extra-small"><i class="fas fa-exclamation-triangle me-1"></i> متأخر ' . round($diff) . ' دقيقة</span>';
                                            } elseif ($diff > 0) {
                                                echo '<span class="badge bg-warning text-dark extra-small"><i class="fas fa-clock me-1"></i> ضمن السماح (' . round($diff) . ' د)</span>';
                                            } elseif ($diff < 0) {
                                                echo '<span class="badge bg-info text-dark extra-small"><i class="fas fa-running me-1"></i> متقدم ' . abs(round($diff)) . ' دقيقة</span>';
                                            } else {
                                                echo '<span class="badge bg-success extra-small"><i class="fas fa-check-circle me-1"></i> في الموعد تماماً</span>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <!-- موقع الحضور والجهاز -->
                                    <td class="small">
                                        <?php if (!empty($rec['check_in_latitude']) && !empty($rec['check_in_longitude'])): ?>
                                            <div class="mb-1"><i class="fas fa-map-marker-alt text-primary me-1"></i>
                                                <a href="https://www.google.com/maps?q=<?php echo urlencode($rec['check_in_latitude'] . ',' . $rec['check_in_longitude']); ?>" target="_blank" class="text-decoration-none text-primary">
                                                    عرض على الخريطة
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($rec['check_in_device'])): ?>
                                            <div class="text-muted"><i class="fas fa-mobile-alt me-1"></i> <?php echo htmlspecialchars($rec['check_in_device']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($rec['check_in_browser'])): ?>
                                            <div class="text-muted"><i class="fas fa-globe me-1"></i> <?php echo htmlspecialchars($rec['check_in_browser']); ?></div>
                                        <?php endif; ?>
                                        <?php if (empty($rec['check_in_latitude']) && empty($rec['check_in_device']) && empty($rec['check_in_browser'])): ?>
                                            <span class="text-muted">---</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="small text-muted mb-1"><i class="fas fa-clock me-1"></i> الانصراف المقرر: <?php echo $rec['scheduled_out'] ? str_replace(['AM', 'PM'], ['صباحاً', 'مساءً'], date('h:i A', strtotime($rec['scheduled_out']))) : '---'; ?></div>
                                        <div class="fw-bold mb-1 <?php 
                                            if ($rec['check_out'] && $rec['scheduled_out']) {
                                                $diff_out = (strtotime($rec['check_out']) - strtotime($rec['scheduled_out'])) / 60;
                                                echo ($diff_out >= 0) ? 'text-success' : 'text-warning';
                                            } else {
                                                echo 'text-muted';
                                            }
                                        ?>">
                                            <i class="fas fa-sign-out-alt me-1"></i> الانصراف الفعلي: <?php echo $rec['check_out'] ? str_replace(['AM', 'PM'], ['صباحاً', 'مساءً'], date('h:i A', strtotime($rec['check_out']))) : '---'; ?>
                                        </div>
                                        <?php 
                                        if ($rec['check_out'] && $rec['scheduled_out']) {
                                            if ($diff_out > 0) {
                                                echo '<span class="badge bg-info text-dark extra-small"><i class="fas fa-plus-circle me-1"></i> إضافي ' . round($diff_out) . ' دقيقة</span>';
                                            } elseif ($diff_out < 0) {
                                                echo '<span class="badge bg-warning text-dark extra-small"><i class="fas fa-history me-1"></i> خروج مبكر ' . abs(round($diff_out)) . ' د</span>';
                                            } else {
                                                echo '<span class="badge bg-success extra-small"><i class="fas fa-check-circle me-1"></i> في الموعد تماماً</span>';
                                            }
                                        }
                                        ?>
                                    </td>
                                    <!-- موقع الانصراف والجهاز -->
                                    <td class="small">
                                        <?php if (!empty($rec['check_out_latitude']) && !empty($rec['check_out_longitude'])): ?>
                                            <div class="mb-1"><i class="fas fa-map-marker-alt text-success me-1"></i>
                                                <a href="https://www.google.com/maps?q=<?php echo urlencode($rec['check_out_latitude'] . ',' . $rec['check_out_longitude']); ?>" target="_blank" class="text-decoration-none text-success">
                                                    عرض على الخريطة
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($rec['check_out_device'])): ?>
                                            <div class="text-muted"><i class="fas fa-mobile-alt me-1"></i> <?php echo htmlspecialchars($rec['check_out_device']); ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($rec['check_out_browser'])): ?>
                                            <div class="text-muted"><i class="fas fa-globe me-1"></i> <?php echo htmlspecialchars($rec['check_out_browser']); ?></div>
                                        <?php endif; ?>
                                        <?php if (empty($rec['check_out_latitude']) && empty($rec['check_out_device']) && empty($rec['check_out_browser'])): ?>
                                            <span class="text-muted">---</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php 
                                        $row_late_in = 0;
                                        $row_early_out = 0;
                                        
                                        // احتساب تأخير الدخول الفعلي
                                        if ($rec['check_in'] && $rec['scheduled_in']) {
                                            $diff_in = (strtotime($rec['check_in']) - strtotime($rec['scheduled_in'])) / 60;
                                            $row_late_in = max(0, round($diff_in));
                                        }
                                        
                                        // احتساب الخروج المبكر الفعلي
                                        if ($rec['check_out'] && $rec['scheduled_out']) {
                                            $diff_out = (strtotime($rec['scheduled_out']) - strtotime($rec['check_out'])) / 60;
                                            $row_early_out = max(0, round($diff_out));
                                        }
                                        
                                        $total_row_delay = $row_late_in + $row_early_out;
                                        
                                        if ($row_late_in > 0) {
                                            $color = ($row_late_in > 15) ? 'text-danger' : 'text-warning';
                                            echo '<div class="' . $color . ' mb-1" title="تأخير دخول"><i class="fas fa-sign-in-alt me-1"></i> دخول: ' . $row_late_in . ' د</div>';
                                        }
                                        
                                        if ($row_early_out > 0) {
                                            echo '<div class="text-warning mb-1" title="خروج مبكر"><i class="fas fa-sign-out-alt me-1"></i> خروج: ' . $row_early_out . ' د</div>';
                                        }
                                        
                                        if ($total_row_delay == 0) {
                                            echo '<span class="text-success small"><i class="fas fa-check-circle me-1"></i> ملتزم</span>';
                                        } else {
                                            echo '<div class="fw-bold border-top mt-1 pt-1 ' . ($rec['deduction_amount'] > 0 ? 'text-danger' : 'text-muted') . '">الإجمالي: ' . $total_row_delay . ' د</div>';
                                        }
                                        
                                        if ($rec['deduction_amount'] > 0): ?>
                                            <div class="badge bg-danger-soft text-danger extra-small mt-1">خصم: <?php echo number_format($rec['deduction_amount'], 2); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="pe-4 fw-bold text-primary">
                                        <?php 
                                        $daily_salary = ($rec['total_salary'] / 30);
                                        $net_day = $daily_salary - $rec['deduction_amount'];
                                        echo number_format($net_day, 2); 
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    .card { border: 1px solid #eee !important; box-shadow: none !important; }
    body { background-color: white !important; }
    .container-fluid { padding: 0 !important; }
}
.extra-small { font-size: 0.75rem; }
.bg-danger-soft { background-color: rgba(220, 53, 69, 0.1); }
</style>

<?php 
require_once 'footer.php'; 
ob_end_flush();
?>