<?php
// =====================================================
// account_limits.php - إدارة الحدود القصوى للمعاملات لكل حساب
// =====================================================

require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/accounting_functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? 'editor';
$is_admin = ($user_role === 'admin' || $user_role === 'developer');

// معالجة حفظ الحدود
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_limits'])) {
    $account_id = (int)$_POST['account_id'];
    $currency_id = (int)$_POST['currency_id'];

    $data = [
        'max_debit_per_transaction' => $_POST['max_debit_per_transaction'] ?: null,
        'max_credit_per_transaction' => $_POST['max_credit_per_transaction'] ?: null,
        'max_debit_per_day' => $_POST['max_debit_per_day'] ?: null,
        'max_credit_per_day' => $_POST['max_credit_per_day'] ?: null,
        'max_debit_per_month' => $_POST['max_debit_per_month'] ?: null,
        'max_credit_per_month' => $_POST['max_credit_per_month'] ?: null,
        'min_balance' => $_POST['min_balance'] ?: null,
        'max_balance' => $_POST['max_balance'] ?: null,
        'alert_on_exceed' => isset($_POST['alert_on_exceed']) ? 1 : 0,
        'prevent_on_exceed' => isset($_POST['prevent_on_exceed']) ? 1 : 0,
    ];

    // التحقق من وجود سابق
    $stmt_check = $pdo->prepare("SELECT id FROM account_limits WHERE account_id = ? AND currency_id = ?");
    $stmt_check->execute([$account_id, $currency_id]);
    $existing = $stmt_check->fetch();

    if ($existing) {
        $stmt = $pdo->prepare("
            UPDATE account_limits SET
                max_debit_per_transaction = ?, max_credit_per_transaction = ?,
                max_debit_per_day = ?, max_credit_per_day = ?,
                max_debit_per_month = ?, max_credit_per_month = ?,
                min_balance = ?, max_balance = ?,
                alert_on_exceed = ?, prevent_on_exceed = ?
            WHERE account_id = ? AND currency_id = ?
        ");
        $stmt->execute([
            $data['max_debit_per_transaction'],
            $data['max_credit_per_transaction'],
            $data['max_debit_per_day'],
            $data['max_credit_per_day'],
            $data['max_debit_per_month'],
            $data['max_credit_per_month'],
            $data['min_balance'],
            $data['max_balance'],
            $data['alert_on_exceed'],
            $data['prevent_on_exceed'],
            $account_id,
            $currency_id
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO account_limits (
                account_id, currency_id, max_debit_per_transaction, max_credit_per_transaction,
                max_debit_per_day, max_credit_per_day, max_debit_per_month, max_credit_per_month,
                min_balance, max_balance, alert_on_exceed, prevent_on_exceed, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $account_id,
            $currency_id,
            $data['max_debit_per_transaction'],
            $data['max_credit_per_transaction'],
            $data['max_debit_per_day'],
            $data['max_credit_per_day'],
            $data['max_debit_per_month'],
            $data['max_credit_per_month'],
            $data['min_balance'],
            $data['max_balance'],
            $data['alert_on_exceed'],
            $data['prevent_on_exceed'],
            $_SESSION['admin_id']
        ]);
    }

    $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم حفظ حدود الحساب بنجاح'];
    header("Location: account_limits.php?account_id={$account_id}&currency_id={$currency_id}");
    exit();
}

// جلب الحسابات مصنفة حسب النوع
$grouped_accounts = [
    'box'      => ['title' => 'الصناديق', 'icon' => 'fa-cash-register', 'accounts' => []],
    'bank'     => ['title' => 'البنوك', 'icon' => 'fa-university', 'accounts' => []],
    'agent'    => ['title' => 'الوكلاء', 'icon' => 'fa-handshake', 'accounts' => []],
    'branch'   => ['title' => 'الفروع', 'icon' => 'fa-code-branch', 'accounts' => []],
    'customer' => ['title' => 'العملاء', 'icon' => 'fa-users', 'accounts' => []],
    'supplier' => ['title' => 'الموردين', 'icon' => 'fa-truck-loading', 'accounts' => []],
    'income'   => ['title' => 'الإيرادات', 'icon' => 'fa-coins', 'accounts' => []],
    'expense'  => ['title' => 'المصروفات', 'icon' => 'fa-file-invoice-dollar', 'accounts' => []],
    'other'    => ['title' => 'حسابات أخرى', 'icon' => 'fa-folder', 'accounts' => []],
];

$all_accounts_data = $pdo->query("SELECT id, account_code, account_name_ar as account_name, account_type FROM unified_accounts WHERE is_active = 1 ORDER BY account_type, account_code")->fetchAll();
foreach ($all_accounts_data as $acc) {
    $type = $acc['account_type'];
    if (isset($grouped_accounts[$type])) {
        $grouped_accounts[$type]['accounts'][] = $acc;
    } else {
        $grouped_accounts['other']['accounts'][] = $acc;
    }
}

// جلب العملات
$currencies = $pdo->query("SELECT id, currency_name, currency_code FROM currencies WHERE is_active = 1")->fetchAll();

$selected_account_id = (int)($_GET['account_id'] ?? 0);
$selected_currency_id = (int)($_GET['currency_id'] ?? 0);
$active_tab = $_GET['tab'] ?? 'limits';
$limits = null;

// معالجة تفعيل/إلغاء تفعيل العملة للحساب
if (isset($_POST['toggle_currency'])) {
    $acc_id = (int)$_POST['account_id'];
    $curr_id = (int)$_POST['currency_id'];
    $action = $_POST['toggle_action']; // enable / disable

    try {
        if ($action === 'enable') {
            $stmt = $pdo->prepare("INSERT IGNORE INTO account_allowed_currencies (account_id, currency_id, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$acc_id, $curr_id, $_SESSION['admin_id']]);

            // التأكد من وجود سجل في أرصدة الحسابات لهذه العملة
            $stmt_bal = $pdo->prepare("INSERT IGNORE INTO account_balances (account_id, currency_id, current_balance) VALUES (?, ?, 0)");
            $stmt_bal->execute([$acc_id, $curr_id]);

            $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم تفعيل العملة للحساب بنجاح'];
        } else {
            // التحقق من الرصيد في نظام الأرصدة المتكامل قبل الإلغاء
            $stmt_check = $pdo->prepare("SELECT fn_get_balance_at_date(?, CURDATE(), ?) as bal");
            $stmt_check->execute([$acc_id, $curr_id]);
            $bal = (float)$stmt_check->fetchColumn();

            if (abs($bal) > 0.01) {
                $_SESSION['flash_message'] = ['type' => 'danger', 'body' => 'لا يمكن إلغاء تفعيل العملة لوجود رصيد متبقي (' . number_format($bal, 2) . ')'];
            } else {
                $pdo->prepare("DELETE FROM account_allowed_currencies WHERE account_id = ? AND currency_id = ?")->execute([$acc_id, $curr_id]);
                $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم إلغاء تفعيل العملة للحساب'];
            }
        }
    } catch (Exception $e) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'body' => 'خطأ: ' . $e->getMessage()];
    }
    header("Location: account_limits.php?account_id={$acc_id}&tab=currencies");
    exit();
}

