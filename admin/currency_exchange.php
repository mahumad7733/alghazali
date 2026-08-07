<?php
// =====================================================
// currency_exchange.php - عمليات تصريف وتحويل العملات المطورة
// =====================================================

require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/accounting_functions.php';
require_once '../core/Finance/FinancePostingAdapter.php';
require_once '../includes/security.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit();
}

$user_role = $_SESSION['role'] ?? 'editor';
$is_admin = ($user_role === 'admin' || $user_role === 'developer');

// التحقق من الصلاحية
if (!$is_admin && !in_array($user_role, ['accountant', 'branch_manager'])) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// جلب العملات المتاحة
$currencies = $pdo->query("SELECT id, currency_name, currency_code, currency_symbol FROM currencies ORDER BY is_default DESC, currency_name ASC")->fetchAll();

// جلب جميع الحسابات النهائية لدليل الحسابات (لاستخدامها في أرباح وخسائر الصرف)
$all_leaf_accounts = $pdo->query("SELECT id, account_code, account_name_ar FROM unified_accounts ORDER BY account_code")->fetchAll();

// تحديد حسابات الأرباح والخسائر التلقائية (نبحث عنها بالكود لضمان الدقة)
$profit_account_id = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '402' LIMIT 1")->fetchColumn() ?: 17;
$loss_account_id = $pdo->query("SELECT id FROM unified_accounts WHERE account_code = '502' LIMIT 1")->fetchColumn() ?: 20;

