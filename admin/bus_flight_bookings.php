<?php
ob_start();
require_once 'header.php';

// Ensure $is_admin is defined (in case header.php didn't for some reason)
$user_role = $currentUser['role_name'] ?? $_SESSION['role'] ?? 'employee';
$is_admin = ($user_role === 'admin' || $user_role === 'developer');

$auto_invoice_generation = isset($settings['auto_invoice_generation']) && ($settings['auto_invoice_generation'] == '1' || $settings['auto_invoice_generation'] === true);
$base_currency = $pdo->query("SELECT * FROM currencies WHERE is_default = 1")->fetch();
$base_currency_symbol = $base_currency['currency_symbol'] ?? 'ر.ي';

// معالجة إعادة تعيين الفواتير إلى مسودة (مثل invoices.php)
if (isset($_GET['reset_invoice']) || isset($_GET['unpost_invoice'])) {
    try {
        $id = isset($_GET['reset_invoice']) ? (int)$_GET['reset_invoice'] : (int)$_GET['unpost_invoice'];
        $type = $_GET['reset_type'] ?? 'sales'; // sales, purchase, all
        $user_id = $_SESSION['admin_id'];

        // جلب الإعدادات
        $settings = getSettings($pdo);

        // جلب بيانات الفاتورة
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['invoice_status'] != 'posted') {
            header('Location: bus_flight_bookings.php?posted=1&error=not_posted');
            exit;
        }

        // البحث عن الفاتورة المرتبطة
        $s_pref = getServiceInvoiceConfig($row['source_type'], $settings)['sales_prefix'];
        $p_pref = getServiceInvoiceConfig($row['source_type'], $settings)['purchase_prefix'];

        $pur_id = null;
        $sal_id = null;
        if ($row['invoice_category'] == 'sales') {
            $linked_num = str_replace($s_pref, $p_pref, $row['invoice_number']);
            $stmt_linked = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ? AND invoice_category = 'purchase' LIMIT 1");
            $stmt_linked->execute([$linked_num]);
            $pur_id = $stmt_linked->fetchColumn();
        } else {
            $linked_num = str_replace($p_pref, $s_pref, $row['invoice_number']);
            $stmt_linked = $pdo->prepare("SELECT id FROM invoices WHERE invoice_number = ? AND invoice_category = 'sales' LIMIT 1");
            $stmt_linked->execute([$linked_num]);
            $sal_id = $stmt_linked->fetchColumn();
        }

        // تحديد الفواتير المراد إعادة تعيينها
        $ids_to_reset = [];
        if ($type == 'all') {
            $ids_to_reset[] = $row['id'];
            if ($pur_id) $ids_to_reset[] = $pur_id;
            if ($sal_id) $ids_to_reset[] = $sal_id;
        } elseif ($type == 'purchase') {
            if ($row['invoice_category'] == 'purchase') $ids_to_reset[] = $row['id'];
            elseif ($pur_id) $ids_to_reset[] = $pur_id;
        } else { // sales
            if ($row['invoice_category'] == 'sales') $ids_to_reset[] = $row['id'];
            elseif ($sal_id) $ids_to_reset[] = $sal_id;
        }

        foreach ($ids_to_reset as $reset_id) {
            // حذف القيود المحاسبية المرتبطة بالفاتورة
            $pdo->prepare("DELETE FROM journal_lines WHERE invoice_id = ?")->execute([$reset_id]);

            // تحديث حالة الفاتورة إلى مسودة
            $pdo->prepare("UPDATE invoices SET invoice_status = 'draft', posted_by = NULL, posted_at = NULL WHERE id = ?")->execute([$reset_id]);

            // تسجيل السجل
        log_audit($pdo, 'reset_to_draft', 'invoices', $reset_id, ['status' => 'posted'], ['status' => 'draft'], "إعادة تعيين الفاتورة إلى مسودة");
        }

        $_SESSION['flash_message'] = [
            'type' => 'success',
            'title' => 'تم بنجاح!',
            'body' => 'تم إعادة تعيين الفاتورة إلى مسودة.'
        ];

        header('Location: bus_flight_bookings.php?posted=1');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_message'] = [
            'type' => 'danger',
            'title' => 'خطأ!',
            'body' => 'خطأ في إعادة التعيين: ' . $e->getMessage()
        ];
        header('Location: bus_flight_bookings.php?posted=1');
        exit;
    }
}

// Check permissions
if (!has_permission('bookings_view')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// إضافة عمود account_id إذا لم يكن موجوداً
try {
    $pdo->exec("ALTER TABLE bus_flight_bookings ADD COLUMN account_id INT NULL AFTER customer_id");
} catch (Exception $e) {
    // العمود موجود بالفعل
}

// Handle Add New Booking Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_booking'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ!', 'body' => 'خطأ في التحقق من الطلب (CSRF).'];
        header('Location: bus_flight_bookings.php');
        exit();
    }
    $errors = [];

    // تعيين الفرع أولاً من النموذج أو الجلسة
    $branch_id = filter_input(INPUT_POST, 'branch_id', FILTER_VALIDATE_INT) ?: ($currentUser['branch_id'] ?? $_SESSION['branch_id'] ?? null);

    // 1. Validate and Sanitize Input
    $traveler_name = filter_input(INPUT_POST, 'traveler_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $mobile_number = filter_input(INPUT_POST, 'mobile_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $date_of_birth = filter_input(INPUT_POST, 'date_of_birth');
    $place_of_birth = filter_input(INPUT_POST, 'place_of_birth', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $gender = filter_input(INPUT_POST, 'gender', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $nationality_id = filter_input(INPUT_POST, 'nationality_id', FILTER_VALIDATE_INT) ?: NULL;
    $id_type = filter_input(INPUT_POST, 'id_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $id_number = filter_input(INPUT_POST, 'id_number', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $id_issue_place = filter_input(INPUT_POST, 'id_issue_place', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $id_issue_date = filter_input(INPUT_POST, 'id_issue_date');

    $booking_date = filter_input(INPUT_POST, 'booking_date');
    $service_type = filter_input(INPUT_POST, 'service_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $bus_type = filter_input(INPUT_POST, 'bus_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $trip_type = filter_input(INPUT_POST, 'trip_type', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $from_city_id = filter_input(INPUT_POST, 'from_city_id', FILTER_VALIDATE_INT);
    $to_city_id = filter_input(INPUT_POST, 'to_city_id', FILTER_VALIDATE_INT);
    $departure_date = filter_input(INPUT_POST, 'departure_date');
    $return_date = filter_input(INPUT_POST, 'return_date'); // Optional
    $operation_date = filter_input(INPUT_POST, 'operation_date'); // تاريخ العملية
    $supplier_id = filter_input(INPUT_POST, 'supplier_id', FILTER_VALIDATE_INT);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    $sale_currency_id  = filter_input(INPUT_POST, 'sale_currency_id',  FILTER_VALIDATE_INT); // عملة البيع
    $currency_id       = filter_input(INPUT_POST, 'currency_id',        FILTER_VALIDATE_INT); // عملة الشراء/التكلفة
    $sale_price        = filter_input(INPUT_POST, 'sale_price',         FILTER_VALIDATE_FLOAT);
    $discount          = (float)($_POST['discount'] ?? 0); // الخصم
    $purchase_price    = filter_input(INPUT_POST, 'purchase_price',     FILTER_VALIDATE_FLOAT);
    $exchange_rate     = (float)($_POST['exchange_rate'] ?? 1); // سعر الصرف بين عملة الشراء وعملة البيع
    $amount_received   = filter_input(INPUT_POST, 'amount_received',    FILTER_VALIDATE_FLOAT);
    $delivery_type     = filter_input(INPUT_POST, 'delivery_type',      FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $account_id        = filter_input(INPUT_POST, 'account_id',         FILTER_VALIDATE_INT);
    $customer_id       = filter_input(INPUT_POST, 'customer_id',        FILTER_VALIDATE_INT);
    $agent_id          = filter_input(INPUT_POST, 'agent_id',           FILTER_VALIDATE_INT);
    $record_purchase   = filter_input(INPUT_POST, 'record_purchase',    FILTER_VALIDATE_INT);
    if (!$record_purchase && $record_purchase !== 0) $record_purchase = 1;
    // إذا لم تُرسل عملة البيع، نستخدم عملة الشراء كافتراضي
    if (!$sale_currency_id) $sale_currency_id = $currency_id;

    // إذا كان نوع التوصيل آجل، يتم التأكد من وجود العميل
    if ($delivery_type === 'credit' && !$customer_id) $errors[] = 'يجب اختيار العميل في حالة الدفع الآجل.';

    if ($from_city_id === $to_city_id) $errors[] = 'يجب أن تكون مدينة المغادرة مختلفة عن مدينة الوصول.';
    if ($departure_date < date('Y-m-d')) $errors[] = 'تاريخ المغادرة لا يمكن أن يكون في الماضي.';
    if ($purchase_price > $sale_price) $errors[] = 'تنبيه: سعر الشراء لا يمكن أن يكون أكبر من سعر البيع.';
    if ($amount_received > $sale_price) $errors[] = 'المبلغ الموصل لا يمكن أن يكون أكبر من سعر البيع.';

    if (!$traveler_name) $errors[] = 'اسم المسافر مطلوب.';
    if (!$mobile_number) $errors[] = 'رقم الجوال مطلوب.';
    if (!$booking_date) $errors[] = 'تاريخ الحجز مطلوب.';
    if (!$operation_date) $errors[] = 'تاريخ العملية مطلوب.';
    if (!$gender) $errors[] = 'الجنس مطلوب.';
    if (!$service_type) $errors[] = 'نوع الخدمة مطلوب.';
    if (!$trip_type) $errors[] = 'نوع الرحلة مطلوب.';
    if (!$from_city_id) $errors[] = 'مدينة المغادرة مطلوبة.';
    if (!$to_city_id) $errors[] = 'مدينة الوصول مطلوبة.';
    if (!$departure_date) $errors[] = 'تاريخ المغادرة مطلوب.';
    if ($trip_type === 'round_trip' && !$return_date) $errors[] = 'تاريخ العودة مطلوب لرحلة الذهاب والعودة.';
    if (!$supplier_id) $errors[] = 'المورد مطلوب.';
    if (!$branch_id) $errors[] = 'الفرع مطلوب.';
    if ($delivery_type === 'credit' && !$customer_id) $errors[] = 'يجب اختيار العميل (الحساب) في حالة الدفع الآجل.';
    if (!$currency_id) $errors[] = 'عملة الشراء مطلوبة.';
    if ($sale_price === false || $sale_price < 0) $errors[] = 'سعر البيع غير صحيح.';
    if ($purchase_price === false || $purchase_price < 0) $errors[] = 'سعر الشراء غير صحيح.';
    $net_sale = $sale_price - $discount;
    if ($net_sale < 0) $errors[] = 'الخصم لا يمكن أن يكون أكبر من سعر البيع.';

    if ($amount_received === false || $amount_received < 0) $errors[] = 'المبلغ الموصل غير صحيح.';
    if ($amount_received > $net_sale) $errors[] = 'يجب أن يكون المبلغ الموصل أقل من أو يساوي صافي سعر البيع.';

    // إذا كان نوع الدفع غير نقدي، يجب أن يكون المبلغ الموصل صفراً عند الإضافة الأولية
    if (!in_array($delivery_type, ['cash', 'bank_transfer'], true) && $amount_received > 0) {
        $errors[] = 'المبلغ الموصل متاح فقط في حالة الدفع النقدي.';
    }

    if (!$delivery_type) $errors[] = 'نوع التوصيل مطلوب.';
    if (($delivery_type === 'cash' || $delivery_type === 'bank_transfer') && !$account_id) $errors[] = 'الحساب مطلوب لنوع التوصيل المحدد.';

    // التحقق من حدود الحساب والعملات المسموحة (نظام مالي متكامل)
    if ($account_id && $amount_received > 0) {
        // جلب كود الحساب المالي الموحد
        $stmt_fa_check = $pdo->prepare("SELECT id FROM unified_accounts WHERE id = ?");
        $stmt_fa_check->execute([$account_id]);
        $chart_account_id_check = $stmt_fa_check->fetchColumn();

        if ($chart_account_id_check) {
            // التحقق من العملة المسموحة (اختياري في النظام الموحد حالياً)
            // if (!is_currency_allowed_for_account($chart_account_id_check, $currency_id)) {
            //    $errors[] = 'العملة المختارة غير مسموحة لهذا الحساب المالي.';
            // }
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $booking_number = generateBookingNumber($service_type);

            // جلب معرف حالة "حجز جديد"
            $stmt_status = $pdo->prepare("SELECT id FROM statuses WHERE status_name = 'حجز جديد' LIMIT 1");
            $stmt_status->execute();
            $initial_status_id = $stmt_status->fetchColumn();

            if (!$initial_status_id) {
                // إذا لم تكن موجودة، ننشئها
                $stmt_add_status = $pdo->prepare("INSERT INTO statuses (status_name, status_color) VALUES ('حجز جديد', 'primary')");
                $stmt_add_status->execute();
                $initial_status_id = $pdo->lastInsertId();
            }

            $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
            if (!$description) {
                // Get city names for description as fallback
                $stmt_city1 = $pdo->prepare("SELECT city_name FROM cities WHERE id = ?");
                $stmt_city1->execute([$from_city_id]);
                $from_city_name = $stmt_city1->fetchColumn();

                $stmt_city2 = $pdo->prepare("SELECT city_name FROM cities WHERE id = ?");
                $stmt_city2->execute([$to_city_id]);
                $to_city_name = $stmt_city2->fetchColumn();

                $description = "حجز تذكرة من " . $from_city_name . " إلى " . $to_city_name . " للمسافر " . $traveler_name;
            }

            $stmt = $pdo->prepare("
                INSERT INTO bus_flight_bookings (
                    booking_number, traveler_name, mobile_number, date_of_birth, place_of_birth, gender, nationality_id,
                    id_type, id_number, id_issue_place, id_issue_date, booking_date, service_type,
                    bus_type, trip_type, from_city_id, to_city_id, departure_date, return_date,
                    supplier_type, supplier_id, customer_id, account_id, notes, created_by,
                    status_id, description, branch_id, agent_id, operation_date
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?
                )
            ");

            // جلب بيانات الفرع والوكيل (الأولوية للنموذج ثم للمستخدم الحالي)
            $branch_id = $_POST['branch_id'] ?? $currentUser['branch_id'] ?? $_SESSION['branch_id'] ?? null;
            $form_agent_id = $agent_id ?: ($currentUser['agent_id'] ?? $_SESSION['agent_id'] ?? null);

            // إذا لم يكن للمستخدم فرع (مثلاً مدير النظام)، ولم يتم اختيار فرع من النموذج، نستخدم أول فرع متاح كافتراضي
            if ($branch_id === null) {
                $stmt_branch = $pdo->query("SELECT id FROM branches LIMIT 1");
                $branch_id = $stmt_branch->fetchColumn();
            }

            $stmt->execute([
                $booking_number,
                $traveler_name,
                $mobile_number,
                $date_of_birth,
                $place_of_birth,
                $gender,
                $nationality_id,
                $id_type,
                $id_number,
                $id_issue_place,
                $id_issue_date,
                $booking_date,
                $service_type,
                $bus_type,
                $trip_type,
                $from_city_id,
                $to_city_id,
                $departure_date,
                ($trip_type === 'round_trip' ? $return_date : NULL),
                'agent', // supplier_type
                $supplier_id,
                $customer_id,
                $account_id,
                $notes,
                $_SESSION['user_id'],
                $initial_status_id,
                $description,
                $branch_id,
                $form_agent_id,
                $operation_date
            ]);

            $new_booking_id = $pdo->lastInsertId();

            // استخدام المحرك المالي الموحد
            try {
                require_once '../includes/ServiceFinancialEngine.php';
                $financialEngine = new ServiceFinancialEngine($pdo, $_SESSION['user_id']);
                $financeResults = $financialEngine->processServiceFinance([
                    'service_type'    => 'تذاكر طيران وبصات',
                    'source_id'       => $new_booking_id,
                    'source_number'   => $booking_number,
                    'branch_id'       => $branch_id,
                    'customer_id'     => $customer_id,
                    'agent_id'        => $agent_id,
                    'supplier_id'     => $supplier_id,
                    'sale_price'      => $sale_price,
                    'discount'        => $discount,
                    'purchase_price'  => $purchase_price,
                    'sale_currency_id'=> $sale_currency_id,
                    'pur_currency_id' => $currency_id,
                    'exchange_rate'   => $exchange_rate,
                    'amount_received' => $amount_received,
                    'payment_account_id' => $account_id,
                    'delivery_type'   => $delivery_type,
                    'description'     => "حجز " . ($service_type == 'bus' ? 'باص' : 'طيران') . " للمسافر: " . $traveler_name . " - رقم الحجز " . $booking_number,
                    'operation_date'  => $operation_date
                ]);

                // ربط الحجز بفاتورة البيع والشراء
                $update_stmt = $pdo->prepare("
                    UPDATE bus_flight_bookings 
                    SET sales_invoice_id = ?, purchase_invoice_id = ?, auto_invoice_generated = 1 
                    WHERE id = ?
                ");
                $update_stmt->execute([
                    $financeResults['sales_invoice_id'], 
                    $financeResults['purchase_invoice_id'] ?? null, 
                    $new_booking_id
                ]);

            } catch (Exception $e) {
                error_log("Error in financial posting for booking: " . $e->getMessage());
                throw new Exception("خطأ في إنشاء الفواتير المالية: " . $e->getMessage());
            }

            $pdo->commit();

            $_SESSION['flash_message'] = [
                'type' => 'success',
                'title' => 'تم بنجاح!',
                'body' => 'تم إضافة الحجز الجديد بنجاح برقم: ' . $booking_number
            ];
            header('Location: bus_flight_bookings.php');
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['flash_message'] = [
                'type' => 'danger',
                'title' => 'خطأ!',
                'body' => 'حدث خطأ أثناء إضافة الحجز: ' . $e->getMessage()
            ];
            header('Location: bus_flight_bookings.php');
            exit();
        }
    } else {
        $_SESSION['flash_message'] = [
            'type' => 'danger',
            'title' => 'خطأ في الإدخال!',
            'body' => implode('<br>', $errors)
        ];
        header('Location: bus_flight_bookings.php');
        exit();
    }
}


// Handle Confirm/Cancel actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $booking_id = (int)$_GET['id'];
    $user_id = $_SESSION['user_id'];

    try {
        if ($action === 'confirm') {
            if (!has_permission('bookings_confirm')) throw new Exception('ليس لديك صلاحية لتأكيد الحجز');

            $stmt_status = $pdo->prepare("SELECT id FROM statuses WHERE status_name = 'تم تأكيد الحجز' LIMIT 1");
            $stmt_status->execute();
            $status_id = $stmt_status->fetchColumn();

            if ($status_id) {
                $pdo->prepare("UPDATE bus_flight_bookings SET status_id = ? WHERE id = ?")->execute([$status_id, $booking_id]);
                change_booking_status($booking_id, $status_id, $user_id, 'تم تأكيد الحجز');

                // الترحيل المالي عند التأكيد إذا كان مفعل في الإعدادات
                $settings = getSettings($pdo);
                $trigger = $settings['booking_post_trigger'] ?? '';
                if ($trigger === 'on_confirm') {
                    post_booking_to_financials($booking_id, $user_id);
                }

                $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم التأكيد', 'body' => 'تم تأكيد الحجز بنجاح'];
            }
        } elseif ($action === 'cancel') {
            if (!has_permission('bookings_cancel')) throw new Exception('ليس لديك صلاحية لإلغاء الحجز');

            // تحقق من الحالة الحالية قبل الإلغاء المباشر
            $stmt_curr = $pdo->prepare("SELECT s.status_name FROM bus_flight_bookings b JOIN statuses s ON b.status_id = s.id WHERE b.id = ?");
            $stmt_curr->execute([$booking_id]);
            $curr_status = $stmt_curr->fetchColumn();

            if (in_array($curr_status, ['سافر', 'مسافر'])) {
                throw new Exception("لا يمكن إلغاء حجز في حالة 'مسافر'");
            }

            $stmt_status = $pdo->prepare("SELECT id FROM statuses WHERE status_name = 'تم إلغاء الحجز' LIMIT 1");
            $stmt_status->execute();
            $status_id = $stmt_status->fetchColumn();

            if ($status_id) {
                $pdo->prepare("UPDATE bus_flight_bookings SET status_id = ? WHERE id = ?")->execute([$status_id, $booking_id]);
                change_booking_status($booking_id, $status_id, $user_id, 'تم إلغاء الحجز');
                $_SESSION['flash_message'] = ['type' => 'warning', 'title' => 'تم الإلغاء', 'body' => 'تم إلغاء الحجز بنجاح'];
            }
        }
    } catch (Exception $e) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => $e->getMessage()];
    }
    header('Location: bus_flight_bookings.php');
    exit();
}

// معالجة تغيير الحالة عبر سير العمل
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_workflow_status'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ!', 'body' => 'خطأ في التحقق من الطلب (CSRF).'];
        header('Location: bus_flight_bookings.php');
        exit();
    }
    $booking_id = (int)$_POST['booking_id'];
    $to_status_id = (int)$_POST['to_status_id'];
    $notes = $_POST['workflow_notes'] ?? '';
    $user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];
    $extra_fields = $_POST['extra_fields'] ?? [];
    $transition_id = $_POST['transition_id'] ?? null;

    if ($booking_id > 0 && $to_status_id > 0) {
        if (change_booking_status($booking_id, $to_status_id, $user_id, $notes, $extra_fields, $transition_id)) {
            $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم التحديث', 'body' => 'تم نقل الحجز إلى المرحلة الجديدة بنجاح'];
        } else {
            $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'فشل التحديث', 'body' => 'حدث خطأ أثناء محاولة تحديث الحالة'];
        }
    }
    header("Location: bus_flight_bookings.php");
    exit();
}

