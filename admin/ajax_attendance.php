<?php
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/security.php';
require_once dirname(__DIR__) . '/includes/accounting_functions.php';

header('Content-Type: application/json');

// Haversine formula to calculate distance between two points (in meters)
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371000; // meters
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earthRadius * $c;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_id = $_POST['employee_id'];
    $action = $_POST['action']; // 'check_in' or 'check_out'
    $latitude = isset($_POST['latitude']) ? (float)$_POST['latitude'] : null;
    $longitude = isset($_POST['longitude']) ? (float)$_POST['longitude'] : null;
    $browser = isset($_POST['browser']) ? $_POST['browser'] : null;
    $device = isset($_POST['device']) ? $_POST['device'] : null;
    $date = date('Y-m-d');
    $time = date('H:i:s');
    
    // Get base currency
    $base_currency_id = $pdo->query("SELECT id FROM currencies WHERE is_default = 1 LIMIT 1")->fetchColumn();

    try {
        // جلب بيانات الموظف والراتب وكافة الفترات المرتبطة بمسمى وظيفتة
        $stmt = $pdo->prepare("
            SELECT e.*, 
                   jt.title_name as job_title_name
            FROM employees e
            LEFT JOIN job_titles jt ON e.job_title_id = jt.id
            WHERE e.id = ?
        ");
        $stmt->execute([$employee_id]);
        $emp = $stmt->fetch();

        if (!$emp) throw new Exception("الموظف غير موجود");

        // Check location
        if ($latitude === null || $longitude === null) {
            throw new Exception("لم يتم الحصول على موقعك، يرجى السماح بالوصول للموقع");
        }

        // Get allowed locations for employee
        $allowed_locations = [];
        if ($emp['attendance_location_id']) {
            $stmt_loc = $pdo->prepare("SELECT * FROM attendance_locations WHERE id = ? AND is_active = 1");
            $stmt_loc->execute([$emp['attendance_location_id']]);
            $allowed_locations = $stmt_loc->fetchAll();
        } else {
            $allowed_locations = $pdo->query("SELECT * FROM attendance_locations WHERE is_active = 1")->fetchAll();
        }

        if (empty($allowed_locations)) {
            throw new Exception("لا توجد مواقع مسموح بها للحضور، يرجى إضافة مواقع في الإعدادات");
        }

        $is_allowed = false;
        $allowed_location_name = '';
        $debug_info = "موقعك الحالي: $latitude, $longitude\n";
        foreach ($allowed_locations as $loc) {
            $distance = calculateDistance($latitude, $longitude, $loc['latitude'], $loc['longitude']);
            $debug_info .= " - الموقع: " . $loc['name'] . " (" . $loc['latitude'] . ", " . $loc['longitude'] . "), نصف قطر: " . $loc['radius_meters'] . "م, المسافة: " . round($distance, 2) . "م\n";
            if ($distance <= $loc['radius_meters']) {
                $is_allowed = true;
                $allowed_location_name = $loc['name'];
                break;
            }
        }

        if (!$is_allowed) {
            throw new Exception("أنت خارج النطاق المسموح به للحضور!\n" . $debug_info);
        }

        // جلب كافة فترات الدوام والرواتب المرتبطة بالمسمى الوظيفي
        $stmt_shifts = $pdo->prepare("
            SELECT ws.*, sjs.salary, sjs.currency_id
            FROM work_shifts ws
            JOIN shift_job_title_salaries sjs ON ws.id = sjs.shift_id
            WHERE sjs.job_title_id = ?
        ");
        $stmt_shifts->execute([$emp['job_title_id']]);
        $shifts = $stmt_shifts->fetchAll();

        if (empty($shifts)) throw new Exception("لا توجد فترات دوام مرتبطة بهذا المسمى الوظيفي");

        // تحديد الفترة الحالية (الأقرب لوقت التحضير)
        $current_shift = null;
        $check_time_unix = strtotime($time);
        
        if ($action === 'check_in') {
            // عند الدخول: نختار الفترة التي لم تبدأ بعد أو بدأت للتو (الأقرب لوقت الحضور)
            $min_diff = null;
            foreach ($shifts as $s) {
                $diff = abs($check_time_unix - strtotime($s['start_time']));
                if ($min_diff === null || $diff < $min_diff) {
                    $min_diff = $diff;
                    $current_shift = $s;
                }
            }
        } else {
            // عند الانصراف: نختار الفترة التي تم تسجيل الدخول لها اليوم
            $stmt_last = $pdo->prepare("SELECT shift_id FROM employee_attendance WHERE employee_id = ? AND attendance_date = ?");
            $stmt_last->execute([$employee_id, $date]);
            $last_shift_id = $stmt_last->fetchColumn();
            
            if ($last_shift_id) {
                foreach ($shifts as $s) {
                    if ($s['id'] == $last_shift_id) {
                        $current_shift = $s;
                        break;
                    }
                }
            }
            
            // إذا لم نجد فترة مسجلة، نأخذ الأقرب لوقت الانصراف
            if (!$current_shift) {
                $min_diff = null;
                foreach ($shifts as $s) {
                    $diff = abs($check_time_unix - strtotime($s['end_time']));
                    if ($min_diff === null || $diff < $min_diff) {
                        $min_diff = $diff;
                        $current_shift = $s;
                    }
                }
            }
        }

        $salary = $current_shift['salary'] ?: 0;
        $minute_rate = ($salary / 30 / 8 / 60); // بافتراض 30 يوم و 8 ساعات عمل
        $currency_id = $current_shift['currency_id'] ?: $base_currency_id;

        // جلب حساب تسويات الرواتب
        $adj_account_id = $pdo->query("SELECT id FROM unified_accounts WHERE (account_name_ar LIKE BINARY '%تسويات رواتب%' OR account_name_ar LIKE BINARY '%خصومات موظفين%' OR account_name_ar LIKE BINARY '%رواتب%') LIMIT 1")->fetchColumn();
        if (!$adj_account_id) {
            $adj_account_id = $pdo->query("SELECT id FROM unified_accounts WHERE account_code LIKE BINARY '5%' AND id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL) LIMIT 1")->fetchColumn();
        }

        if ($action === 'check_in') {
            $late_minutes = 0;
            $deduction = 0;
            $status = 'present';

            $shift_start = strtotime($current_shift['start_time']);
            $check_in_time = strtotime($time);

            if ($check_in_time > $shift_start) {
                $late_minutes = round(($check_in_time - $shift_start) / 60);
                if ($late_minutes > 15) { // فترة سماح 15 دقيقة
                    $status = 'late';
                    $deduction = $late_minutes * $minute_rate;

                    // تسجيل القيد المالي (خصم من الراتب)
                    if ($emp['account_id'] && $adj_account_id && $deduction > 0) {
                        \Core\Finance\FinancePostingAdapter::createVoucherAndPost(
                            $pdo, 'payment', $emp['branch_id'] ?: 1, 'employee', $employee_id,
                            $deduction, $currency_id, $adj_account_id, $emp['account_id'],
                            "خصم تأخير دخول (فترة: " . $current_shift['shift_name'] . ") - موظف: " . $emp['full_name'],
                            "ATT-LAT-$employee_id-" . time()
                        );
                    }
                }
            }

            // تسجيل الدخول مع حفظ رقم الفترة والموقع والجهاز والمتصفح
            $stmt = $pdo->prepare("INSERT INTO employee_attendance (employee_id, attendance_date, check_in, status, late_minutes, deduction_amount, shift_id, check_in_latitude, check_in_longitude, check_in_device, check_in_browser) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE check_in = VALUES(check_in), status = VALUES(status), late_minutes = VALUES(late_minutes), deduction_amount = VALUES(deduction_amount), shift_id = VALUES(shift_id), check_in_latitude = VALUES(check_in_latitude), check_in_longitude = VALUES(check_in_longitude), check_in_device = VALUES(check_in_device), check_in_browser = VALUES(check_in_browser)");
            $stmt->execute([$employee_id, $date, $time, $status, $late_minutes, $deduction, $current_shift['id'], $latitude, $longitude, $device, $browser]);
            
            echo json_encode(['success' => true, 'message' => 'تم تحضير الدخول لفترة: ' . $current_shift['shift_name'], 'late' => $late_minutes, 'deduction' => round($deduction, 2)]);
        } elseif ($action === 'check_out') {
            $overtime_minutes = 0;
            $early_leave_minutes = 0;
            $bonus = 0;
            $deduction = 0;

            $shift_end = strtotime($current_shift['end_time']);
            $check_out_time = strtotime($time);

            if ($check_out_time > $shift_end) {
                // إضافي (Overtime)
                $overtime_minutes = round(($check_out_time - $shift_end) / 60);
                $bonus = $overtime_minutes * $minute_rate * 1.5;

                if ($emp['account_id'] && $adj_account_id && $bonus > 0) {
                    \Core\Finance\FinancePostingAdapter::createVoucherAndPost(
                        $pdo, 'receipt', $emp['branch_id'] ?: 1, 'employee', $employee_id,
                        $bonus, $currency_id, $adj_account_id, $emp['account_id'],
                        "إضافي عمل (فترة: " . $current_shift['shift_name'] . ") - موظف: " . $emp['full_name'],
                        "ATT-OT-$employee_id-" . time()
                    );
                }
            } elseif ($check_out_time < $shift_end) {
                // خروج مبكر (Early Leave)
                $early_leave_minutes = round(($shift_end - $check_out_time) / 60);
                $deduction = $early_leave_minutes * $minute_rate;

                if ($emp['account_id'] && $adj_account_id && $deduction > 0) {
                    \Core\Finance\FinancePostingAdapter::createVoucherAndPost(
                        $pdo, 'payment', $emp['branch_id'] ?: 1, 'employee', $employee_id,
                        $deduction, $currency_id, $adj_account_id, $emp['account_id'],
                        "خصم خروج مبكر (فترة: " . $current_shift['shift_name'] . ") - موظف: " . $emp['full_name'],
                        "ATT-EL-$employee_id-" . time()
                    );
                }
            }

            $stmt = $pdo->prepare("UPDATE employee_attendance SET check_out = ?, deduction_amount = deduction_amount + ?, check_out_latitude = ?, check_out_longitude = ?, check_out_device = ?, check_out_browser = ? WHERE employee_id = ? AND attendance_date = ?");
            $stmt->execute([$time, $deduction, $latitude, $longitude, $device, $browser, $employee_id, $date]);
            
            echo json_encode(['success' => true, 'message' => 'تم تسجيل انصراف فترة: ' . $current_shift['shift_name'], 'overtime' => $overtime_minutes, 'bonus' => round($bonus, 2), 'early_leave' => $early_leave_minutes, 'deduction' => round($deduction, 2)]);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'خطأ: ' . $e->getMessage()]);
    }
}

