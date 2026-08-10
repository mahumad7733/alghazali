<?php
ob_start();
require_once 'header.php';

// التحقق من الصلاحيات
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$success_msg = "";
$error_msg = "";

// إضافة دفعة جديدة
if (isset($_POST['add_payment'])) {
    try {
        $branch_id = $_POST['branch_id'];
        $type = $_POST['payment_type'];
        $date = $_POST['payment_date'];
        $entity_type = $_POST['party_type'];
        $entity_id = $_POST['party_id'];
        $party_account_id = !empty($_POST['party_account_id']) ? $_POST['party_account_id'] : null;
        $cash_bank_account_id = $_POST['cash_bank_account_id'];
        $currency_id = $_POST['currency_id'];
        $amount = $_POST['amount'];
        $description = $_POST['description'];
        $cost_center_id = !empty($_POST['cost_center_id']) ? $_POST['cost_center_id'] : null;
        $created_by = $_SESSION['admin_id'];

        $res = \Core\Finance\FinancePostingAdapter::createVoucherAndPost(
            $pdo, $type, $branch_id, $entity_type, $entity_id,
            $amount, $currency_id, $cash_bank_account_id, $party_account_id,
            $description, null, null, $cost_center_id
        );

        if ($res) {
            header("Location: unified_payments.php?success=1");
            exit;
        } else {
            $error_msg = "حدث خطأ أثناء معالجة السند";
        }
    } catch (Exception $e) {
        $error_msg = "خطأ في إنشاء السند: " . $e->getMessage();
    }
}

// جلب السندات من الجدول الموحد الجديد
$query = "SELECT t.*,
                 c.currency_symbol, u.username as creator_name,
                 COALESCE(b.branch_name, 'الفرع الرئيسي') as branch_name,
                 (SELECT account_name_ar FROM unified_accounts WHERE id = t.cash_bank_account_id) as cash_bank_name
          FROM financial_transactions t
          JOIN currencies c ON t.currency_id = c.id
          JOIN users u ON t.created_by = u.id
          LEFT JOIN branches b ON t.branch_id = b.id
          WHERE t.transaction_type IN ('receipt', 'payment', 'transfer')
          ORDER BY t.created_at DESC";
try {
    $payments = $pdo->query($query)->fetchAll();
} catch (PDOException $e) {
    $payments = [];
    $error_msg = "يرجى تحديث قاعدة البيانات لتفعيل نظام السندات الموحد.";
}

// جلب البيانات للقوائم المنسدلة
$branches = $pdo->query("SELECT id, branch_name FROM branches")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol FROM currencies")->fetchAll();
// جلب الصناديق والبنوك من شجرة الحسابات الموحدة (أكواد 101 و 102)
$cash_bank_accounts = $pdo->query("SELECT id, account_name_ar FROM unified_accounts WHERE account_code LIKE '101%' OR account_code LIKE '102%' AND is_active = 1")->fetchAll();

?>