// معالجة إضافة عملية تصريف جديدة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_exchange'])) {
    require_csrf();
    // التحقق من الصلاحية مرة أخرى
    if (!$is_admin && !in_array($user_role, ['accountant', 'branch_manager'])) {
        $errors[] = "ليس لديك صلاحية لإجراء هذه العملية.";
    } else {
        $date = $_POST['date'] ?: date('Y-m-d');

        // --- التحقق من إغلاق الفترة المالية ---
        if (is_period_closed($pdo, $date)) {
            $errors[] = "تنبيه: لا يمكن تنفيذ العملية. التاريخ المحدد ($date) يقع ضمن فترة مالية مغلقة.";
        }
        // --- نهاية التحقق ---

        $from_account_type = $_POST['from_account_type'];
        $from_account_id = (int)$_POST['from_account_id'];
        $from_currency_id = (int)$_POST['from_currency_id'];
        $from_amount = (float)$_POST['from_amount'];

        $to_account_type = $_POST['to_account_type'];
        $to_account_id = (int)$_POST['to_account_id'];
        $to_currency_id = (int)$_POST['to_currency_id'];
        $to_amount = (float)$_POST['to_amount'];

        $exchange_rate = (float)$_POST['exchange_rate'];
        $notes = $_POST['notes'] ?? '';

        $profit_account_id = (int)$_POST['profit_account_id'];
        $loss_account_id = (int)$_POST['loss_account_id'];

        $errors = [];

        // التحقق من المدخلات
        if ($from_amount <= 0) $errors[] = "المبلغ المباع يجب أن يكون أكبر من الصفر.";
        if ($to_amount <= 0) $errors[] = "المبلغ المشترى يجب أن يكون أكبر من الصفر.";
        if ($from_account_id === $to_account_id && $from_currency_id === $to_currency_id) {
            $errors[] = "لا يمكن إجراء عملية تصريف لنفس الحساب ونفس العملة.";
        }
        if ($exchange_rate <= 0) $errors[] = "سعر التحويل غير صحيح أو غير موجود.";
        if (!$profit_account_id || !$loss_account_id) $errors[] = "يجب تحديد حسابات الأرباح والخسائر.";

        // Server-side validation for currency activation and limits
        // Validate 'from' account currency activation
        $stmt_check_from_currency = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND currency_id = ? AND is_frozen = 0");
        $stmt_check_from_currency->execute([$from_account_id, $from_currency_id]);
        if ($stmt_check_from_currency->fetchColumn() == 0) {
            $errors[] = "العملة \"منها\" غير مفعلة أو مجمدة للحساب المحدد.";
        }

        // Validate 'to' account currency activation
        $stmt_check_to_currency = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND currency_id = ? AND is_frozen = 0");
        $stmt_check_to_currency->execute([$to_account_id, $to_currency_id]);
        if ($stmt_check_to_currency->fetchColumn() == 0) {
            $errors[] = "العملة \"إليها\" غير مفعلة أو مجمدة للحساب المحدد.";
        }

        // Enforce credit/debit limits for 'from_account_id' (amount decreased)
        $stmt_from_limits = $pdo->prepare("SELECT current_balance, credit_limit FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
        $stmt_from_limits->execute([$from_account_id, $from_currency_id]);
        $from_account_info = $stmt_from_limits->fetch(PDO::FETCH_ASSOC);

        if ($from_account_info) {
            $from_current_balance = floatval($from_account_info['current_balance']);
            $from_credit_limit = floatval($from_account_info['credit_limit']);
            $new_from_balance = $from_current_balance - $from_amount;

            if ($from_current_balance + 0.000001 < $from_amount) {
                $errors[] = "الرصيد غير كافٍ. المتاح: " . number_format($from_current_balance, 2);
            }

            if ($from_credit_limit != 0 && $new_from_balance < $from_credit_limit) {
                $errors[] = "المبلغ المحول يتجاوز الحد الائتماني للحساب المحول منه. الرصيد الجديد المتوقع: " . number_format($new_from_balance, 2);
            }
        } else {
            $errors[] = "لا يمكن جلب معلومات الرصيد للحساب المحول منه.";
        }

        // Enforce credit/debit limits for 'to_account_id' (amount increased)
        $stmt_to_limits = $pdo->prepare("SELECT current_balance, debit_limit FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
        $stmt_to_limits->execute([$to_account_id, $to_currency_id]);
        $to_account_info = $stmt_to_limits->fetch(PDO::FETCH_ASSOC);

        if ($to_account_info) {
            $to_current_balance = floatval($to_account_info['current_balance']);
            $to_debit_limit = floatval($to_account_info['debit_limit']);
            $new_to_balance = $to_current_balance + $to_amount;

            if ($to_debit_limit != 0 && $new_to_balance > $to_debit_limit) {
                $errors[] = "المبلغ المحول يتجاوز الحد الدائن للحساب المحول إليه. الرصيد الجديد المتوقع: " . number_format($new_to_balance, 2);
            }
        } else {
            $errors[] = "لا يمكن جلب معلومات الرصيد للحساب المحول إليه.";
        }

        if (empty($errors)) {
            try {
                // إزالة beginTransaction هنا لأن الدوال الفرعية ستتعامل مع المعاملات

                // توليد رقم تسلسلي تلقائي
                $stmt_count = $pdo->query("SELECT COUNT(*) FROM currency_exchange_transactions");
                $count = $stmt_count->fetchColumn() + 1;
                $transaction_number = 'EX-' . str_pad($count, 6, '0', STR_PAD_LEFT);

                // 1. إضافة سجل في جدول currency_exchange_transactions
                $stmt_ex = $pdo->prepare("
                INSERT INTO currency_exchange_transactions (
                    transaction_number, transaction_date,
                    from_account_id, from_account_type, from_currency_id, from_amount,
                    to_account_id, to_account_type, to_currency_id, to_amount,
                    exchange_rate, notes, created_by, branch_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
                $stmt_ex->execute([
                    $transaction_number,
                    $date,
                    $from_account_id,
                    $from_account_type,
                    $from_currency_id,
                    $from_amount,
                    $to_account_id,
                    $to_account_type,
                    $to_currency_id,
                    $to_amount,
                    $exchange_rate,
                    $notes,
                    $_SESSION['admin_id'],
                    $_SESSION['branch_id'] ?? null
                ]);
                $exchange_id = $pdo->lastInsertId();

                // 2. حساب أرباح وخسائر فروق العملات
                // جلب الأسعار المرجعية للعملتين
                $stmt_rates = $pdo->prepare("SELECT id, exchange_rate FROM currencies WHERE id IN (?, ?)");
                $stmt_rates->execute([$from_currency_id, $to_currency_id]);
                $ref_rates = $stmt_rates->fetchAll(PDO::FETCH_UNIQUE);

                $from_ref_rate = (float)($ref_rates[$from_currency_id]['exchange_rate'] ?? 1);
                $to_ref_rate = (float)($ref_rates[$to_currency_id]['exchange_rate'] ?? 1);

                // القيمة بالعملة الأساسية (المحلية) بناءً على السعر المرجعي
                $from_value_base = $from_amount * $from_ref_rate;
                $actual_to_amount = $to_amount > 0 ? $to_amount : ($from_amount * $exchange_rate);
                $to_value_base = $actual_to_amount * $to_ref_rate;
                $diff_base = round($to_value_base - $from_value_base, 2);

                // إذا فشل أي قيد، نحذف سجل تبادل العملات الأولي لتجنب السجلات اليتيمة
                $cleanupExchangeRecord = function () use ($pdo, $exchange_id) {
                    if ($exchange_id) {
                        $stmt_cleanup = $pdo->prepare('DELETE FROM currency_exchange_transactions WHERE id = ?');
                        $stmt_cleanup->execute([$exchange_id]);
                    }
                };

                // 3. إنشاء القيود المالية المزدوجة (عبر نظام المستندات الموحد)
                $description = "تصريف عملات: $transaction_number | بيع $from_amount مقابل شراء $to_amount";

                // تسجيل الطرف المدين (الحساب المحول إليه)
                $res1 = \Core\Finance\FinancePostingAdapter::createFinancialEntry(
                    $pdo,
                    $date,
                    'exchange',
                    null,
                    null,
                    $to_account_id,
                    null,
                    $to_amount,
                    $to_currency_id,
                    $description,
                    $_SESSION['admin_id'],
                    $_SESSION['branch_id'] ?? null,
                    null,
                    null,
                    'exchange',
                    $exchange_id
                );

                // تسجيل الطرف الدائن (الحساب المحول منه)
                $res2 = \Core\Finance\FinancePostingAdapter::createFinancialEntry(
                    $pdo,
                    $date,
                    'exchange',
                    null,
                    null,
                    null,
                    $from_account_id,
                    $from_amount,
                    $from_currency_id,
                    $description,
                    $_SESSION['admin_id'],
                    $_SESSION['branch_id'] ?? null,
                    null,
                    null,
                    'exchange',
                    $exchange_id
                );

                if (!$res1 || !$res2) {
                    $cleanupExchangeRecord();
                    throw new Exception("فشل في إنشاء القيود المحاسبية للعملية. تأكد من اكتمال عملية الهجرة.");
                }

                // 4. تسجيل فروق العملات (إذا وجدت)
                if (abs($diff_base) > 0.01) {
                    if ($diff_base > 0) {
                        // ربح صرف
                        $pl_account_id = $profit_account_id;
                        $pl_desc = "أرباح فروق صرف عملية $transaction_number";
                        $res3 = \Core\Finance\FinancePostingAdapter::createFinancialEntry(
                            $pdo,
                            $date,
                            'exchange_diff',
                            null,
                            null,
                            null,
                            $pl_account_id,
                            abs($diff_base),
                            3,
                            $pl_desc,
                            $_SESSION['admin_id'],
                            $_SESSION['branch_id'] ?? null,
                            null,
                            null,
                            'exchange',
                            $exchange_id
                        );
                    } else {
                        // خسارة صرف
                        $pl_account_id = $loss_account_id;
                        $pl_desc = "خسائر فروق صرف عملية $transaction_number";
                        $res3 = \Core\Finance\FinancePostingAdapter::createFinancialEntry(
                            $pdo,
                            $date,
                            'exchange_diff',
                            null,
                            null,
                            $pl_account_id,
                            null,
                            abs($diff_base),
                            3,
                            $pl_desc,
                            $_SESSION['admin_id'],
                            $_SESSION['branch_id'] ?? null,
                            null,
                            null,
                            'exchange',
                            $exchange_id
                        );
                    }

                    if (!$res3) {
                        $cleanupExchangeRecord();
                        throw new Exception("فشل في إنشاء قيد فروق صرف العملية.");
                    }
                }

                $_SESSION['flash_message'] = ['type' => 'success', 'body' => "تمت عملية التصريف بنجاح برقم: $transaction_number"];
                header("Location: currency_exchange.php");
                exit();
            } catch (Exception $e) {
                $errors[] = "خطأ في تنفيذ العملية: " . $e->getMessage();
            }
        }
    } // end else permission check
}

// معالجة الفلترة
$where_clauses = ["1=1"];
$query_params = [];

if (!empty($_GET['from_date'])) {
    $where_clauses[] = "cet.transaction_date >= ?";
    $query_params[] = $_GET['from_date'];
}
if (!empty($_GET['to_date'])) {
    $where_clauses[] = "cet.transaction_date <= ?";
    $query_params[] = $_GET['to_date'];
}
if (!empty($_GET['acc_type'])) {
    $where_clauses[] = "(cet.from_account_type = ? OR cet.to_account_type = ?)";
    $query_params[] = $_GET['acc_type'];
    $query_params[] = $_GET['acc_type'];
}
if (!empty($_GET['currency'])) {
    $where_clauses[] = "(cet.from_currency_id = ? OR cet.to_currency_id = ?)";
    $query_params[] = $_GET['currency'];
    $query_params[] = $_GET['currency'];
}

$where_sql = implode(" AND ", $where_clauses);

// جلب العمليات مع الفلترة
$last_exchanges_stmt = $pdo->prepare("
    SELECT cet.*,
           coa1.account_name_ar as from_account_name,
           coa2.account_name_ar as to_account_name,
           c1.currency_code as code_from,
           c2.currency_code as code_to,
           u.username
    FROM currency_exchange_transactions cet
    JOIN unified_accounts coa1 ON cet.from_account_id = coa1.id
    JOIN unified_accounts coa2 ON cet.to_account_id = coa2.id
    JOIN currencies c1 ON cet.from_currency_id = c1.id
    JOIN currencies c2 ON cet.to_currency_id = c2.id
    LEFT JOIN users u ON cet.created_by = u.id
    WHERE $where_sql
    ORDER BY cet.transaction_date DESC, cet.id DESC
    LIMIT 100
");
$last_exchanges_stmt->execute($query_params);
$last_exchanges = $last_exchanges_stmt->fetchAll();

// إحصائيات سريعة (للفترة المختارة)
$stats_stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_count,
        SUM(CASE WHEN from_currency_id != to_currency_id THEN 1 ELSE 0 END) as exchange_count,
        SUM(CASE WHEN from_currency_id = to_currency_id THEN 1 ELSE 0 END) as transfer_count
    FROM currency_exchange_transactions cet
    WHERE $where_sql
");
$stats_stmt->execute($query_params);
$stats = $stats_stmt->fetch();

$page_title = "تصريف وتحويل العملات";
require_once 'header.php';
?>

<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #4e73df 0%, #224abe 100%);
        --secondary-gradient: linear-gradient(135deg, #858796 0%, #60616f 100%);
        --success-gradient: linear-gradient(135deg, #1cc88a 0%, #13855c 100%);
        --info-gradient: linear-gradient(135deg, #36b9cc 0%, #258391 100%);
        --warning-gradient: linear-gradient(135deg, #f6c23e 0%, #dda20a 100%);
        --danger-gradient: linear-gradient(135deg, #e74a3b 0%, #be2617 100%);
    }

    .card-stats {
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border: none;
        border-radius: 15px;
    }

    .card-stats:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1) !important;
    }

    .stat-icon {
        width: 48px;
        height: 48px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 12px;
        font-size: 1.5rem;
    }

    .exchange-form-card {
        border: none;
        border-radius: 20px;
        overflow: hidden;
    }

    .exchange-form-card .card-header {
        background: var(--primary-gradient);
        color: white;
        padding: 1.5rem;
        border: none;
    }

    .section-divider {
        height: 1px;
        background: #e3e6f0;
        margin: 1.5rem 0;
        position: relative;
    }

    .section-divider span {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        background: white;
        padding: 0 15px;
        color: #858796;
        font-size: 0.8rem;
        font-weight: bold;
        text-transform: uppercase;
    }

    .btn-action {
        width: 32px;
        height: 32px;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 8px;
        transition: all 0.2s;
    }

    .filter-card {
        background: #f8f9fc;
        border: 1px solid #e3e6f0;
        border-radius: 15px;
    }

    .table thead th {
        background: #f8f9fc;
        border-bottom: 2px solid #e3e6f0;
        color: #4e73df;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.5px;
    }

    .badge-soft-primary {
        background: rgba(78, 115, 223, 0.1);
        color: #4e73df;
    }

    .badge-soft-success {
        background: rgba(28, 200, 138, 0.1);
        color: #1cc88a;
    }

    .badge-soft-danger {
        background: rgba(231, 74, 59, 0.1);
        color: #e74a3b;
    }

    .badge-soft-info {
        background: rgba(54, 185, 204, 0.1);
        color: #36b9cc;
    }

    .search-input-group {
        position: relative;
    }

    .search-input-group i {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #d1d3e2;
    }

    .search-input-group input {
        padding-left: 40px;
        border-radius: 10px;
    }
</style>

<div class="container-fluid py-4">
    <!-- رأس الصفحة والإحصائيات -->
    <div class="row mb-4 align-items-center">
        <div class="col-lg-6">
            <h3 class="fw-bold text-dark mb-1"><i class="fas fa-money-bill-transfer me-2 text-primary"></i> تصريف وتحويل العملات</h3>
            <p class="text-muted small mb-0">إدارة عمليات الصرف بين العملات والتحويلات المالية بين الحسابات</p>
        </div>
        <div class="col-lg-6 text-end">
            <button class="btn btn-primary rounded-pill px-4 shadow-sm me-2" type="button" data-bs-toggle="modal" data-bs-target="#addExchangeModal">
                <i class="fas fa-plus me-1"></i> تنفيذ عملية جديدة
            </button>
            <button class="btn btn-outline-secondary rounded-pill px-4 shadow-sm" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse">
                <i class="fas fa-filter me-1"></i> الفلترة والبحث
            </button>
        </div>
    </div>

    <!-- بطاقات الإحصائيات -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card card-stats shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-primary text-white shadow-sm me-3">
                            <i class="fas fa-list-check"></i>
                        </div>
                        <div>
                            <p class="text-muted small mb-0">إجمالي العمليات</p>
                            <h4 class="fw-bold mb-0"><?php echo number_format($stats['total_count']); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stats shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-success text-white shadow-sm me-3">
                            <i class="fas fa-repeat"></i>
                        </div>
                        <div>
                            <p class="text-muted small mb-0">عمليات التصريف</p>
                            <h4 class="fw-bold mb-0"><?php echo number_format($stats['exchange_count']); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card card-stats shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="stat-icon bg-info text-white shadow-sm me-3">
                            <i class="fas fa-right-left"></i>
                        </div>
                        <div>
                            <p class="text-muted small mb-0">عمليات التحويل</p>
                            <h4 class="fw-bold mb-0"><?php echo number_format($stats['transfer_count']); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- منطقة الفلترة -->
    <div class="collapse mb-4" id="filterCollapse">
        <div class="card filter-card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">من تاريخ</label>
                        <input type="date" name="from_date" class="form-control form-control-sm rounded-3" value="<?php echo h($_GET['from_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">إلى تاريخ</label>
                        <input type="date" name="to_date" class="form-control form-control-sm rounded-3" value="<?php echo h($_GET['to_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">نوع الحساب</label>
                        <select name="acc_type" class="form-select form-select-sm rounded-3">
                            <option value="">الكل</option>
                            <option value="box" <?php echo ($_GET['acc_type'] ?? '') == 'box' ? 'selected' : ''; ?>>صناديق</option>
                            <option value="bank" <?php echo ($_GET['acc_type'] ?? '') == 'bank' ? 'selected' : ''; ?>>بنوك</option>
                            <option value="customer" <?php echo ($_GET['acc_type'] ?? '') == 'customer' ? 'selected' : ''; ?>>عملاء</option>
                            <option value="supplier" <?php echo ($_GET['acc_type'] ?? '') == 'supplier' ? 'selected' : ''; ?>>موردين</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-bold">العملة</label>
                        <select name="currency" class="form-select form-select-sm rounded-3">
                            <option value="">الكل</option>
                            <?php foreach ($currencies as $curr): ?>
                                <option value="<?php echo $curr['id']; ?>" <?php echo h($_GET['currency'] ?? '') == $curr['id'] ? 'selected' : ''; ?>>
                                    <?php echo $curr['currency_name']; ?> (<?php echo $curr['currency_code']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary btn-sm rounded-pill flex-grow-1">تطبيق</button>
                        <a href="currency_exchange.php" class="btn btn-light btn-sm rounded-pill px-3">تصفية</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show rounded-4 shadow-sm border-0">
            <?php echo $_SESSION['flash_message']['body']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger rounded-4 shadow-sm border-0">
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo $err; ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- جدول العمليات الأخيرة -->
        <div class="col-12">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-header bg-white border-0 py-4 px-4">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-0">سجل العمليات السابقة</h5>
                            <p class="text-muted small mb-0 mt-1">عرض آخر العمليات التي تم تنفيذها في النظام</p>
                        </div>
                        <div class="col-md-6">
                            <div class="search-input-group">
                                <i class="fas fa-search"></i>
                                <input type="text" id="dynamicSearch" class="form-control border-0 bg-light py-2 rounded-3" placeholder="بحث سريع في العمليات (رقم، حساب، مبلغ)...">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 text-center" id="exchangesTable">
                            <thead>
                                <tr>
                                    <th class="py-3 px-4 text-start">الرقم / التاريخ</th>
                                    <th>من حساب</th>
                                    <th>إلى حساب</th>
                                    <th>المباع</th>
                                    <th>المشترى</th>
                                    <th>السعر</th>
                                    <th class="px-4">الإجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($last_exchanges as $ex): ?>
                                    <tr class="exchange-row">
                                        <td class="py-3 px-4 text-start">
                                            <div class="fw-bold text-primary mb-1"><?php echo $ex['transaction_number']; ?></div>
                                            <div class="text-muted extra-small"><i class="far fa-calendar-alt me-1"></i><?php echo date('Y-m-d', strtotime($ex['transaction_date'])); ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold small mb-1"><?php echo htmlspecialchars($ex['from_account_name']); ?></div>
                                            <span class="badge badge-soft-danger extra-small rounded-pill"><?php echo $ex['from_account_type']; ?></span>
                                        </td>
                                        <td>
                                            <div class="fw-bold small mb-1"><?php echo htmlspecialchars($ex['to_account_name']); ?></div>
                                            <span class="badge badge-soft-success extra-small rounded-pill"><?php echo $ex['to_account_type']; ?></span>
                                        </td>
                                        <td>
                                            <div class="text-danger fw-bold small mb-1"><?php echo number_format($ex['from_amount'], 2); ?></div>
                                            <div class="text-muted extra-small fw-bold"><?php echo $ex['code_from']; ?></div>
                                        </td>
                                        <td>
                                            <div class="text-success fw-bold small mb-1"><?php echo number_format($ex['to_amount'], 2); ?></div>
                                            <div class="text-muted extra-small fw-bold"><?php echo $ex['code_to']; ?></div>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark small bg-light rounded-pill px-2 py-1 d-inline-block border"><?php echo number_format($ex['exchange_rate'], 4); ?></div>
                                        </td>
                                        <td class="px-4">
                                            <div class="d-flex justify-content-center gap-2">
                                                <button class="btn-action btn-soft-info" data-bs-toggle="modal" data-bs-target="#detailsModal<?php echo $ex['id']; ?>" title="عرض التفاصيل"><i class="fas fa-eye"></i></button>
                                                <button class="btn-action btn-soft-warning" onclick="editExchange(<?php echo $ex['id']; ?>)" title="تعديل"><i class="fas fa-edit"></i></button>
                                                <button class="btn-action btn-soft-danger" onclick="deleteExchange(<?php echo $ex['id']; ?>, '<?php echo $ex['transaction_number']; ?>')" title="حذف"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($last_exchanges)): ?>
                                    <tr>
                                        <td colspan="7" class="py-5 text-muted text-center">لا توجد عمليات مسجلة حالياً</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- مودال تنفيذ عملية جديدة -->
<div class="modal fade" id="addExchangeModal" tabindex="-1" aria-labelledby="addExchangeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white py-3 px-4 border-0">
                <h5 class="modal-title fw-bold" id="addExchangeModalLabel"><i class="fas fa-plus-circle me-2"></i> تنفيذ عملية تصريف/تحويل جديدة</h5>
                <button type="button" class="btn-close btn-close-white shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="row g-0">
                    <div class="col-lg-4 bg-light p-4 d-none d-lg-block border-end">
                        <div class="mb-4">
                            <h6 class="fw-bold text-primary mb-3">كيفية عمل التصريف:</h6>
                            <div class="d-flex align-items-start mb-3">
                                <div class="bg-danger text-white rounded-circle p-1 me-2" style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px;">1</div>
                                <p class="small text-muted mb-0"><strong>المصدر:</strong> الحساب الذي سيتم خصم المبلغ منه (البيع/التحويل من).</p>
                            </div>
                            <div class="d-flex align-items-start mb-3">
                                <div class="bg-success text-white rounded-circle p-1 me-2" style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px;">2</div>
                                <p class="small text-muted mb-0"><strong>الهدف:</strong> الحساب الذي سيتم إضافة المبلغ إليه (الشراء/التحويل إلى).</p>
                            </div>
                            <div class="d-flex align-items-start">
                                <div class="bg-warning text-dark rounded-circle p-1 me-2" style="width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; font-size: 12px;">3</div>
                                <p class="small text-muted mb-0"><strong>السعر:</strong> سعر التحويل بين العملتين المختارتين.</p>
                            </div>
                        </div>
                        <div class="alert alert-warning border-0 small">
                            <i class="fas fa-info-circle me-1"></i> ملاحظة: النظام سيقوم بتوليد القيود المحاسبية وتحديث الأرصدة تلقائياً فور الحفظ.
                        </div>
                    </div>
                    <div class="col-lg-8 p-4">
                        <form method="POST" id="exchangeForm">
                            <?php echo csrf_input(); ?>
                            <div class="row">
                                <div class="col-md-6 border-end pe-md-4">
                                    <div class="d-flex align-items-center mb-3">
                                        <h6 class="fw-bold mb-0 text-danger border-bottom border-danger border-2 pb-1">بيانات المصدر (من)</h6>
                                    </div>

                                    <div class="row g-2 mb-3">
                                        <div class="col-md-5">
                                            <label class="form-label small fw-bold">نوع الحساب</label>
                                            <select name="from_account_type" id="from_account_type" class="form-select rounded-3 shadow-none bg-light border-0" required onchange="loadAccounts('from')">
                                                <option value="">اختر...</option>
                                                <option value="box">صندوق</option>
                                                <option value="bank">بنك</option>
                                                <option value="customer">عميل</option>
                                                <option value="supplier">مورد</option>
                                                <option value="agent">وكيل</option>
                                                <option value="branch">فرع</option>
                                                <option value="general">حساب عام</option>
                                            </select>
                                        </div>
                                        <div class="col-md-7">
                                            <label class="form-label small fw-bold">رقم الحساب</label>
                                            <input type="text" id="from_account_code" class="form-control rounded-3 bg-light border-0" readonly placeholder="سيظهر تلقائياً">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">الحساب المصدر</label>
                                        <select name="from_account_id" id="from_account_id" class="form-select select2 rounded-3" required disabled onchange="updateAccountCode('from'); updateFromBalance();">
                                            <option value="">اختر النوع أولاً</option>
                                        </select>
                                        <div id="from_account_balance" class="mt-2 d-none"></div>
                                    </div>

                                    <div class="row g-2 mb-4">
                                        <div class="col-6">
                                            <label class="form-label small fw-bold">العملة</label>
                                            <select name="from_currency_id" id="from_currency_id" class="form-select rounded-3" required onchange="getAutoRate(); updateFromBalance();">
                                                <option value="">اختر...</option>
                                                <?php foreach ($currencies as $c): ?>
                                                    <option value="<?php echo $c['id']; ?>"><?php echo $c['currency_name']; ?> (<?php echo $c['currency_code']; ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold">المبلغ</label>
                                            <input type="number" step="0.01" name="from_amount" id="from_amount" class="form-control rounded-3 border-danger" required oninput="calculateToAmount()">
                                        </div>
                                    </div>
                                </div>

                                <div class="col-md-6 ps-md-4">
                                    <div class="d-flex align-items-center mb-3">
                                        <h6 class="fw-bold mb-0 text-success border-bottom border-success border-2 pb-1">بيانات الهدف (إلى)</h6>
                                    </div>

                                    <div class="row g-2 mb-3">
                                        <div class="col-md-5">
                                            <label class="form-label small fw-bold">نوع الحساب</label>
                                            <select name="to_account_type" id="to_account_type" class="form-select rounded-3 shadow-none bg-light border-0" required onchange="loadAccounts('to')">
                                                <option value="">اختر...</option>
                                                <option value="box">صندوق</option>
                                                <option value="bank">بنك</option>
                                                <option value="customer">عميل</option>
                                                <option value="supplier">مورد</option>
                                                <option value="agent">وكيل</option>
                                                <option value="branch">فرع</option>
                                                <option value="general">حساب عام</option>
                                            </select>
                                        </div>
                                        <div class="col-md-7">
                                            <label class="form-label small fw-bold">رقم الحساب</label>
                                            <input type="text" id="to_account_code" class="form-control rounded-3 bg-light border-0" readonly placeholder="سيظهر تلقائياً">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label small fw-bold">الحساب الهدف</label>
                                        <select name="to_account_id" id="to_account_id" class="form-select select2 rounded-3" required disabled onchange="updateAccountCode('to')">
                                            <option value="">اختر النوع أولاً</option>
                                        </select>
                                    </div>

                                    <div class="row g-2 mb-4">
                                        <div class="col-6">
                                            <label class="form-label small fw-bold">العملة</label>
                                            <select name="to_currency_id" id="to_currency_id" class="form-select rounded-3" required onchange="getAutoRate()">
                                                <option value="">اختر...</option>
                                                <?php foreach ($currencies as $c): ?>
                                                    <option value="<?php echo $c['id']; ?>"><?php echo $c['currency_name']; ?> (<?php echo $c['currency_code']; ?>)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label small fw-bold">المبلغ</label>
                                            <input type="number" step="0.01" name="to_amount" id="to_amount" class="form-control rounded-3 border-success" required oninput="calculateRateFromAmount()">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <hr class="my-4">

                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">سعر التحويل <span id="rate_msg" class="ms-2"></span></label>
                                    <div class="input-group">
                                        <input type="number" step="0.000001" name="exchange_rate" id="exchange_rate" class="form-control rounded-start-3" required oninput="calculateToAmount()">
                                        <button type="button" class="btn btn-outline-primary" onclick="getAutoRate()"><i class="fas fa-sync-alt"></i></button>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">تاريخ العملية</label>
                                    <input type="date" name="date" class="form-control rounded-3" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label small fw-bold">ملاحظات</label>
                                    <textarea name="notes" class="form-control rounded-3" rows="1" placeholder="اختياري..."></textarea>
                                </div>
                            </div>

                            <input type="hidden" name="profit_account_id" value="<?php echo $profit_account_id; ?>">
                            <input type="hidden" name="loss_account_id" value="<?php echo $loss_account_id; ?>">

                            <div class="mt-4 pt-2 text-end">
                                <button type="button" class="btn btn-light rounded-pill px-4 me-2" data-bs-dismiss="modal">إلغاء</button>
                                <button type="submit" name="add_exchange" id="btn_submit" class="btn btn-primary rounded-pill px-5 shadow-sm fw-bold">
                                    <i class="fas fa-check-double me-1"></i> تنفيذ العملية وحفظ القيود
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- مودالات التفاصيل -->
<?php foreach ($last_exchanges as $ex): ?>
    <div class="modal fade" id="detailsModal<?php echo $ex['id']; ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-info-circle me-2"></i> تفاصيل عملية التصريف: <?php echo $ex['transaction_number']; ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded-3 border-start border-4 border-danger">
                                <h6 class="fw-bold text-danger mb-2">بيانات المصدر</h6>
                                <p class="mb-1 small text-muted">الحساب:</p>
                                <p class="fw-bold mb-2"><?php echo htmlspecialchars($ex['from_account_name']); ?> (<?php echo $ex['from_account_type']; ?>)</p>
                                <p class="mb-1 small text-muted">المبلغ المباع:</p>
                                <h5 class="fw-bold text-danger"><?php echo number_format($ex['from_amount'], 2); ?> <small><?php echo $ex['code_from']; ?></small></h5>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="p-3 bg-light rounded-3 border-start border-4 border-success">
                                <h6 class="fw-bold text-success mb-2">بيانات الهدف</h6>
                                <p class="mb-1 small text-muted">الحساب:</p>
                                <p class="fw-bold mb-2"><?php echo htmlspecialchars($ex['to_account_name']); ?> (<?php echo $ex['to_account_type']; ?>)</p>
                                <p class="mb-1 small text-muted">المبلغ المشترى:</p>
                                <h5 class="fw-bold text-success"><?php echo number_format($ex['to_amount'], 2); ?> <small><?php echo $ex['code_to']; ?></small></h5>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="p-3 border rounded-3 bg-white">
                                <div class="row text-center">
                                    <div class="col-md-4">
                                        <p class="mb-1 small text-muted">تاريخ العملية</p>
                                        <p class="fw-bold"><?php echo $ex['transaction_date']; ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <p class="mb-1 small text-muted">سعر التحويل المستخدم</p>
                                        <p class="fw-bold text-primary"><?php echo number_format($ex['exchange_rate'], 6); ?></p>
                                    </div>
                                    <div class="col-md-4">
                                        <p class="mb-1 small text-muted">المسؤول</p>
                                        <p class="fw-bold"><?php echo htmlspecialchars($ex['username'] ?: 'النظام'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php if ($ex['notes']): ?>
                            <div class="col-12">
                                <div class="p-3 bg-light rounded-3">
                                    <p class="mb-1 small text-muted">ملاحظات:</p>
                                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($ex['notes'])); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer bg-light border-0">
                    <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إغلاق</button>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<!-- مودال التعديل -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-warning text-dark py-3 px-4">
                <h5 class="modal-title fw-bold"><i class="fas fa-edit me-2"></i> تعديل عملية التصريف: <span id="edit_transaction_number_title"></span></h5>
                <button type="button" class="btn-close shadow-none" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <form id="editForm">
                    <input type="hidden" id="edit_id" name="id">
                    <?php echo csrf_input(); ?>

                    <div class="row">
                        <div class="col-md-6 border-end pe-md-4">
                            <h6 class="fw-bold text-danger mb-3 border-bottom border-danger border-2 pb-1">بيانات المصدر (من)</h6>

                            <div class="row g-2 mb-3">
                                <div class="col-md-5">
                                    <label class="form-label small fw-bold">نوع الحساب</label>
                                    <select id="edit_from_account_type" name="from_account_type" class="form-select rounded-3 bg-light border-0" required onchange="loadAccountsForEdit('from')">
                                        <option value="box">صندوق</option>
                                        <option value="bank">بنك</option>
                                        <option value="customer">عميل</option>
                                        <option value="supplier">مورد</option>
                                        <option value="agent">وكيل</option>
                                        <option value="branch">فرع</option>
                                        <option value="general">حساب عام</option>
                                    </select>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label small fw-bold">رقم الحساب</label>
                                    <input type="text" id="edit_from_account_code" class="form-control rounded-3 bg-light border-0" readonly>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">الحساب</label>
                                <select id="edit_from_account_id" name="from_account_id" class="form-select rounded-3" required onchange="updateAccountCodeEdit('from')">
                                    <option value="">اختر الحساب...</option>
                                </select>
                            </div>

                            <div class="row g-2 mb-4">
                                <div class="col-6">
                                    <label class="form-label small fw-bold">العملة</label>
                                    <select id="edit_from_currency_id" name="from_currency_id" class="form-select rounded-3" required>
                                        <?php foreach ($currencies as $curr): ?>
                                            <option value="<?php echo $curr['id']; ?>"><?php echo $curr['currency_name']; ?> (<?php echo $curr['currency_code']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold">المبلغ المباع</label>
                                    <input type="number" step="0.01" id="edit_from_amount" name="from_amount" class="form-control rounded-3 border-danger" required>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 ps-md-4">
                            <h6 class="fw-bold text-success mb-3 border-bottom border-success border-2 pb-1">بيانات الهدف (إلى)</h6>

                            <div class="row g-2 mb-3">
                                <div class="col-md-5">
                                    <label class="form-label small fw-bold">نوع الحساب</label>
                                    <select id="edit_to_account_type" name="to_account_type" class="form-select rounded-3 bg-light border-0" required onchange="loadAccountsForEdit('to')">
                                        <option value="box">صندوق</option>
                                        <option value="bank">بنك</option>
                                        <option value="customer">عميل</option>
                                        <option value="supplier">مورد</option>
                                        <option value="agent">وكيل</option>
                                        <option value="branch">فرع</option>
                                        <option value="general">حساب عام</option>
                                    </select>
                                </div>
                                <div class="col-md-7">
                                    <label class="form-label small fw-bold">رقم الحساب</label>
                                    <input type="text" id="edit_to_account_code" class="form-control rounded-3 bg-light border-0" readonly>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">الحساب</label>
                                <select id="edit_to_account_id" name="to_account_id" class="form-select rounded-3" required onchange="updateAccountCodeEdit('to')">
                                    <option value="">اختر الحساب...</option>
                                </select>
                            </div>

                            <div class="row g-2 mb-4">
                                <div class="col-6">
                                    <label class="form-label small fw-bold">العملة</label>
                                    <select id="edit_to_currency_id" name="to_currency_id" class="form-select rounded-3" required>
                                        <?php foreach ($currencies as $curr): ?>
                                            <option value="<?php echo $curr['id']; ?>"><?php echo $curr['currency_name']; ?> (<?php echo $curr['currency_code']; ?>)</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold">المبلغ المشترى</label>
                                    <input type="number" step="0.01" id="edit_to_amount" name="to_amount" class="form-control rounded-3 border-success" required>
                                </div>
                            </div>
                        </div>
                    </div>

                    <hr class="my-3">

                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">رقم العملية</label>
                            <input type="text" id="edit_transaction_number" class="form-control rounded-3 bg-light border-0" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">تاريخ العملية</label>
                            <input type="date" id="edit_date" name="date" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">سعر التحويل</label>
                            <input type="number" step="0.000001" id="edit_exchange_rate" name="exchange_rate" class="form-control rounded-3" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">ملاحظات</label>
                            <input type="text" id="edit_notes" name="notes" class="form-control rounded-3">
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer bg-light border-0 px-4 py-3">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">إلغاء</button>
                <button type="button" class="btn btn-warning rounded-pill px-5 fw-bold shadow-sm" onclick="updateExchange()">
                    <i class="fas fa-save me-1"></i> حفظ التعديلات
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const CSRF_TOKEN = <?php echo json_encode(generate_csrf_token()); ?>;
    // مخزن للحسابات المحملة لتسهيل استرجاع الكود
    let accountsData = {
        from: [],
        to: []
    };

    function filterCurrencies(accountId, targetSelectId) {
        if (!accountId) {
            $(`#${targetSelectId} option`).show();
            return;
        }
        $.get('ajax/get_account_currencies.php', { account_id: accountId }, function(res) {
            const $select = $(`#${targetSelectId}`);
            const selectedVal = $select.val();
            const currencies = res.currencies || [];
            
            $select.find('option').each(function() {
                const optVal = $(this).val();
                if (optVal === "") return;
                
                const found = currencies.find(c => c.id == optVal);
                if (found) {
                    $(this).show();
                } else {
                    $(this).hide();
                    if (selectedVal == optVal) $select.val('').trigger('change');
                }
            });
            
            if (currencies.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'تنبيه',
                    text: 'هذا الحساب لا يمتلك أي عملات مفعلة. يرجى تفعيل العملات له أولاً.'
                });
            }
        });
    }

    // تحديث رقم الحساب عند الاختيار
    function updateAccountCode(side) {
        const select = document.getElementById(side + '_account_id');
        const codeInput = document.getElementById(side + '_account_code');
        const selectedId = select.value;

        const account = accountsData[side].find(acc => acc.id == selectedId);
        if (account) {
            codeInput.value = account.code;
            filterCurrencies(selectedId, side + '_currency_id');
        } else {
            codeInput.value = '';
        }
    }

    // تحديث رقم الحساب في مودال التعديل
    function updateAccountCodeEdit(side) {
        const select = document.getElementById(`edit_${side}_account_id`);
        const codeInput = document.getElementById(`edit_${side}_account_code`);
        const selectedId = select.value;

        const account = accountsData[side].find(acc => acc.id == selectedId);
        if (account) {
            codeInput.value = account.code;
        } else {
            codeInput.value = '';
        }
    }

    // البحث الديناميكي في الجدول
    document.getElementById('dynamicSearch').addEventListener('keyup', function() {
        const value = this.value.toLowerCase();
        const rows = document.querySelectorAll('#exchangesTable .exchange-row');

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(value) ? '' : 'none';
        });
    });

    // تحديث رصيد الحساب المصدر
    function updateFromBalance() {
        const accountId = document.getElementById('from_account_id').value;
        const currencyId = document.getElementById('from_currency_id').value;
        const balanceDiv = document.getElementById('from_account_balance');

        if (accountId && currencyId) {
            balanceDiv.classList.remove('d-none');
            balanceDiv.innerHTML = '<span class="badge badge-soft-primary small">جاري تحميل الرصيد...</span>';

            fetch(`ajax_exchange_helper.php?action=get_account_balance_by_currency&account_id=${accountId}&currency_id=${currencyId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.formatted) {
                        let badgeClass = 'badge-soft-primary';
                        if (data.balance > 0) badgeClass = 'badge-soft-success';
                        else if (data.balance < 0) badgeClass = 'badge-soft-danger';

                        balanceDiv.innerHTML = `<span class="badge ${badgeClass} small w-100 py-2">الرصيد: ${data.formatted}</span>`;
                    } else {
                        balanceDiv.innerHTML = '<span class="badge badge-soft-secondary small w-100 py-2">الرصيد: 0.00</span>';
                    }
                })
                .catch(err => {
                    console.error('Error fetching balance:', err);
                    balanceDiv.innerHTML = '<span class="badge badge-soft-danger small w-100 py-2">خطأ في جلب الرصيد</span>';
                });
        } else {
            balanceDiv.classList.add('d-none');
        }
    }

    // تحميل الحسابات ديناميكياً
    function loadAccounts(side) {
        const type = document.getElementById(side + '_account_type').value;
        const select = document.getElementById(side + '_account_id');
        const codeInput = document.getElementById(side + '_account_code');

        if (!type) {
            select.innerHTML = '<option value="">اختر نوع الحساب أولاً</option>';
            select.disabled = true;
            codeInput.value = '';
            if (side === 'from') document.getElementById('from_account_balance').classList.add('d-none');
            return;
        }

        select.innerHTML = '<option value="">جاري التحميل...</option>';
        select.disabled = false;
        codeInput.value = '';

        fetch('ajax_exchange_helper.php?action=get_accounts_by_type&type=' + type)
            .then(response => response.json())
            .then(data => {
                accountsData[side] = data; // تخزين البيانات
                let options = '<option value="">اختر الحساب...</option>';
                data.forEach(acc => {
                    options += `<option value="${acc.id}">${acc.name}</option>`;
                });

                if (typeof $ !== 'undefined' && $(select).hasClass('select2')) {
                    $(select).html(options).trigger('change');
                } else {
                    select.innerHTML = options;
                    updateAccountCode(side);
                    if (side === 'from') updateFromBalance();
                }
            })
            .catch(err => {
                console.error('Error loading accounts:', err);
                select.innerHTML = '<option value="">خطأ في التحميل</option>';
            });
    }

    // جلب سعر الصرف تلقائياً
    function getAutoRate() {
        const from = document.getElementById('from_currency_id').value;
        const to = document.getElementById('to_currency_id').value;
        const rateInput = document.getElementById('exchange_rate');
        const msg = document.getElementById('rate_msg');
        const btn = document.getElementById('btn_submit');

        if (from && to) {
            if (from === to) {
                rateInput.value = '1.000000';
                msg.innerHTML = '';
                btn.disabled = false;
                calculateToAmount();
                return;
            }

            fetch(`ajax_exchange_helper.php?action=get_exchange_rate&from=${from}&to=${to}&type=sell`)
                .then(response => response.json())
                .then(data => {
                    if (data.rate > 0) {
                        rateInput.value = data.rate.toFixed(6);
                        msg.innerHTML = '<i class="fas fa-check-circle text-success"></i> تم جلب السعر';
                        btn.disabled = false;
                        calculateToAmount();
                    } else {
                        rateInput.value = '';
                        msg.innerHTML = '<i class="fas fa-exclamation-triangle text-danger"></i> لا يوجد سعر صرف معرف';
                        btn.disabled = true;
                    }
                });
        }
    }

    // احتساب المبلغ المشترى
    function calculateToAmount() {
        const fromAmount = parseFloat(document.getElementById('from_amount').value);
        const rate = parseFloat(document.getElementById('exchange_rate').value);
        const toInput = document.getElementById('to_amount');

        if (fromAmount > 0 && rate > 0) {
            toInput.value = (fromAmount * rate).toFixed(2);
        }
    }

    // احتساب سعر الصرف من المبالغ (يدوي)
    function calculateRateFromAmount() {
        const fromAmount = parseFloat(document.getElementById('from_amount').value);
        const toAmount = parseFloat(document.getElementById('to_amount').value);
        const rateInput = document.getElementById('exchange_rate');

        if (fromAmount > 0 && toAmount > 0) {
            rateInput.value = (toAmount / fromAmount).toFixed(6);
        }
    }

    // تهيئة Select2 إذا كان موجوداً
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof $ !== 'undefined' && $('.select2').length > 0) {
            $('.select2').select2({
                theme: 'bootstrap-5'
            }).on('change', function() {
                const side = this.id.split('_')[0]; // 'from' or 'to'
                updateAccountCode(side);
                if (side === 'from') updateFromBalance();
            });
        }
    });

    // دوال التعديل والحذف
    let deleteId = null;

    function editExchange(id) {
        // جلب بيانات العملية للتعديل
        fetch(`ajax_exchange_helper.php?action=get_exchange&id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const ex = data.exchange;

                    // ملء النموذج
                    document.getElementById('edit_id').value = ex.id;
                    document.getElementById('edit_date').value = ex.transaction_date;
                    document.getElementById('edit_transaction_number').value = ex.transaction_number;
                    document.getElementById('edit_transaction_number_title').innerText = ex.transaction_number;
                    document.getElementById('edit_from_account_type').value = ex.from_account_type;
                    document.getElementById('edit_to_account_type').value = ex.to_account_type;
                    document.getElementById('edit_from_currency_id').value = ex.from_currency_id;
                    document.getElementById('edit_to_currency_id').value = ex.to_currency_id;
                    document.getElementById('edit_from_amount').value = ex.from_amount;
                    document.getElementById('edit_to_amount').value = ex.to_amount;
                    document.getElementById('edit_exchange_rate').value = ex.exchange_rate;
                    document.getElementById('edit_notes').value = ex.notes || '';

                    // تحميل الحسابات
                    loadAccountsForEdit('from');
                    loadAccountsForEdit('to');

                    // تعيين الحسابات المحددة بعد تحميلها
                    setTimeout(() => {
                        document.getElementById('edit_from_account_id').value = ex.from_account_id;
                        document.getElementById('edit_to_account_id').value = ex.to_account_id;
                        updateAccountCodeEdit('from');
                        updateAccountCodeEdit('to');
                    }, 800);

                    // إظهار المودال
                    const modal = new bootstrap.Modal(document.getElementById('editModal'));
                    modal.show();
                } else {
                    alert('خطأ في جلب بيانات العملية: ' + data.message);
                }
            })
            .catch(err => {
                console.error('Error:', err);
                alert('خطأ في الاتصال بالخادم');
            });
    }

    function loadAccountsForEdit(side) {
        const type = document.getElementById(`edit_${side}_account_type`).value;
        const select = document.getElementById(`edit_${side}_account_id`);

        if (!type) {
            select.innerHTML = '<option value="">اختر نوع الحساب أولاً</option>';
            return;
        }

        select.innerHTML = '<option value="">جاري التحميل...</option>';

        fetch(`ajax_exchange_helper.php?action=get_accounts_by_type&type=${type}`)
            .then(response => response.json())
            .then(data => {
                let options = '<option value="">اختر الحساب...</option>';
                data.forEach(acc => {
                    options += `<option value="${acc.id}">${acc.name}</option>`;
                });
                select.innerHTML = options;
            })
            .catch(err => {
                console.error('Error loading accounts:', err);
                select.innerHTML = '<option value="">خطأ في التحميل</option>';
            });
    }

    function updateExchange() {
        const formData = new FormData(document.getElementById('editForm'));
        const data = Object.fromEntries(formData);

        // التحقق من صحة البيانات
        if (!data.date || !data.from_account_id || !data.to_account_id || !data.from_amount || !data.to_amount || !data.exchange_rate) {
            alert('يرجى ملء جميع الحقول المطلوبة');
            return;
        }

        if (data.from_account_id === data.to_account_id && data.from_currency_id === data.to_currency_id) {
            alert('لا يمكن إجراء عملية تصريف لنفس الحساب ونفس العملة');
            return;
        }

        // إرسال البيانات للتحديث
        fetch('ajax_exchange_helper.php?action=update_exchange', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN,
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    Swal.fire({
                        title: 'تم بنجاح!',
                        text: result.message,
                        icon: 'success',
                        confirmButtonText: 'موافق'
                    }).then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire({
                        title: 'خطأ!',
                        text: result.message,
                        icon: 'error',
                        confirmButtonText: 'موافق'
                    });
                }
            })
            .catch(err => {
                console.error('Error:', err);
                Swal.fire('خطأ!', 'خطأ في الاتصال بالخادم', 'error');
            });
    }

    function deleteExchange(id, transactionNumber) {
        Swal.fire({
            title: 'هل أنت متأكد؟',
            text: `سيتم حذف العملية رقم ${transactionNumber} وجميع قيودها المحاسبية وتحديث أرصدة الحسابات!`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'نعم، احذفها!',
            cancelButtonText: 'إلغاء'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(`ajax_exchange_helper.php?action=delete_exchange&id=${id}`, {
                        method: 'DELETE',
                        headers: {
                            'X-CSRF-Token': CSRF_TOKEN,
                        }
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            Swal.fire('تم الحذف!', result.message, 'success').then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire('خطأ!', result.message, 'error');
                        }
                    })
                    .catch(err => {
                        console.error('Error:', err);
                        Swal.fire('خطأ!', 'خطأ في الاتصال بالخادم', 'error');
                    });
            }
        });
    }
    <?php if (!empty($_GET['edit_id'])): ?>
        document.addEventListener('DOMContentLoaded', function() {
            editExchange(<?php echo (int)$_GET['edit_id']; ?>);
        });
    <?php endif; ?>
</script>

<style>
    .section-title {
        position: relative;
    }

    .section-title::after {
        content: "";
        position: absolute;
        right: 0;
        top: 50%;
        width: 100%;
        height: 1px;
        background: #eee;
        z-index: -1;
    }

    .section-title span {
        background: #fff;
    }

    .extra-small {
        font-size: 0.7rem;
    }

    .form-select,
    .form-control {
        border-color: #dee2e6;
    }

    .form-select:focus,
    .form-control:focus {
        border-color: #3d8bfd;
        box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.05);
    }
</style>

<?php require_once 'footer.php'; ?>
