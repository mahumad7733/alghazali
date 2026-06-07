<?php
// =====================================================
// account_currencies.php - إدارة العملات المسموحة لكل حساب
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

if (!$is_admin && !has_permission('manage_financial_accounts')) {
    header('Location: index.php?error=no_permission');
    exit();
}

$selected_account_id = (int)($_GET['account_id'] ?? 0);

if ($selected_account_id <= 0) {
    header('Location: financial_accounts.php');
    exit();
}

// جلب معلومات الحساب
$stmt_acc = $pdo->prepare("SELECT id, account_code, account_name_ar as account_name FROM unified_accounts WHERE id = ?");
$stmt_acc->execute([$selected_account_id]);
$account = $stmt_acc->fetch();

if (!$account) {
    header('Location: financial_accounts.php?error=account_not_found');
    exit();
}

// معالجة إضافة عملة مسموحة
if (isset($_POST['add_currency'])) {
    $currency_id = (int)$_POST['currency_id'];
    $is_default = isset($_POST['is_default']) ? 1 : 0;
    $exchange_rate = $_POST['exchange_rate'] ?: null;

    try {
        if ($is_default) {
            // إلغاء الافتراضي عن العملات الأخرى لهذا الحساب
            $pdo->prepare("UPDATE account_allowed_currencies SET is_default = 0 WHERE account_id = ?")->execute([$selected_account_id]);
        }

        $stmt = $pdo->prepare("
            INSERT INTO account_allowed_currencies (account_id, currency_id, is_default, exchange_rate, created_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE is_default = ?, exchange_rate = ?
        ");
        $stmt->execute([$selected_account_id, $currency_id, $is_default, $exchange_rate, $_SESSION['admin_id'], $is_default, $exchange_rate]);

        $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تمت إضافة العملة بنجاح'];
    } catch (Exception $e) {
        $_SESSION['flash_message'] = ['type' => 'danger', 'body' => 'خطأ: ' . $e->getMessage()];
    }
    header("Location: account_currencies.php?account_id={$selected_account_id}");
    exit();
}

// معالجة حذف عملة مسموحة
if (isset($_GET['delete_currency'])) {
    $aac_id = (int)$_GET['delete_currency'];
    $pdo->prepare("DELETE FROM account_allowed_currencies WHERE id = ? AND account_id = ?")->execute([$aac_id, $selected_account_id]);
    $_SESSION['flash_message'] = ['type' => 'success', 'body' => 'تم حذف العملة من القائمة المسموحة'];
    header("Location: account_currencies.php?account_id={$selected_account_id}");
    exit();
}

// جلب جميع العملات المتاحة
$all_currencies = $pdo->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY currency_name")->fetchAll();

// جلب العملات المسموحة لهذا الحساب
$allowed_currencies = get_account_allowed_currencies($selected_account_id);

$page_title = "إدارة عملات الحساب";
require_once 'header.php';
?>

<div class="container-fluid py-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h3 class="fw-bold text-primary">
                    <i class="fas fa-coins me-2"></i>
                    إدارة عملات الحساب: <?php echo $account['account_name_ar']; ?>
                </h3>
                <a href="account_limits.php?account_id=<?php echo $selected_account_id; ?>" class="btn btn-outline-primary rounded-pill px-4">
                    <i class="fas fa-sliders-h me-2"></i> إدارة الحدود
                </a>
            </div>

            <?php if (isset($_SESSION['flash_message'])): ?>
                <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show rounded-4 shadow-sm border-0">
                    <?php echo $_SESSION['flash_message']['body']; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['flash_message']); ?>
            <?php endif; ?>

            <div class="card border-0 shadow-sm rounded-4 mb-4">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <h6 class="fw-bold mb-0"><i class="fas fa-plus-circle me-2 text-primary"></i> إضافة عملة مسموحة جديدة</h6>
                </div>
                <div class="card-body p-4">
                    <form method="POST" class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label small fw-bold">اختر العملة</label>
                            <select name="currency_id" class="form-select rounded-3" required>
                                <option value="">اختر العملة...</option>
                                <?php foreach ($all_currencies as $cur): ?>
                                    <option value="<?php echo $cur['id']; ?>"><?php echo $cur['currency_name']; ?> (<?php echo $cur['currency_code']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-bold">سعر صرف مخصص (اختياري)</label>
                            <input type="number" step="0.0001" name="exchange_rate" class="form-control rounded-3" placeholder="اتركه فارغاً للافتراضي">
                        </div>
                        <div class="col-md-2">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="is_default" id="is_default">
                                <label class="form-check-label small fw-bold" for="is_default">عملة افتراضية</label>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" name="add_currency" class="btn btn-primary w-100 rounded-3">
                                <i class="fas fa-plus me-1"></i> إضافة
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card border-0 shadow-sm rounded-4">
                <div class="card-header bg-white border-bottom-0 pt-4 px-4">
                    <h6 class="fw-bold mb-0"><i class="fas fa-list me-2 text-primary"></i> قائمة العملات المسموحة</h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light">
                                <tr>
                                    <th class="ps-4">العملة</th>
                                    <th>الكود</th>
                                    <th>سعر الصرف المخصص</th>
                                    <th class="text-center">الحالة</th>
                                    <th class="text-end pe-4">إجراءات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($allowed_currencies as $cur): ?>
                                    <tr>
                                        <td class="ps-4 fw-bold"><?php echo $cur['currency_name']; ?></td>
                                        <td><span class="badge bg-light text-dark"><?php echo $cur['currency_code']; ?></span></td>
                                        <td><?php echo $cur['custom_rate'] ? number_format($cur['custom_rate'], 4) : '<span class="text-muted small">افتراضي النظام</span>'; ?></td>
                                        <td class="text-center">
                                            <?php if ($cur['is_default']): ?>
                                                <span class="badge bg-success rounded-pill px-3">افتراضية الحساب</span>
                                            <?php else: ?>
                                                <span class="text-muted small">إضافية</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end pe-4">
                                            <a href="?account_id=<?php echo $selected_account_id; ?>&delete_currency=<?php echo $cur['id']; ?>"
                                                class="btn btn-sm btn-outline-danger rounded-3"
                                                onclick="return confirm('هل أنت متأكد من حذف هذه العملة من القائمة المسموحة؟')">
                                                <i class="fas fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($allowed_currencies)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center py-5 text-muted">
                                            <i class="fas fa-info-circle fa-2x mb-3 d-block"></i>
                                            لا توجد عملات مخصصة لهذا الحساب. النظام سيعتمد العملات المفعلة عالمياً.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-4 text-center">
                <a href="financial_accounts.php" class="btn btn-light rounded-pill px-4 text-muted small">
                    <i class="fas fa-arrow-right me-2"></i> العودة لقائمة الحسابات
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
