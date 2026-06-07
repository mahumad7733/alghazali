<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once 'header.php';

// إضافة مسمى وظيفي جديد
if (isset($_POST["add_job_title"])) {
    $title_name = $_POST["title_name"];
    $pdo->prepare("INSERT INTO job_titles (title_name) VALUES (?)")->execute([$title_name]);
    echo "<script>location.href='job_settings.php?success=1';</script>"; exit();
}

// إضافة فترة دوام جديدة
if (isset($_POST["add_shift"])) {
    $shift_name = $_POST["shift_name"];
    $start_time = $_POST["start_time"];
    $end_time = $_POST["end_time"];
    $pdo->prepare("INSERT INTO work_shifts (shift_name, start_time, end_time) VALUES (?, ?, ?)")->execute([$shift_name, $start_time, $end_time]);
    $shift_id = $pdo->lastInsertId();
    
    if (isset($_POST["job_title_ids"]) && is_array($_POST["job_title_ids"])) {
        $salary = !empty($_POST["salary"]) ? $_POST["salary"] : 0.00;
        $currency_id = !empty($_POST["currency_id"]) ? $_POST["currency_id"] : null;
        
        foreach ($_POST["job_title_ids"] as $job_title_id) {
            if ($job_title_id) {
                $pdo->prepare("INSERT INTO shift_job_title_salaries (shift_id, job_title_id, salary, currency_id) VALUES (?, ?, ?, ?)")->execute([$shift_id, $job_title_id, $salary, $currency_id]);
            }
        }
    }
    echo "<script>location.href='job_settings.php?success=1';</script>"; exit();
}

// تعديل فترة دوام
if (isset($_POST["edit_shift"])) {
    $shift_id = $_POST["id"];
    $pdo->prepare("UPDATE work_shifts SET shift_name = ?, start_time = ?, end_time = ? WHERE id = ?")
        ->execute([$_POST["shift_name"], $_POST["start_time"], $_POST["end_time"], $shift_id]);

    $pdo->prepare("DELETE FROM shift_job_title_salaries WHERE shift_id = ?")->execute([$shift_id]);

    if (isset($_POST["job_title_ids"]) && is_array($_POST["job_title_ids"])) {
        $salary = !empty($_POST["salary"]) ? $_POST["salary"] : 0.00;
        $currency_id = !empty($_POST["currency_id"]) ? $_POST["currency_id"] : null;
        
        foreach ($_POST["job_title_ids"] as $job_title_id) {
            if ($job_title_id) {
                $pdo->prepare("INSERT INTO shift_job_title_salaries (shift_id, job_title_id, salary, currency_id) VALUES (?, ?, ?, ?)")->execute([$shift_id, $job_title_id, $salary, $currency_id]);
            }
        }
    }
    echo "<script>location.href='job_settings.php?success=1';</script>"; exit();
}

// تعديل مسمى وظيفي
if (isset($_POST["edit_job_title"])) {
    $pdo->prepare("UPDATE job_titles SET title_name = ? WHERE id = ?")
        ->execute([$_POST["title_name"], $_POST["id"]]);
    echo "<script>location.href='job_settings.php?success=1';</script>"; exit();
}

// حذف مسمى وظيفي
if (isset($_GET["delete_job"])) {
    $pdo->prepare("DELETE FROM job_titles WHERE id = ?")->execute([$_GET["delete_job"]]);
    echo "<script>location.href='job_settings.php?success=1';</script>"; exit();
}

// حذف فترة دوام
if (isset($_GET["delete_shift"])) {
    $pdo->prepare("DELETE FROM work_shifts WHERE id = ?")->execute([$_GET["delete_shift"]]);
    echo "<script>location.href='job_settings.php?success=1';</script>"; exit();
}

