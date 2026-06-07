<?php
/**
 * financial_fields.php — الحقول المالية الموحدة (مطابق لمنطق invoices.php)
 *
 * متغيرات اختيارية قبل التضمين:
 * @var array  $current_invoice
 * @var string $financial_fields_prefix          بادئة معرفات الحقول (مثلاً edit_)
 * @var string $financial_fields_api_url         مسار API (افتراضي: invoices.php)
 * @var string $financial_fields_select2_parent  محدد jQuery لـ dropdownParent (مثلاً #addUmrahModal)
 * @var string $financial_fields_form_selector   محدد النموذج للتحقق قبل الإرسال
 * @var bool   $financial_fields_manual_init   true لتعطيل التهيئة التلقائية
 * @var bool   $financial_fields_show_service_select  false لإخفاء قائمة الخدمة واستخدام source_type مخفي
 */

if (!isset($pdo)) {
    return;
}

if (!function_exists('ff_h')) {
    function ff_h($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

$ff_prefix = $financial_fields_prefix ?? '';
$ff_api_url = $financial_fields_api_url ?? 'invoices.php';
$ff_select2_parent = $financial_fields_select2_parent ?? null;
$ff_form_selector = $financial_fields_form_selector ?? null;
$ff_manual_init = $financial_fields_manual_init ?? false;
$ff_show_service_select = $financial_fields_show_service_select ?? null;

if (!isset($current_invoice) || !is_array($current_invoice)) {
    $current_invoice = [];
}

$ff_source_type = $current_invoice['source_type'] ?? 'general';
if ($ff_show_service_select === null) {
    $ff_show_service_select = ($ff_source_type === '' || $ff_source_type === 'general');
}

// --- تحميل البيانات (نفس invoices.php) ---
if (!isset($settings)) {
    $settings = getSettings($pdo);
}

if (!isset($base_currency)) {
    $base_currency = $pdo->query("SELECT * FROM currencies WHERE is_default = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
}

if (!isset($currencies)) {
    $currencies = $pdo->query(
        "SELECT id, currency_name, currency_symbol, exchange_rate, exchange_rate_buy, exchange_rate_sell, is_default
         FROM currencies ORDER BY is_default DESC, currency_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

if (!isset($branches)) {
    $branches = $pdo->query(
        "SELECT id, branch_name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY branch_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

if (!isset($services)) {
    $services = $pdo->query(
        "SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

if (!function_exists('ff_normalize_suppliers_list')) {
    /**
     * توحيد مفاتيح الموردين (invoices.php يستخدم id، بعض الصفحات تمرّر account_id)
     */
    function ff_normalize_suppliers_list(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (empty($row['id']) && !empty($row['account_id'])) {
                $row['id'] = $row['account_id'];
            }
            if (empty($row['display_name'])) {
                $code = $row['account_code'] ?? '';
                $name = $row['account_name_ar'] ?? $row['supplier_name'] ?? '';
                $row['display_name'] = $code !== '' ? ($code . ' - ' . $name) : $name;
            }
            $normalized[] = $row;
        }
        return $normalized;
    }
}

if (!isset($suppliers_with_codes)) {
    $parent_stmt_suppliers = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
    $parent_stmt_suppliers->execute();
    $suppliers_parent_id = $parent_stmt_suppliers->fetchColumn();

    $suppliers_with_codes = [];
    if ($suppliers_parent_id) {
        $suppliers_stmt = $pdo->prepare("
            SELECT coa.*,
                   (SELECT id FROM suppliers WHERE account_id = coa.id LIMIT 1) as supplier_id
            FROM unified_accounts coa
            WHERE coa.parent_id = ? AND coa.account_status = 'active'
            ORDER BY coa.account_code ASC
        ");
        $suppliers_stmt->execute([$suppliers_parent_id]);
        while ($row = $suppliers_stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['display_name'] = ($row['account_code'] ?? '') . ' - ' . ($row['account_name_ar'] ?? '');
            $suppliers_with_codes[] = $row;
        }
    }
}
$suppliers_with_codes = ff_normalize_suppliers_list($suppliers_with_codes ?? []);

if (!function_exists('get_accounts_under_parent')) {
    function get_accounts_under_parent($pdo, $parent_account_code, $entity_type = null)
    {
        $stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $stmt_parent->execute([$parent_account_code]);
        $parent_id = $stmt_parent->fetchColumn();
        if (!$parent_id) {
            return [];
        }

        $stmt = $pdo->prepare("
            SELECT ua.id, ua.account_code, ua.account_name_ar,
                   (SELECT id FROM customers WHERE account_id = ua.id LIMIT 1) as customer_id,
                   (SELECT id FROM agents WHERE account_id = ua.id LIMIT 1) as agent_id,
                   (SELECT id FROM suppliers WHERE account_id = ua.id LIMIT 1) as supplier_id
            FROM unified_accounts ua
            WHERE ua.parent_id = ? AND ua.account_status = 'active'
            ORDER BY ua.account_code ASC
        ");
        $stmt->execute([$parent_id]);
        $accounts = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['display_name'] = ($row['account_code'] ?? '') . ' - ' . ($row['account_name_ar'] ?? '');
            $row['name'] = $row['account_name_ar'] ?? '';
            $accounts[] = $row;
        }
        return $accounts;
    }
}

if (!isset($cashboxes_entities)) {
    $cashboxes_entities = get_accounts_under_parent($pdo, '11101');
}
if (!isset($banks_entities)) {
    $banks_entities = get_accounts_under_parent($pdo, '11102');
}
if (!isset($customers_entities)) {
    $customers_entities = get_accounts_under_parent($pdo, '11201');
}
if (!isset($agents_entities)) {
    $agents_entities = get_accounts_under_parent($pdo, '11203');
}

if (!isset($service_configs)) {
    $service_configs = [];
    foreach ($services as $s) {
        $cfg = getServiceInvoiceConfig($s['service_name'], $settings);
        $revenue_acc_name = '';
        if (!empty($cfg['revenue_account_id'])) {
            $stmt = $pdo->prepare("SELECT account_code, account_name_ar FROM unified_accounts WHERE id = ?");
            $stmt->execute([$cfg['revenue_account_id']]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($acc) {
                $revenue_acc_name = $acc['account_code'] . ' - ' . $acc['account_name_ar'];
            }
        }
        $cost_acc_name = '';
        if (!empty($cfg['cost_account_id'])) {
            $stmt = $pdo->prepare("SELECT account_code, account_name_ar FROM unified_accounts WHERE id = ?");
            $stmt->execute([$cfg['cost_account_id']]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($acc) {
                $cost_acc_name = $acc['account_code'] . ' - ' . $acc['account_name_ar'];
            }
        }
        $profit_acc_name = '';
        if (!empty($cfg['profit_account_id'])) {
            $stmt = $pdo->prepare("SELECT account_code, account_name_ar FROM unified_accounts WHERE id = ?");
            $stmt->execute([$cfg['profit_account_id']]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($acc) {
                $profit_acc_name = $acc['account_code'] . ' - ' . $acc['account_name_ar'];
            }
        }
        $service_configs[$s['service_name']] = [
            'revenue_account_id' => $cfg['revenue_account_id'] ?? null,
            'revenue_account_name' => $revenue_acc_name,
            'cost_account_id' => $cfg['cost_account_id'] ?? null,
            'cost_account_name' => $cost_acc_name,
            'profit_account_id' => $cfg['profit_account_id'] ?? null,
            'profit_account_name' => $profit_acc_name,
        ];
    }
}

$default_currency_id = 1;
foreach ($currencies as $curr) {
    if (!empty($curr['is_default'])) {
        $default_currency_id = (int)$curr['id'];
        break;
    }
}

$p = $ff_prefix;
$cid = static function ($name) use ($p) {
    return $p . $name;
};

$val = static function ($key, $default = '') use ($current_invoice) {
    return $current_invoice[$key] ?? $default;
};
?>

<fieldset class="border p-3 mb-4 financial-fields-block" data-ff-prefix="<?php echo ff_h($p); ?>">
    <legend class="w-auto px-2">💰 البيانات المالية (الفاتورة)</legend>

    <!-- المعلومات الأساسية -->
    <div class="row g-3 mb-3">
        <div class="col-md-3">
            <label class="form-label small fw-bold text-muted">تاريخ الفاتورة</label>
            <input type="date" name="invoice_date" id="<?php echo ff_h($cid('invoice_date')); ?>" class="form-control"
                   value="<?php echo ff_h($val('invoice_date', date('Y-m-d'))); ?>" required>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-muted">الفرع المسؤول</label>
            <select name="branch_id" id="<?php echo ff_h($cid('branch_id')); ?>" class="form-control form-select" required>
                <option value="">-- اختر الفرع --</option>
                <?php foreach ($branches as $b): ?>
                    <option value="<?php echo ff_h($b['id'] ?? ''); ?>" <?php echo ((string)$val('branch_id') === (string)($b['id'] ?? '')) ? 'selected' : ''; ?>>
                        <?php echo ff_h($b['branch_name'] ?? ''); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($ff_show_service_select): ?>
            <div class="col-md-3">
                <label class="form-label small fw-bold text-muted">نوع الخدمة</label>
                <select name="source_type" id="<?php echo ff_h($cid('service_id')); ?>" class="form-select select2-financial">
                    <option value="general" <?php echo ($ff_source_type === 'general') ? 'selected' : ''; ?>>عام (General)</option>
                    <?php foreach ($services as $s): ?>
                        <option value="<?php echo ff_h($s['service_name']); ?>" data-id="<?php echo ff_h($s['id']); ?>"
                            <?php echo ($ff_source_type === $s['service_name']) ? 'selected' : ''; ?>>
                            <?php echo ff_h($s['service_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <input type="hidden" name="source_type" id="<?php echo ff_h($cid('source_type_hidden')); ?>" value="<?php echo ff_h($ff_source_type); ?>">
        <?php endif; ?>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-muted">نوع التوصيل</label>
            <select name="delivery_type" id="<?php echo ff_h($cid('delivery_type')); ?>" class="form-select" required>
                <option value="" <?php echo empty($val('delivery_type')) ? 'selected' : ''; ?> disabled>-- اختر النوع --</option>
                <option value="draft" <?php echo ($val('delivery_type') === 'draft') ? 'selected' : ''; ?>>📝 مسودة</option>
                <option value="cash" <?php echo ($val('delivery_type') === 'cash') ? 'selected' : ''; ?>>💵 نقد</option>
                <option value="credit" <?php echo ($val('delivery_type') === 'credit') ? 'selected' : ''; ?>>📅 آجل</option>
                <option value="bank_transfer" <?php echo ($val('delivery_type') === 'bank_transfer') ? 'selected' : ''; ?>>🏦 تحويل بنكي</option>
                <option value="agent" <?php echo ($val('delivery_type') === 'agent') ? 'selected' : ''; ?>>👤 وكيل</option>
            </select>
        </div>
    </div>

    <!-- الأطراف والحسابات -->
    <div class="row g-3 mb-3 p-3 bg-light rounded-4 border border-dashed">
        <div class="col-md-6">
            <label class="form-label small fw-bold text-muted" id="<?php echo ff_h($cid('account_label')); ?>">الحساب المتأثر</label>
            <select name="account_id" id="<?php echo ff_h($cid('account_select')); ?>" class="form-select select2-financial" required disabled>
                <option value="">-- اختر نوع التوصيل أولاً --</option>
            </select>
            <div id="<?php echo ff_h($cid('account_balance_info')); ?>" class="mt-2 p-2 rounded-3 bg-white border shadow-sm d-none" style="font-size: 0.8rem;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted"><i class="fas fa-wallet me-1"></i> صافي الرصيد الموحد:</span>
                    <span id="<?php echo ff_h($cid('unified_balance_display')); ?>" class="fw-bold"></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted"><i class="fas fa-shield-alt me-1"></i> الحد الائتماني:</span>
                    <span id="<?php echo ff_h($cid('unified_limit_display')); ?>" class="fw-bold text-danger"></span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label small fw-bold text-muted">المورد (جهة التكلفة)</label>
            <select name="supplier_id" id="<?php echo ff_h($cid('supplier_id')); ?>" class="form-select select2-financial">
                <option value="">-- اختر المورد --</option>
                <?php foreach ($suppliers_with_codes as $s): ?>
                    <?php
                    if (empty($s['supplier_id'])) {
                        continue;
                    }
                    $supplier_account_id = $s['id'] ?? $s['account_id'] ?? '';
                    ?>
                    <option value="<?php echo ff_h($s['supplier_id']); ?>" data-account="<?php echo ff_h($supplier_account_id); ?>"
                        <?php echo ((string)$val('supplier_id') === (string)$s['supplier_id']) ? 'selected' : ''; ?>>
                        <?php echo ff_h($s['display_name'] ?? ''); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <?php if (isset($settings['auto_invoice_generation']) && ($settings['auto_invoice_generation'] == '1' || $settings['auto_invoice_generation'] === true)): ?>
                <input type="hidden" name="record_purchase" id="<?php echo ff_h($cid('record_purchase')); ?>" value="1">
            <?php else: ?>
                <div class="mt-2 p-2 bg-primary bg-opacity-10 rounded-3 border border-primary border-opacity-25">
                    <label class="form-label extra-small fw-bold text-primary mb-1"><i class="fas fa-question-circle me-1"></i> هل تريد إنشاء فاتورة شراء للمورد؟</label>
                    <select name="record_purchase" id="<?php echo ff_h($cid('record_purchase')); ?>" class="form-select form-select-sm border-primary" required>
                        <option value="" disabled <?php echo ($val('record_purchase', '') === '') ? 'selected' : ''; ?>>-- يجب الاختيار --</option>
                        <option value="1" <?php echo ($val('record_purchase', '1') == '1') ? 'selected' : ''; ?>>نعم، تسجيل مديونية</option>
                        <option value="0" <?php echo ($val('record_purchase', '1') == '0') ? 'selected' : ''; ?>>لا، مبيعات فقط</option>
                    </select>
                </div>
            <?php endif; ?>

            <div id="<?php echo ff_h($cid('supplier_balance_info')); ?>" class="mt-2 p-2 rounded-3 bg-white border shadow-sm d-none" style="font-size: 0.8rem;">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted"><i class="fas fa-wallet me-1"></i> رصيد المكتب عند المورد:</span>
                    <span id="<?php echo ff_h($cid('supplier_unified_balance_display')); ?>" class="fw-bold"></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted"><i class="fas fa-shield-alt me-1"></i> الحد الدائن المسموح:</span>
                    <span id="<?php echo ff_h($cid('supplier_unified_limit_display')); ?>" class="fw-bold text-success"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- المبالغ والعملات -->
    <div class="row g-3 mb-3 p-3 bg-white border rounded-4 shadow-sm">
        <div class="col-md-2">
            <label class="form-label small fw-bold text-primary">إجمالي سعر البيع</label>
            <input type="number" step="0.01" name="total_amount" id="<?php echo ff_h($cid('total_amount')); ?>" class="form-control fw-bold text-primary" required
                   value="<?php echo ff_h($val('total_amount', 0)); ?>"
                   data-original-price="<?php echo ff_h($val('total_amount', 0)); ?>"
                   data-service-currency-id="<?php echo ff_h($val('sale_currency_id', $default_currency_id)); ?>">
            <div id="<?php echo ff_h($cid('sales_exchange_info')); ?>" class="extra-small text-muted mt-1" style="display: none;"></div>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold text-danger">مبلغ الخصم</label>
            <input type="number" step="0.01" name="discount" id="<?php echo ff_h($cid('discount')); ?>" class="form-control"
                   value="<?php echo ff_h($val('discount', 0)); ?>" data-original-discount="0">
        </div>
        <div class="col-md-3" id="<?php echo ff_h($cid('sale_currency_field')); ?>">
            <label class="form-label small fw-bold text-muted">عملة البيع</label>
            <select name="sale_currency_id" id="<?php echo ff_h($cid('sale_currency_id')); ?>" class="form-select">
                <?php foreach ($currencies as $curr): ?>
                    <option value="<?php echo ff_h($curr['id']); ?>"
                            data-symbol="<?php echo ff_h($curr['currency_symbol'] ?? ''); ?>"
                            data-buy="<?php echo ff_h($curr['exchange_rate_buy'] ?? 1); ?>"
                            data-sell="<?php echo ff_h($curr['exchange_rate_sell'] ?? 1); ?>"
                            data-rate="<?php echo ff_h($curr['exchange_rate'] ?? 1); ?>"
                        <?php echo ((string)$val('sale_currency_id', $default_currency_id) === (string)$curr['id']) ? 'selected' : ''; ?>>
                        <?php echo ff_h($curr['currency_name'] ?? ''); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-bold text-warning">سعر التكلفة</label>
            <input type="number" step="0.01" name="cost_amount" id="<?php echo ff_h($cid('cost_amount')); ?>" class="form-control fw-bold text-warning"
                   value="<?php echo ff_h($val('cost_amount', 0)); ?>"
                   data-original-cost="<?php echo ff_h($val('cost_amount', 0)); ?>"
                   data-cost-service-currency-id="<?php echo ff_h($val('currency_id', $default_currency_id)); ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-bold text-muted" id="<?php echo ff_h($cid('main_currency_label')); ?>">عملة التكلفة</label>
            <select name="currency_id" id="<?php echo ff_h($cid('main_currency_id')); ?>" class="form-select">
                <?php foreach ($currencies as $curr): ?>
                    <option value="<?php echo ff_h($curr['id']); ?>"
                            data-symbol="<?php echo ff_h($curr['currency_symbol'] ?? ''); ?>"
                            data-buy="<?php echo ff_h($curr['exchange_rate_buy'] ?? 1); ?>"
                            data-sell="<?php echo ff_h($curr['exchange_rate_sell'] ?? 1); ?>"
                            data-rate="<?php echo ff_h($curr['exchange_rate'] ?? 1); ?>"
                        <?php echo ((string)$val('currency_id', $default_currency_id) === (string)$curr['id']) ? 'selected' : ''; ?>>
                        <?php echo ff_h($curr['currency_name'] ?? ''); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- سعر الصرف والتكلفة المعادلة -->
    <div class="row g-3 mb-3" id="<?php echo ff_h($cid('exchange_rate_container')); ?>" style="display: none;">
        <div class="col-md-8">
            <div class="p-3 bg-light border border-dashed rounded-4">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted" id="<?php echo ff_h($cid('exchange_rate_label')); ?>">سعر الصرف</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white">1 <span class="<?php echo ff_h($p); ?>pur-symbol"></span> =</span>
                            <input type="number" step="0.000001" name="exchange_rate" id="<?php echo ff_h($cid('invoice_exchange_rate')); ?>"
                                   class="form-control text-center fw-bold"
                                   value="<?php echo ff_h($val('exchange_rate', '1.000000')); ?>">
                            <span class="input-group-text bg-white"><span class="<?php echo ff_h($p); ?>sale-symbol"></span></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-bold text-muted" id="<?php echo ff_h($cid('equivalent_cost_label')); ?>">التكلفة المعادلة</label>
                        <input type="text" id="<?php echo ff_h($cid('equivalent_cost_display')); ?>" class="form-control bg-white" readonly>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- المبلغ الواصل -->
    <div class="row g-3 mb-3">
        <div class="col-md-6" id="<?php echo ff_h($cid('received_amount_field')); ?>" style="display: none;">
            <label class="form-label small fw-bold text-muted">المبلغ الواصل (المقبوض)</label>
            <div class="input-group">
                <span class="input-group-text bg-light text-primary"><i class="fas fa-hand-holding-usd"></i></span>
                <input type="number" step="0.01" name="received_amount" id="<?php echo ff_h($cid('received_amount')); ?>"
                       class="form-control fw-bold border-primary text-primary" placeholder="0.00"
                       value="<?php echo ff_h($val('received_amount', $val('amount_received', 0))); ?>">
            </div>
        </div>
    </div>

    <!-- حسابات الخدمة -->
    <div class="row g-3 mb-3 p-3 bg-light rounded-4 border border-dashed">
        <div class="col-12 mb-2">
            <h6 class="fw-bold text-primary mb-0"><i class="fas fa-book me-2"></i> حسابات الخدمة</h6>
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold text-success">حساب الإيرادات</label>
            <input type="text" id="<?php echo ff_h($cid('service_revenue_account')); ?>" class="form-control bg-white" readonly
                   placeholder="اختر نوع الخدمة أولاً">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold text-danger">حساب التكلفة</label>
            <input type="text" id="<?php echo ff_h($cid('service_cost_account')); ?>" class="form-control bg-white" readonly
                   placeholder="اختر نوع الخدمة أولاً">
        </div>
        <div class="col-md-4">
            <label class="form-label small fw-bold text-warning">حساب الأرباح</label>
            <input type="text" id="<?php echo ff_h($cid('service_profit_account')); ?>" class="form-control bg-white" readonly
                   placeholder="اختر نوع الخدمة أولاً">
        </div>
    </div>

    <!-- الوصف -->
    <div class="row g-3">
        <div class="col-12">
            <label class="form-label small fw-bold text-muted">البيان / الوصف (يظهر في القيد المحاسبي)</label>
            <textarea name="description" id="<?php echo ff_h($cid('description')); ?>" class="form-control" rows="2"
                      placeholder="اكتب تفاصيل الفاتورة هنا..."><?php echo ff_h($val('description', '')); ?></textarea>
        </div>
    </div>
</fieldset>

<script>
(function() {
    var ffConfig = {
        prefix: <?php echo json_encode($p); ?>,
        apiUrl: <?php echo json_encode($ff_api_url); ?>,
        select2Parent: <?php echo json_encode($ff_select2_parent); ?>,
        formSelector: <?php echo json_encode($ff_form_selector); ?>,
        baseSymbol: <?php echo json_encode($base_currency['currency_symbol'] ?? ''); ?>,
        requireCostCenter: <?php echo !empty($settings['require_cost_center']) ? 'true' : 'false'; ?>,
        fixedSourceType: <?php echo json_encode($ff_show_service_select ? '' : $ff_source_type); ?>,
        initialAccountId: <?php echo json_encode($val('account_id', '')); ?>,
        initialDeliveryType: <?php echo json_encode($val('delivery_type', '')); ?>
    };

    var entitiesData = {
        cashboxes: <?php echo json_encode($cashboxes_entities); ?>,
        customers: <?php echo json_encode($customers_entities); ?>,
        banks: <?php echo json_encode($banks_entities); ?>,
        agents: <?php echo json_encode($agents_entities); ?>
    };
    var serviceConfigs = <?php echo json_encode($service_configs); ?>;

    window.entitiesData = entitiesData;

    function pid(name) {
        return ffConfig.prefix + name;
    }

    function updateServiceAccounts(serviceName) {
        var p = ffConfig.prefix;
        var config = serviceConfigs[serviceName];
        if (!config) {
            $('#' + pid('service_revenue_account')).val('').attr('placeholder', 'اختر نوع الخدمة أولاً');
            $('#' + pid('service_cost_account')).val('').attr('placeholder', 'اختر نوع الخدمة أولاً');
            $('#' + pid('service_profit_account')).val('').attr('placeholder', 'اختر نوع الخدمة أولاً');
            return;
        }
        $('#' + pid('service_revenue_account')).val(config.revenue_account_name || 'لم يتم إعداد الحساب');
        $('#' + pid('service_cost_account')).val(config.cost_account_name || 'لم يتم إعداد الحساب');
        $('#' + pid('service_profit_account')).val(config.profit_account_name || 'لم يتم إعداد الحساب');
    }

    function updateCurrencyDropdown(currencySelectId, accountId) {
        var select = $('#' + currencySelectId);
        var currentValue = select.val();

        function populate(currencies) {
            select.empty();
            (currencies || []).forEach(function(curr) {
                var isSelected = (String(curr.id) === String(currentValue)) || curr.is_default;
                select.append($('<option>', {
                    value: curr.id,
                    'data-symbol': curr.currency_symbol || '',
                    'data-buy': curr.exchange_rate_buy ?? 1,
                    'data-sell': curr.exchange_rate_sell ?? 1,
                    'data-rate': curr.exchange_rate ?? 1,
                    selected: isSelected
                }).text(curr.currency_name || ''));
            });
            updateLogic();
        }

        if (!accountId) {
            $.get(ffConfig.apiUrl, { action: 'get_active_currencies', account_id: 'all' }, function(response) {
                var currencies = typeof response === 'string' ? JSON.parse(response) : response;
                populate(currencies);
            });
            return;
        }

        $.get(ffConfig.apiUrl, { action: 'get_active_currencies', account_id: accountId }, function(currencies) {
            if (!currencies || currencies.length === 0) {
                $.get(ffConfig.apiUrl, { action: 'get_active_currencies', account_id: 'all' }, function(response) {
                    populate(typeof response === 'string' ? JSON.parse(response) : response);
                });
                return;
            }
            populate(currencies);
        }, 'json');
    }

    function updateLogic() {
        var p = ffConfig.prefix;
        var recordPurchase = $('#' + pid('record_purchase')).val() === '1';
        var purCurrencyId = $('#' + pid('main_currency_id')).val();
        var saleCurrencyId = $('#' + pid('sale_currency_id')).val();

        $('#' + pid('sale_currency_field')).show();
        $('#' + pid('main_currency_label')).text(recordPurchase ? 'عملة التكلفة (المورد)' : 'العملة');

        if (purCurrencyId && saleCurrencyId && purCurrencyId != saleCurrencyId) {
            $('#' + pid('exchange_rate_container')).show();
            var purOpt = $('#' + pid('main_currency_id') + ' option:selected');
            var saleOpt = $('#' + pid('sale_currency_id') + ' option:selected');
            var purSymbol = purOpt.data('symbol') || '---';
            var saleSymbol = saleOpt.data('symbol') || '---';
            var rate = (parseFloat(purOpt.data('buy')) || 1) / (parseFloat(saleOpt.data('sell')) || 1);

            $('.' + p + 'pur-symbol').text(purSymbol);
            $('.' + p + 'sale-symbol').text(saleSymbol);
            $('#' + pid('exchange_rate_label')).html('1 ' + purSymbol + ' = ? ' + saleSymbol);
            $('#' + pid('invoice_exchange_rate')).val(rate.toFixed(6));
        } else {
            $('#' + pid('invoice_exchange_rate')).val('1.000000');
            $('#' + pid('exchange_rate_container')).hide();
        }
        calculateEquivalent();
    }

    function calculateEquivalent() {
        var p = ffConfig.prefix;
        var cost = parseFloat($('#' + pid('cost_amount')).val()) || 0;
        var saleCurrencyId = $('#' + pid('sale_currency_id')).val();
        var mainCurrencyId = $('#' + pid('main_currency_id')).val();
        var rate = parseFloat($('#' + pid('invoice_exchange_rate')).val()) || 1;
        var equivalent = (saleCurrencyId != mainCurrencyId) ? cost * rate : cost;
        var saleSymbol = $('#' + pid('sale_currency_id') + ' option:selected').data('symbol') || 'ر.ي';
        $('#' + pid('equivalent_cost_display')).val(equivalent.toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' ' + saleSymbol);
    }

    function validateDiscount() {
        var p = ffConfig.prefix;
        var total = parseFloat($('#' + pid('total_amount')).val()) || 0;
        var discount = parseFloat($('#' + pid('discount')).val()) || 0;
        var cost = parseFloat($('#' + pid('cost_amount')).val()) || 0;
        var saleCurrencyId = $('#' + pid('sale_currency_id')).val();
        var mainCurrencyId = $('#' + pid('main_currency_id')).val();
        var rate = parseFloat($('#' + pid('invoice_exchange_rate')).val()) || 1;
        var costInSaleCurrency = (saleCurrencyId != mainCurrencyId) ? cost * rate : cost;
        var netPrice = total - discount;

        if (discount > 0 && netPrice < costInSaleCurrency - 0.01) {
            $('#' + pid('discount')).addClass('is-invalid');
            var maxAllowed = Math.max(0, total - costInSaleCurrency);
            var errorMsg = 'عفواً! لا يمكن أن يقل السعر الصافي عن التكلفة (' + costInSaleCurrency.toFixed(2) + '). أقصى خصم مسموح: ' + maxAllowed.toFixed(2);
            if (!$('#' + pid('discount_error')).length) {
                $('#' + pid('discount')).after('<div id="' + pid('discount_error') + '" class="invalid-feedback extra-small fw-bold"></div>');
            }
            $('#' + pid('discount_error')).text(errorMsg);
            return false;
        }
        $('#' + pid('discount')).removeClass('is-invalid');
        $('#' + pid('discount_error')).remove();
        return true;
    }

    function updateConvertedPrices(skipDiscount) {
        var p = ffConfig.prefix;
        var priceOrig = parseFloat($('#' + pid('total_amount')).attr('data-original-price')) || 0;
        var priceCurrId = $('#' + pid('total_amount')).attr('data-service-currency-id');
        var saleCurrId = $('#' + pid('sale_currency_id')).val();

        if (priceOrig > 0) {
            $('#' + pid('total_amount')).prop('readonly', true);
            var convBase = priceOrig;
            if (saleCurrId && priceCurrId && saleCurrId != priceCurrId) {
                var saleOpt = $('#' + pid('sale_currency_id') + ' option:selected');
                var serviceOpt = $('#' + pid('sale_currency_id') + ' option[value="' + priceCurrId + '"]').length
                    ? $('#' + pid('sale_currency_id') + ' option[value="' + priceCurrId + '"]')
                    : $('#' + pid('main_currency_id') + ' option[value="' + priceCurrId + '"]');
                if (serviceOpt.length) {
                    var rate = (parseFloat(serviceOpt.data('buy')) || 1) / (parseFloat(saleOpt.data('sell')) || 1);
                    convBase = priceOrig * rate;
                    $('#' + pid('sales_exchange_info')).html('<i class="fas fa-sync-alt me-1"></i> 1 ' + (serviceOpt.data('symbol') || '---') + ' = ' + rate.toFixed(4) + ' ' + (saleOpt.data('symbol') || '---')).show();
                }
            } else {
                $('#' + pid('sales_exchange_info')).hide();
            }
            $('#' + pid('total_amount')).val(convBase.toFixed(2));
        } else {
            $('#' + pid('total_amount')).prop('readonly', false);
            $('#' + pid('sales_exchange_info')).hide();
        }

        var costOrig = parseFloat($('#' + pid('cost_amount')).attr('data-original-cost')) || 0;
        var costCurrId = $('#' + pid('cost_amount')).attr('data-cost-service-currency-id');
        var mainCurrId = $('#' + pid('main_currency_id')).val();
        if (costOrig > 0) {
            $('#' + pid('cost_amount')).prop('readonly', true);
            var convCost = costOrig;
            if (mainCurrId && costCurrId && mainCurrId != costCurrId) {
                var mainOpt = $('#' + pid('main_currency_id') + ' option:selected');
                var costSrvOpt = $('#' + pid('main_currency_id') + ' option[value="' + costCurrId + '"]').length
                    ? $('#' + pid('main_currency_id') + ' option[value="' + costCurrId + '"]')
                    : $('#' + pid('sale_currency_id') + ' option[value="' + costCurrId + '"]');
                if (costSrvOpt.length) {
                    convCost = costOrig * ((parseFloat(costSrvOpt.data('buy')) || 1) / (parseFloat(mainOpt.data('sell')) || 1));
                }
            }
            $('#' + pid('cost_amount')).val(convCost.toFixed(2));
        } else {
            $('#' + pid('cost_amount')).prop('readonly', false);
        }

        validateDiscount();
        calculateEquivalent();
    }

    function handleDeliveryType(type) {
        var p = ffConfig.prefix;
        var list = [];
        var label = 'الحساب المتأثر';
        var $sel = $('#' + pid('account_select'));

        if (!type) {
            $sel.prop('disabled', true).empty().append('<option value="">-- اختر نوع التوصيل أولاً --</option>').trigger('change');
            $('#' + pid('account_label')).text('الحساب المتأثر');
            $('#' + pid('received_amount_field')).hide();
            return;
        }

        $sel.prop('disabled', false);
        if (type === 'cash') {
            list = entitiesData.cashboxes;
            label = 'الحساب: الصناديق';
            $('#' + pid('received_amount_field')).show();
        } else if (type === 'credit') {
            list = entitiesData.customers;
            label = 'الحساب: العملاء';
            $('#' + pid('received_amount_field')).hide();
        } else if (type === 'bank_transfer') {
            list = entitiesData.banks;
            label = 'الحساب: البنوك';
            $('#' + pid('received_amount_field')).hide();
        } else if (type === 'agent') {
            list = entitiesData.agents;
            label = 'الحساب: الوكلاء';
            $('#' + pid('received_amount_field')).hide();
        } else {
            $('#' + pid('received_amount_field')).hide();
        }

        $('#' + pid('account_label')).text(label);
        $sel.empty().append('<option value="">-- اختر --</option>');
        list.forEach(function(item) {
            var displayName = item.display_name || ((item.account_code || '') + ' - ' + (item.name || item.account_name_ar || ''));
            $sel.append('<option value="' + item.id + '" data-customer-id="' + (item.customer_id || '') + '" data-agent-id="' + (item.agent_id || '') + '">' + displayName + '</option>');
        });
        $sel.trigger('change');
    }

    function setCustomerAgentHidden(customerId, agentId) {
        if ($('#customer_id_hidden').length) {
            $('#customer_id_hidden').val(customerId || '');
        }
        if ($('#agent_id_hidden').length) {
            $('#agent_id_hidden').val(agentId || '');
        }
    }

    function fetchAccountBalance(accountId) {
        if (!accountId) {
            $('#' + pid('account_balance_info')).addClass('d-none');
            return;
        }
        $.get('ajax_get_account_balances.php', { account_id: accountId }, function(data) {
            if (!data || !data.length) {
                $('#' + pid('account_balance_info')).addClass('d-none');
                return;
            }
            var totalNetBalanceBase = 0;
            var creditLimitBase = parseFloat(data[0].credit_limit_base) || 0;
            var normalBalance = data[0].normal_balance;
            data.forEach(function(bal) {
                totalNetBalanceBase += parseFloat(bal.current_balance_base) || 0;
            });

            var statusText = '';
            var statusClass = '';
            if (Math.abs(totalNetBalanceBase) < 0.01) {
                statusText = '(متعادل)';
                statusClass = 'text-muted';
            } else {
                statusText = totalNetBalanceBase > 0 ? '(عليه)' : '(له)';
                statusClass = totalNetBalanceBase > 0 ? 'text-danger' : 'text-success';
            }

            $('#' + pid('unified_balance_display')).html(
                '<span class="' + statusClass + '">' + Math.abs(totalNetBalanceBase).toLocaleString(undefined, { minimumFractionDigits: 2 }) +
                '</span> <small class="text-muted">' + ffConfig.baseSymbol + '</small> ' + statusText
            );
            $('#' + pid('unified_limit_display')).text(
                creditLimitBase > 0 ? creditLimitBase.toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' ' + ffConfig.baseSymbol : 'غير محدد'
            );
            $('#' + pid('account_balance_info')).removeClass('d-none');
        });
    }

    function fetchSupplierBalance(accountId) {
        var $infoBox = $('#' + pid('supplier_balance_info'));
        if (!accountId) {
            $infoBox.addClass('d-none');
            return;
        }
        $.get('ajax_get_account_balances.php', { account_id: accountId }, function(data) {
            if (!data || !data.length) {
                $infoBox.addClass('d-none');
                return;
            }
            var totalNetBalanceBase = 0;
            var debitLimitBase = parseFloat(data[0].debit_limit_base) || 0;
            data.forEach(function(bal) {
                totalNetBalanceBase += parseFloat(bal.current_balance_base) || 0;
            });

            var statusText = totalNetBalanceBase > 0 ? '(لنا عنده)' : '(له عندنا)';
            var statusClass = totalNetBalanceBase > 0 ? 'text-success' : 'text-danger';

            $('#' + pid('supplier_unified_balance_display')).html(
                '<span class="' + statusClass + '">' + Math.abs(totalNetBalanceBase).toLocaleString(undefined, { minimumFractionDigits: 2 }) +
                '</span> <small class="text-muted">' + ffConfig.baseSymbol + '</small> ' + statusText
            );
            $('#' + pid('supplier_unified_limit_display')).text(
                debitLimitBase > 0 ? debitLimitBase.toLocaleString(undefined, { minimumFractionDigits: 2 }) + ' ' + ffConfig.baseSymbol : 'غير محدد'
            );
            $infoBox.removeClass('d-none');
        });
    }

    function bindEvents() {
        var p = ffConfig.prefix;
        var ns = '.financialFields' + p;

        $('#' + pid('delivery_type')).off(ns).on('change' + ns, function() {
            handleDeliveryType($(this).val());
        });

        $('#' + pid('account_select')).off(ns).on('change' + ns, function() {
            var customerId = $(this).find(':selected').data('customer-id');
            var agentId = $(this).find(':selected').data('agent-id');
            var accountId = $(this).val();
            setCustomerAgentHidden(customerId, agentId);
            updateCurrencyDropdown(pid('sale_currency_id'), accountId);
            fetchAccountBalance(accountId);
            $(document).trigger('financialFields:accountChanged', [{ customerId: customerId, agentId: agentId, accountId: accountId, prefix: p }]);
        });

        $('#' + pid('supplier_id')).off(ns).on('change' + ns, function() {
            var supplierId = $(this).val();
            var accountId = $(this).find(':selected').data('account');
            if (supplierId) {
                $.get(ffConfig.apiUrl, {
                    action: 'get_account_from_entity',
                    entity_type: 'supplier',
                    entity_id: supplierId
                }, function(data) {
                    if (data && data.account_id) {
                        updateCurrencyDropdown(pid('main_currency_id'), data.account_id);
                    }
                }, 'json');
            } else {
                updateCurrencyDropdown(pid('main_currency_id'), null);
            }
            fetchSupplierBalance(accountId);
            $(document).trigger('financialFields:supplierChanged', [{ supplierId: supplierId, accountId: accountId, prefix: p }]);
        });

        $('#' + pid('main_currency_id') + ', #' + pid('sale_currency_id') + ', #' + pid('record_purchase'))
            .off(ns).on('change' + ns, function() {
                updateLogic();
                updateConvertedPrices();
            });

        $('#' + pid('invoice_exchange_rate') + ', #' + pid('cost_amount'))
            .off(ns).on('input' + ns, function() {
                calculateEquivalent();
                updateConvertedPrices();
            });

        $('#' + pid('discount')).off(ns).on('input' + ns, function() {
            updateConvertedPrices(true);
        });

        $('#' + pid('total_amount')).off(ns).on('input' + ns, function() {
            validateDiscount();
        });

        var serviceSelector = ffConfig.fixedSourceType ? null : ('#' + pid('service_id'));
        if (serviceSelector && $(serviceSelector).length) {
            $(serviceSelector).off(ns).on('change' + ns, function() {
                updateServiceAccounts($(this).val());
                $(document).trigger('financialFields:serviceChanged', [{ serviceName: $(this).val(), prefix: p }]);
            });
        }

        if (ffConfig.formSelector) {
            $(ffConfig.formSelector).off('submit' + ns).on('submit' + ns, function(e) {
                var recordVal = $('#' + pid('record_purchase')).val();
                if (recordVal === null || recordVal === '') {
                    e.preventDefault();
                    alert('يرجى اختيار ما إذا كنت تريد تسجيل مديونية للمورد أم لا.');
                    $('#' + pid('record_purchase')).focus();
                    return false;
                }
                if (!validateDiscount()) {
                    e.preventDefault();
                    alert('عفواً! لا يمكن حفظ الفاتورة لأن السعر بعد الخصم أقل من سعر التكلفة.');
                    $('#' + pid('discount')).focus();
                    return false;
                }
                if (ffConfig.requireCostCenter) {
                    var branchId = $('#' + pid('branch_id')).val();
                    if (!branchId) {
                        e.preventDefault();
                        alert('عفواً! اختيار الفرع (مركز التكلفة) إلزامي حسب إعدادات النظام.');
                        $('#' + pid('branch_id')).focus();
                        return false;
                    }
                }
            });
        }
    }

    function initFinancialFields() {
        if (typeof jQuery === 'undefined') {
            return;
        }

        if ($.fn.select2 && ffConfig.select2Parent) {
            $('.financial-fields-block[data-ff-prefix="' + ffConfig.prefix + '"] .select2-financial').select2({
                dropdownParent: $(ffConfig.select2Parent),
                width: '100%'
            });
        }

        bindEvents();

        var serviceName = ffConfig.fixedSourceType || ($('#' + pid('service_id')).val() || $('#' + pid('source_type_hidden')).val() || 'general');
        updateServiceAccounts(serviceName);

        if (ffConfig.initialDeliveryType) {
            handleDeliveryType(ffConfig.initialDeliveryType);
            if (ffConfig.initialAccountId) {
                setTimeout(function() {
                    $('#' + pid('account_select')).val(ffConfig.initialAccountId).trigger('change');
                }, 100);
            }
        }

        updateLogic();
        updateConvertedPrices();
    }

    window.handleDeliveryType = function(type, selectId, labelId, receivedFieldId) {
        handleDeliveryType(type);
    };
    window.updateLogic = updateLogic;
    window.calculateEquivalent = calculateEquivalent;
    window.updateConvertedPrices = updateConvertedPrices;
    window.validateDiscount = validateDiscount;
    window.updateCurrencyDropdown = updateCurrencyDropdown;

    window.FinancialFields = {
        config: ffConfig,
        init: initFinancialFields,
        updateLogic: updateLogic,
        calculateEquivalent: calculateEquivalent,
        updateConvertedPrices: updateConvertedPrices,
        validateDiscount: validateDiscount,
        handleDeliveryType: handleDeliveryType,
        updateServiceAccounts: updateServiceAccounts,
        updateCurrencyDropdown: updateCurrencyDropdown
    };

    <?php if (!$ff_manual_init): ?>
    if (typeof jQuery !== 'undefined') {
        jQuery(function() {
            initFinancialFields();
        });
    }
    <?php endif; ?>
})();
</script>