// Handle Request Approval (Cancellation/Modification)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_approval'])) {
    if (!verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ!', 'body' => 'خطأ في التحقق من الطلب (CSRF).'];
        header('Location: bus_flight_bookings.php');
        exit();
    }
    if (!has_permission('bookings_request_approval')) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'ليس لديك صلاحية لإنشاء طلبات اعتماد'];
        header('Location: bus_flight_bookings.php');
        exit();
    }
    $booking_id = (int)$_POST['booking_id'];
    $to_status_id = (int)$_POST['to_status_id'];
    $discount_amount = (float)($_POST['discount_amount'] ?? 0);
    $notes = $_POST['notes'] ?? '';
    $user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];
    $role_id = $_SESSION['role_id'] ?? null;

    try {
        $pdo->beginTransaction();

        // تحقق من وجود طلب معلق مسبقاً لهذا الحجز
        $stmt_check = $pdo->prepare("SELECT id FROM workflow_approval_requests WHERE booking_id = ? AND status = 'pending' LIMIT 1");
        $stmt_check->execute([$booking_id]);
        if ($stmt_check->fetch()) {
            throw new Exception("يوجد طلب معلق بالفعل لهذا الحجز، بانتظار موافقة المدير.");
        }

        // جلب الحالة الحالية
        $stmt_curr = $pdo->prepare("SELECT b.status_id, b.branch_id, s.status_name
                                    FROM bus_flight_bookings b
                                    JOIN statuses s ON b.status_id = s.id
                                    WHERE b.id = ?");
        $stmt_curr->execute([$booking_id]);
        $curr = $stmt_curr->fetch();

        if (!$curr) throw new Exception("الحجز غير موجود");

        if (in_array($curr['status_name'], ['سافر', 'مسافر'])) {
            throw new Exception("لا يمكن إلغاء أو تعديل حجز في حالة 'مسافر'");
        }

        if ($curr['status_name'] === 'تم إلغاء الحجز') {
            throw new Exception("الحجز ملغي بالفعل");
        }

        // البحث عن خطوة سير العمل المقابلة للحالة الحالية
        $stmt_ws_from = $pdo->prepare("SELECT ws.id FROM workflow_steps ws
                                      JOIN workflows w ON ws.workflow_id = w.id
                                      WHERE w.transaction_type = 'bus_flight_bookings'
                                      AND (w.branch_id = ? OR w.branch_id IS NULL)
                                      AND ws.status_id = ?
                                      ORDER BY w.branch_id DESC LIMIT 1");
        $stmt_ws_from->execute([$curr['branch_id'], $curr['status_id']]);
        $from_step_id = $stmt_ws_from->fetchColumn() ?: 0;

        // البحث عن خطوة سير العمل المقابلة للحالة المستهدفة
        $stmt_ws_to = $pdo->prepare("SELECT ws.id FROM workflow_steps ws
                                    JOIN workflows w ON ws.workflow_id = w.id
                                    WHERE w.transaction_type = 'bus_flight_bookings'
                                    AND (w.branch_id = ? OR w.branch_id IS NULL)
                                    AND ws.status_id = ?
                                    ORDER BY w.branch_id DESC LIMIT 1");
        $stmt_ws_to->execute([$curr['branch_id'], $to_status_id]);
        $to_step_id = $stmt_ws_to->fetchColumn() ?: 0;

        // إزالة شرط الإجبار على وجود خطوات سير عمل للسماح بالطلبات حتى لو لم يتم إعداد السير يدوياً
        // سيتم تخزين المعرفات كـ 0 إذا لم توجد، ومعالجة ذلك في صفحة الاعتمادات

        // إنشاء طلب الاعتماد
        $extra_data = json_encode(['discount_amount' => $discount_amount]);

        // توليد رقم طلب تلقائي
        $stmt_count = $pdo->query("SELECT COUNT(*) FROM workflow_approval_requests");
        $req_count = $stmt_count->fetchColumn() + 1;
        $request_number = "REQ-" . date('Ymd') . "-" . str_pad($req_count, 4, '0', STR_PAD_LEFT);

        $stmt_req = $pdo->prepare("INSERT INTO workflow_approval_requests
                                  (request_number, booking_id, from_step_id, to_step_id, requested_by, requested_role_id, notes, extra_data, status)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmt_req->execute([$request_number, $booking_id, $from_step_id, $to_step_id, $user_id, $role_id, $notes, $extra_data]);
        $request_id = $pdo->lastInsertId();

        // إرسال إشعار للمدراء والمسؤولين عن الاعتماد
        $notif_title = "طلب اعتماد جديد (" . $request_number . "): " . ($curr['status_name'] === 'تم إلغاء الحجز' ? "إلغاء حجز" : "تعديل حجز");
        $notif_msg = "المسافر: " . $curr['traveler_name'] . "\n";
        $notif_msg .= "رقم الحجز: " . $curr['booking_number'] . "\n";
        $notif_msg .= "الغرامة المقترحة: " . number_format($discount_amount, 2) . "\n";
        $notif_msg .= "ملاحظات: " . $notes;
        $notif_link = "workflow_approvals.php?status=pending";

        // جلب جميع المستخدمين الذين لديهم صلاحية الاعتماد أو هم مدراء
        $stmt_notif_users = $pdo->query("
            SELECT u.id
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE r.name IN ('admin', 'developer', 'super_admin', 'مدير', 'مطور')
               OR u.user_type IN ('admin', 'developer')
               OR u.id IN (
                   SELECT u2.id
                   FROM users u2
                   JOIN role_permissions_unified rp ON u2.role_id = rp.role_id
                   JOIN unified_permissions p ON rp.permission_id = p.id
                   WHERE p.permission_code = 'bookings_approve_requests'
               )
        ");
        $notif_users = $stmt_notif_users->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($notif_users)) {
            $stmt_notif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link, type, created_by) VALUES (?, ?, ?, ?, 'warning', ?)");
            foreach ($notif_users as $target_user_id) {
                $stmt_notif->execute([$target_user_id, $notif_title, $notif_msg, $notif_link, $user_id]);
            }
        }

        $pdo->commit();
        $_SESSION['flash_message'] = ['type' => 'info', 'title' => 'تم إرسال الطلب', 'body' => 'تم إرسال طلب الاعتماد للمدير بنجاح.'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => $e->getMessage()];
    }
    header('Location: bus_flight_bookings.php');
    exit();
}

// Handle Update Booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking'])) {
    if (!has_permission('bookings_edit')) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => 'ليس لديك صلاحية لتعديل الحجوزات'];
        header('Location: bus_flight_bookings.php');
        exit();
    }
    $booking_id      = (int)$_POST['booking_id'];
    $traveler_name   = $_POST['traveler_name'];
    $mobile_number   = $_POST['mobile_number'];
    $gender          = $_POST['gender'] ?? null;
    $date_of_birth   = $_POST['date_of_birth'] ?? null;
    $place_of_birth  = $_POST['place_of_birth'] ?? null;
    $nationality_id  = isset($_POST['nationality_id']) ? (int)$_POST['nationality_id'] : null;
    $id_type         = $_POST['id_type'] ?? null;
    $id_number       = $_POST['id_number'] ?? null;
    $service_type    = $_POST['service_type'];
    $bus_type        = $_POST['bus_type'] ?? null;
    $trip_type       = $_POST['trip_type'] ?? 'one_way';
    $from_city_id    = (int)$_POST['from_city_id'];
    $to_city_id      = (int)$_POST['to_city_id'];
    $description     = $_POST['description'];
    $booking_date    = $_POST['booking_date'];
    $departure_date  = $_POST['departure_date'];
    $return_date     = $_POST['return_date'] ?? null;
    $id_issue_place  = $_POST['id_issue_place'] ?? null;
    $id_issue_date   = $_POST['id_issue_date'] ?? null;
    $supplier_id     = isset($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : null;
    $notes           = $_POST['notes'];
    $branch_id       = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : null;

    // الحقول المالية
    $edit_operation_date   = $_POST['operation_date'] ?? null;
    $edit_sale_price       = isset($_POST['sale_price'])       ? (float)$_POST['sale_price']       : null;
    $edit_discount         = isset($_POST['discount'])         ? (float)$_POST['discount']         : 0;
    $edit_purchase_price   = isset($_POST['purchase_price'])   ? (float)$_POST['purchase_price']   : null;
    $edit_sale_currency_id = isset($_POST['sale_currency_id']) ? (int)$_POST['sale_currency_id']   : null;
    $edit_currency_id      = isset($_POST['currency_id'])      ? (int)$_POST['currency_id']        : null;
    $edit_exchange_rate    = isset($_POST['exchange_rate'])    ? (float)$_POST['exchange_rate']    : 1;
    $edit_delivery_type    = $_POST['delivery_type'] ?? null;
    $edit_customer_id      = isset($_POST['customer_id']) ? (int)$_POST['customer_id'] : null;
    $edit_agent_id         = isset($_POST['agent_id']) ? (int)$_POST['agent_id'] : null;
    $edit_account_id       = isset($_POST['account_id']) ? (int)$_POST['account_id'] : null;
    $edit_amount_received  = isset($_POST['amount_received'])  ? (float)$_POST['amount_received']  : null;

    try {
        $pdo->beginTransaction();

        // 1. تحديث بيانات الحجز الأساسية
        $pdo->prepare("
            UPDATE bus_flight_bookings SET
            traveler_name = ?, mobile_number = ?, gender = ?, date_of_birth = ?,
            place_of_birth = ?, nationality_id = ?, id_type = ?, id_number = ?,
            service_type = ?, bus_type = ?, trip_type = ?, from_city_id = ?, to_city_id = ?,
            supplier_id = ?, description = ?, booking_date = ?, departure_date = ?, return_date = ?, id_issue_place = ?,
            id_issue_date = ?, notes = ?, branch_id = ?,
            customer_id = ?, account_id = ?, operation_date = ?, agent_id = ?
            WHERE id = ?
        ")->execute([
            $traveler_name,
            $mobile_number,
            $gender,
            $date_of_birth,
            $place_of_birth,
            $nationality_id,
            $id_type,
            $id_number,
            $service_type,
            $bus_type,
            $trip_type,
            $from_city_id,
            $to_city_id,
            $supplier_id,
            $description,
            $booking_date,
            $departure_date,
            ($trip_type === 'round_trip' ? $return_date : null),
            $id_issue_place,
            $id_issue_date,
            $notes,
            $branch_id,
            $edit_customer_id,
            $edit_account_id,
            $edit_operation_date,
            $edit_agent_id,
            $booking_id
        ]);

        // 2. تحديث الفاتورتين (بيع + شراء) إن كانتا draft
        // جلب بيانات الحجز لمعرفة invoice_id
        $stmt_bk = $pdo->prepare("SELECT invoice_id, branch_id FROM bus_flight_bookings WHERE id = ?");
        $stmt_bk->execute([$booking_id]);
        $bk = $stmt_bk->fetch();

        if ($bk && $bk['invoice_id']) {
            // جلب فاتورة البيع
            $stmt_si = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $stmt_si->execute([$bk['invoice_id']]);
            $si = $stmt_si->fetch();

            if ($si) {
                $new_sale_curr = $edit_sale_currency_id ?? (int)$si['currency_id'];
                $new_delivery  = $edit_delivery_type    ?? $si['delivery_type'];
                $new_customer  = $edit_customer_id      ?? $si['customer_id'];
                $new_account   = $edit_account_id       ?? $si['account_id'];
                
                if ($si['invoice_status'] === 'draft') {
                    $new_sale      = $edit_sale_price       ?? (float)$si['total_amount'];
                    $new_disc      = $edit_discount         ?? (float)($si['discount'] ?? 0);
                    $new_pur_curr  = $edit_currency_id      ?? $new_sale_curr;
                    $new_agent     = $edit_agent_id         ?? $si['agent_id'];
                    $new_date      = $edit_operation_date   ?? $si['invoice_date'];

                    // التكلفة بعملة البيع (مثل invoices.php)
                    $new_cost_in_sale = $edit_purchase_price ?? (float)$si['cost_amount'];
                    if ($edit_purchase_price !== null && $new_sale_curr != $new_pur_curr && $edit_exchange_rate > 0) {
                        $new_cost_in_sale = $edit_purchase_price * $edit_exchange_rate;
                    }

                    $pdo->prepare("UPDATE invoices SET total_amount = ?, discount = ?, cost_amount = ?, currency_id = ?, description = ?, delivery_type = ?, customer_id = ?, agent_id = ?, account_id = ?, invoice_date = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$new_sale, $new_disc, $new_cost_in_sale, $new_sale_curr, $description ?: $si['description'], $new_delivery, $new_customer, $new_agent, $new_account, $new_date, $si['id']]);
                }

                // تحديث المبلغ الواصل إذا تم تزويده وإن كانت الفاتورة مسودة
                if ($edit_amount_received !== null && $si['invoice_status'] === 'draft') {
                    $old_amount_received = (float)($si['amount_received'] ?? 0);
                    $new_net_sale = (float)($si['total_amount'] - ($si['discount'] ?? 0));
                    
                    if ($edit_amount_received > $new_net_sale) {
                        throw new Exception("المبلغ الواصل لا يمكن أن يكون أكبر من صافي سعر البيع ($new_net_sale)");
                    }

                    // --- إلغاء السندات القديمة أولاً ---
                    // جلب جميع السندات المرتبطة بهذه الفاتورة
                    $stmt_old_vouchers = $pdo->prepare("
                        SELECT ft.id 
                        FROM payment_allocations pa
                        JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
                        WHERE pa.invoice_id = ?
                        AND ft.status = 'posted'
                        AND NOT (ft.reference_id = ? AND ft.reference_type = 'invoice')
                    ");
                    $stmt_old_vouchers->execute([$si['id'], $si['id']]);
                    $old_voucher_ids = $stmt_old_vouchers->fetchAll(PDO::FETCH_COLUMN);

                    foreach ($old_voucher_ids as $ft_id) {
                        // عكس الأرصدة قبل الحذف
                        $pdo->prepare("UPDATE journal_lines SET debit = -debit, credit = -credit WHERE financial_transaction_id = ?")->execute([$ft_id]);
                        $pdo->prepare("CALL sp_update_account_balances(?)")->execute([$ft_id]);

                        // حذف السند
                        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$ft_id]);
                        $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$ft_id]);
                        $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$ft_id]);
                    }

                    // تحديث المبلغ الواصل في الفاتورة
                    $new_payment_status = $edit_amount_received >= $new_net_sale ? 'fully_paid' : ($edit_amount_received > 0 ? 'partial' : 'unpaid');
                    $pdo->prepare("UPDATE invoices SET amount_received = ?, payment_status = ? WHERE id = ?")
                        ->execute([$edit_amount_received, $new_payment_status, $si['id']]);

                    // إذا كان المبلغ الجديد أكبر من الصفر وطريقة الدفع نقد أو تحويل بنكي، نقوم بإنشاء سند قبض
                    if ($edit_amount_received > 0 && in_array($new_delivery, ['cash', 'bank_transfer']) && $new_account && $new_customer) {
                        $stmt_p = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
                        $stmt_p->execute([$new_customer]);
                        $party_account_id = $stmt_p->fetchColumn();

                        if ($party_account_id) {
                            $stmt_sp = $pdo->prepare("CALL sp_create_receipt_voucher(?, ?, ?, ?, ?, 1.0, ?, ?, ?, ?, ?, ?, @v_id, @v_num)");
                            $stmt_sp->execute([
                                $bk['branch_id'],
                                'customer',
                                $new_customer,
                                $edit_amount_received,
                                $new_sale_curr,
                                $edit_account_id,
                                $party_account_id,
                                $bk['booking_number'],
                                "دفعة للحجز رقم: {$bk['booking_number']} للمسافر {$traveler_name}",
                                $_SESSION['user_id'],
                                null
                            ]);
                            $stmt_sp->closeCursor();
                            $voucher_id = $pdo->query("SELECT @v_id")->fetchColumn();

                            if ($voucher_id) {
                                $pdo->prepare(
                                    "INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount) VALUES (?, ?, ?)"
                                )->execute([$voucher_id, $si['id'], $edit_amount_received]);
                                $pdo->prepare("UPDATE financial_transactions SET status = 'posted' WHERE id = ? AND status = 'draft'")->execute([$voucher_id]);
                            }
                        }
                    }
                }
            }

                // فاتورة الشراء المرتبطة (عملة الشراء الأصلية)
                if ($si) {
                    $stmt_pi = $pdo->prepare("SELECT * FROM invoices WHERE source_type = ? AND source_id = ? AND invoice_category = 'purchase' LIMIT 1");
                    $stmt_pi->execute([$si['source_type'], $si['source_id']]);
                    $pi = $stmt_pi->fetch();

                    if ($pi && $pi['invoice_status'] === 'draft') {
                        $new_pur_cost = $edit_purchase_price ?? (float)$pi['total_amount'];
                        $new_pur_curr = $edit_currency_id    ?? (int)$pi['currency_id'];
                        $new_date      = $edit_operation_date   ?? $pi['invoice_date'];
                        $pdo->prepare("UPDATE invoices SET total_amount = ?, currency_id = ?, description = ?, invoice_date = ?, updated_at = NOW() WHERE id = ?")
                            ->execute([$new_pur_cost, $new_pur_curr, $description ?: $pi['description'], $new_date, $pi['id']]);
                    }
                }
        }


        $pdo->commit();
        $_SESSION['flash_message'] = ['type' => 'success', 'title' => 'تم التحديث', 'body' => 'تم تحديث بيانات الحجز والفواتير المرتبطة بنجاح'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_message'] = ['type' => 'danger', 'title' => 'خطأ', 'body' => $e->getMessage()];
    }
    header('Location: bus_flight_bookings.php');
    exit();
}

