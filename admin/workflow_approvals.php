<?php
ob_start();
require_once 'header.php';

// التحقق من الصلاحية (المدراء فقط أو مستلم وثائق أو من لديه صلاحية اعتماد الحجوزات)
if (!in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'developer', 'مدير', 'مطور']) && !has_permission('document_receiver_confirm') && !has_permission('bookings_approve_requests')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

$success = $_GET['success'] ?? null;
$error = $_GET['error'] ?? null;

// معالجة طلبات الاعتماد أو الرفض
if (isset($_POST['action']) && isset($_POST['request_id'])) {
    $request_id = $_POST['request_id'];
    $action = $_POST['action']; // 'approve' or 'reject'
    $admin_notes = $_POST['admin_notes'] ?? '';

    try {
        $pdo->beginTransaction();

        // جلب تفاصيل الطلب
        $stmt_req = $pdo->prepare("SELECT * FROM workflow_approval_requests WHERE id = ? AND status = 'pending'");
        $stmt_req->execute([$request_id]);
        $request = $stmt_req->fetch();

        if (!$request) {
            throw new Exception("الطلب غير موجود أو تم معالجته مسبقاً");
        }

        $processor_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'];

        if ($action === 'approve') {
            if ($request['booking_id']) {
                // معالجة اعتماد حجز باص أو طيران
                $extra_data = json_decode($request['extra_data'], true) ?: [];
                $booking_id = $request['booking_id'];
                
                // جلب الـ status_id الصحيح من خطوة سير العمل
                $stmt_step = $pdo->prepare("SELECT status_id FROM workflow_steps WHERE id = ?");
                $stmt_step->execute([$request['to_step_id']]);
                $to_status_id = $stmt_step->fetchColumn();

                // إذا لم توجد خطوة (to_step_id = 0)، نحاول جلب الحالة من الاسم المخزن أو الافتراضي
                if (!$to_status_id) {
                    // في حالتنا، الطلبات التي تنشأ من زر الإلغاء السريع قد لا تملك خطوة سير عمل
                    // سنبحث عن حالة "تم إلغاء الحجز" أو "تم تعديل الحجز" بناءً على البيانات
                    $status_search = (isset($extra_data['cancel_reason']) || strpos($request['notes'], 'إلغاء') !== false) ? 'تم إلغاء الحجز' : 'تم تعديل الحجز';
                    $stmt_s = $pdo->prepare("SELECT id FROM statuses WHERE status_name = ? LIMIT 1");
                    $stmt_s->execute([$status_search]);
                    $to_status_id = $stmt_s->fetchColumn();
                }

                if (!$to_status_id) throw new Exception("لم يتم العثور على الحالة المستهدفة للاعتماد");
                
                // جلب بيانات الحجز الحالية + القيم المالية من الفاتورة الموحدة أولاً
                $stmt_b = $pdo->prepare("
                    SELECT b.*, inv.currency_id AS currency_id, inv.amount_received AS amount_received, inv.total_amount AS sale_price
                    FROM bus_flight_bookings b
                    LEFT JOIN invoices inv ON inv.id = b.invoice_id
                    WHERE b.id = ?
                ");
                $stmt_b->execute([$booking_id]);
                $booking = $stmt_b->fetch();

                if (!$booking) throw new Exception("الحجز غير موجود");

                // تنفيذ الخصم والاسترداد المالي إذا كان هناك مبلغ مخصوم
                $discount_amount = isset($extra_data['discount_amount']) ? (float)$extra_data['discount_amount'] : 0;
                $new_payment_id = null;

                if ($discount_amount > 0) {
                    // تحديث مبلغ الخصم على الفاتورة الموحدة بدل جدول التشغيل
                    if (!empty($booking['invoice_id'])) {
                        $stmt_upd_discount = $pdo->prepare("UPDATE invoices SET discount = ? WHERE id = ?");
                        $stmt_upd_discount->execute([$discount_amount, (int)$booking['invoice_id']]);
                    }

                    // حساب المبلغ المستحق استرداده للعميل
                    // المبلغ المدفوع - الغرامة
                    $refund_amount = $booking['amount_received'] - $discount_amount;

                    if ($refund_amount > 0) {
                        // إنشاء سند صرف (استرداد) والقيد المالي الموحد (نظام ERP الجديد)
                        $account_id = $booking['account_id']; // أو حساب محدد من الإعدادات

                        // جلب حساب العميل كحساب مدين (استرداد)
                        $stmt_cust_coa = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
                        $stmt_cust_coa->execute([$booking['customer_id']]);
                        $debit_account_id = $stmt_cust_coa->fetchColumn();

                        if ($account_id && $debit_account_id) {
                            $new_payment_id = php_create_financial_entry(
                                $pdo,
                                date('Y-m-d'),
                                'payment',
                                'customer',
                                $booking['customer_id'],
                                $debit_account_id, // حساب العميل مدين
                                $account_id, // حساب الصندوق دائن
                                $refund_amount,
                                $booking['currency_id'],
                                "استرداد مبلغ بعد خصم الغرامة (" . $discount_amount . ") للمسافر " . $booking['traveler_name'],
                                $processor_id,
                                $booking['branch_id'],
                                null,
                                null,
                                'bus_flight_booking',
                                $booking_id
                            );
                        }

                        // تحديث المقبوض ليكون مساوياً للغرامة فقط على الفاتورة الموحدة
                        if (!empty($booking['invoice_id'])) {
                            $new_amount_received = (float)$discount_amount;
                            $payment_status = 'unpaid';
                            if ($new_amount_received >= (float)$booking['sale_price']) {
                                $payment_status = 'fully_paid';
                            } elseif ($new_amount_received > 0) {
                                $payment_status = 'partial';
                            }

                            $stmt_upd_received = $pdo->prepare("UPDATE invoices SET amount_received = ?, payment_status = ? WHERE id = ?");
                            $stmt_upd_received->execute([$new_amount_received, $payment_status, (int)$booking['invoice_id']]);
                        }
                    }
                }

                // تحديث حالة الحجز
                $stmt_status_upd = $pdo->prepare("UPDATE bus_flight_bookings SET status_id = ? WHERE id = ?");
                $stmt_status_upd->execute([$to_status_id, $booking_id]);

                // تسجيل تاريخ الإلغاء إذا كانت الحالة "ملغي"
                $stmt_is_cancel = $pdo->prepare("SELECT status_name FROM statuses WHERE id = ?");
                $stmt_is_cancel->execute([$to_status_id]);
                if (strpos($stmt_is_cancel->fetchColumn(), 'إلغاء') !== false) {
                    $pdo->prepare("UPDATE bus_flight_bookings SET cancel_datetime = NOW(), is_cancelled = 1 WHERE id = ?")->execute([$booking_id]);
                }

                // إضافة سجل في تاريخ الحالات
                change_booking_status($booking_id, $to_status_id, $processor_id, "تم الاعتماد من المدير: " . $admin_notes);

            } else {
                // تنفيذ النقل الفعلي للحالة للمعاملات (القديم)
                $extra_data = json_decode($request['extra_data'], true) ?: [];
                if (change_transaction_status($request['passport_id'], $request['to_step_id'], $processor_id, $request['notes'] . "\n(تم الاعتماد: " . $admin_notes . ")", $extra_data)) {
                    // (بقية الكود القديم للموافقة على الجوازات...)
                } else {
                    throw new Exception("فشل تغيير حالة المعاملة");
                }
            }

            // تحديث حالة الطلب
            $stmt_upd = $pdo->prepare("UPDATE workflow_approval_requests SET status = 'approved', processed_by = ?, processed_at = NOW(), admin_notes = ?, payment_id = ? WHERE id = ?");
            $stmt_upd->execute([$processor_id, $admin_notes, $new_payment_id ?? null, $request_id]);

            // إرسال إشعار
            $title = "تم اعتماد طلبك";
            $msg_body = "تمت الموافقة على طلبك بنجاح.";
            $link = $request['booking_id'] ? "bus_flight_bookings.php" : "work_visa.php?id=" . $request['passport_id'];
            
            $stmt_n = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link, type, created_by) VALUES (?, ?, ?, ?, 'success', ?)");
            $stmt_n->execute([$request['requested_by'], $title, $msg_body, $link, $processor_id]);

        } else {
            // رفض الطلب
            $stmt_upd = $pdo->prepare("UPDATE workflow_approval_requests SET status = 'rejected', processed_by = ?, processed_at = NOW(), admin_notes = ? WHERE id = ?");
            $stmt_upd->execute([$processor_id, $admin_notes, $request_id]);

            // إرسال إشعار بالرفض
            $title = "تم رفض طلب الاعتماد";
            $msg_body = "تم رفض طلبك.\nسبب الرفض: " . $admin_notes;
            $link = $request['booking_id'] ? "bus_flight_bookings.php" : "work_visa.php?id=" . $request['passport_id'];

            $stmt_n = $pdo->prepare("INSERT INTO notifications (user_id, title, message, link, type, created_by) VALUES (?, ?, ?, ?, 'danger', ?)");
            $stmt_n->execute([$request['requested_by'], $title, $msg_body, $link, $processor_id]);
        }

        $pdo->commit();
        header("Location: workflow_approvals.php?success=1");
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

// جلب الطلبات بناءً على الحالة المختارة
$status_filter = $_GET['status'] ?? 'pending';
if (!in_array($status_filter, ['pending', 'approved', 'rejected'])) $status_filter = 'pending';

$where_clause = "ar.status = " . $pdo->quote($status_filter);

if (!in_array(strtolower($_SESSION['role'] ?? ''), ['admin', 'developer', 'مدير', 'مطور']) && has_permission('document_receiver_confirm')) {
    // مستلم الوثائق يرى فقط الطلبات التي تنتقل إلى "تم تأكيد استلام الوثائق"
    $where_clause .= " AND ws_to.step_name LIKE '%تأكيد استلام%'";
}

$requests = $pdo->query("
    SELECT ar.*, 
           p.full_name as passport_name, p.passport_number, 
           b.traveler_name as booking_name, b.booking_number,
           ws_from.step_name as from_name, ws_to.step_name as to_name,
           u.full_name as requester_name, r.display_name as requester_role,
           up.full_name as processor_name,
           wt.auto_action
    FROM workflow_approval_requests ar
    LEFT JOIN passports p ON ar.passport_id = p.id
    LEFT JOIN bus_flight_bookings b ON ar.booking_id = b.id
    LEFT JOIN workflow_steps ws_from ON ar.from_step_id = ws_from.id
    LEFT JOIN workflow_steps ws_to ON ar.to_step_id = ws_to.id
    LEFT JOIN workflow_transitions wt ON (ar.from_step_id = wt.from_step_id AND ar.to_step_id = wt.to_step_id)
    JOIN users u ON ar.requested_by = u.id
    LEFT JOIN roles r ON ar.requested_role_id = r.id
    LEFT JOIN users up ON ar.processed_by = up.id
    WHERE $where_clause
    ORDER BY ar.created_at DESC
")->fetchAll();
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-shield-halved me-2"></i> سجل اعتمادات سير العمل</h3>
            <p class="text-muted small mb-0">مراجعة واعتماد طلبات نقل المعاملات والحجوزات</p>
        </div>
    </div>

    <!-- فلاتر الحالة -->
    <ul class="nav nav-pills mb-4 bg-white p-2 rounded-4 shadow-sm d-inline-flex border">
        <li class="nav-item">
            <a class="nav-link rounded-pill px-4 <?php echo ($status_filter === 'pending') ? 'active' : 'text-dark'; ?>" href="?status=pending">
                <i class="fas fa-clock me-2"></i> طلبات معلقة
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill px-4 <?php echo ($status_filter === 'approved') ? 'active' : 'text-dark'; ?>" href="?status=approved">
                <i class="fas fa-check-circle me-2"></i> طلبات معتمدة
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link rounded-pill px-4 <?php echo ($status_filter === 'rejected') ? 'active' : 'text-dark'; ?>" href="?status=rejected">
                <i class="fas fa-times-circle me-2"></i> طلبات مرفوضة
            </a>
        </li>
    </ul>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-check-circle me-2"></i> تمت العملية بنجاح.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-4 border-0 shadow-sm mb-4">
            <i class="fas fa-exclamation-circle me-2"></i> <?php echo $error; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <?php if (empty($requests)): ?>
            <div class="col-12 text-center py-5">
                <div class="mb-3 text-muted opacity-25"><i class="fas fa-inbox fa-5x"></i></div>
                <h5 class="text-muted">لا توجد طلبات معلقة حالياً</h5>
            </div>
        <?php else: ?>
            <?php foreach ($requests as $req): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 overflow-hidden h-100">
                        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center">
                            <?php if ($req['status'] === 'pending'): ?>
                                <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-3">طلب معلق</span>
                            <?php elseif ($req['status'] === 'approved'): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3">تم الاعتماد</span>
                            <?php else: ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3">مرفوض</span>
                            <?php endif; ?>
                            <small class="text-muted"><i class="far fa-clock me-1"></i> <?php echo date('Y-m-d H:i', strtotime($req['created_at'])); ?></small>
                        </div>
                        <div class="card-body p-4">
                            <div class="d-flex align-items-center mb-3">
                                <div class="avatar-sm bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                    <?php if ($req['booking_id']): ?>
                                        <i class="fas fa-ticket-alt fa-lg"></i>
                                    <?php else: ?>
                                        <i class="fas fa-passport fa-lg"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center mb-1">
                                        <h6 class="fw-bold mb-0 me-2"><?php echo htmlspecialchars($req['booking_id'] ? $req['booking_name'] : $req['passport_name']); ?></h6>
                                        <span class="badge bg-secondary extra-small"><?php echo htmlspecialchars($req['request_number']); ?></span>
                                    </div>
                                    <small class="text-muted"><?php echo htmlspecialchars($req['booking_id'] ? $req['booking_number'] : $req['passport_number']); ?></small>
                                </div>
                            </div>

                            <div class="bg-light rounded-4 p-3 mb-3 border">
                                <div class="row g-2 text-center">
                                    <div class="col-5">
                                        <small class="d-block text-muted mb-1">من مرحلة</small>
                                        <div class="fw-bold small"><?php echo htmlspecialchars($req['from_name']); ?></div>
                                    </div>
                                    <div class="col-2 d-flex align-items-center justify-content-center">
                                        <i class="fas fa-long-arrow-alt-left text-primary"></i>
                                    </div>
                                    <div class="col-5">
                                        <small class="d-block text-muted mb-1">إلى مرحلة</small>
                                        <div class="fw-bold small text-primary"><?php echo htmlspecialchars($req['to_name']); ?></div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <small class="text-muted d-block mb-1">بواسطة:</small>
                                <div class="d-flex align-items-center">
                                    <i class="fas fa-user-circle text-muted me-2"></i>
                                    <span class="small fw-bold"><?php echo htmlspecialchars($req['requester_name']); ?></span>
                                    <span class="badge bg-light text-dark extra-small ms-2 border"><?php echo htmlspecialchars($req['requester_role']); ?></span>
                                </div>
                            </div>

                            <?php if (!empty($req['auto_action'])): ?>
                                <div class="mb-3">
                                    <div class="p-2 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 small">
                                        <i class="fas fa-magic text-success me-1"></i>
                                        <span class="text-muted">إجراء تلقائي عند الاعتماد:</span>
                                        <span class="fw-bold text-success">
                                            <?php 
                                                $actions_map = [
                                                    'financial_posting' => 'ترحيل مالي (إنشاء قيود)',
                                                    'supplier_credit_posting' => 'تسجيل سعر الشراء كأجل للمورد',
                                                    'close_transaction' => 'إغلاق نهائي للمعاملة',
                                                    'create_log' => 'تسجيل ملاحظة تلقائية'
                                                ];
                                                echo $actions_map[$req['auto_action']] ?? $req['auto_action'];
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($req['notes']): ?>
                                <div class="mb-3">
                                    <small class="text-muted d-block mb-1">ملاحظات مقدم الطلب:</small>
                                    <div class="p-2 bg-warning bg-opacity-10 border border-warning border-opacity-25 rounded-3 small italic">
                                        "<?php echo htmlspecialchars($req['notes']); ?>"
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php 
                            $extra = json_decode($req['extra_data'], true);
                            if (!empty($extra)): ?>
                                <div class="mb-3">
                                    <small class="text-muted d-block mb-1">البيانات المدخلة:</small>
                                    <div class="row g-1">
                                        <?php foreach($extra as $k => $v): 
                                            $label = $k;
                                            if ($k === 'discount_amount') $label = 'المبلغ المخصوم';
                                            if ($k === 'mod_reason') $label = 'سبب التعديل';
                                            if ($k === 'cancel_reason') $label = 'سبب الإلغاء';
                                            if ($k === 'ticket_number') $label = 'رقم التذكرة';
                                        ?>
                                            <div class="col-6">
                                                <div class="extra-small bg-white border rounded px-2 py-1 text-truncate" title="<?php echo $label; ?>: <?php echo $v; ?>">
                                                    <strong><?php echo $label; ?>:</strong> <?php echo $v; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($req['status'] === 'pending'): ?>
                                <form method="POST" class="mt-4 pt-3 border-top">
                                    <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                    <div class="mb-3">
                                        <textarea name="admin_notes" class="form-control form-control-sm rounded-3" placeholder="ملاحظاتك (اختياري)..." rows="2"></textarea>
                                    </div>
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="action" value="approve" class="btn btn-success rounded-pill fw-bold">
                                            <i class="fas fa-check-circle me-1"></i> اعتماد النقل
                                        </button>
                                        <button type="submit" name="action" value="reject" class="btn btn-outline-danger rounded-pill fw-bold btn-sm">
                                            <i class="fas fa-times-circle me-1"></i> رفض الطلب
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="mt-4 pt-3 border-top">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="fas fa-user-check text-muted me-2"></i>
                                        <small class="text-muted">تمت المعالجة بواسطة:</small>
                                        <span class="small fw-bold ms-2"><?php echo htmlspecialchars($req['processor_name']); ?></span>
                                    </div>
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="far fa-calendar-check text-muted me-2"></i>
                                        <small class="text-muted">بتاريخ:</small>
                                        <span class="small fw-bold ms-2"><?php echo date('Y-m-d H:i', strtotime($req['processed_at'])); ?></span>
                                    </div>
                                    <?php if ($req['admin_notes']): ?>
                                        <div class="p-2 bg-light rounded-3 small italic mb-2">
                                            <strong>ملاحظات المدير:</strong> "<?php echo htmlspecialchars($req['admin_notes']); ?>"
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($req['payment_id']): ?>
                                        <div class="d-grid">
                                            <a href="payments_print.php?id=<?php echo $req['payment_id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill fw-bold">
                                                <i class="fas fa-print me-1"></i> طباعة سند الاسترداد
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php 
require_once 'footer.php'; 
ob_end_flush();
?>