// جلب البيانات
$currencies = $pdo->query("SELECT * FROM currencies ORDER BY is_default DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$job_titles = $pdo->query("SELECT * FROM job_titles ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$work_shifts = $pdo->query("SELECT * FROM work_shifts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

foreach ($work_shifts as &$shift) {
    // جلب المسميات والرواتب المرتبطة بالفترة
    $stmt = $pdo->prepare("SELECT sjs.salary, sjs.currency_id, c.currency_name, GROUP_CONCAT(jt.title_name SEPARATOR ', ') as titles, GROUP_CONCAT(jt.id) as title_ids FROM shift_job_title_salaries sjs JOIN job_titles jt ON sjs.job_title_id = jt.id LEFT JOIN currencies c ON sjs.currency_id = c.id WHERE sjs.shift_id = ? GROUP BY sjs.salary, sjs.currency_id");
    $stmt->execute([$shift["id"]]);
    $shift["grouped_salaries"] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="mb-0 text-primary"><i class="fas fa-briefcase me-2"></i> الرواتب وفترات الدوام</h3>
        <a href="system_hub.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-right me-2"></i> عودة للمركز</a>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            تمت العملية بنجاح.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- المسميات الوظيفية -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-briefcase text-primary me-2"></i> المسميات الوظيفية والرواتب</h5>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addJobTitleModal"><i class="fas fa-plus"></i> إضافة مسمى</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>المسمى</th>
                                    <th>عدد الفترات</th>
                                    <th>تفاصيل الرواتب</th>
                                    <th>إجمالي الراتب</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($job_titles as $jt): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($jt["title_name"]); ?></td>
                                    <td>
                                        <?php
                                        $stmt_count = $pdo->prepare("SELECT COUNT(*) FROM shift_job_title_salaries WHERE job_title_id = ?");
                                        $stmt_count->execute([$jt["id"]]);
                                        echo $stmt_count->fetchColumn();
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        $stmt_salaries = $pdo->prepare("SELECT ws.shift_name, sjs.salary, c.currency_name FROM shift_job_title_salaries sjs JOIN work_shifts ws ON sjs.shift_id = ws.id LEFT JOIN currencies c ON sjs.currency_id = c.id WHERE sjs.job_title_id = ?");
                                        $stmt_salaries->execute([$jt["id"]]);
                                        $salaries = $stmt_salaries->fetchAll(PDO::FETCH_ASSOC);
                                        if ($salaries) {
                                            echo "<ul class='mb-0 small'>";
                                            foreach ($salaries as $s) {
                                                echo "<li>" . htmlspecialchars($s["shift_name"]) . ": " . number_format($s["salary"], 2) . " " . htmlspecialchars($s["currency_name"]) . "</li>";
                                            }
                                            echo "</ul>";
                                        } else {
                                            echo "<span class='text-muted'>غير محدد</span>";
                                        }
                                        ?>
                                    </td>
                                    <td class="fw-bold text-success">
                                        <?php
                                        $stmt_total = $pdo->prepare("SELECT SUM(sjs.salary) as total, c.currency_name FROM shift_job_title_salaries sjs LEFT JOIN currencies c ON sjs.currency_id = c.id WHERE sjs.job_title_id = ? GROUP BY sjs.currency_id");
                                        $stmt_total->execute([$jt["id"]]);
                                        $totals = $stmt_total->fetchAll(PDO::FETCH_ASSOC);
                                        if ($totals) {
                                            foreach ($totals as $t) {
                                                echo number_format($t["total"], 2) . " " . htmlspecialchars($t["currency_name"]) . "<br>";
                                            }
                                        } else {
                                            echo "0.00";
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info text-white" data-bs-toggle="modal" data-bs-target="#editJobModal<?php echo $jt["id"]; ?>"><i class="fas fa-edit"></i></button>
                                        <a href="?delete_job=<?php echo $jt["id"]; ?>" class="btn btn-sm btn-danger" onclick="return confirm('هل أنت متأكد؟')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>

                                <!-- Modal تعديل مسمى وظيفي -->
                                <div class="modal fade" id="editJobModal<?php echo $jt["id"]; ?>" tabindex="-1">
                                    <div class="modal-dialog">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <input type="hidden" name="id" value="<?php echo $jt["id"]; ?>">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">تعديل المسمى الوظيفي</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label">اسم المسمى</label>
                                                        <input type="text" name="title_name" class="form-control" value="<?php echo htmlspecialchars($jt["title_name"]); ?>" required>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                                    <button type="submit" name="edit_job_title" class="btn btn-primary">حفظ</button>
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

        <!-- فترات الدوام -->
        <div class="col-md-6 mb-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-clock text-primary me-2"></i> فترات الدوام</h5>
                    <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addShiftModal"><i class="fas fa-plus"></i> إضافة فترة</button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>الفترة</th>
                                    <th>الوقت</th>
                                    <th>المسميات والرواتب</th>
                                    <th>الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($work_shifts as $ws): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ws["shift_name"]); ?></td>
                                    <td><?php echo str_replace(["AM", "PM"], ["صباحاً", "مساءً"], date("h:i A", strtotime($ws["start_time"]))) . " - " . str_replace(["AM", "PM"], ["صباحاً", "مساءً"], date("h:i A", strtotime($ws["end_time"]))); ?></td>
                                    <td>
                                        <?php if (!empty($ws["grouped_salaries"])): ?>
                                            <ul class="mb-0 small">
                                                <?php foreach ($ws["grouped_salaries"] as $gs): ?>
                                                    <li><strong><?php echo htmlspecialchars($gs["titles"]); ?></strong>: <?php echo number_format($gs["salary"], 2) . " " . htmlspecialchars($gs["currency_name"]); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <span class="text-muted">لا يوجد</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-info text-white" data-bs-toggle="modal" data-bs-target="#editShiftModal<?php echo $ws["id"]; ?>"><i class="fas fa-edit"></i></button>
                                        <a href="?delete_shift=<?php echo $ws["id"]; ?>" class="btn btn-sm btn-danger" onclick="return confirm('هل أنت متأكد؟')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>

                                <!-- Modal تعديل فترة دوام -->
                                <div class="modal fade" id="editShiftModal<?php echo $ws["id"]; ?>" tabindex="-1">
                                    <div class="modal-dialog modal-lg">
                                        <div class="modal-content">
                                            <form method="POST">
                                                <input type="hidden" name="id" value="<?php echo $ws["id"]; ?>">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">تعديل فترة الدوام</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="mb-3">
                                                        <label class="form-label">اسم الفترة</label>
                                                        <input type="text" name="shift_name" class="form-control" value="<?php echo htmlspecialchars($ws["shift_name"]); ?>" required>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">وقت الحضور</label>
                                                            <input type="time" name="start_time" class="form-control" value="<?php echo $ws["start_time"]; ?>" required>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">وقت الانصراف</label>
                                                            <input type="time" name="end_time" class="form-control" value="<?php echo $ws["end_time"]; ?>" required>
                                                        </div>
                                                    </div>
                                                    <hr>
                                                    <h6>المسميات الوظيفية والرواتب لهذه الفترة</h6>
                                                    <?php 
                                                    $current_gs = !empty($ws["grouped_salaries"]) ? $ws["grouped_salaries"][0] : null;
                                                    $selected_ids = $current_gs ? explode(',', $current_gs["title_ids"]) : [];
                                                    ?>
                                                    <div class="mb-3">
                                                        <label class="form-label">المسميات الوظيفية (يمكنك اختيار أكثر من مسمى)</label>
                                                        <select name="job_title_ids[]" class="form-select" multiple required style="height: 120px;">
                                                            <?php foreach ($job_titles as $jt_opt): ?>
                                                                <option value="<?php echo $jt_opt["id"]; ?>" <?php echo in_array($jt_opt["id"], $selected_ids) ? "selected" : ""; ?>><?php echo htmlspecialchars($jt_opt["title_name"]); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <small class="text-muted">اضغط Ctrl (أو Command) للاختيار المتعدد</small>
                                                    </div>
                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">الراتب</label>
                                                            <input type="number" step="0.01" name="salary" class="form-control" value="<?php echo $current_gs ? $current_gs["salary"] : ""; ?>" required>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label class="form-label">العملة</label>
                                                            <select name="currency_id" class="form-select" required>
                                                                <?php foreach ($currencies as $c): ?>
                                                                    <option value="<?php echo $c["id"]; ?>" <?php echo ($current_gs && $c["id"] == $current_gs["currency_id"]) ? "selected" : ""; ?>><?php echo htmlspecialchars($c["currency_name"]); ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                                                    <button type="submit" name="edit_shift" class="btn btn-success">حفظ التعديلات</button>
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
    </div>
</div>

<!-- Modal إضافة مسمى وظيفي -->
<div class="modal fade" id="addJobTitleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة مسمى وظيفي</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم المسمى</label>
                        <input type="text" name="title_name" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_job_title" class="btn btn-primary">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal إضافة فترة دوام -->
<div class="modal fade" id="addShiftModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">إضافة فترة دوام</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">اسم الفترة</label>
                        <input type="text" name="shift_name" class="form-control" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">وقت الحضور</label>
                            <input type="time" name="start_time" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">وقت الانصراف</label>
                            <input type="time" name="end_time" class="form-control" required>
                        </div>
                    </div>
                    <hr>
                    <h6>المسميات الوظيفية والرواتب لهذه الفترة</h6>
                    <div class="mb-3">
                        <label class="form-label">المسميات الوظيفية (يمكنك اختيار أكثر من مسمى)</label>
                        <select name="job_title_ids[]" class="form-select" multiple required style="height: 120px;">
                            <?php foreach ($job_titles as $jt_opt): ?>
                                <option value="<?php echo $jt_opt["id"]; ?>"><?php echo htmlspecialchars($jt_opt["title_name"]); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">اضغط Ctrl (أو Command) للاختيار المتعدد</small>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">الراتب</label>
                            <input type="number" step="0.01" name="salary" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">العملة</label>
                            <select name="currency_id" class="form-select" required>
                                <option value="">اختر العملة</option>
                                <?php foreach ($currencies as $c): ?>
                                    <option value="<?php echo $c["id"]; ?>"><?php echo htmlspecialchars($c["currency_name"]); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_shift" class="btn btn-success">حفظ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