// Fetch filter data
$cities = $pdo->query("SELECT id, city_name FROM cities ORDER BY city_name ASC")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name FROM currencies ORDER BY currency_name ASC")->fetchAll();
$booking_statuses = $pdo->query("SELECT id, status_name FROM statuses WHERE status_name IN ('حجز جديد', 'مؤكد', 'ملغي', 'معدل') ORDER BY status_name ASC")->fetchAll();
$customers = $pdo->query("SELECT id, full_name FROM customers ORDER BY full_name ASC")->fetchAll();
$users = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name ASC")->fetchAll();
// جلب الموردين مع أكواد حساباتهم مثل invoices.php
$parent_stmt_suppliers = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$parent_stmt_suppliers->execute();
$suppliers_parent_id = $parent_stmt_suppliers->fetchColumn();

$suppliers_stmt = $pdo->prepare("
    SELECT coa.*,
           (SELECT id FROM suppliers WHERE account_id = coa.id LIMIT 1) as supplier_id
    FROM unified_accounts coa
    WHERE coa.parent_id = ? AND coa.account_status = 'active'
    ORDER BY coa.account_code ASC
");
$suppliers_stmt->execute([$suppliers_parent_id]);
$suppliers_with_codes = [];
while ($row = $suppliers_stmt->fetch()) {
    $row['display_name'] = $row['account_code'] . ' - ' . $row['account_name_ar'];
    $suppliers_with_codes[] = $row;
}

$suppliers = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE status = 'active' ORDER BY supplier_name ASC")->fetchAll();
$countries = $pdo->query("SELECT id, country_name FROM countries ORDER BY country_name ASC")->fetchAll();
$branches = $pdo->query("SELECT id, branch_name FROM branches ORDER BY branch_name ASC")->fetchAll();
// جلب الكيانات مع حساباتها (مثل invoices.php)
$customers_entities = $pdo->query("
    SELECT c.id as id, c.account_id as account_id, c.full_name as name, ua.account_code
    FROM customers c
    JOIN unified_accounts ua ON c.account_id = ua.id
    WHERE c.status = 'active' AND c.deleted_at IS NULL
    ORDER BY c.full_name ASC
")->fetchAll();

$agents_entities = $pdo->query("
    SELECT a.id, a.agent_name as name, a.account_id as account_id, acc.account_code
    FROM agents a
    JOIN unified_accounts acc ON a.account_id = acc.id
    WHERE a.status = 'active' AND a.deleted_at IS NULL
    ORDER BY a.agent_name ASC
")->fetchAll();

$cashboxes_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE account_code LIKE '101%' AND account_code != '101' AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();
$cash_accounts = $cashboxes_entities; // توحيد المسمى مع invoices.php

$banks_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE account_code LIKE '102%' AND account_code != '102' AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();
$bank_accounts = $banks_entities; // توحيد المسمى مع invoices.php

// جميع الحسابات الموحدة (للإختيار عند نوع التوصيل 'آجل')
$all_unified_accounts = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE account_status = 'active' 
    ORDER BY account_code ASC
")->fetchAll();


// Build dynamic query for bookings
$where_clauses = [];
$params = [];

// Apply user-specific filtering (scope of visibility)
$session_user_type = $_SESSION['user_type'] ?? '';
if ($session_user_type === 'agent' && isset($_SESSION['agent_id'])) {
    $where_clauses[] = "b.agent_id = ?";
    $params[] = $_SESSION['agent_id'];
} elseif ($session_user_type === 'branch' && isset($_SESSION['branch_id'])) {
    $where_clauses[] = "b.branch_id = ?";
    $params[] = $_SESSION['branch_id'];
}
// Add more scope filtering as per requirements (e.g., employee)

if (isset($_GET['from_date']) && !empty($_GET['from_date'])) {
    $where_clauses[] = "b.booking_date >= ?";
    $params[] = $_GET['from_date'];
}
if (isset($_GET['to_date']) && !empty($_GET['to_date'])) {
    $where_clauses[] = "b.booking_date <= ?";
    $params[] = $_GET['to_date'];
}
if (isset($_GET['service_type']) && !empty($_GET['service_type'])) {
    $where_clauses[] = "b.service_type = ?";
    $params[] = $_GET['service_type'];
}
if (isset($_GET['status_id']) && !empty($_GET['status_id'])) {
    $where_clauses[] = "b.status_id = ?";
    $params[] = $_GET['status_id'];
}
if (isset($_GET['from_city_id']) && !empty($_GET['from_city_id'])) {
    $where_clauses[] = "b.from_city_id = ?";
    $params[] = $_GET['from_city_id'];
}
if (isset($_GET['to_city_id']) && !empty($_GET['to_city_id'])) {
    $where_clauses[] = "b.to_city_id = ?";
    $params[] = $_GET['to_city_id'];
}
if (isset($_GET['supplier_id']) && !empty($_GET['supplier_id'])) {
    $where_clauses[] = "b.supplier_id = ?";
    $params[] = $_GET['supplier_id'];
}
if (isset($_GET['currency_id']) && !empty($_GET['currency_id'])) {
    $where_clauses[] = "inv.currency_id = ?";
    $params[] = $_GET['currency_id'];
}
if (isset($_GET['payment_type']) && !empty($_GET['payment_type'])) {
    $where_clauses[] = "inv.delivery_type = ?";
    $params[] = $_GET['payment_type'];
}
if (isset($_GET['created_by_user_id']) && !empty($_GET['created_by_user_id'])) {
    $where_clauses[] = "b.created_by = ?";
    $params[] = $_GET['created_by_user_id'];
}
if (isset($_GET['branch_id']) && !empty($_GET['branch_id'])) {
    $where_clauses[] = "b.branch_id = ?";
    $params[] = $_GET['branch_id'];
}
if (isset($_GET['agent_id']) && !empty($_GET['agent_id'])) {
    $where_clauses[] = "b.agent_id = ?";
    $params[] = $_GET['agent_id'];
}

// حقل البحث الديناميكي (رقم الحجز، اسم المسافر، رقم الجوال)
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = "%" . $_GET['search'] . "%";
    $where_clauses[] = "(b.booking_number LIKE ? OR b.traveler_name LIKE ? OR b.mobile_number LIKE ?)";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

$query = "
    SELECT
        b.*,
        COALESCE(inv.total_amount, 0) AS sale_price,
        COALESCE(inv_p.total_amount, 0) AS purchase_price,

        -- حساب المبلغ المحصل (البيع) - نفس منطق invoices.php
        (
            IFNULL((
                SELECT SUM(jl.debit)
                FROM journal_lines jl
                JOIN financial_transactions ft_i ON jl.financial_transaction_id = ft_i.id
                WHERE ft_i.reference_id = inv.id AND ft_i.reference_type = 'invoice' AND ft_i.status = 'posted'
                AND jl.account_id IN (
                    SELECT id FROM unified_accounts
                    WHERE account_code LIKE '101%' OR account_code LIKE '102%' OR account_code LIKE '111%' OR account_type IN ('box', 'bank')
                )
            ), inv.amount_received) +
            IFNULL((
                SELECT SUM(pa.allocated_amount)
                FROM payment_allocations pa
                JOIN financial_transactions ft ON pa.financial_transaction_id = ft.id
                WHERE pa.invoice_id = inv.id AND ft.status = 'posted'
                AND ft.id NOT IN (
                    SELECT id FROM financial_transactions
                    WHERE reference_id = inv.id AND reference_type = 'invoice'
                )
            ), 0)
        ) AS amount_received,

        -- حساب المبلغ المسدد للمورد (الشراء) - نفس منطق invoices.php
        (
            IFNULL((
                SELECT SUM(jl_p.credit)
                FROM journal_lines jl_p
                JOIN financial_transactions ft_ip ON jl_p.financial_transaction_id = ft_ip.id
                WHERE ft_ip.reference_id = inv_p.id AND ft_ip.reference_type = 'invoice' AND ft_ip.status = 'posted'
                AND jl_p.account_id IN (
                    SELECT id FROM unified_accounts
                    WHERE account_code LIKE '101%' OR account_code LIKE '102%' OR account_code LIKE '111%' OR account_type IN ('box', 'bank')
                )
            ), inv_p.amount_received) +
            IFNULL((
                SELECT SUM(pa_p.allocated_amount)
                FROM payment_allocations pa_p
                JOIN financial_transactions ft_p ON pa_p.financial_transaction_id = ft_p.id
                WHERE pa_p.invoice_id = inv_p.id AND ft_p.status = 'posted'
                AND ft_p.id NOT IN (
                    SELECT id FROM financial_transactions
                    WHERE reference_id = inv_p.id AND reference_type = 'invoice'
                )
            ), 0)
        ) AS purchase_received,

        ((COALESCE(inv.total_amount, 0) - COALESCE(inv.discount, 0)) - COALESCE(inv.amount_received, 0)) AS remaining_amount,

        -- حساب الربح بناءً على فرق البيع والتكلفة المخزنة في فاتورة البيع
        (IFNULL(inv.total_amount, 0) - IFNULL(inv.discount, 0) - IFNULL(inv.cost_amount, 0)) AS profit,
        b.customer_id,
        b.agent_id,
        b.account_id,
        inv.delivery_type,


        COALESCE(inv.delivery_type, 'cash') AS payment_type,
        COALESCE(inv.currency_id, 1) AS currency_id,
        c_from.city_name AS from_city_name,
        c_to.city_name AS to_city_name,
        curr.currency_name,
        bs.status_name AS booking_status_name,
        bs.status_color AS booking_status_color,
        cust.full_name AS customer_full_name,
        ua.account_name_ar AS account_name,
        ua.account_code AS account_code,
        u.full_name AS created_by_user_full_name,
        s.supplier_name,
        inv.id AS sales_invoice_id, inv.invoice_status AS sales_status, inv.invoice_number AS sales_invoice_number,
        inv_p.id AS purchase_invoice_id, inv_p.invoice_status AS purchase_status, inv_p.invoice_number AS purchase_invoice_number
    FROM bus_flight_bookings b
    LEFT JOIN cities c_from ON b.from_city_id = c_from.id
    LEFT JOIN cities c_to ON b.to_city_id = c_to.id
    LEFT JOIN invoices inv ON (
        inv.id = b.sales_invoice_id 
        OR inv.id = b.invoice_id 
        OR (inv.source_type = 'تذاكر طيران وبصات' AND inv.source_id = b.id AND inv.invoice_category = 'sales')
    )
    LEFT JOIN invoices inv_p ON (
        inv_p.id = b.purchase_invoice_id 
        OR (inv_p.source_type = 'تذاكر طيران وبصات' AND inv_p.source_id = b.id AND inv_p.invoice_category = 'purchase')
    )
    LEFT JOIN currencies curr ON COALESCE(inv.currency_id, 1) = curr.id
    LEFT JOIN statuses bs ON b.status_id = bs.id
    LEFT JOIN customers cust ON b.customer_id = cust.id
    LEFT JOIN unified_accounts ua ON b.account_id = ua.id
    LEFT JOIN users u ON b.created_by = u.id
    LEFT JOIN suppliers s ON b.supplier_id = s.id
";

if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(" AND ", $where_clauses);
}

$query .= " ORDER BY b.created_at DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// فحص صلاحية مشاهدة كافة البيانات
if (!has_permission('bookings_view_all') && !$is_admin) {
    // إذا لم تكن لديه الصلاحية وليس مديراً، يرى فقط الحجوزات التي أنشأها بنفسه
    $filtered_bookings = [];
    foreach ($bookings as $b) {
        if ($b['created_by'] == $_SESSION['user_id']) {
            $filtered_bookings[] = $b;
        }
    }
    $bookings = $filtered_bookings;
}

// جلب معرفات الحجوزات التي لها طلبات اعتماد معلقة
$pending_bookings_ids = $pdo->query("SELECT booking_id FROM workflow_approval_requests WHERE status = 'pending' AND booking_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

// Flash message display (if any) - assuming it's handled in header.php or a common place
if (isset($_SESSION['flash_message'])) {
    $msg = $_SESSION['flash_message'];
    echo sprintf(
        '<div class="alert alert-%s border-0 shadow-sm rounded-4 p-3 mb-4 d-flex align-items-center alert-dismissible fade show" role="alert">
            <div class="bg-%s bg-opacity-10 p-2 rounded-circle me-3">
                <i class="fas %s fs-4"></i>
            </div>
            <div>
                <h6 class="mb-0 fw-bold">%s</h6>
                <small>%s</small>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>',
        $msg['type'],
        $msg['type'],
        $msg['type'] === 'success' ? 'fa-check-circle text-success' : 'fa-exclamation-triangle text-danger',
        htmlspecialchars($msg['title']),
        htmlspecialchars($msg['body'])
    );
    unset($_SESSION['flash_message']);
}
?>

<style>
    .booking-form-dialog {
        width: min(1200px, calc(100vw - 2rem));
        max-width: 1200px;
    }

    .booking-form-content {
        max-height: 92vh;
        overflow: hidden;
        display: flex;
        flex-direction: column;
    }

    .booking-form-body {
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    .booking-form-body .row {
        align-items: start;
    }

    .booking-form-body h5 {
        font-size: 0.875rem;
        padding-bottom: 0.3rem;
        margin-bottom: 0.5rem;
        border-bottom: 1px solid rgba(13, 110, 253, .14);
    }

    .booking-form-body .form-label {
        min-height: 1rem;
        font-size: 0.8rem;
        margin-bottom: 0.2rem;
    }

    .booking-form-body .form-control,
    .booking-form-body .form-select,
    .booking-form-body .select2-container .select2-selection--single {
        min-height: 32px;
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
    }

    .booking-form-body textarea.form-control {
        min-height: 50px;
        padding: 0.25rem 0.5rem;
        font-size: 0.8rem;
    }

    .booking-form-footer {
        position: sticky;
        bottom: 0;
        z-index: 2;
        gap: 0.5rem;
        padding: 0.5rem 1rem !important;
    }

    @media (min-width: 992px) {
        .booking-form-body {
            padding: 0.75rem 1rem !important;
        }

        .booking-form-body .row {
            row-gap: 0.5rem !important;
        }
    }

    @media (max-width: 575.98px) {
        .booking-form-dialog {
            width: 100%;
            max-width: 100%;
        }

        .booking-form-content {
            height: 100%;
            max-height: 100%;
            border-radius: 0 !important;
        }

        .booking-form-body {
            padding: 0.5rem !important;
        }

        .booking-form-footer {
            padding: 0.5rem !important;
            flex-direction: column-reverse;
            align-items: stretch;
        }

        .booking-form-footer .btn {
            width: 100%;
        }
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-plane-departure me-2"></i> إدارة حجوزات الباصات والطيران</h3>
            <p class="text-muted small mb-0">عرض وإدارة جميع حجوزات الباصات والطيران في النظام</p>
        </div>
        <?php if (has_permission('bookings_create')): ?>
            <button class="btn btn-primary rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#addBookingModal">
                <i class="fas fa-plus me-1"></i> إضافة حجز جديد
            </button>
        <?php endif; ?>
    </div>

    <!-- Filters Form -->
    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body">
            <form method="GET" action="bus_flight_bookings.php" id="filterForm">
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="search" class="form-label small fw-bold text-primary"><i class="fas fa-search me-1"></i> بحث شامل (اسم، جوال، رقم حجز)</label>
                        <input type="text" class="form-control rounded-3" id="search" name="search" placeholder="ابحث هنا..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="from_date" class="form-label small text-muted">من تاريخ</label>
                        <input type="date" class="form-control rounded-3" id="from_date" name="from_date" value="<?php echo htmlspecialchars($_GET['from_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="to_date" class="form-label small text-muted">إلى تاريخ</label>
                        <input type="date" class="form-control rounded-3" id="to_date" name="to_date" value="<?php echo htmlspecialchars($_GET['to_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="status_id" class="form-label small text-muted">الحالة</label>
                        <select class="form-select rounded-3" id="status_id" name="status_id">
                            <option value="">الكل</option>
                            <?php foreach ($booking_statuses as $status): ?>
                                <option value="<?php echo $status['id']; ?>" <?php echo (h($_GET['status_id'] ?? '') == $status['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status['status_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-primary rounded-3 w-100 shadow-sm"><i class="fas fa-filter me-1"></i> تصفية</button>
                        <a href="bus_flight_bookings.php" class="btn btn-light rounded-3 border" title="إعادة تعيين"><i class="fas fa-redo"></i></a>
                    </div>
                </div>

                <!-- خيارات إضافية مخفية -->
                <div class="collapse mt-3" id="moreFilters">
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="service_type" class="form-label small text-muted">نوع الخدمة</label>
                            <select class="form-select rounded-3" id="service_type" name="service_type">
                                <option value="">الكل</option>
                                <option value="bus" <?php echo (($_GET['service_type'] ?? '') == 'bus') ? 'selected' : ''; ?>>باص</option>
                                <option value="flight" <?php echo (($_GET['service_type'] ?? '') == 'flight') ? 'selected' : ''; ?>>طيران</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="supplier_id" class="form-label small text-muted">المورد</label>
                            <select class="form-select rounded-3" id="supplier_id" name="supplier_id">
                                <option value="">الكل</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>" <?php echo (($_GET['supplier_id'] ?? '') == $supplier['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="payment_type" class="form-label small text-muted">نوع التوصيل</label>
                            <select class="form-select rounded-3" id="payment_type" name="payment_type">
                                <option value="">الكل</option>
                                <option value="cash" <?php echo (($_GET['payment_type'] ?? '') == 'cash') ? 'selected' : ''; ?>>نقد</option>
                                <option value="credit" <?php echo (($_GET['payment_type'] ?? '') == 'credit') ? 'selected' : ''; ?>>أجل</option>
                                <option value="bank_transfer" <?php echo (($_GET['payment_type'] ?? '') == 'bank_transfer') ? 'selected' : ''; ?>>تحويل بنكي</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="text-center mt-2">
                    <button type="button" class="btn btn-link btn-sm text-decoration-none" data-bs-toggle="collapse" data-bs-target="#moreFilters">
                        <i class="fas fa-chevron-down me-1"></i> خيارات بحث إضافية
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bookings Table -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="px-4 py-3 border-0 text-secondary small text-uppercase fw-bold">رقم الفاتورة</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">التاريخ</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">العميل/المورد</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">البيان</th>
                            <th class="text-end border-0 text-secondary small text-uppercase fw-bold">المبلغ</th>
                            <th class="text-end border-0 text-secondary small text-uppercase fw-bold">المحصل</th>
                            <th class="text-end border-0 text-secondary small text-uppercase fw-bold">المتبقي</th>
                            <th class="text-end border-0 text-secondary small text-uppercase fw-bold">الربح</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">العملة</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">حالة الفاتورة</th>
                            <th class="border-0 text-secondary small text-uppercase fw-bold">حالة السداد</th>
                            <th class="text-center border-0 text-secondary small text-uppercase fw-bold">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($bookings)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-4 text-muted">لا توجد حجوزات لعرضها حالياً.</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $workflows_cache = [];
                            foreach ($bookings as $booking):
                                // جلب سير العمل (مع التخزين المؤقت للفرع)
                                $bid = $booking['branch_id'] ?: 0;
                                if (!isset($workflows_cache[$bid])) {
                                    $workflows_cache[$bid] = get_workflow_for_transaction('bus_flight_bookings', $booking['branch_id']);
                                }
                                $workflow = $workflows_cache[$bid];
                                $allowed_transitions = [];
                                $current_step_id = null;

                                if ($workflow) {
                                    // جلب الخطوة الحالية بناءً على الحالة
                                    $stmt_curr = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id = ? AND status_id = ? LIMIT 1");
                                    $stmt_curr->execute([$workflow['id'], $booking['status_id']]);
                                    $current_step_id = $stmt_curr->fetchColumn();

                                    if ($current_step_id) {
                                        $allowed_transitions = get_allowed_transitions($workflow['id'], $current_step_id, $_SESSION['role_id'] ?? null, $_SESSION['user_id'] ?? null);
                                    }
                                }
                            ?>
                                <tr>
                                    <!-- رقم الفاتورة -->
                                    <td class="px-4 py-3 fw-bold small text-primary">
                                        <?php if ($booking['sales_invoice_number']): ?>
                                            <div class="mb-1">
                                                <?php echo htmlspecialchars($booking['sales_invoice_number']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($booking['purchase_invoice_number']): ?>
                                            <div>
                                                <?php echo htmlspecialchars($booking['purchase_invoice_number']); ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!$booking['sales_invoice_number'] && !$booking['purchase_invoice_number']): ?>
                                            <span class="text-muted small">---</span>
                                        <?php endif; ?>
                                    </td>
                                    <!-- التاريخ -->
                                    <td class="small"><?php echo htmlspecialchars($booking['booking_date']); ?></td>
                                    <!-- العميل/المورد -->
                                    <td class="small">
                                        <div class="mb-1">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill small">المورد</span>
                                            <span class="text-muted"><?php echo htmlspecialchars($booking['supplier_name'] ?: '---'); ?></span>
                                        </div>
                                        <div>
                                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill small">الحساب</span>
                                            <span class="text-muted"><?php echo htmlspecialchars(($booking['account_code'] ? $booking['account_code'] . ' - ' : '') . ($booking['account_name'] ?: '---')); ?></span>
                                        </div>
                                    </td>
                                    <!-- البيان (خط السير) -->
                                    <td class="small">
                                        <div class="d-flex align-items-center">
                                            <span class="text-muted small"><?php echo htmlspecialchars($booking['from_city_name']); ?></span>
                                            <i class="fas fa-long-arrow-alt-left mx-2 text-primary small"></i>
                                            <span class="fw-bold small"><?php echo htmlspecialchars($booking['to_city_name']); ?></span>
                                        </div>
                                        <div class="mt-1">
                                            <?php if ($booking['service_type'] == 'bus'): ?>
                                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill small"><i class="fas fa-bus me-1"></i> باص</span>
                                            <?php else: ?>
                                                <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill small"><i class="fas fa-plane me-1"></i> طيران</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <!-- المبلغ (الصافي) -->
                                    <td class="text-end small fw-bold text-primary">
                                        <?php
                                        $net_amount = $booking['sale_price'] - ($booking['discount'] ?? 0);
                                        echo number_format($net_amount, 2);
                                        ?>
                                    </td>
                                    <!-- المحصل -->
                                    <td class="text-end small fw-bold text-success"><?php echo number_format($booking['amount_received'], 2); ?></td>
                                    <!-- المتبقي -->
                                    <td class="text-end small fw-bold text-danger">
                                        <?php
                                        $remaining = $net_amount - $booking['amount_received'];
                                        echo number_format($remaining, 2);
                                        ?>
                                    </td>
                                    <!-- الربح -->
                                    <td class="text-end small fw-bold <?php echo ($booking['profit'] >= 0) ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo number_format($booking['profit'], 2); ?>
                                    </td>
                                    <!-- العملة -->
                                    <td class="small"><?php echo htmlspecialchars($booking['currency_name']); ?></td>
                                    <!-- حالة الفاتورة (الترحيل) - الفاتورتين -->
                                    <td class="small">
                                        <!-- فاتورة البيع -->
                                        <div class="mb-1">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill small">بيع</span>
                                            <?php if ($booking['sales_status'] == 'posted'): ?>
                                                <span class="badge bg-success rounded-pill small">مرحل</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary rounded-pill small">مسودة</span>
                                            <?php endif; ?>
                                        </div>
                                        <!-- فاتورة الشراء -->
                                        <div>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill small">شراء</span>
                                            <?php if ($booking['purchase_status'] == 'posted'): ?>
                                                <span class="badge bg-success rounded-pill small">مرحل</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary rounded-pill small">مسودة</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <!-- حالة السداد - الفاتورتين -->
                                    <td class="small">
                                        <?php
                                        $net_amount = $booking['sale_price'] - ($booking['discount'] ?? 0);
                                        $remaining = $net_amount - $booking['amount_received'];
                                        ?>
                                        <!-- فاتورة البيع -->
                                        <div class="mb-1">
                                            <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill small">بيع</span>
                                            <?php if ($remaining <= 0): ?>
                                                <span class="badge bg-success rounded-pill small"><i class="fas fa-check me-1"></i>مسددة</span>
                                            <?php elseif ($booking['amount_received'] > 0): ?>
                                                <span class="badge bg-warning text-dark rounded-pill small"><i class="fas fa-clock me-1"></i>جزئي</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger rounded-pill small"><i class="fas fa-times me-1"></i>غير مسددة</span>
                                            <?php endif; ?>
                                        </div>
                                        <!-- فاتورة الشراء -->
                                        <div>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill small">شراء</span>
                                            <?php
                                            // لاحقاً يمكن إضافة منطق سداد فاتورة الشراء
                                            $purchase_paid = $booking['purchase_paid'] ?? 0;
                                            $purchase_remaining = $booking['purchase_price'] - $purchase_paid;
                                            ?>
                                            <?php if ($purchase_remaining <= 0): ?>
                                                <span class="badge bg-success rounded-pill small"><i class="fas fa-check me-1"></i>مسددة</span>
                                            <?php elseif ($purchase_paid > 0): ?>
                                                <span class="badge bg-warning text-dark rounded-pill small"><i class="fas fa-clock me-1"></i>جزئي</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger rounded-pill small"><i class="fas fa-times me-1"></i>غير مسددة</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group shadow-sm">
                                            <?php if (has_permission('bookings_edit')): ?>
                                                <button class="btn btn-sm btn-light border" data-bs-toggle="modal" data-bs-target="#editBookingModal<?php echo $booking['id']; ?>" title="تعديل">
                                                    <i class="fas fa-edit text-primary"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (has_permission('bookings_print')): ?>
                                                <a href="bus_flight_bookings_print.php?id=<?php echo $booking['id']; ?>" target="_blank" class="btn btn-sm btn-light border" title="طباعة الحجز">
                                                    <i class="fas fa-print text-secondary"></i>
                                                </a>
                                                <?php if ($booking['booking_status_name'] === 'مؤكد'): ?>
                                                    <a href="bus_flight_bookings_ticket.php?id=<?php echo $booking['id']; ?>" target="_blank" class="btn btn-sm btn-light border" title="طباعة التذكرة">
                                                        <i class="fas fa-ticket-alt text-primary"></i>
                                                    </a>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php
                                            // التحقق من وجود سند استرداد لهذا الحجز (استخدام جدول المستندات الجديد)
                                            $stmt_refund = $pdo->prepare("SELECT id FROM documents WHERE reference_type = ? AND reference_id = ? AND document_type = 'Payment_Voucher' ORDER BY id DESC LIMIT 1");
                                            $stmt_refund->execute([$booking['service_type'], $booking['id']]);
                                            $refund_id = $stmt_refund->fetchColumn();

                                            if ($booking['booking_status_name'] === 'تم إلغاء الحجز' && $refund_id): ?>
                                                <a href="payments_print.php?id=<?php echo $refund_id; ?>" target="_blank" class="btn btn-sm btn-light border" title="طباعة سند الاسترداد">
                                                    <i class="fas fa-file-invoice-dollar text-success"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php
                                            // التحقق من وجود فواتير قابلة للترحيل
                                            $can_post_sales = $booking['sales_invoice_id'] && $booking['sales_status'] == 'draft';
                                            $can_post_purchase = $booking['purchase_invoice_id'] && $booking['purchase_status'] == 'draft';
                                            $can_post_all = $can_post_sales && $can_post_purchase;

                                            if ($can_post_sales || $can_post_purchase):
                                            ?>
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fas fa-file-invoice-dollar me-1"></i> ترحيل
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                        <?php if ($can_post_all): ?>
                                                            <li>
                                                                <a class="dropdown-item text-success fw-bold" href="invoices.php?post_invoice=<?php echo $booking['sales_invoice_id']; ?>&post_purchase=<?php echo $booking['purchase_invoice_id']; ?>&return_to=bus_flight_bookings.php" onclick="return confirm('هل أنت متأكد من ترحيل الفواتير (البيع والشراء) معاً؟')">
                                                                    <i class="fas fa-check-double me-2"></i> ترحيل الكل (بيع + شراء)
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <hr class="dropdown-divider">
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($can_post_sales): ?>
                                                            <li>
                                                                <a class="dropdown-item text-primary" href="invoices.php?post_invoice=<?php echo $booking['sales_invoice_id']; ?>&return_to=bus_flight_bookings.php" onclick="return confirm('هل أنت متأكد من ترحيل فاتورة البيع؟')">
                                                                    <i class="fas fa-file-invoice-dollar me-2"></i> ترحيل فاتورة البيع
                                                                    <span class="badge bg-primary-subtle text-primary ms-1"><?php echo $booking['sales_invoice_number']; ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($can_post_purchase): ?>
                                                            <li>
                                                                <a class="dropdown-item text-warning" href="invoices.php?post_invoice=<?php echo $booking['purchase_invoice_id']; ?>&return_to=bus_flight_bookings.php" onclick="return confirm('هل أنت متأكد من ترحيل فاتورة الشراء؟')">
                                                                    <i class="fas fa-file-invoice me-2"></i> ترحيل فاتورة الشراء
                                                                    <span class="badge bg-warning-subtle text-warning ms-1"><?php echo $booking['purchase_invoice_number']; ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>
                                                        <li class="dropdown-header text-muted small">حالة الفواتير: مسودة (draft)</li>

                                                        <?php if ($booking['sales_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item text-info" href="invoices.php?action=view&id=<?php echo $booking['sales_invoice_id']; ?>" target="_blank">
                                                                    <i class="fas fa-eye me-2"></i> عرض فاتورة البيع
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($booking['purchase_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item text-info" href="invoices.php?action=view&id=<?php echo $booking['purchase_invoice_id']; ?>" target="_blank">
                                                                    <i class="fas fa-eye me-2"></i> عرض فاتورة الشراء
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php elseif ($booking['sales_invoice_id'] || $booking['purchase_invoice_id']): ?>
                                                <!-- الفواتير مُرحلة - عرض روابط وزر التراجع -->
                                                <div class="btn-group">
                                                    <button type="button" class="btn btn-sm btn-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fas fa-check me-1"></i> مُرحل
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                                        <li>
                                                            <h6 class="dropdown-header small fw-bold text-danger">إعادة التعيين إلى مسودة</h6>
                                                        </li>
                                                        <?php if ($booking['sales_invoice_id'] && $booking['sales_status'] == 'posted' && $booking['purchase_invoice_id'] && $booking['purchase_status'] == 'posted'): ?>
                                                            <li>
                                                                <a class="dropdown-item py-2 text-danger" href="invoices.php?reset_invoice=<?php echo $booking['sales_invoice_id']; ?>&reset_purchase=<?php echo $booking['purchase_invoice_id']; ?>&reset_type=all&return_to=bus_flight_bookings.php" onclick="return confirm('إلغاء ترحيل البيع والشراء معاً؟')">
                                                                    <i class="fas fa-sync me-2"></i> إلغاء ترحيل الكل
                                                                </a>
                                                            </li>
                                                            <li>
                                                                <hr class="dropdown-divider">
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($booking['sales_invoice_id'] && $booking['sales_status'] == 'posted'): ?>
                                                            <li>
                                                                <a class="dropdown-item py-2 text-warning" href="invoices.php?reset_invoice=<?php echo $booking['sales_invoice_id']; ?>&reset_type=sales&return_to=bus_flight_bookings.php" onclick="return confirm('إلغاء ترحيل فاتورة البيع؟')">
                                                                    <i class="fas fa-undo me-2"></i> إلغاء ترحيل البيع
                                                                    <span class="badge bg-warning-subtle text-warning ms-1"><?php echo $booking['sales_invoice_number']; ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($booking['purchase_invoice_id'] && $booking['purchase_status'] == 'posted'): ?>
                                                            <li>
                                                                <a class="dropdown-item py-2 text-secondary" href="invoices.php?reset_invoice=<?php echo $booking['purchase_invoice_id']; ?>&reset_type=purchase&return_to=bus_flight_bookings.php" onclick="return confirm('إلغاء ترحيل فاتورة الشراء؟')">
                                                                    <i class="fas fa-history me-2"></i> إلغاء ترحيل الشراء
                                                                    <span class="badge bg-secondary-subtle text-secondary ms-1"><?php echo $booking['purchase_invoice_number']; ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>
                                                        <li class="dropdown-header text-muted small">حذف الفواتير</li>

                                                        <?php if ($booking['sales_invoice_id'] && $booking['purchase_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item py-2 text-danger" href="invoices.php?delete_invoice=<?php echo $booking['sales_invoice_id']; ?>&delete_both=<?php echo $booking['purchase_invoice_id']; ?>&return_to=bus_flight_bookings.php" onclick="return confirm('حذف فاتورة البيع والشراء معاً؟')">
                                                                    <i class="fas fa-trash-alt me-2"></i> حذف الكل (الفواتير)
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($booking['sales_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item py-2 text-primary" href="invoices.php?delete_invoice=<?php echo $booking['sales_invoice_id']; ?>&confirm_linked=1&return_to=bus_flight_bookings.php" onclick="return confirm('حذف فاتورة البيع؟')">
                                                                    <i class="fas fa-trash me-2"></i> حذف فاتورة البيع
                                                                    <span class="badge bg-primary-subtle text-primary ms-1"><?php echo $booking['sales_invoice_number']; ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($booking['purchase_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item py-2 text-warning" href="invoices.php?delete_invoice=<?php echo $booking['purchase_invoice_id']; ?>&confirm_linked=1&return_to=bus_flight_bookings.php" onclick="return confirm('حذف فاتورة الشراء؟')">
                                                                    <i class="fas fa-trash me-2"></i> حذف فاتورة الشراء
                                                                    <span class="badge bg-warning-subtle text-warning ms-1"><?php echo $booking['purchase_invoice_number']; ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <li>
                                                            <hr class="dropdown-divider">
                                                        </li>

                                                        <?php if ($booking['sales_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item text-success" href="invoices.php?action=view&id=<?php echo $booking['sales_invoice_id']; ?>" target="_blank">
                                                                    <i class="fas fa-file-invoice-dollar me-2"></i> عرض فاتورة البيع
                                                                    <span class="badge bg-success ms-1"><?php echo $booking['sales_invoice_number']; ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>

                                                        <?php if ($booking['purchase_invoice_id']): ?>
                                                            <li>
                                                                <a class="dropdown-item text-success" href="invoices.php?action=view&id=<?php echo $booking['purchase_invoice_id']; ?>" target="_blank">
                                                                    <i class="fas fa-file-invoice me-2"></i> عرض فاتورة الشراء
                                                                    <span class="badge bg-success ms-1"><?php echo $booking['purchase_invoice_number']; ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (has_permission('bookings_view_details')): ?>
                                                <a href="bus_flight_bookings_details.php?id=<?php echo $booking['id']; ?>" class="btn btn-sm btn-light border" title="عرض التفاصيل">
                                                    <i class="fas fa-eye text-info"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php
                                            $has_pending_req = in_array($booking['id'], $pending_bookings_ids);
                                            $can_request_change = has_permission('bookings_request_approval') && !in_array($booking['booking_status_name'], ['تم إلغاء الحجز', 'سافر', 'مسافر']);

                                            if ($can_request_change): ?>
                                                <button class="btn btn-sm btn-light border <?= $has_pending_req ? 'opacity-50' : '' ?>"
                                                    <?= $has_pending_req ? 'onclick="alert(\'يوجد طلب سابق، يرجى الانتظار موافقة المدير\'); return false;"' : 'data-bs-toggle="modal" data-bs-target="#requestCancelModal' . $booking['id'] . '"' ?>
                                                    title="طلب إلغاء">
                                                    <i class="fas fa-times-circle text-danger"></i>
                                                </button>
                                                <button class="btn btn-sm btn-light border <?= $has_pending_req ? 'opacity-50' : '' ?>"
                                                    <?= $has_pending_req ? 'onclick="alert(\'يوجد طلب سابق، يرجى الانتظار موافقة المدير\'); return false;"' : 'data-bs-toggle="modal" data-bs-target="#requestModModal' . $booking['id'] . '"' ?>
                                                    title="طلب تعديل">
                                                    <i class="fas fa-edit text-warning"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if (has_permission('bookings_change_workflow') && !empty($allowed_transitions)): ?>
                                                <div class="dropdown d-inline-block">
                                                    <button class="btn btn-sm btn-light border dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="تغيير المرحلة">
                                                        <i class="fas fa-random text-success"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3">
                                                        <li>
                                                            <h6 class="dropdown-header extra-small text-muted">نقل إلى:</h6>
                                                        </li>
                                                        <?php foreach ($allowed_transitions as $trans): ?>
                                                            <li>
                                                                <a class="dropdown-item small d-flex align-items-center justify-content-between py-2"
                                                                    href="#"
                                                                    data-bs-toggle="modal"
                                                                    data-bs-target="#workflowModal_<?= $booking['id'] ?>_<?= $trans['to_step_id'] ?>">
                                                                    <span><i class="fas fa-chevron-left me-2 text-<?= $trans['color'] ?: 'primary' ?>"></i> <?= htmlspecialchars($trans['to_step_name']) ?></span>
                                                                </a>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
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
</div>

<!-- =================================================================================================== -->
<!--                                        SECTION: MODALS                                              -->
<!-- =================================================================================================== -->

<!-- Add Booking Modal -->
<div class="modal fade" id="addBookingModal">
    <div class="modal-dialog modal-xl modal-fullscreen-sm-down modal-dialog-centered booking-form-dialog">
        <div class="modal-content border-0 shadow-lg rounded-4 booking-form-content">
            <form method="POST" action="bus_flight_bookings.php">
                <?php echo csrf_input(); ?>
                <input type="hidden" name="source_type" value="bus_flight_bookings">
                <div class="modal-header bg-primary text-white border-0 py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إضافة حجز جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4 bg-light booking-form-body">
                    <div class="row g-3">
                        <!-- Passenger and Branch Details -->
                        <div class="col-12">
                            <h5 class="text-primary fw-bold mb-3"><i class="fas fa-user me-2"></i> بيانات المسافر والفرع</h5>
                        </div>

                        <div class="col-xl-2 col-lg-3 col-md-3">
                            <label for="add_traveler_name" class="form-label fw-bold text-primary mb-2">اسم المسافر <span class="text-danger">*</span></label>
                            <input type="text" class="form-control rounded-3 shadow-sm border-2" id="add_traveler_name" name="traveler_name" oninput="updateDescription()" required>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-3">
                            <label for="add_mobile_number" class="form-label fw-bold text-primary mb-2">رقم الجوال <span class="text-danger">*</span></label>
                            <input type="text" class="form-control rounded-3 shadow-sm border-2" id="add_mobile_number" name="mobile_number" required>
                        </div>
                        <div class="col-xl-1 col-lg-2 col-md-2">
                            <label for="add_gender" class="form-label fw-bold text-primary mb-2">الجنس <span class="text-danger">*</span></label>
                            <select class="form-select rounded-3 shadow-sm border-2" id="add_gender" name="gender" required>
                                <option value="male">ذكر</option>
                                <option value="female">أنثى</option>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-2">
                            <label for="add_date_of_birth" class="form-label fw-bold text-primary mb-2">تاريخ الميلاد</label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_date_of_birth" name="date_of_birth">
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-2">
                            <label for="add_place_of_birth" class="form-label fw-bold text-primary mb-2">مكان الميلاد</label>
                            <input type="text" class="form-control rounded-3 shadow-sm border-2" id="add_place_of_birth" name="place_of_birth">
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-3">
                            <label for="add_nationality_id" class="form-label fw-bold text-primary mb-2">الجنسية</label>
                            <select class="form-select select2-modal rounded-3 shadow-sm border-2" id="add_nationality_id" name="nationality_id">
                                <option value="">اختر الجنسية</option>
                                <?php foreach ($countries as $country): ?>
                                    <option value="<?php echo $country['id']; ?>"><?php echo htmlspecialchars($country['country_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-1 col-lg-2 col-md-2">
                            <label for="add_id_type" class="form-label fw-bold text-primary mb-2">نوع الهوية</label>
                            <select class="form-select rounded-3 shadow-sm border-2" id="add_id_type" name="id_type">
                                <option value="passport">جواز سفر</option>
                                <option value="national_id">بطاقة وطنية</option>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-3">
                            <label for="id_number" class="form-label fw-bold text-primary mb-2">رقم الهوية</label>
                            <input type="text" class="form-control rounded-3 shadow-sm border-2" id="id_number" name="id_number">
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-2">
                            <label for="add_id_issue_place" class="form-label fw-bold text-primary mb-2">مكان الإصدار</label>
                            <input type="text" class="form-control rounded-3 shadow-sm border-2" id="add_id_issue_place" name="id_issue_place">
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-2">
                            <label for="add_id_issue_date" class="form-label fw-bold text-primary mb-2">تاريخ الإصدار</label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_id_issue_date" name="id_issue_date">
                        </div>

                        <!-- Separator -->
                        <div class="col-12">
                            <hr class="my-4 border-primary opacity-25">
                        </div>

                        <!-- Booking Details -->
                        <div class="col-12 mt-4">
                            <h5 class="text-primary fw-bold mb-3"><i class="fas fa-ticket-alt me-2"></i> بيانات الحجز</h5>
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-2">
                            <label for="add_booking_date" class="form-label fw-bold text-primary mb-2">تاريخ الحجز <span class="text-danger">*</span></label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_booking_date" name="booking_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-2">
                            <label for="add_service_type" class="form-label fw-bold text-primary mb-2">نوع الخدمة <span class="text-danger">*</span></label>
                            <select class="form-select rounded-3 shadow-sm border-2" id="add_service_type" name="service_type" onchange="updateDescription()" required>
                                <option value="">اختر النوع</option>
                                <option value="bus">باص</option>
                                <option value="flight">طيران</option>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-2">
                            <label for="add_trip_type" class="form-label fw-bold text-primary mb-2">نوع الرحلة <span class="text-danger">*</span></label>
                            <select class="form-select rounded-3 shadow-sm border-2" id="add_trip_type" name="trip_type" required>
                                <option value="one_way">ذهاب فقط</option>
                                <option value="round_trip">ذهاب وعودة</option>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-2">
                            <label for="add_from_city_id" class="form-label fw-bold text-primary mb-2">من مدينة <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal rounded-3 shadow-sm border-2" id="add_from_city_id" name="from_city_id" onchange="updateDescription()" required>
                                <option value="">اختر</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?php echo $city['id']; ?>"><?php echo htmlspecialchars($city['city_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-2">
                            <label for="add_to_city_id" class="form-label fw-bold text-primary mb-2">إلى مدينة <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal rounded-3 shadow-sm border-2" id="add_to_city_id" name="to_city_id" onchange="updateDescription()" required>
                                <option value="">اختر</option>
                                <?php foreach ($cities as $city): ?>
                                    <option value="<?php echo $city['id']; ?>"><?php echo htmlspecialchars($city['city_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-2 col-md-2">
                            <label for="add_departure_date" class="form-label fw-bold text-primary mb-2">تاريخ المغادرة <span class="text-danger">*</span></label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_departure_date" name="departure_date" required>
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-4" id="add_return_date_field" style="display: none;">
                            <label for="add_return_date" class="form-label fw-bold text-primary mb-2">تاريخ العودة</label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_return_date" name="return_date">
                        </div>
                        <div class="col-xl-3 col-lg-4 col-md-4" id="add_bus_type_field" style="display: none;">
                            <label for="add_bus_type" class="form-label fw-bold text-primary mb-2">نوع الباص</label>
                            <select class="form-select rounded-3 shadow-sm border-2" id="add_bus_type" name="bus_type">
                                <option value="">اختر</option>
                                <option value="tourist">سياحي</option>
                                <option value="regular">عادي</option>
                            </select>
                        </div>


                        <!-- Separator -->
                        <div class="col-12">
                            <hr class="my-4 border-primary opacity-25">
                        </div>

                        <!-- Financial Details - Unified Invoice Style -->
                        <div class="col-12 mt-4">
                            <h5 class="text-primary fw-bold mb-3"><i class="fas fa-dollar-sign me-2"></i> البيانات المالية</h5>
                        </div>

                        <!-- Additional fields specific to bus/flight bookings -->
                        <div class="col-xl-3 col-lg-3 col-md-3">
                            <label for="add_supplier_id" class="form-label fw-bold text-primary mb-2">المورد <span class="text-danger">*</span></label>
                            <select class="form-select select2-modal rounded-3 shadow-sm border-2" id="add_supplier_id" name="supplier_id" required>
                                <option value="">اختر المورد</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?php echo $supplier['id']; ?>"><?php echo htmlspecialchars($supplier['supplier_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-xl-2 col-lg-3 col-md-3">
                            <label for="add_operation_date" class="form-label fw-bold text-primary mb-2">تاريخ العملية <span class="text-danger">*</span></label>
                            <input type="date" class="form-control rounded-3 shadow-sm border-2" id="add_operation_date" name="operation_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-xl-4 col-lg-4 col-md-4 mt-3">
                            <label for="notes" class="form-label fw-bold text-primary mb-2">ملاحظات إضافية</label>
                            <textarea class="form-control rounded-3 shadow-sm border-2" id="notes" name="notes" rows="2"></textarea>
                        </div>
                        
                        <?php
                        // إعداد بيانات الفاتورة الحالية
                        $current_invoice = [
                            'invoice_date' => date('Y-m-d'),
                            'branch_id' => $currentUser['branch_id'] ?? null,
                            'source_type' => 'تذاكر طيران وبصات',
                            'delivery_type' => 'cash',
                            'total_amount' => 0,
                            'discount' => 0,
                            'cost_amount' => 0,
                            'amount_received' => 0,
                            'currency_id' => 1,
                            'description' => ''
                        ];
                        $financial_fields_select2_parent = '#addBookingModal';
                        $financial_fields_show_service_select = false;
                        include '../includes/financial_fields.php';
                        ?>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0 pt-3 booking-form-footer">
                    <button type="button" class="btn btn-secondary btn-lg rounded-pill px-5 py-2" data-bs-dismiss="modal"><i class="fas fa-times me-2"></i>إلغاء</button>
                    <button type="submit" name="add_new_booking" class="btn btn-primary btn-lg rounded-pill px-5 py-2"><i class="fas fa-save me-2"></i>حفظ الحجز</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Booking-specific Modals -->
<?php foreach ($bookings as $booking):
    // إعادة حساب الانتقالات المسموحة لهذا الحجز في حلقة المودالات
    $bid = $booking['branch_id'] ?: 0;
    $workflow = $workflows_cache[$bid];
    $allowed_transitions = [];
    if ($workflow) {
        $stmt_curr = $pdo->prepare("SELECT id FROM workflow_steps WHERE workflow_id = ? AND status_id = ? LIMIT 1");
        $stmt_curr->execute([$workflow['id'], $booking['status_id']]);
        $current_step_id = $stmt_curr->fetchColumn();
        if ($current_step_id) {
            $allowed_transitions = get_allowed_transitions($workflow['id'], $current_step_id, $_SESSION['role_id'] ?? null, $_SESSION['user_id'] ?? null);
        }
    }
?>
    <!-- 1. Edit Booking Modal -->
    <div class="modal fade" id="editBookingModal<?php echo $booking['id']; ?>">
        <div class="modal-dialog modal-xl modal-fullscreen-sm-down modal-dialog-centered booking-form-dialog">
            <div class="modal-content border-0 shadow-lg rounded-4 booking-form-content">
                <form method="POST" action="bus_flight_bookings.php">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                    <div class="modal-header bg-primary text-white border-0 py-3">
                        <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل بيانات الحجز</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light text-start booking-form-body">
                        <div class="row g-3">
                            <!-- Passenger and Branch Details -->
                            <div class="col-12">
                                <h5 class="text-primary fw-bold mb-3"><i class="fas fa-user me-2"></i> بيانات المسافر والفرع</h5>
                            </div>

                            <div class="col-xl-2 col-lg-3 col-md-3">
                                <label class="form-label fw-bold text-primary mb-2 small">اسم المسافر <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3 shadow-sm border-2 traveler-name-edit" name="traveler_name" value="<?php echo htmlspecialchars($booking['traveler_name']); ?>" required>
                            </div>

                            <div class="col-xl-2 col-lg-3 col-md-3">
                                <label class="form-label fw-bold text-primary mb-2 small">رقم الجوال <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3 shadow-sm border-2" name="mobile_number" value="<?php echo htmlspecialchars($booking['mobile_number']); ?>" required>
                            </div>

                            <div class="col-xl-1 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">الجنس <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3 shadow-sm border-2" name="gender" required>
                                    <option value="male" <?= $booking['gender'] == 'male' ? 'selected' : '' ?>>ذكر</option>
                                    <option value="female" <?= $booking['gender'] == 'female' ? 'selected' : '' ?>>أنثى</option>
                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">تاريخ الميلاد</label>
                                <input type="date" class="form-control rounded-3 shadow-sm border-2" name="date_of_birth" value="<?php echo $booking['date_of_birth']; ?>">
                            </div>

                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">مكان الميلاد</label>
                                <input type="text" class="form-control rounded-3 shadow-sm border-2" name="place_of_birth" value="<?php echo htmlspecialchars($booking['place_of_birth'] ?? ''); ?>">
                            </div>

                            <div class="col-xl-2 col-lg-3 col-md-3">
                                <label class="form-label fw-bold text-primary mb-2 small">الجنسية</label>
                                <select class="form-select select2-modal rounded-3 shadow-sm border-2" name="nationality_id">
                                    <option value="">اختر</option>
                                    <?php foreach ($countries as $country): ?>
                                        <option value="<?php echo $country['id']; ?>" <?= $booking['nationality_id'] == $country['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($country['country_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-xl-1 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">نوع الهوية</label>
                                <select class="form-select rounded-3 shadow-sm border-2" name="id_type">
                                    <option value="passport" <?= $booking['id_type'] == 'passport' ? 'selected' : '' ?>>جواز سفر</option>
                                    <option value="national_id" <?= $booking['id_type'] == 'national_id' ? 'selected' : '' ?>>بطاقة وطنية</option>
                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-3 col-md-3">
                                <label class="form-label fw-bold text-primary mb-2 small">رقم الهوية</label>
                                <input type="text" class="form-control rounded-3 shadow-sm border-2" name="id_number" value="<?php echo htmlspecialchars($booking['id_number'] ?? ''); ?>">
                            </div>

                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">مكان إصدار الهوية</label>
                                <input type="text" class="form-control rounded-3 shadow-sm border-2" name="id_issue_place" value="<?php echo htmlspecialchars($booking['id_issue_place'] ?? ''); ?>">
                            </div>

                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">تاريخ الإصدار</label>
                                <input type="date" class="form-control rounded-3 shadow-sm border-2" name="id_issue_date" value="<?php echo $booking['id_issue_date'] ?? ''; ?>">
                            </div>

                            <!-- Separator -->
                            <div class="col-12">
                                <hr class="my-4 border-primary opacity-25">
                            </div>

                            <!-- Booking Details -->
                            <div class="col-12 mt-4">
                                <h5 class="text-primary fw-bold mb-3"><i class="fas fa-ticket-alt me-2"></i> بيانات الحجز</h5>
                            </div>

                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">تاريخ الحجز <span class="text-danger">*</span></label>
                                <input type="date" class="form-control rounded-3 shadow-sm border-2" name="booking_date" value="<?php echo $booking['booking_date']; ?>" required>
                            </div>

                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">نوع الخدمة <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3 shadow-sm border-2 service-type-edit" name="service_type" onchange="const modal = this.closest('.modal-content'); const busTypeField = modal.querySelector('.bus-type-edit-field'); if(this.value === 'bus') busTypeField.style.display = 'block'; else busTypeField.style.display = 'none';" required>
                                    <option value="bus" <?= $booking['service_type'] == 'bus' ? 'selected' : '' ?>>باص</option>
                                    <option value="flight" <?= $booking['service_type'] == 'flight' ? 'selected' : '' ?>>طيران</option>
                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">نوع الرحلة <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3 shadow-sm border-2 trip-type-edit" name="trip_type" onchange="const modal = this.closest('.modal-content'); const returnField = modal.querySelector('.return-date-edit-field'); if(this.value === 'round_trip') returnField.style.display = 'block'; else returnField.style.display = 'none';" required>
                                    <option value="one_way" <?= ($booking['trip_type'] ?? 'one_way') == 'one_way' ? 'selected' : '' ?>>ذهاب فقط</option>
                                    <option value="round_trip" <?= ($booking['trip_type'] ?? '') == 'round_trip' ? 'selected' : '' ?>>ذهاب وعودة</option>
                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">من مدينة <span class="text-danger">*</span></label>
                                <select class="form-select select2-modal rounded-3 shadow-sm border-2 from-city-edit" name="from_city_id" required>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?php echo $city['id']; ?>" <?= $booking['from_city_id'] == $city['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($city['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">إلى مدينة <span class="text-danger">*</span></label>
                                <select class="form-select select2-modal rounded-3 shadow-sm border-2 to-city-edit" name="to_city_id" required>
                                    <?php foreach ($cities as $city): ?>
                                        <option value="<?php echo $city['id']; ?>" <?= $booking['to_city_id'] == $city['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($city['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-xl-2 col-lg-2 col-md-2">
                                <label class="form-label fw-bold text-primary mb-2 small">تاريخ المغادرة <span class="text-danger">*</span></label>
                                <input type="date" class="form-control rounded-3 shadow-sm border-2" name="departure_date" value="<?php echo $booking['departure_date']; ?>" required>
                            </div>

                            <div class="col-xl-3 col-lg-4 col-md-4 return-date-edit-field" style="<?= ($booking['trip_type'] ?? '') == 'round_trip' ? 'display: block;' : 'display: none;' ?>">
                                <label class="form-label fw-bold text-primary mb-2 small">تاريخ العودة</label>
                                <input type="date" class="form-control rounded-3 shadow-sm border-2" name="return_date" value="<?php echo $booking['return_date'] ?? ''; ?>">
                            </div>

                            <div class="col-xl-3 col-lg-4 col-md-4 bus-type-edit-field" style="<?= $booking['service_type'] == 'bus' ? 'display: block;' : 'display: none;' ?>">
                                <label class="form-label fw-bold text-primary mb-2 small">نوع الباص</label>
                                <select class="form-select rounded-3 shadow-sm border-2" name="bus_type">
                                    <option value="">اختر النوع</option>
                                    <option value="tourist" <?= $booking['bus_type'] == 'tourist' ? 'selected' : '' ?>>سياحي (Tourist)</option>
                                    <option value="regular" <?= $booking['bus_type'] == 'regular' ? 'selected' : '' ?>>عادي (Regular)</option>
                                </select>
                            </div>

                            <!-- Separator -->
                            <div class="col-12">
                                <hr class="my-4 border-primary opacity-25">
                            </div>

                            <!-- Financial Details -->
                            <?php
                            // جلب حالة الفاتورتين لتحديد إمكانية التعديل
                            $inv_status_edit = 'draft';
                            $pur_inv_edit = null;
                            $si = null;
                            if ($booking['invoice_id']) {
                                $stmt_inv_st = $pdo->prepare("SELECT invoice_status, currency_id, discount, invoice_date AS transaction_date FROM invoices WHERE id = ?");
                                $stmt_inv_st->execute([$booking['invoice_id']]);
                                $si = $stmt_inv_st->fetch();
                                $inv_status_edit = $si['invoice_status'] ?? 'posted';
                                // فاتورة الشراء
                                $stmt_pur_edit = $pdo->prepare("SELECT id, invoice_status, total_amount, currency_id FROM invoices WHERE source_type = 'تذاكر طيران وبصات' AND source_id = ? AND invoice_category = 'purchase' LIMIT 1");
                                $stmt_pur_edit->execute([$booking['id']]);
                                $pur_inv_edit = $stmt_pur_edit->fetch();
                            }
                            $can_edit_financials = ($inv_status_edit === 'draft');
                            ?>

                            <div class="col-12 mt-4">
                                <h5 class="text-primary fw-bold mb-3">
                                    <i class="fas fa-dollar-sign me-2"></i> البيانات المالية
                                    <?php if ($can_edit_financials): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle ms-2 small">قابلة للتعديل</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-2 small"><i class="fas fa-lock me-1"></i>مؤمنة (الفاتورة مرحّلة)</span>
                                    <?php endif; ?>
                                </h5>
                            </div>

                            <?php if ($can_edit_financials): ?>
                                <?php
                                $sale_inv_currency_id = $si['currency_id'] ?? $booking['currency_id'];
                                $pur_inv_currency_id  = $pur_inv_edit['currency_id'] ?? $booking['currency_id'];
                                ?>
                                <!-- صف 1: المورد وبيانات الشراء -->
                                <div class="col-xl-3 col-lg-3 col-md-3">
                                    <label class="form-label fw-bold text-primary mb-2 small">المورد <span class="text-danger">*</span></label>
                                    <select class="form-select select2-modal rounded-3 shadow-sm border-2" name="supplier_id" required>
                                        <option value="">اختر المورد</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?php echo $supplier['id']; ?>" <?= $booking['supplier_id'] == $supplier['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($supplier['supplier_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xl-2 col-lg-3 col-md-3">
                                    <label class="form-label fw-bold text-primary mb-2 small">عملة الشراء <span class="text-danger">*</span></label>
                                    <select class="form-select select2-modal rounded-3 shadow-sm border-2 edit-booking-currency" name="currency_id"
                                        data-booking="<?= $booking['id'] ?>" onchange="updateBookingExchangeRate('edit_<?= $booking['id'] ?>')" required>
                                        <?php foreach ($currencies as $cur): ?>
                                            <option value="<?= $cur['id'] ?>" <?= $pur_inv_currency_id == $cur['id'] ? 'selected' : '' ?>
                                                data-buy="<?= $cur['exchange_rate_buy'] ?? 1 ?>"
                                                data-sell="<?= $cur['exchange_rate_sell'] ?? 1 ?>">
                                                <?= htmlspecialchars($cur['currency_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xl-2 col-lg-3 col-md-3">
                                    <label class="form-label fw-bold text-primary mb-2 small">سعر الشراء <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" class="form-control rounded-3 shadow-sm border-2 edit-price-input" name="purchase_price" value="<?php echo $booking['purchase_price']; ?>" required>
                                </div>
                                <div class="col-xl-2 col-lg-3 col-md-3">
                                    <label class="form-label fw-bold text-primary mb-2 small">تاريخ العملية <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control rounded-3 shadow-sm border-2" name="operation_date" value="<?= $si['transaction_date'] ?? date('Y-m-d') ?>" required>
                                </div>

                                <!-- سعر الصرف لنموذج التعديل -->
                                <div class="col-12 d-none" id="edit_exrate_row_<?= $booking['id'] ?>">
                                    <div class="p-2 bg-warning bg-opacity-10 rounded-3 border border-warning border-opacity-25 mb-2">
                                        <div class="row g-2 align-items-end">
                                            <div class="col-xl-4 col-lg-4 col-md-4">
                                                <label class="form-label small fw-bold text-warning mb-1">سعر الصرف</label>
                                                <div class="input-group input-group-sm">
                                                    <span class="input-group-text edit-pur-sym-<?= $booking['id'] ?>">ش</span>
                                                    <input type="number" step="0.000001" class="form-control" id="edit_exrate_<?= $booking['id'] ?>" name="exchange_rate" value="1.000000" oninput="calcEditEquivalent(<?= $booking['id'] ?>)">
                                                    <span class="input-group-text edit-sale-sym-<?= $booking['id'] ?>">ب</span>
                                                </div>
                                            </div>
                                            <div class="col-xl-4 col-lg-4 col-md-4">
                                                <label class="form-label small text-muted mb-1">التكلفة المعادلة بعملة البيع</label>
                                                <input type="text" class="form-control form-control-sm bg-light" id="edit_equiv_<?= $booking['id'] ?>" readonly>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- صف 2: نوع التوصيل والحساب وبيانات البيع -->
                                <div class="col-xl-2 col-lg-2 col-md-2">
                                    <label class="form-label fw-bold text-primary mb-2 small">نوع التوصيل <span class="text-danger">*</span></label>
                                    <select class="form-select rounded-3 shadow-sm border-2" name="delivery_type" required>
                                        <option value="draft" <?= $booking['delivery_type'] == 'draft' ? 'selected' : '' ?>>📝 مسودة</option>
                                        <option value="cash" <?= $booking['delivery_type'] == 'cash' ? 'selected' : '' ?>>💵 نقد</option>
                                        <option value="credit" <?= $booking['delivery_type'] == 'credit' ? 'selected' : '' ?>>📅 آجل</option>
                                        <option value="bank_transfer" <?= $booking['delivery_type'] == 'bank_transfer' ? 'selected' : '' ?>>🏦 تحويل بنكي</option>
                                        <option value="agent" <?= $booking['delivery_type'] == 'agent' ? 'selected' : '' ?>>👤 وكيل</option>
                                    </select>
                                </div>
                                <div class="col-xl-2 col-lg-3 col-md-3">
                                    <label class="form-label fw-bold text-primary mb-2 small" id="edit_account_label_<?= $booking['id'] ?>">الحساب المتأثر</label>
                                    <select class="form-select select2-modal rounded-3 shadow-sm border-2" id="edit_account_id_<?= $booking['id'] ?>" name="account_id" data-current-id="<?= $booking['account_id'] ?>" required <?= ($booking['delivery_type'] == 'draft' || empty($booking['delivery_type'])) ? 'disabled' : '' ?>>
                                        <option value="">-- اختر النوع --</option>
                                    </select>
                                    <!-- منطقة عرض الرصيد والحد الائتماني (تعديل) -->
                                    <div id="edit_account_balance_info_<?= $booking['id'] ?>" class="mt-2 p-2 rounded-3 bg-white border shadow-sm d-none" style="font-size: 0.8rem;">
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <span class="text-muted"><i class="fas fa-wallet me-1"></i> صافي الرصيد الموحد:</span>
                                            <span id="edit_unified_balance_display_<?= $booking['id'] ?>" class="fw-bold"></span>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="text-muted"><i class="fas fa-shield-alt me-1"></i> الحد الائتماني:</span>
                                            <span id="edit_unified_limit_display_<?= $booking['id'] ?>" class="fw-bold text-danger"></span>
                                        </div>
                                    </div>
                                    <input type="hidden" name="customer_id" id="edit_<?= $booking['id'] ?>_customer_id_hidden" value="<?= $booking['customer_id'] ?>">
                                    <input type="hidden" name="agent_id" id="edit_<?= $booking['id'] ?>_agent_id_hidden" value="<?= $booking['agent_id'] ?>">
                                </div>
                                <div class="col-xl-2 col-lg-2 col-md-2">
                                    <label class="form-label fw-bold text-primary mb-2 small">العملة <span class="text-danger">*</span></label>
                                    <select class="form-select select2-modal rounded-3 shadow-sm border-2 edit-booking-currency" name="sale_currency_id"
                                        data-booking="<?= $booking['id'] ?>" onchange="updateBookingExchangeRate('edit_<?= $booking['id'] ?>')" required>
                                        <?php foreach ($currencies as $cur): ?>
                                            <option value="<?= $cur['id'] ?>" <?= $sale_inv_currency_id == $cur['id'] ? 'selected' : '' ?>
                                                data-buy="<?= $cur['exchange_rate_buy'] ?? 1 ?>"
                                                data-sell="<?= $cur['exchange_rate_sell'] ?? 1 ?>">
                                                <?= htmlspecialchars($cur['currency_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xl-2 col-lg-2 col-md-2">
                                    <label class="form-label fw-bold text-primary mb-2 small">سعر البيع <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" class="form-control rounded-3 shadow-sm border-2 edit-price-input" name="sale_price" value="<?php echo $booking['sale_price']; ?>" required>
                                </div>
                                <div class="col-xl-2 col-lg-2 col-md-2" id="edit_amount_received_field_<?= $booking['id'] ?>" style="display: none;">
                                    <label class="form-label fw-bold text-primary mb-2 small">المبلغ الواصل <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" class="form-control rounded-3 shadow-sm border-2 edit-price-input" name="amount_received" value="<?php echo $booking['amount_received']; ?>">
                                </div>
                                <div class="col-xl-1 col-lg-1 col-md-1">
                                    <label class="form-label fw-bold text-primary mb-2 small">الخصم</label>
                                    <input type="number" step="0.01" class="form-control rounded-3 shadow-sm border-2" name="discount" value="<?= $si['discount'] ?? 0 ?>" placeholder="0.00">
                                </div>
                            <?php else: ?>
                                <!-- الفاتورة مرحّلة - عرض فقط -->
                                <div class="col-12">
                                    <div class="p-3 bg-white rounded-4 border shadow-sm">
                                        <div class="row text-center g-3">
                                            <div class="col-md-3">
                                                <div class="small text-muted mb-1">سعر البيع</div>
                                                <div class="h5 fw-bold mb-0"><?= number_format($booking['sale_price'], 2) ?> <small><?= htmlspecialchars($booking['currency_name']) ?></small></div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="small text-muted mb-1">التكلفة</div>
                                                <div class="h5 fw-bold text-warning mb-0"><?= number_format($booking['purchase_price'], 2) ?></div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="small text-muted mb-1">الربح</div>
                                                <div class="h5 fw-bold text-success mb-0"><?= number_format($booking['profit'], 2) ?></div>
                                            </div>
                                            <div class="col-md-3">
                                                <div class="small text-muted mb-1">الحالة</div>
                                                <div><span class="badge bg-posted rounded-pill">مرحّلة</span></div>
                                            </div>
                                        </div>
                                        <div class="text-center mt-3 pt-3 border-top">
                                            <a href="invoice_details.php?id=<?= $booking['invoice_id'] ?>" target="_blank" class="btn btn-sm btn-primary rounded-pill px-4 me-2"><i class="fas fa-external-link-alt me-1"></i>تفاصيل الفاتورة</a>
                                            <a href="invoices.php?q=<?= urlencode($booking['booking_number']) ?>" target="_blank" class="btn btn-sm btn-outline-info rounded-pill px-4"><i class="fas fa-search me-1"></i>تتبع الفواتير</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Row 3: البيان والملاحظات والفرع (تعديل) -->
                            <div class="col-md-4 mt-4">
                                <label class="form-label fw-bold text-primary mb-2 small">البيان التلقائي</label>
                                <textarea class="form-control rounded-3 shadow-sm border-2 description-edit bg-light" name="description" rows="2" readonly><?php echo htmlspecialchars($booking['description']); ?></textarea>
                            </div>

                            <div class="col-md-4 mt-4">
                                <label class="form-label fw-bold text-primary mb-2 small">ملاحظات إضافية</label>
                                <textarea class="form-control rounded-3 shadow-sm border-2" name="notes" rows="2"><?php echo htmlspecialchars($booking['notes']); ?></textarea>
                            </div>

                            <div class="col-md-4 mt-4">
                                <label class="form-label fw-bold text-primary mb-2 small">الفرع المسؤول</label>
                                <select class="form-select select2-modal rounded-3 shadow-sm border-2" name="branch_id" required>
                                    <?php foreach ($branches as $branch): ?>
                                        <option value="<?php echo $branch['id']; ?>" <?= $booking['branch_id'] == $branch['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($branch['branch_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer bg-light border-0 booking-form-footer">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" name="update_booking" class="btn btn-primary rounded-pill px-5 fw-bold shadow">حفظ التعديلات</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 2. Workflow Modals -->
    <?php if (!empty($allowed_transitions)):
        $all_fields_info = get_all_workflow_fields();
        foreach ($allowed_transitions as $trans):
            $step_fields = get_step_fields($trans['to_step_id']);
    ?>
            <div class="modal fade" id="workflowModal_<?= $booking['id'] ?>_<?= $trans['to_step_id'] ?>">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content border-0 shadow-lg rounded-4">
                        <form method="POST">
                            <?php echo csrf_input(); ?>
                            <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                            <input type="hidden" name="to_status_id" value="<?= (int)$pdo->query("SELECT status_id FROM workflow_steps WHERE id = " . $trans['to_step_id'])->fetchColumn() ?>">
                            <input type="hidden" name="transition_id" value="<?= $trans['transition_id'] ?>">
                            <div class="modal-header bg-<?= $trans['color'] ?: 'primary' ?> text-white border-0 py-3">
                                <h6 class="modal-title fw-bold">نقل الحجز رقم <?= $booking['booking_number'] ?> إلى: <?= htmlspecialchars($trans['to_step_name']) ?></h6>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4 text-start">
                                <p class="text-muted small mb-3">هل أنت متأكد من رغبتك في نقل الحجز إلى مرحلة "<?= htmlspecialchars($trans['to_step_name']) ?>"؟</p>

                                <?php if (!empty($step_fields)): ?>
                                    <div class="row g-3 mb-3">
                                        <?php foreach ($step_fields as $fkey):
                                            if (!isset($all_fields_info[$fkey])) continue;
                                            $ftype = 'text';
                                            $fvalue = '';
                                            if (strpos($fkey, 'date') !== false || strpos($fkey, 'datetime') !== false) {
                                                $ftype = (strpos($fkey, 'datetime') !== false) ? 'datetime-local' : 'date';
                                                if (in_array($fkey, ['confirm_datetime', 'mod_datetime', 'cancel_datetime'])) $fvalue = date('Y-m-d\TH:i');
                                            }
                                            if (strpos($fkey, 'amount') !== false || strpos($fkey, 'price') !== false) $ftype = 'number';
                                        ?>
                                            <div class="col-md-12">
                                                <label class="form-label small fw-bold"><?= $all_fields_info[$fkey] ?></label>
                                                <?php if ($fkey == 'is_cancelled'): ?>
                                                    <select name="extra_fields[<?= $fkey ?>]" class="form-select rounded-3">
                                                        <option value="0">لا</option>
                                                        <option value="1">نعم</option>
                                                    </select>
                                                <?php elseif (strpos($fkey, 'reason') !== false || $fkey == 'notes'): ?>
                                                    <textarea name="extra_fields[<?= $fkey ?>]" class="form-control rounded-3" rows="2"></textarea>
                                                <?php else: ?>
                                                    <input type="<?= $ftype ?>" name="extra_fields[<?= $fkey ?>]" class="form-control rounded-3" step="0.01" value="<?= $fvalue ?>">
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="mb-0">
                                    <label class="form-label small fw-bold">ملاحظات التحويل</label>
                                    <textarea class="form-control rounded-3" name="workflow_notes" rows="3" placeholder="أدخل أي ملاحظات اختيارية هنا..."></textarea>
                                </div>
                            </div>
                            <div class="modal-footer bg-light border-0">
                                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                                <button type="submit" name="change_workflow_status" class="btn btn-<?= $trans['color'] ?: 'primary' ?> rounded-pill px-4">تأكيد النقل</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
    <?php endforeach;
    endif; ?>

    <!-- 3. Cancel Request Modal -->
    <div class="modal fade" id="requestCancelModal<?php echo $booking['id']; ?>">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                    <input type="hidden" name="to_status_id" value="<?= (int)$pdo->query("SELECT id FROM statuses WHERE status_name = 'تم إلغاء الحجز' LIMIT 1")->fetchColumn() ?>">
                    <div class="modal-header bg-danger text-white border-0 py-3">
                        <h5 class="modal-title fw-bold"><i class="fas fa-times-circle me-2"></i> طلب إلغاء الحجز</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light text-start">
                        <div class="alert alert-warning small rounded-3 border-0 shadow-sm mb-3">
                            <i class="fas fa-info-circle me-2"></i> سيتم إرسال هذا الطلب للمدير للموافقة عليه. عند الموافقة سيتم خصم الغرامة المحددة وإرجاع الباقي من الصندوق.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">إجمالي مبلغ الحجز</label>
                                <input type="text" class="form-control rounded-3 bg-white fw-bold" value="<?= number_format($booking['sale_price'], 2) ?> <?= htmlspecialchars($booking['currency_name']) ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">المبلغ المخصوم (الغرامة)</label>
                                <input type="number" step="0.01" name="discount_amount" class="form-control rounded-3 fw-bold border-danger discount-input" value="0" required oninput="calculateNetAmount(this, <?= $booking['sale_price'] ?>)">
                            </div>
                            <div class="col-md-12 text-center">
                                <div class="p-2 bg-white rounded-3 border">
                                    <span class="small text-muted d-block">المبلغ الصافي بعد الخصم (الذي سيتم استرداده)</span>
                                    <span class="h5 fw-bold text-success mb-0 net-amount-display"><?= number_format($booking['amount_received'], 2) ?></span>
                                    <small class="text-muted"><?= htmlspecialchars($booking['currency_name']) ?></small>
                                </div>
                                <div class="mt-1 small text-muted">المبلغ المدفوع حالياً: <?= number_format($booking['amount_received'], 2) ?></div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">سبب الإلغاء / ملاحظات</label>
                                <textarea name="notes" class="form-control rounded-3" rows="3" placeholder="أدخل سبب الإلغاء هنا..." required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" name="request_approval" class="btn btn-danger rounded-pill px-5 fw-bold shadow">إرسال طلب الإلغاء</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- 4. Modification Request Modal -->
    <div class="modal fade" id="requestModModal<?php echo $booking['id']; ?>">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4">
                <form method="POST">
                    <?php echo csrf_input(); ?>
                    <input type="hidden" name="booking_id" value="<?= $booking['id'] ?>">
                    <input type="hidden" name="to_status_id" value="<?= (int)$pdo->query("SELECT id FROM statuses WHERE status_name = 'تم تعديل الحجز' LIMIT 1")->fetchColumn() ?>">
                    <div class="modal-header bg-warning text-dark border-0 py-3">
                        <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> طلب تعديل الحجز</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4 bg-light text-start">
                        <div class="alert alert-info small rounded-3 border-0 shadow-sm mb-3">
                            <i class="fas fa-info-circle me-2"></i> سيتم إرسال طلب التعديل للمدير للموافقة عليه.
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">إجمالي مبلغ الحجز</label>
                                <input type="text" class="form-control rounded-3 bg-white fw-bold" value="<?= number_format($booking['sale_price'], 2) ?> <?= htmlspecialchars($booking['currency_name']) ?>" readonly>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">المبلغ المخصوم (غرامة التعديل)</label>
                                <input type="number" step="0.01" name="discount_amount" class="form-control rounded-3 fw-bold border-warning discount-input" value="0" oninput="calculateNetAmount(this, <?= $booking['sale_price'] ?>)">
                            </div>
                            <div class="col-md-12 text-center">
                                <div class="p-2 bg-white rounded-3 border">
                                    <span class="small text-muted d-block">المبلغ الصافي بعد الخصم</span>
                                    <span class="h5 fw-bold text-primary mb-0 net-amount-display"><?= number_format($booking['sale_price'], 2) ?></span>
                                    <small class="text-muted"><?= htmlspecialchars($booking['currency_name']) ?></small>
                                </div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label small fw-bold">تفاصيل التعديل المطلوب / ملاحظات</label>
                                <textarea name="notes" class="form-control rounded-3" rows="3" placeholder="أدخل تفاصيل التعديل المطلوبة هنا..." required></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light border-0">
                        <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                        <button type="submit" name="request_approval" class="btn btn-warning rounded-pill px-5 fw-bold shadow text-dark">إرسال طلب التعديل</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<script>
    /**
     * تحديث سعر الصرف والتكلفة المعادلة عند تغيير العملات
     */
    function updateBookingExchangeRate(prefix) {
        let isEdit = prefix.startsWith('edit_');
        let id = isEdit ? prefix.replace('edit_', '') : null;

        let saleSelId = isEdit ? `select[name="sale_currency_id"][data-booking="${id}"]` : '#add_sale_currency_id';
        let purSelId = isEdit ? `select[name="currency_id"][data-booking="${id}"]` : '#add_currency_id';

        let saleSel = $(saleSelId);
        let purSel = $(purSelId);

        let saleOpt = saleSel.find('option:selected');
        let purOpt = purSel.find('option:selected');

        let saleId = saleOpt.val();
        let purId = purOpt.val();

        let exRateRow = isEdit ? $(`#edit_exrate_row_${id}`) : $('#add_exchange_rate_row');
        let exRateInput = isEdit ? $(`#edit_exrate_${id}`) : $('#add_exchange_rate');

        if (saleId && purId && saleId !== purId) {
            let saleSym = saleOpt.text().trim();
            let purSym = purOpt.text().trim();

            if (isEdit) {
                $(`.edit-sale-sym-${id}`).text(saleSym);
                $(`.edit-pur-sym-${id}`).text(purSym);
            } else {
                $('.add-sale-sym').text(saleSym);
                $('.add-pur-sym').text(purSym);
            }

            let purBuy = parseFloat(purOpt.data('buy')) || 1;
            let saleSell = parseFloat(saleOpt.data('sell')) || 1;
            let rate = purBuy / saleSell;

            exRateInput.val(rate.toFixed(6));
            exRateRow.removeClass('d-none');
        } else {
            exRateInput.val('1.000000');
            exRateRow.addClass('d-none');
        }

        if (isEdit) {
            calcEditEquivalent(id);
        } else {
            calcAddEquivalent();
        }
    }

    function calcAddEquivalent() {
        let purPrice = parseFloat($('#add_purchase_price').val()) || 0;
        let rate = parseFloat($('#add_exchange_rate').val()) || 1;
        let saleSym = $('#add_sale_currency_id option:selected').text().trim() || '';

        let saleId = $('#add_sale_currency_id').val();
        let purId = $('#add_currency_id').val();

        let equiv = (saleId !== purId) ? (purPrice * rate) : purPrice;
        $('#add_equivalent_cost').val(equiv.toLocaleString(undefined, {
            minimumFractionDigits: 2
        }) + ' ' + saleSym);
    }

    function calcEditEquivalent(id) {
        let modal = $(`#editBookingModal${id}`);
        let purPrice = parseFloat(modal.find('input[name="purchase_price"]').val()) || 0;
        let rate = parseFloat($(`#edit_exrate_${id}`).val()) || 1;
        let saleSym = modal.find('select[name="sale_currency_id"] option:selected').text().trim() || '';

        let saleId = modal.find('select[name="sale_currency_id"]').val();
        let purId = modal.find('select[name="currency_id"]').val();

        let equiv = (saleId !== purId) ? (purPrice * rate) : purPrice;
        $(`#edit_equiv_${id}`).val(equiv.toLocaleString(undefined, {
            minimumFractionDigits: 2
        }) + ' ' + saleSym);
    }

    /**
     * تحديث البيان التلقائي في مودال الإضافة
     */
    function updateDescription() {
        const travelerName = $('#add_traveler_name').val() || '';
        const fromCity = $('#add_from_city_id option:selected').text();
        const toCity = $('#add_to_city_id option:selected').text();

        let description = 'حجز تذكرة';
        if (fromCity && fromCity !== 'اختر' && fromCity !== '') description += ' من ' + fromCity;
        if (toCity && toCity !== 'اختر' && toCity !== '') description += ' إلى ' + toCity;
        if (travelerName) description += ' للمسافر ' + travelerName;

        $('#add_description').val(description);
    }

    /**
     * تحديث البيان التلقائي في مودالات التعديل
     */
    function updateEditDescription(modal) {
        const $modal = $(modal);
        const travelerName = $modal.find('input[name="traveler_name"]').val() || '';
        const fromCity = $modal.find('select[name="from_city_id"] option:selected').text();
        const toCity = $modal.find('select[name="to_city_id"] option:selected').text();

        let description = 'حجز تذكرة';
        if (fromCity && fromCity !== 'اختر' && fromCity !== '') description += ' من ' + fromCity;
        if (toCity && toCity !== 'اختر' && toCity !== '') description += ' إلى ' + toCity;
        if (travelerName) description += ' للمسافر ' + travelerName;

        $modal.find('input[name="description"]').val(description);
    }

    /**
     * تحديث المبالغ بالحروف باستخدام المكتبة العالمية
     */
    function updatePriceInWords() {
        const currency = $('#add_currency_id option:selected').text() || "ريال";
        const fields = [
            { input: '#add_sale_price', display: '#sale_price_words' },
            { input: '#add_purchase_price', display: '#purchase_price_words' },
            { input: '#add_amount_received', display: '#amount_received_words' }
        ];

        fields.forEach(field => {
            const val = parseFloat($(field.input).val());
            if (val && val > 0) $(field.display).text(tafqeet(val, currency));
            else $(field.display).text("");
        });
    }

    /**
     * تحديث المبالغ بالحروف لنماذج التعديل
     */
    function updateEditPriceInWords(modal) {
        const $modal = $(modal);
        const currency = "ريال";
        const fields = [
            { input: 'input[name="sale_price"]', display: '.edit-sale-price-words' },
            { input: 'input[name="purchase_price"]', display: '.edit-purchase-price-words' },
            { input: 'input[name="amount_received"]', display: '.edit-amount-received-words' }
        ];

        fields.forEach(field => {
            const val = parseFloat($modal.find(field.input).val());
            if (val && val > 0) $modal.find(field.display).text(tafqeet(val, currency));
            else $modal.find(field.display).text("");
        });
    }

    /**
     * التحقق من صحة الأسعار والمدن والتواريخ
     */
    function validateFinancialsAndRoute() {
        const sale = parseFloat($('#add_sale_price').val()) || 0;
        const purchase = parseFloat($('#add_purchase_price').val()) || 0;
        const received = parseFloat($('#add_amount_received').val()) || 0;
        const fromCity = $('#add_from_city_id').val();
        const toCity = $('#add_to_city_id').val();
        const depDate = $('#add_departure_date').val();
        const today = new Date().toISOString().split('T')[0];

        if (purchase > sale && sale > 0) {
            $('#purchase_price_words').addClass('text-danger').removeClass('text-primary').html('<i class="fas fa-exclamation-triangle"></i> تنبيه: سعر الشراء أكبر!');
        } else {
            $('#purchase_price_words').removeClass('text-danger').addClass('text-primary');
        }

        if (received > sale && sale > 0) {
            $('#amount_received_words').addClass('text-danger').removeClass('text-primary').html('<i class="fas fa-exclamation-triangle"></i> تنبيه: الموصل أكبر!');
        } else {
            $('#amount_received_words').removeClass('text-danger').addClass('text-primary');
        }

        if (fromCity && toCity && fromCity === toCity) {
            alert('خطأ: مدينة المغادرة والوصول متطابقتان!');
            $('#add_to_city_id').val('').trigger('change');
        }

        if (depDate && depDate < today) {
            alert('خطأ: تاريخ المغادرة في الماضي!');
            $('#add_departure_date').val(today);
        }
    }

    /**
     * تحديث صافي المبلغ في مودالات الوورك فلو
     */
    function calculateNetAmount(input, total) {
        const discount = parseFloat(input.value) || 0;
        const net = Math.max(0, total - discount);
        const modal = input.closest('.modal');
        const netInput = modal.querySelector('input[name*="net_amount"]');
        if (netInput) netInput.value = net.toFixed(2);
    }

    $(document).ready(function() {
        console.log("Booking script loaded");

        // ربط أحداث الإدخال لتحديث التكلفة المعادلة مباشرة
        $('#add_purchase_price').on('input', calcAddEquivalent);
        $('.edit-price-input').on('input', function() {
            let id = $(this).closest('form').find('input[name="booking_id"]').val();
            calcEditEquivalent(id);
        });
        $('input[name="purchase_price"]').on('input', function() {
            let id = $(this).closest('form').find('input[name="booking_id"]').val();
            if (id) calcEditEquivalent(id);
        });

        // تهيئة Select2 للمودالات
        $('.select2-modal').each(function() {
            const $p = $(this).closest('.modal');
            $(this).select2({
                dropdownParent: $p,
                width: '100%'
            });
        });

        const entitiesData = {
            cashboxes: <?php echo json_encode($cashboxes_entities); ?>,
            customers: <?php echo json_encode($customers_entities); ?>,
            banks: <?php echo json_encode($banks_entities); ?>,
            agents: <?php echo json_encode($agents_entities); ?>,
            all_unified_accounts: <?php echo json_encode($all_unified_accounts); ?>
        };

        const baseSymbol = '<?php echo $base_currency_symbol; ?>';

        window.AUTO_INVOICE_GENERATION = <?php echo $auto_invoice_generation ? 'true' : 'false'; ?>;

        /**
         * منطق التعامل مع نوع التوصيل الموحد - مودال الإضافة
         */
        window.updatePaymentLogic = function() {
            const $paymentType = $('#add_delivery_type');
            if (!$paymentType.length) return;

            const val = $paymentType.val();
            const $accountField = $('#add_account_field');
            const $accountSelect = $('#add_account_id');
            const $amountReceived = $('#add_amount_received');
            const $amountReceivedField = $('#add_amount_received').closest('.col-md-2');

            if (!$accountSelect.length) return;

            // تصفير وإخفاء الحقول مبدئياً
            $accountSelect.empty().append('<option value="">اختر الحساب</option>');
            $accountSelect.prop('disabled', true);
            $accountField.addClass('d-none');
            $accountSelect.removeAttr('required');
            $('#account_balance_info').addClass('d-none');
            
            // إخفاء حقل المبلغ الواصل مبدئياً
            $amountReceivedField.hide();
            $amountReceived.removeAttr('required');

            // إعادة تعيين الحقول المخفية
            $('#customer_id_hidden').val('');
            $('#agent_id_hidden').val('');

            if (!val || val === '') {
                $amountReceived.prop('readonly', true).addClass('bg-light').val('');
                return;
            }

            let accounts = [];
            let label = 'الحساب المتأثر';

            if (val === 'cash') {
                accounts = <?php echo json_encode($cash_accounts); ?> || [];
                label = 'الحساب: الصناديق';
                $amountReceived.prop('readonly', false).removeClass('bg-light').attr('required', 'required');
                $amountReceivedField.show();
            } else if (val === 'bank_transfer') {
                accounts = <?php echo json_encode($bank_accounts); ?> || [];
                label = 'الحساب: البنوك';
                $amountReceived.prop('readonly', false).removeClass('bg-light');
                if (!$amountReceived.val()) $amountReceived.val('0');
                $amountReceivedField.show();
            } else if (val === 'credit') {
                accounts = <?php echo json_encode($customers_entities); ?> || [];
                label = 'الحساب: العملاء';
                $amountReceived.prop('readonly', true).addClass('bg-light').val('0');
            } else if (val === 'agent') {
                accounts = <?php echo json_encode($agents_entities); ?> || [];
                label = 'الحساب: الوكلاء';
                $amountReceived.prop('readonly', true).addClass('bg-light').val('0');
            } else if (val === 'draft') {
                $amountReceived.prop('readonly', true).addClass('bg-light').val('0');
                return;
            }

            // تحديث خيارات الحساب المالي
            $accountSelect.prop('disabled', false);
            $accountField.removeClass('d-none');
            $accountSelect.attr('required', 'required');
            if (accounts.length === 0) {
                $accountSelect.append('<option value="" disabled>لا يوجد حسابات متاحة</option>');
            } else {
                accounts.forEach(acc => {
                    const entityId = acc.id || '';
                    $accountSelect.append($('<option>', {
                        value: acc.account_id,
                        text: (acc.account_code ? acc.account_code + ' - ' : '') + acc.name,
                        'data-entity-id': entityId
                    }));
                });
            }
            $('#add_account_label').text(label);
            $accountSelect.select2({
                dropdownParent: $accountSelect.closest('.modal'),
                width: '100%'
            });
        };

        /**
         * منطق التعامل مع نوع التوصيل الموحد - مودال التعديل
         */
        window.updateEditPaymentLogic = function(modal) {
            const $modal = $(modal);
            const $paymentType = $modal.find('select[name="delivery_type"]');
            if (!$paymentType.length) return;

            const val = $paymentType.val();
            const bookingId = $modal.find('input[name="booking_id"]').val();
            const $accountSelect = $modal.find(`select[name="account_id"]`);
            const $amountReceived = $modal.find('input[name="amount_received"]');
            const $amountReceivedField = $modal.find(`#edit_amount_received_field_${bookingId}`);

            if (!$accountSelect.length) return;

            const currentAccId = $accountSelect.data('current-id');
            
            // تصفير وإخفاء الكل مبدئياً
            $accountSelect.empty().append('<option value="">اختر الحساب</option>');
            $accountSelect.prop('disabled', true);
            $amountReceivedField.hide();
            $amountReceived.removeAttr('required');
            $modal.find(`#edit_${bookingId}_customer_id_hidden`).val('');
            $modal.find(`#edit_${bookingId}_agent_id_hidden`).val('');

            if (!val || val === '') {
                $amountReceived.prop('readonly', true).addClass('bg-light');
                return;
            }

            let accounts = [];
            let label = 'الحساب المتأثر';

            if (val === 'cash') {
                accounts = <?php echo json_encode($cash_accounts); ?> || [];
                label = 'الحساب: الصناديق';
                $amountReceived.prop('readonly', false).removeClass('bg-light').attr('required', 'required');
                $amountReceivedField.show();
            } else if (val === 'bank_transfer') {
                accounts = <?php echo json_encode($bank_accounts); ?> || [];
                label = 'الحساب: البنوك';
                $amountReceived.prop('readonly', false).removeClass('bg-light');
                $amountReceivedField.show();
            } else if (val === 'credit') {
                accounts = <?php echo json_encode($customers_entities); ?> || [];
                label = 'الحساب: العملاء';
                $amountReceived.prop('readonly', true).addClass('bg-light');
            } else if (val === 'agent') {
                accounts = <?php echo json_encode($agents_entities); ?> || [];
                label = 'الحساب: الوكلاء';
                $amountReceived.prop('readonly', true).addClass('bg-light');
            } else if (val === 'draft') {
                $amountReceived.prop('readonly', true).addClass('bg-light');
                return;
            }

            $accountSelect.prop('disabled', false);
            if (accounts.length > 0) {
                accounts.forEach(acc => {
                    const entityId = acc.id || '';
                    const selected = (acc.account_id == currentAccId);
                    $accountSelect.append($('<option>', {
                        value: acc.account_id,
                        text: (acc.account_code ? acc.account_code + ' - ' : '') + acc.name,
                        selected: selected,
                        'data-entity-id': entityId
                    }));
                });
            } else {
                $accountSelect.append('<option value="" disabled>لا يوجد حسابات متاحة</option>');
            }
            $modal.find(`#edit_account_label_${bookingId}`).text(label);
            $accountSelect.select2({
                dropdownParent: $modal,
                width: '100%'
            });
            $accountSelect.trigger('change');
        };

        /**
         * جلب وعرض رصيد الحساب الموحد
         */
        function fetchAccountBalance(accountId, balanceInfoId, balanceDisplayId, limitDisplayId) {
            if (!accountId) {
                $(`#${balanceInfoId}`).addClass('d-none');
                return;
            }

            $.get('ajax_get_account_balances.php', { account_id: accountId }, function(data) {
                if (data && data.length > 0) {
                    let totalNetBalanceBase = 0;
                    let creditLimitBase = parseFloat(data[0].credit_limit_base) || 0;
                    const normalBalance = data[0].normal_balance;

                    data.forEach(bal => {
                        totalNetBalanceBase += parseFloat(bal.current_balance_base) || 0;
                    });

                    let statusText = '';
                    let statusClass = '';
                    if (Math.abs(totalNetBalanceBase) < 0.01) {
                        statusText = '(متعادل)';
                        statusClass = 'text-muted';
                    } else if (normalBalance === 'debit') {
                        statusText = totalNetBalanceBase > 0 ? '(له)' : '(عليه)';
                        statusClass = totalNetBalanceBase > 0 ? 'text-success' : 'text-danger';
                    } else {
                        statusText = totalNetBalanceBase > 0 ? '(عليه)' : '(له)';
                        statusClass = totalNetBalanceBase > 0 ? 'text-danger' : 'text-success';
                    }

                    const baseSymbol = '<?php echo $base_currency_symbol; ?>';
                    $(`#${balanceDisplayId}`).html(`<span class="${statusClass}">${Math.abs(totalNetBalanceBase).toLocaleString(undefined, {minimumFractionDigits: 2})}</span> <small class="text-muted">${baseSymbol}</small> ${statusText}`);
                    $(`#${limitDisplayId}`).text(creditLimitBase > 0 ? creditLimitBase.toLocaleString(undefined, {
                        minimumFractionDigits: 2
                    }) + ' ' + baseSymbol : 'غير محدد');
                    $(`#${balanceInfoId}`).removeClass('d-none');
                } else {
                    $(`#${balanceInfoId}`).addClass('d-none');
                }
            });
        }

        // مستمع تغيير الحساب لتحديث الهويات والرصيد
        $(document).on('change', 'select[name="account_id"]', function() {
            const $modal = $(this).closest('.modal');
            const type = $modal.find('select[name="delivery_type"]').val();
            const entityId = $(this).find(':selected').data('entity-id') || '';
            const accountId = $(this).val();
            
            let prefix = '';
            let balanceInfoId, balanceDisplayId, limitDisplayId;

            if ($modal.attr('id') === 'addBookingModal') {
                prefix = '';
                balanceInfoId = 'account_balance_info';
                balanceDisplayId = 'unified_balance_display';
                limitDisplayId = 'unified_limit_display';
            } else {
                const bookingId = $modal.find('input[name="booking_id"]').val();
                prefix = `edit_${bookingId}_`;
                balanceInfoId = `edit_account_balance_info_${bookingId}`;
                balanceDisplayId = `edit_unified_balance_display_${bookingId}`;
                limitDisplayId = `edit_unified_limit_display_${bookingId}`;
            }

            $('#' + prefix + 'customer_id_hidden').val(type === 'credit' ? entityId : '');
            $('#' + prefix + 'agent_id_hidden').val(type === 'agent' ? entityId : '');

            fetchAccountBalance(accountId, balanceInfoId, balanceDisplayId, limitDisplayId);
        });

        // تنبيه عند النقر على الحساب قبل اختيار نوع التوصيل
        $(document).on('mousedown click', '.select2-container', function(e) {
            const $select = $(this).prev('select');
            if ($select.length && $select.prop('disabled')) {
                const id = $select.attr('id');
                if (id && (id.includes('account_id') || id.includes('account_select'))) {
                    e.preventDefault();
                    e.stopImmediatePropagation();
                    alert('يرجى اختيار "نوع التوصيل" أولاً لتتمكن من اختيار الحساب.');
                    const $modal = $(this).closest('.modal');
                    $modal.find('select[name="delivery_type"]').focus();
                    return false;
                }
            }
        });

        // مستمعات فتح المودالات
        $('#addBookingModal').on('shown.bs.modal', function() {
            updatePaymentLogic();
        });

        $(document).on('shown.bs.modal', '.modal', function() {
            const $modal = $(this);
            if ($modal.attr('id') === 'addBookingModal') return;
            if ($modal.find('select[name="delivery_type"]').length) {
                window.updateEditPaymentLogic(this);
            }
        });

        // مستمع تغيير نوع التوصيل في جميع المودالات
        $(document).on('change', 'select[name="delivery_type"]', function() {
            const modal = this.closest('.modal');
            if (modal) {
                if ($(modal).attr('id') === 'addBookingModal') {
                    window.updatePaymentLogic();
                } else {
                    window.updateEditPaymentLogic(modal);
                }
            }
        });

        // مستمعات عامة
        $('#add_traveler_name').on('input', updateDescription);
        $('#add_from_city_id, #add_to_city_id, #add_service_type').on('change', updateDescription);

        $('.price-input').on('input', updatePriceInWords);
        $('#add_currency_id').on('change', updatePriceInWords);

        $('#add_trip_type').on('change', function() {
            if ($(this).val() === 'round_trip') $('#add_return_date_field').show();
            else $('#add_return_date_field').hide().find('input').val('');
        });

        $('#add_service_type').on('change', function() {
            if ($(this).val() === 'bus') $('#add_bus_type_field').show();
            else $('#add_bus_type_field').hide().find('input').val('');
        });

        $(document).on('input', '.traveler-name-edit', function() { updateEditDescription($(this).closest('.modal-content')); });
        $(document).on('change', '.from-city-edit, .to-city-edit, .service-type-edit', function() { updateEditDescription($(this).closest('.modal-content')); });
        $(document).on('input', '.edit-price-input', function() { updateEditPriceInWords($(this).closest('.modal-content')); });

        // التحقق عند الإرسال
        $('#addBookingModal form').on('submit', function(e) {
            const sale = parseFloat($('#add_sale_price').val()) || 0;
            const purchase = parseFloat($('#add_purchase_price').val()) || 0;
            const received = parseFloat($('#add_amount_received').val()) || 0;
            const fromCity = $('#add_from_city_id').val();
            const toCity = $('#add_to_city_id').val();

            if (fromCity === toCity) { alert('يجب اختيار مدينتين مختلفتين'); e.preventDefault(); return false; }
            if (received > sale) { alert('الموصل لا يمكن أن يتجاوز سعر البيع'); e.preventDefault(); return false; }
            if (purchase > sale && !confirm('سعر الشراء أكبر من البيع، هل تستمر؟')) { e.preventDefault(); return false; }
        });
    });
</script>
<?php
require_once 'footer.php';
ob_end_flush();
?>