if ($selected_account_id > 0 && $selected_currency_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM account_limits WHERE account_id = ? AND currency_id = ?");
    $stmt->execute([$selected_account_id, $selected_currency_id]);
    $limits = $stmt->fetch();
}

$page_title = "حدود المعاملات";
require_once 'header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold text-primary"><i class="fas fa-chart-line me-2"></i> حدود المعاملات للحسابات</h3>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show rounded-4">
            <?php echo $_SESSION['flash_message']['body']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <div class="row">
        <!-- قائمة الحسابات المصنفة -->
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0"><i class="fas fa-layer-group me-2 text-primary"></i> إدارة الحسابات</h6>
                    <small class="text-muted"><?php echo count($all_accounts_data); ?> حساب</small>
                </div>
                <div class="card-body p-3" style="max-height: 800px; overflow-y: auto;">
                    <div class="accordion accordion-flush" id="accountsAccordion">
                        <?php $gi = 0;
                        foreach ($grouped_accounts as $type => $group):
                            if (empty($group['accounts'])) continue;
                            $gi++;
                            $is_type_active = false;
                            foreach ($group['accounts'] as $a) if ($a['id'] == $selected_account_id) $is_type_active = true;
                        ?>
                            <div class="accordion-item border-0 mb-2 rounded-3 overflow-hidden shadow-sm">
                                <h2 class="accordion-header">
                                    <button class="accordion-button <?php echo $is_type_active ? '' : 'collapsed'; ?> py-2 px-3 bg-light" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $type; ?>">
                                        <i class="fas <?php echo $group['icon']; ?> me-2 text-primary small"></i>
                                        <span class="small fw-bold"><?php echo $group['title']; ?></span>
                                        <span class="badge bg-white text-primary border ms-auto small"><?php echo count($group['accounts']); ?></span>
                                    </button>
                                </h2>
                                <div id="collapse<?php echo $type; ?>" class="accordion-collapse collapse <?php echo $is_type_active ? 'show' : ''; ?>" data-bs-parent="#accountsAccordion">
                                    <div class="accordion-body p-0">
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($group['accounts'] as $acc):
                                                $is_selected = ($selected_account_id == $acc['id']);
                                                $bg_class = $is_selected ? 'bg-primary bg-opacity-10 border-start border-primary border-4' : '';
                                            ?>
                                                <div class="list-group-item list-group-item-action border-0 py-2 <?php echo $bg_class; ?>">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div class="flex-grow-1">
                                                            <div class="small fw-bold <?php echo $is_selected ? 'text-primary' : 'text-dark'; ?>"><?php echo $acc['account_name']; ?></div>
                                                            <div class="extra-small text-muted"><?php echo $acc['account_code']; ?></div>
                                                        </div>
                                                        <div class="d-flex flex-wrap gap-1 justify-content-end" style="max-width: 120px;">
                                                            <?php
                                                            $acc_currencies = get_account_allowed_currencies($acc['id']);
                                                            foreach ($acc_currencies as $cur):
                                                                $has_limits_stmt = $pdo->prepare("SELECT id FROM account_limits WHERE account_id = ? AND currency_id = ?");
                                                                $has_limits_stmt->execute([$acc['id'], $cur['id']]);
                                                                $has_limits = $has_limits_stmt->fetch();
                                                            ?>
                                                                <a href="?account_id=<?php echo $acc['id']; ?>&currency_id=<?php echo $cur['id']; ?>&tab=limits"
                                                                    class="badge <?php echo ($selected_account_id == $acc['id'] && $selected_currency_id == $cur['id']) ? 'bg-primary' : 'bg-light text-dark'; ?> text-decoration-none"
                                                                    style="font-size: 0.6rem;">
                                                                    <?php echo $cur['currency_code']; ?>
                                                                    <?php if ($has_limits): ?><i class="fas fa-check-circle text-success ms-1"></i><?php endif; ?>
                                                                </a>
                                                            <?php endforeach; ?>
                                                            <a href="?account_id=<?php echo $acc['id']; ?>&tab=currencies" class="badge bg-info text-white text-decoration-none" style="font-size: 0.6rem;" title="إدارة العملات">
                                                                <i class="fas fa-plus"></i>
                                                            </a>
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
        </div>

        <!-- المنطقة الرئيسية للإعدادات -->
        <div class="col-md-8">
            <?php if ($selected_account_id > 0):
                $account_info_stmt = $pdo->prepare("SELECT id, account_code, account_name_ar as account_name, account_type FROM unified_accounts WHERE id = ?");
                $account_info_stmt->execute([$selected_account_id]);
                $acc_info = $account_info_stmt->fetch();
            ?>
                <!-- ملخص العملات المفعلة والأرصدة -->
                <div class="row g-3 mb-4">
                    <?php
                    $enabled_currencies = get_account_allowed_currencies($selected_account_id);
                    if (empty($enabled_currencies)): ?>
                        <div class="col-12">
                            <div class="alert alert-warning border-0 shadow-sm rounded-4 mb-0">
                                <i class="fas fa-exclamation-triangle me-2"></i> لا توجد عملات مفعلة لهذا الحساب حالياً. يرجى تفعيل العملات من تبويب "تفعيل العملات".
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($enabled_currencies as $ecur):
                            // جلب أحدث رصيد من نظام الأرصدة المتكامل
                            $stmt_b = $pdo->prepare("SELECT closing_balance FROM account_balances WHERE account_id = ? AND currency_id = ? ORDER BY balance_date DESC LIMIT 1");
                            $stmt_b->execute([$selected_account_id, $ecur['id']]);
                            $ebal = $stmt_b->fetchColumn();

                            // إذا لم يوجد سجل في جدول الأرصدة، نستخدم الدالة لحسابه لحظياً
                            if ($ebal === false) {
                                $stmt_fn = $pdo->prepare("SELECT fn_get_balance_at_date(?, CURDATE(), ?) as bal");
                                $stmt_fn->execute([$selected_account_id, $ecur['id']]);
                                $ebal = (float)($stmt_fn->fetchColumn() ?: 0);
                            } else {
                                $ebal = (float)$ebal;
                            }

                            // جلب طبيعة الحساب لتحديد حالة الرصيد
                            $acc_stmt = $pdo->prepare("SELECT normal_balance FROM unified_accounts WHERE id = ?");
                            $acc_stmt->execute([$selected_account_id]);
                            $normal_bal = $acc_stmt->fetchColumn() ?: 'debit';

                            $is_current_selection = ($selected_currency_id == $ecur['id']);
                        ?>
                            <div class="col-md-4">
                                <a href="?account_id=<?php echo $selected_account_id; ?>&currency_id=<?php echo $ecur['id']; ?>&tab=limits"
                                    class="card border-0 shadow-sm rounded-4 text-decoration-none h-100 transition-all <?php echo $is_current_selection ? 'bg-primary text-white shadow' : 'bg-white text-dark'; ?>">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <div class="small fw-bold opacity-75"><?php echo $ecur['currency_name']; ?></div>
                                            <span class="badge <?php echo $is_current_selection ? 'bg-white text-primary' : 'bg-primary bg-opacity-10 text-primary'; ?> rounded-pill">
                                                <?php echo $ecur['currency_code']; ?>
                                            </span>
                                        </div>
                                        <div class="d-flex align-items-baseline gap-2">
                                            <div class="h4 fw-bold mb-0"><?php echo number_format(abs($ebal), 2); ?></div>
                                            <div class="extra-small opacity-75"><?php echo $ecur['currency_code']; ?></div>
                                        </div>
                                        <div class="mt-1 d-flex justify-content-between align-items-center">
                                            <div class="extra-small opacity-75">الرصيد الحالي</div>
                                            <div class="extra-small fw-bold">
                                                <?php
                                                if (abs($ebal) < 0.01) echo '<span class="opacity-50">متعادل</span>';
                                                else if (($normal_bal == 'debit' && $ebal > 0) || ($normal_bal == 'credit' && $ebal < 0))
                                                    echo '<span class="' . ($is_current_selection ? 'text-white' : 'text-success') . '">لنا</span>';
                                                else
                                                    echo '<span class="' . ($is_current_selection ? 'text-white' : 'text-danger') . '">علينا</span>';
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Tabs Navigation -->
                <ul class="nav nav-pills nav-fill mb-4 bg-white p-2 rounded-4 shadow-sm">
                    <li class="nav-item">
                        <a class="nav-link rounded-3 <?php echo $active_tab == 'limits' ? 'active' : ''; ?>" href="?account_id=<?php echo $selected_account_id; ?>&currency_id=<?php echo $selected_currency_id; ?>&tab=limits">
                            <i class="fas fa-sliders-h me-2"></i> حدود المعاملات
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link rounded-3 <?php echo $active_tab == 'currencies' ? 'active' : ''; ?>" href="?account_id=<?php echo $selected_account_id; ?>&tab=currencies">
                            <i class="fas fa-coins me-2"></i> تفعيل العملات والأرصدة
                        </a>
                    </li>
                </ul>

                <?php if ($active_tab == 'limits'): ?>
                    <?php if ($selected_currency_id > 0):
                        $currency_info_stmt = $pdo->prepare("SELECT * FROM currencies WHERE id = ?");
                        $currency_info_stmt->execute([$selected_currency_id]);
                        $currency_info = $currency_info_stmt->fetch();

                        // جلب الإحصائيات الحالية
                        $today_debit = get_account_debit_sum_for_day($selected_account_id, $selected_currency_id);
                        $today_credit = get_account_credit_sum_for_day($selected_account_id, $selected_currency_id);
                        $month_debit = get_account_debit_sum_for_month($selected_account_id, $selected_currency_id);
                        $month_credit = get_account_credit_sum_for_month($selected_account_id, $selected_currency_id);

                        // جلب الرصيد من نظام الأرصدة المتكامل
                        $bal_stmt = $pdo->prepare("SELECT closing_balance FROM account_balances WHERE account_id = ? AND currency_id = ? ORDER BY balance_date DESC LIMIT 1");
                        $bal_stmt->execute([$selected_account_id, $selected_currency_id]);
                        $current_balance = $bal_stmt->fetchColumn();

                        if ($current_balance === false) {
                            $stmt_fn = $pdo->prepare("SELECT fn_get_balance_at_date(?, CURDATE(), ?) as bal");
                            $stmt_fn->execute([$selected_account_id, $selected_currency_id]);
                            $current_balance = (float)($stmt_fn->fetchColumn() ?: 0);
                        } else {
                            $current_balance = (float)$current_balance;
                        }
                    ?>
                        <!-- ... (محتوى نموذج الحدود - يبقى كما هو) ... -->
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-header bg-primary text-white border-0 py-3 rounded-top-4">
                                <h5 class="mb-0 fw-bold">
                                    <i class="fas fa-sliders-h me-2"></i>
                                    حدود الحساب: <?php echo h($acc_info['account_name_ar']); ?> - <?php echo h($currency_info['currency_name']); ?>
                                </h5>
                            </div>
                            <div class="card-body p-4">
                                <!-- الإحصائيات الحالية -->
                                <div class="row g-3 mb-4">
                                    <div class="col-md-3">
                                        <div class="card border-0 shadow-sm rounded-3 bg-info bg-opacity-10">
                                            <div class="card-body p-2 text-center">
                                                <small class="text-muted d-block">مدين اليوم</small>
                                                <h6 class="mb-0 text-info fw-bold"><?php echo number_format($today_debit, 2); ?></h6>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="card border-0 shadow-sm rounded-3 bg-success bg-opacity-10">
                                            <div class="card-body p-2 text-center">
                                                <small class="text-muted d-block">دائن اليوم</small>
                                                <h6 class="mb-0 text-success fw-bold"><?php echo number_format($today_credit, 2); ?></h6>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="card border-0 shadow-sm rounded-3 bg-warning bg-opacity-10">
                                            <div class="card-body p-2 text-center">
                                                <small class="text-muted d-block">مدين الشهر</small>
                                                <h6 class="mb-0 text-warning fw-bold"><?php echo number_format($month_debit, 2); ?></h6>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="card border-0 shadow-sm rounded-3 bg-primary bg-opacity-10">
                                            <div class="card-body p-2 text-center">
                                                <small class="text-muted d-block">الرصيد الحالي</small>
                                                <h6 class="mb-0 text-primary fw-bold"><?php echo number_format($current_balance, 2); ?></h6>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <form method="POST">
                                    <input type="hidden" name="account_id" value="<?php echo $selected_account_id; ?>">
                                    <input type="hidden" name="currency_id" value="<?php echo $selected_currency_id; ?>">

                                    <div class="row g-4">
                                        <div class="col-md-6">
                                            <div class="border rounded-3 p-3 h-100">
                                                <h6 class="fw-bold text-primary mb-3 border-bottom pb-2"><i class="fas fa-arrow-up me-1"></i> حدود المبالغ المدينة</h6>
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold">الحد الأقصى للمعاملة الواحدة</label>
                                                    <div class="input-group">
                                                        <input type="number" step="0.01" name="max_debit_per_transaction" class="form-control" value="<?php echo $limits['max_debit_per_transaction'] ?? ''; ?>" placeholder="بدون حد">
                                                        <span class="input-group-text"><?php echo $currency_info['currency_code']; ?></span>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold">الحد الأقصى في اليوم</label>
                                                    <div class="input-group">
                                                        <input type="number" step="0.01" name="max_debit_per_day" class="form-control" value="<?php echo $limits['max_debit_per_day'] ?? ''; ?>" placeholder="بدون حد">
                                                        <span class="input-group-text"><?php echo $currency_info['currency_code']; ?></span>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold">الحد الأقصى في الشهر</label>
                                                    <div class="input-group">
                                                        <input type="number" step="0.01" name="max_debit_per_month" class="form-control" value="<?php echo $limits['max_debit_per_month'] ?? ''; ?>" placeholder="بدون حد">
                                                        <span class="input-group-text"><?php echo $currency_info['currency_code']; ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="border rounded-3 p-3 h-100">
                                                <h6 class="fw-bold text-success mb-3 border-bottom pb-2"><i class="fas fa-arrow-down me-1"></i> حدود المبالغ الدائنة</h6>
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold">الحد الأقصى للمعاملة الواحدة</label>
                                                    <div class="input-group">
                                                        <input type="number" step="0.01" name="max_credit_per_transaction" class="form-control" value="<?php echo $limits['max_credit_per_transaction'] ?? ''; ?>" placeholder="بدون حد">
                                                        <span class="input-group-text"><?php echo $currency_info['currency_code']; ?></span>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold">الحد الأقصى في اليوم</label>
                                                    <div class="input-group">
                                                        <input type="number" step="0.01" name="max_credit_per_day" class="form-control" value="<?php echo $limits['max_credit_per_day'] ?? ''; ?>" placeholder="بدون حد">
                                                        <span class="input-group-text"><?php echo $currency_info['currency_code']; ?></span>
                                                    </div>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold">الحد الأقصى في الشهر</label>
                                                    <div class="input-group">
                                                        <input type="number" step="0.01" name="max_credit_per_month" class="form-control" value="<?php echo $limits['max_credit_per_month'] ?? ''; ?>" placeholder="بدون حد">
                                                        <span class="input-group-text"><?php echo $currency_info['currency_code']; ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="border rounded-3 p-3 h-100 bg-light bg-opacity-10">
                                                <h6 class="fw-bold text-warning mb-3 border-bottom pb-2"><i class="fas fa-balance-scale me-1"></i> حدود مديونية الحساب (الدين والائتمان)</h6>

                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold text-danger">حد مديونية الحساب لنا (يسلف منا)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-white"><i class="fas fa-arrow-right text-danger"></i></span>
                                                        <input type="number" step="0.01" name="max_balance" class="form-control" value="<?php echo $limits['max_balance'] ?? ''; ?>" placeholder="مثلاً: 5000">
                                                        <span class="input-group-text"><?php echo $currency_info['currency_code']; ?></span>
                                                    </div>
                                                    <div class="form-text extra-small">أقصى مبلغ نسمح للحساب أن يدين به لنا (رصيد إيجابي).</div>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label small fw-bold text-success">حد مديونيتنا للحساب (نسلف منه)</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text bg-white"><i class="fas fa-arrow-left text-success"></i></span>
                                                        <input type="number" step="0.01" name="min_balance" class="form-control" value="<?php echo $limits['min_balance'] ?? ''; ?>" placeholder="مثلاً: -2000">
                                                        <span class="input-group-text"><?php echo $currency_info['currency_code']; ?></span>
                                                    </div>
                                                    <div class="form-text extra-small">أدخل المبلغ كقيمة سالبة (مثلاً -2000) لتحديد أقصى مبلغ يمكن أن ندين به لهذا الحساب.</div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="border rounded-3 p-3 h-100">
                                                <h6 class="fw-bold text-danger mb-3 border-bottom pb-2"><i class="fas fa-bell me-1"></i> إعدادات التنبيه والمنع</h6>
                                                <div class="form-check form-switch mb-3 mt-4">
                                                    <input class="form-check-input" type="checkbox" name="alert_on_exceed" id="alert_on_exceed" <?php echo ($limits['alert_on_exceed'] ?? 1) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label fw-bold" for="alert_on_exceed">إرسال تنبيه عند تجاوز الحد</label>
                                                    <div class="form-text small">سيظهر تنبيه في لوحة التحكم وللمحاسبين.</div>
                                                </div>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" name="prevent_on_exceed" id="prevent_on_exceed" <?php echo ($limits['prevent_on_exceed'] ?? 0) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label fw-bold" for="prevent_on_exceed">منع المعاملة عند تجاوز الحد</label>
                                                    <div class="form-text small">سيتم إيقاف المعاملة ولن يتم حفظها في النظام.</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-4 text-end">
                                        <button type="submit" name="save_limits" class="btn btn-primary rounded-pill px-5 shadow-sm">
                                            <i class="fas fa-save me-2"></i> حفظ الإعدادات
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- سجل التنبيهات -->
                        <div class="card border-0 shadow-sm rounded-4 mt-4">
                            <div class="card-header bg-white border-bottom-0 pt-4 px-4 d-flex justify-content-between align-items-center">
                                <h6 class="fw-bold mb-0"><i class="fas fa-history me-2 text-warning"></i> سجل تنبيهات تجاوز الحدود (آخر 20)</h6>
                                <span class="badge bg-light text-dark"><?php echo $currency_info['currency_code']; ?></span>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="bg-light">
                                            <tr>
                                                <th class="ps-4">التاريخ</th>
                                                <th>نوع الحد</th>
                                                <th>الرسالة</th>
                                                <th class="text-center">الحالة</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $alerts_stmt = $pdo->prepare("
                                                SELECT * FROM account_limit_alerts
                                                WHERE account_id = ? AND currency_id = ?
                                                ORDER BY created_at DESC LIMIT 20
                                            ");
                                            $alerts_stmt->execute([$selected_account_id, $selected_currency_id]);
                                            $alerts_list = $alerts_stmt->fetchAll();
                                            ?>
                                            <?php foreach ($alerts_list as $alert): ?>
                                                <tr class="small">
                                                    <td class="ps-4"><?php echo date('Y-m-d H:i', strtotime($alert['created_at'])); ?></td>
                                                    <td>
                                                        <span class="badge bg-secondary bg-opacity-10 text-secondary">
                                                            <?php echo str_replace('_', ' ', $alert['limit_type']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($alert['alert_message']); ?></td>
                                                    <td class="text-center">
                                                        <?php if ($alert['was_prevented']): ?>
                                                            <span class="badge bg-danger rounded-pill">تم المنع</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-warning text-dark rounded-pill">تنبيه فقط</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <?php if (empty($alerts_list)): ?>
                                                <tr>
                                                    <td colspan="4" class="text-center py-5 text-muted">لا توجد تنبيهات سابقة لهذا الحساب</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card border-0 shadow-sm rounded-4 h-100 d-flex align-items-center justify-content-center p-5 bg-white">
                            <div class="text-center">
                                <div class="mb-4">
                                    <i class="fas fa-mouse-pointer fa-4x text-light"></i>
                                </div>
                                <h5 class="text-muted">يرجى اختيار عملة</h5>
                                <p class="text-muted small">انقر على رمز العملة بجانب اسم الحساب في القائمة الجانبية لإدارة حدودها.</p>
                            </div>
                        </div>
                    <?php endif; ?>

                <?php elseif ($active_tab == 'currencies'): ?>
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-info text-white border-0 py-3 rounded-top-4">
                            <h5 class="mb-0 fw-bold"><i class="fas fa-coins me-2"></i> تفعيل العملات للحساب: <?php echo $acc_info['account_name_ar']; ?></h5>
                        </div>
                        <div class="card-body p-4">
                            <div class="alert alert-info small rounded-3 border-0 shadow-sm mb-4">
                                <i class="fas fa-info-circle me-2"></i> تفعيل العملة يسمح للحساب بإجراء عمليات مالية بهذه العملة. لا يمكن إلغاء تفعيل عملة إذا كان لها رصيد متبقي.
                            </div>

                            <!-- العملات المفعلة -->
                            <h6 class="fw-bold mb-3 text-success"><i class="fas fa-check-circle me-2"></i> العملات المفعلة حالياً</h6>
                            <div class="table-responsive mb-5">
                                <table class="table table-hover align-middle">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4">العملة</th>
                                            <th class="text-end">الرصيد الحالي</th>
                                            <th class="text-center">إجراء</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $has_enabled = false;
                                        $allowed_cur_ids = array_column($enabled_currencies, 'id');
                                        foreach ($currencies as $cur):
                                            if (!in_array($cur['id'], $allowed_cur_ids)) continue;
                                            $has_enabled = true;

                                            // جلب الرصيد من نظام الأرصدة المتكامل
                                            $stmt_bal = $pdo->prepare("SELECT fn_get_balance_at_date(?, CURDATE(), ?) as bal");
                                            $stmt_bal->execute([$selected_account_id, $cur['id']]);
                                            $balance = (float)($stmt_bal->fetchColumn() ?: 0);
                                        ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="fw-bold"><?php echo $cur['currency_name']; ?></div>
                                                    <small class="text-muted"><?php echo $cur['currency_code']; ?></small>
                                                </td>
                                                <td class="text-end fw-bold <?php echo $balance > 0 ? 'text-success' : ($balance < 0 ? 'text-danger' : ''); ?>">
                                                    <?php echo number_format($balance, 2); ?>
                                                </td>
                                                <td class="text-center">
                                                    <form method="POST" onsubmit="return confirm('هل أنت متأكد من إلغاء تفعيل هذه العملة؟');">
                                                        <input type="hidden" name="account_id" value="<?php echo $selected_account_id; ?>">
                                                        <input type="hidden" name="currency_id" value="<?php echo $cur['id']; ?>">
                                                        <input type="hidden" name="toggle_currency" value="1">
                                                        <input type="hidden" name="toggle_action" value="disable">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger rounded-pill px-3" <?php echo abs($balance) > 0.01 ? 'disabled title="لا يمكن الإلغاء لوجود رصيد"' : ''; ?>>
                                                            إلغاء التفعيل
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (!$has_enabled): ?>
                                            <tr>
                                                <td colspan="3" class="text-center py-4 text-muted">لا توجد عملات مفعلة بعد</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- عملات متاحة للتفعيل -->
                            <h6 class="fw-bold mb-3 text-primary"><i class="fas fa-plus-circle me-2"></i> عملات متاحة للتفعيل</h6>
                            <div class="table-responsive">
                                <table class="table table-hover align-middle">
                                    <thead class="bg-light">
                                        <tr>
                                            <th class="ps-4">العملة</th>
                                            <th class="text-center">إجراء</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $has_available = false;
                                        foreach ($currencies as $cur):
                                            if (in_array($cur['id'], $allowed_cur_ids)) continue;
                                            $has_available = true;
                                        ?>
                                            <tr>
                                                <td class="ps-4">
                                                    <div class="fw-bold"><?php echo $cur['currency_name']; ?></div>
                                                    <small class="text-muted"><?php echo $cur['currency_code']; ?></small>
                                                </td>
                                                <td class="text-center">
                                                    <form method="POST">
                                                        <input type="hidden" name="account_id" value="<?php echo $selected_account_id; ?>">
                                                        <input type="hidden" name="currency_id" value="<?php echo $cur['id']; ?>">
                                                        <input type="hidden" name="toggle_currency" value="1">
                                                        <input type="hidden" name="toggle_action" value="enable">
                                                        <button type="submit" class="btn btn-sm btn-primary rounded-pill px-4">تفعيل العملة</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php if (!$has_available): ?>
                                            <tr>
                                                <td colspan="2" class="text-center py-4 text-muted">جميع العملات المتاحة مفعلة لهذا الحساب</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="card border-0 shadow-sm rounded-4 h-100 d-flex align-items-center justify-content-center p-5 bg-white">
                    <div class="text-center">
                        <div class="mb-4">
                            <i class="fas fa-sitemap fa-4x text-light"></i>
                        </div>
                        <h5 class="text-muted">يرجى اختيار حساب من القائمة</h5>
                        <p class="text-muted small">اختر حساباً من دليل الحسابات الجانبي لإدارة حدوده وعملاته المتاحة.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
</div>
</div>

<style>
    .btn-xs {
        padding: 0.2rem 0.4rem;
        font-size: 0.75rem;
        line-height: 1;
        border-radius: 0.2rem;
    }
</style>

<?php require_once 'footer.php'; ?>