<div class="container-fluid py-4">
    <div class="row mb-4 align-items-center">
        <div class="col">
            <h3 class="fw-bold text-dark mb-1"><i class="fas fa-money-check-alt me-2 text-primary"></i> المدفوعات الموحدة</h3>
            <p class="text-muted small mb-0">سندات قبض، صرف، وتحويلات مالية</p>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary shadow-sm rounded-pill px-4 fw-bold" data-bs-toggle="modal" data-bs-target="#addPaymentModal">
                <i class="fas fa-plus me-2"></i> إضافة سند جديد
            </button>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-4 mb-4"><i class="fas fa-check-circle me-2"></i> تم إنشاء السند والترحيل المحاسبي بنجاح!</div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-4 mb-4"><?php echo $error_msg; ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="px-4 py-3">رقم السند</th>
                            <th class="py-3">التاريخ</th>
                            <th class="py-3">النوع</th>
                            <th class="py-3">الفرع</th>
                            <th class="py-3">الصندوق/البنك</th>
                            <th class="py-3 text-end">المبلغ</th>
                            <th class="py-3">البيان</th>
                            <th class="py-3 text-center">الحالة</th>
                            <th class="px-4 py-3 text-center">الإجراءات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td class="px-4 fw-bold text-primary"><?php echo $p['transaction_number']; ?></td>
                                <td><?php echo $p['transaction_date']; ?></td>
                                <td>
                                    <?php if ($p['transaction_type'] == 'receipt'): ?>
                                        <span class="badge bg-success-subtle text-success rounded-pill px-3">سند قبض</span>
                                    <?php elseif ($p['transaction_type'] == 'payment'): ?>
                                        <span class="badge bg-danger-subtle text-danger rounded-pill px-3">سند صرف</span>
                                    <?php else: ?>
                                        <span class="badge bg-info-subtle text-info rounded-pill px-3">تحويل مالي</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($p['branch_name']); ?></td>
                                <td><span class="small"><?php echo htmlspecialchars($p['cash_bank_name'] ?? '-'); ?></span></td>
                                <td class="text-end fw-bold <?php echo $p['transaction_type'] == 'receipt' ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo number_format($p['amount'], 2); ?> <small><?php echo $p['currency_symbol']; ?></small>
                                </td>
                                <td><span class="small text-muted"><?php echo htmlspecialchars(mb_strimwidth($p['description'], 0, 40, "...")); ?></span></td>
                                <td class="text-center">
                                    <?php if($p['status'] == 'posted'): ?>
                                        <span class="badge bg-success rounded-pill px-3">مرحل</span>
                                    <?php elseif($p['status'] == 'cancelled'): ?>
                                        <span class="badge bg-danger rounded-pill px-3">ملغى</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark rounded-pill px-3">مسودة</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 text-center">
                                    <a href="payments_print.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-dark rounded-pill px-3" target="_blank">
                                        <i class="fas fa-print"></i> طباعة
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($payments)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fas fa-history fa-3x mb-3 d-block"></i>
                                    لا توجد سندات مسجلة حالياً
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal إضافة سند -->
<div class="modal fade" id="addPaymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <form method="POST">
                <div class="modal-header border-0 bg-primary text-white py-3">
                    <h5 class="modal-title fw-bold"><i class="fas fa-plus-circle me-2"></i> إنشاء سند مالي جديد</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted">نوع السند</label>
                            <select name="payment_type" class="form-select rounded-3 border-light bg-light" required>
                                <option value="receipt">سند قبض (استلام)</option>
                                <option value="payment">سند صرف (دفع)</option>
                                <option value="bank_transfer">تحويل بنكي</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted">الفرع</label>
                            <select name="branch_id" class="form-select rounded-3 border-light bg-light" required>
                                <?php foreach ($branches as $b): ?>
                                    <option value="<?php echo $b['id']; ?>"><?php echo $b['branch_name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted">تاريخ السند</label>
                            <input type="date" name="payment_date" class="form-control rounded-3 border-light bg-light" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">الجهة (من / إلى)</label>
                            <div class="input-group">
                                <select name="party_type" id="party_type" class="form-select rounded-start-3 border-light bg-light" style="max-width: 120px;" required>
                                    <option value="customer">عميل</option>
                                    <option value="agent">وكيل</option>
                                    <option value="branch">فرع</option>
                                    <option value="supplier">مورد</option>
                                    <option value="employee">موظف</option>
                                </select>
                                <select name="party_id" id="party_id" class="form-select border-light bg-light" required>
                                    <option value="">اختر الطرف...</option>
                                    <!-- سيتم تحميل الأطراف عبر AJAX أو تحميلها مسبقاً -->
                                </select>
                                <input type="hidden" name="party_account_id" id="party_account_id">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">الصندوق / البنك</label>
                            <select name="cash_bank_account_id" class="form-select rounded-3 border-light bg-light" required>
                                <option value="">اختر الصندوق أو البنك...</option>
                                <?php foreach ($cash_bank_accounts as $acc): ?>
                                    <option value="<?php echo $acc['id']; ?>"><?php echo $acc['account_name_ar']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted">العملة</label>
                            <select name="currency_id" class="form-select rounded-3 border-light bg-light" required>
                                <?php foreach ($currencies as $curr): ?>
                                    <option value="<?php echo $curr['id']; ?>"><?php echo $curr['currency_name']; ?> (<?php echo $curr['currency_symbol']; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-bold small text-muted">المبلغ</label>
                            <input type="number" step="0.01" name="amount" class="form-control rounded-3 border-light bg-light fw-bold fs-5 text-center" placeholder="0.00" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">مركز التكلفة (اختياري)</label>
                            <select name="cost_center_id" class="form-select rounded-3 border-light bg-light">
                                <option value="">-- بدون مركز تكلفة --</option>
                                <?php 
                                $all_ccs = $pdo->query("SELECT id, center_name_ar FROM cost_centers ORDER BY center_code")->fetchAll();
                                foreach($all_ccs as $cc): 
                                ?>
                                    <option value="<?php echo $cc['id']; ?>"><?php echo $cc['center_name_ar']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted">البيان (الوصف)</label>
                            <textarea name="description" class="form-control rounded-3 border-light bg-light" rows="1" placeholder="اكتب تفاصيل السند هنا..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-0 bg-light py-3">
                    <button type="button" class="btn btn-link text-muted fw-bold text-decoration-none" data-bs-dismiss="modal">إلغاء</button>
                    <button type="submit" name="add_payment" class="btn btn-primary px-5 shadow rounded-pill fw-bold">حفظ وترحيل السند</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    // سكربت بسيط لتحديث قائمة الأطراف (يمكن تطويره ليكون AJAX)
    document.getElementById('party_type').addEventListener('change', function() {
        const type = this.value;
        const partySelect = document.getElementById('party_id');
        const partyAccountIdInput = document.getElementById('party_account_id');

        // مسح الخيارات الحالية
        partySelect.innerHTML = '<option value="">جاري التحميل...</option>';

        fetch(`ajax_get_accounts_for_filter.php?type=${type}`)
            .then(response => response.json())
            .then(data => {
                partySelect.innerHTML = '<option value="">اختر الطرف...</option>';
                data.forEach(item => {
                    const option = document.createElement('option');
                    option.value = item.id;
                    option.textContent = item.name;
                    option.dataset.accountId = item.account_id;
                    partySelect.appendChild(option);
                });
            });
    });

    document.getElementById('party_id').addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        document.getElementById('party_account_id').value = selectedOption.dataset.accountId || '';
    });
</script>

<?php require_once 'footer.php'; ?>
