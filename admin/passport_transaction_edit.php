<?php
ob_start();
require_once 'header.php';

// Check permissions
if (!has_permission('passport_transactions_edit')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

if (!isset($_GET['id'])) {
    header('Location: passport_transactions.php');
    exit();
}

$id = (int)$_GET['id'];

// Fetch transaction details
$stmt = $pdo->prepare("
    SELECT pt.*, 
           inv.currency_id, 
           inv.total_amount as sale_price, 
           inv.cost_amount as purchase_price,
           inv.delivery_type as payment_type,
           inv.account_id,
           inv.customer_id
    FROM passport_transactions pt
    LEFT JOIN invoices inv ON inv.source_type = 'passport_transaction' AND inv.source_id = pt.id AND inv.invoice_category = 'sales'
    WHERE pt.id = ?
");
$stmt->execute([$id]);
$trx = $stmt->fetch();

if (!$trx) {
    header('Location: passport_transactions.php');
    exit();
}

$page_title = "تعديل معاملة جوازات";
$settings = getSettings($pdo);

// Fetch auxiliary data
$cities = $pdo->query("SELECT id, city_name FROM cities ORDER BY city_name ASC")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol, is_default, exchange_rate, exchange_rate_buy, exchange_rate_sell FROM currencies WHERE is_active = 1 ORDER BY currency_name ASC")->fetchAll();
$passport_types = $pdo->query("SELECT id, type_name, default_cost, default_sale_price, currency_id FROM passport_transaction_types WHERE is_active = 1 ORDER BY type_name ASC")->fetchAll();

// Get entities for financial logic (similar to invoices.php)
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
    WHERE (account_code LIKE '101%' OR account_code LIKE '111%' OR account_type = 'box') 
      AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();

$banks_entities = $pdo->query("
    SELECT id as account_id, account_name_ar as name, account_code 
    FROM unified_accounts 
    WHERE (account_code LIKE '102%' OR account_type = 'bank') 
      AND account_status = 'active' 
    ORDER BY account_name_ar ASC
")->fetchAll();

// Fetch invoices associated with this transaction
$stmt_invoices = $pdo->prepare("SELECT * FROM invoices WHERE source_type = 'passport_transaction' AND source_id = ?");
$stmt_invoices->execute([$id]);
$invoices = $stmt_invoices->fetchAll();

$sales_invoice = null;
$purchase_invoice = null;
foreach($invoices as $inv) {
    if ($inv['invoice_category'] == 'sales') $sales_invoice = $inv;
    if ($inv['invoice_category'] == 'purchase') $purchase_invoice = $inv;
}
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-edit me-2"></i> تعديل معاملة جوازات</h3>
            <p class="text-muted small mb-0">تحديث بيانات المعاملة رقم: <?php echo htmlspecialchars($trx['transaction_number']); ?></p>
        </div>
        <a href="passport_transaction_view.php?id=<?php echo $id; ?>" class="btn btn-light border rounded-pill px-4 shadow-sm">
            <i class="fas fa-arrow-right me-1"></i> عودة للتفاصيل
        </a>
    </div>

    <form action="process_passport_transaction.php" method="POST" id="transactionForm">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?php echo $id; ?>">

        <div class="row g-4">
            <!-- Basic Information -->
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="fw-bold mb-0 text-primary small"><i class="fas fa-user-edit me-2"></i> بيانات المسافر والهوية</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">الاسم الكامل <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" name="full_name" value="<?php echo htmlspecialchars($trx['full_name']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">رقم الهاتف <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" name="phone_number" value="<?php echo htmlspecialchars($trx['phone_number']); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">مكان الميلاد</label>
                                <input type="text" class="form-control rounded-3" name="place_of_birth" value="<?php echo htmlspecialchars($trx['place_of_birth']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">تاريخ الميلاد</label>
                                <input type="date" class="form-control rounded-3" name="date_of_birth" value="<?php echo $trx['date_of_birth']; ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">نوع الهوية</label>
                                <select class="form-select rounded-3" name="id_type">
                                    <option value="passport" <?php echo $trx['id_type'] == 'passport' ? 'selected' : ''; ?>>جواز سفر</option>
                                    <option value="national_id" <?php echo $trx['id_type'] == 'national_id' ? 'selected' : ''; ?>>هوية وطنية</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">رقم الهوية</label>
                                <input type="text" class="form-control rounded-3" name="id_number" value="<?php echo htmlspecialchars($trx['id_number']); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">من مدينة <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3" name="from_city_id" required>
                                    <option value="">اختر مدينة...</option>
                                    <?php foreach($cities as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo $trx['from_city_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">إلى مدينة <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3" name="to_city_id" required>
                                    <option value="">اختر مدينة...</option>
                                    <?php foreach($cities as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" <?php echo $trx['to_city_id'] == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">تاريخ السفر</label>
                                <div class="input-group">
                                    <input type="date" class="form-control rounded-start-3" name="travel_date" id="travel_date" value="<?php echo $trx['travel_date'] ?? ''; ?>">
                                    <span class="input-group-text bg-light border-start-0 rounded-end-3 fw-bold text-primary" id="travel_day_name" style="min-width: 100px; justify-content: center;">---</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transaction Details (Shown only if workflow is disabled or manually enabled) -->
                <?php if (!($settings['passport_workflow_enabled'] ?? 1)): ?>
                <div class="card border-0 shadow-sm rounded-4 mb-4">
                    <div class="card-header bg-white border-0 py-3">
                        <h5 class="fw-bold mb-0 text-primary small"><i class="fas fa-tasks me-2"></i> تفاصيل المعاملة</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-4">
                            <label class="form-label small fw-bold">نوع المعاملة <span class="text-danger">*</span></label>
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="transaction_type" id="type_both" value="both" <?php echo $trx['transaction_type'] == 'both' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary" for="type_both">بطاقة وجواز</label>
                                
                                <input type="radio" class="btn-check" name="transaction_type" id="type_card" value="card_only" <?php echo $trx['transaction_type'] == 'card_only' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary" for="type_card">بطاقة فقط</label>
                                
                                <input type="radio" class="btn-check" name="transaction_type" id="type_passport" value="passport_only" <?php echo $trx['transaction_type'] == 'passport_only' ? 'checked' : ''; ?>>
                                <label class="btn btn-outline-primary" for="type_passport">جواز فقط</label>
                            </div>
                        </div>

                        <div id="card_section" style="<?php echo $trx['transaction_type'] == 'passport_only' ? 'display:none;' : ''; ?>">
                            <h6 class="fw-bold small text-muted mb-3"><i class="fas fa-id-card me-2"></i> بيانات البطاقة</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم معاملة البطاقة</label>
                                    <input type="text" class="form-control rounded-3" name="card_transaction_number" value="<?php echo htmlspecialchars($trx['card_transaction_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ معاملة البطاقة</label>
                                    <input type="date" class="form-control rounded-3" name="card_transaction_date" value="<?php echo $trx['card_transaction_date']; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم البطاقة</label>
                                    <input type="text" class="form-control rounded-3" name="card_number" value="<?php echo htmlspecialchars($trx['card_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ إصدار البطاقة</label>
                                    <input type="date" class="form-control rounded-3" name="card_issue_date" value="<?php echo $trx['card_issue_date']; ?>">
                                </div>
                            </div>
                        </div>

                        <div id="passport_section" style="<?php echo $trx['transaction_type'] == 'card_only' ? 'display:none;' : ''; ?>">
                            <h6 class="fw-bold small text-muted mb-3"><i class="fas fa-passport me-2"></i> بيانات الجواز</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم معاملة الجواز</label>
                                    <input type="text" class="form-control rounded-3" name="passport_transaction_number" value="<?php echo htmlspecialchars($trx['passport_transaction_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ معاملة الجواز</label>
                                    <input type="date" class="form-control rounded-3" name="passport_transaction_date" value="<?php echo $trx['passport_transaction_date']; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم الجواز</label>
                                    <input type="text" class="form-control rounded-3" name="passport_number" value="<?php echo htmlspecialchars($trx['passport_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ إصدار الجواز</label>
                                    <input type="date" class="form-control rounded-3" name="passport_issue_date" value="<?php echo $trx['passport_issue_date']; ?>">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">اسم العميل المستلم</label>
                            <input type="text" class="form-control rounded-3" name="delivery_receiver_name" value="<?php echo htmlspecialchars($trx['delivery_receiver_name'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <input type="hidden" name="transaction_type" value="<?php echo $trx['transaction_type']; ?>">
                <?php endif; ?>
            </div>

            <!-- Financial Information -->
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm rounded-4 mb-4 bg-light">
                    <div class="card-header bg-primary text-white border-0 py-3 rounded-top-4">
                        <h5 class="fw-bold mb-0 small"><i class="fas fa-money-bill-wave me-2"></i> البيانات المالية</h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <input type="hidden" name="transaction_number" value="<?php echo htmlspecialchars($trx['transaction_number']); ?>">
                            
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-primary">نوع المعاملة (التسعيرة) <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3 border-primary" name="transaction_type_id" id="transaction_type_id" required>
                                    <option value="">اختر نوع المعاملة...</option>
                                    <?php foreach($passport_types as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" 
                                                data-cost="<?php echo $type['default_cost']; ?>" 
                                                data-sale="<?php echo $type['default_sale_price']; ?>"
                                                data-currency="<?php echo $type['currency_id']; ?>"
                                                <?php echo $trx['transaction_type_id'] == $type['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($type['type_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">تاريخ العملية <span class="text-danger">*</span></label>
                                <input type="date" class="form-control rounded-3" name="operation_date" value="<?php echo $trx['operation_date']; ?>" required>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-bold">عملة البيع <span class="text-danger">*</span></label>
                                <select name="sale_currency_id" id="sale_currency_id" class="form-select select2-financial">
                                    <?php foreach ($currencies as $curr): ?>
                                        <option value="<?php echo $curr['id']; ?>" 
                                                data-symbol="<?php echo $curr['currency_symbol']; ?>" 
                                                data-buy="<?php echo $curr['exchange_rate_buy'] ?? 1; ?>" 
                                                data-sell="<?php echo $curr['exchange_rate_sell'] ?? 1; ?>" 
                                                data-rate="<?php echo $curr['exchange_rate'] ?? 1; ?>"
                                                <?php echo ($sales_invoice ? $sales_invoice['currency_id'] : ($curr['is_default'])) == $curr['id'] ? 'selected' : ''; ?>>
                                            <?php echo $curr['currency_name']; ?> (<?php echo $curr['currency_symbol']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-primary">سعر البيع <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="sale_price" id="sale_price" class="form-control fw-bold text-primary" value="<?php echo $sales_invoice ? $sales_invoice['total_amount'] : '0.00'; ?>" required>
                                <div id="sale_price_equivalent_hint" class="extra-small text-muted mt-1 fw-bold" style="display:none;"></div>
                            </div>
                            <div class="col-md-4" <?php echo ($settings['passport_allow_discount'] ?? 1) ? '' : 'style="display:none;"'; ?>>
                                <label class="form-label small fw-bold text-danger">الخصم</label>
                                <input type="number" step="0.01" name="discount" id="discount" class="form-control" value="<?php echo $sales_invoice ? $sales_invoice['discount_amount'] : '0.00'; ?>">
                            </div>

                            <hr class="my-2">

                            <div class="col-md-4" id="supplier_select_div">
                                <label class="form-label small fw-bold text-danger">المورد <span class="text-danger">*</span></label>
                                <select class="form-select select2-financial" name="supplier_id" id="supplier_id">
                                    <option value="">اختر المورد...</option>
                                    <?php 
                                    $suppliers = $pdo->query("SELECT id, supplier_name FROM suppliers WHERE deleted_at IS NULL ORDER BY supplier_name ASC")->fetchAll();
                                    foreach($suppliers as $sup): 
                                    ?>
                                        <option value="<?php echo $sup['id']; ?>" <?php echo ($purchase_invoice && $purchase_invoice['supplier_id'] == $sup['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($sup['supplier_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-muted">عملة التكلفة</label>
                                <select name="currency_id" id="main_currency_id" class="form-select select2-financial">
                                    <?php foreach ($currencies as $curr): ?>
                                        <option value="<?php echo $curr['id']; ?>" 
                                                data-symbol="<?php echo $curr['currency_symbol']; ?>" 
                                                data-buy="<?php echo $curr['exchange_rate_buy'] ?? 1; ?>" 
                                                data-sell="<?php echo $curr['exchange_rate_sell'] ?? 1; ?>" 
                                                data-rate="<?php echo $curr['exchange_rate'] ?? 1; ?>"
                                                <?php echo ($purchase_invoice ? $purchase_invoice['currency_id'] : ($curr['is_default'])) == $curr['id'] ? 'selected' : ''; ?>>
                                            <?php echo $curr['currency_name']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label small fw-bold text-warning">سعر التكلفة <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" name="purchase_price" id="purchase_price" class="form-control fw-bold text-warning" value="<?php echo $purchase_invoice ? $purchase_invoice['total_amount'] : ($sales_invoice ? $sales_invoice['cost_amount'] : '0.00'); ?>" required>
                                <div id="cost_price_equivalent_hint" class="extra-small text-muted mt-1 fw-bold" style="display:none;"></div>
                            </div>

                            <!-- سعر الصرف (يظهر عند اختلاف العملات) -->
                            <div class="col-12" id="exchange_rate_container" style="display: none;">
                                <div class="p-2 bg-white border border-dashed rounded-3">
                                    <label class="form-label extra-small fw-bold text-muted mb-1" id="exchange_rate_label">سعر الصرف</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-light">1 <span class="pur-symbol"></span> =</span>
                                        <input type="number" step="0.000001" name="exchange_rate" id="invoice_exchange_rate" class="form-control text-center fw-bold" value="1.000000">
                                        <span class="input-group-text bg-light"><span class="sale-symbol"></span></span>
                                    </div>
                                    <div class="mt-1 extra-small text-muted">التكلفة المعادلة: <span id="equivalent_cost_display" class="fw-bold">0.00</span></div>
                                </div>
                            </div>

                            <div class="col-12">
                                <?php if (isset($settings['auto_invoice_generation']) && ($settings['auto_invoice_generation'] == '1' || $settings['auto_invoice_generation'] === true)): ?>
                                    <input type="hidden" name="record_purchase" id="record_purchase" value="1">
                                <?php else: ?>
                                    <div class="mt-2 p-3 bg-primary bg-opacity-10 rounded-4 border border-primary border-opacity-25">
                                        <label class="form-label small fw-bold text-primary mb-2"><i class="fas fa-question-circle me-1"></i> هل تريد إنشاء فاتورة شراء للمورد؟</label>
                                        <select name="record_purchase" id="record_purchase" class="form-select border-primary" required>
                                            <option value="1" <?php echo $purchase_invoice ? 'selected' : ''; ?>>نعم، تسجيل مديونية</option>
                                            <option value="0" <?php echo !$purchase_invoice ? 'selected' : ''; ?>>لا، مبيعات فقط</option>
                                        </select>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <hr class="my-2">

                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">نوع التوصيل <span class="text-danger">*</span></label>
                                <select name="delivery_type" id="delivery_type" class="form-select" required>
                                    <option value="draft" <?php echo (!$sales_invoice || $sales_invoice['delivery_type'] == 'draft') ? 'selected' : ''; ?>>📝 مسودة (ترحيل يدوي لاحقاً)</option>
                                    <option value="cash" <?php echo ($sales_invoice && $sales_invoice['delivery_type'] == 'cash') ? 'selected' : ''; ?>>💵 نقد</option>
                                    <option value="credit" <?php echo ($sales_invoice && $sales_invoice['delivery_type'] == 'credit') ? 'selected' : ''; ?>>📅 آجل (على حساب العميل)</option>
                                    <option value="bank_transfer" <?php echo ($sales_invoice && $sales_invoice['delivery_type'] == 'bank_transfer') ? 'selected' : ''; ?>>🏦 تحويل بنكي</option>
                                    <option value="agent" <?php echo ($sales_invoice && $sales_invoice['delivery_type'] == 'agent') ? 'selected' : ''; ?>>👤 وكيل (على حساب الوكيل)</option>
                                </select>
                            </div>

                            <div class="col-12" id="account_select_div">
                                <label class="form-label small fw-bold text-muted" id="account_label">الحساب المتأثر</label>
                                <select name="account_id" id="account_id" class="form-select select2-financial" required>
                                    <option value="">-- اختر --</option>
                                    <?php
                                    $delivery_type = $sales_invoice ? $sales_invoice['delivery_type'] : 'draft';
                                    $current_account_id = $sales_invoice ? $sales_invoice['account_id'] : null;
                                    $current_customer_id = $sales_invoice ? $sales_invoice['customer_id'] : null;
                                    $current_agent_id = $sales_invoice ? $sales_invoice['agent_id'] : null;
                                    
                                    $list = [];
                                    if ($delivery_type == 'cash') $list = $cashboxes_entities;
                                    elseif ($delivery_type == 'credit') $list = $customers_entities;
                                    elseif ($delivery_type == 'bank_transfer') $list = $banks_entities;
                                    elseif ($delivery_type == 'agent') $list = $agents_entities;
                                    
                                    foreach($list as $item) {
                                        $selected = '';
                                        if ($delivery_type == 'cash' || $delivery_type == 'bank_transfer') {
                                            if ($current_account_id == $item['account_id']) $selected = 'selected';
                                        } elseif ($delivery_type == 'credit') {
                                            if ($current_customer_id == $item['id']) $selected = 'selected';
                                        } elseif ($delivery_type == 'agent') {
                                            if ($current_agent_id == $item['id']) $selected = 'selected';
                                        }
                                        echo '<option value="'.$item['account_id'].'" data-entity-id="'.($item['id'] ?? '').'" '.$selected.'>'.$item['account_code'].' - '.$item['name'].'</option>';
                                    }
                                    ?>
                                </select>
                                <input type="hidden" name="customer_id" id="customer_id_hidden" value="<?php echo $current_customer_id; ?>">
                                <input type="hidden" name="agent_id" id="agent_id_hidden" value="<?php echo $current_agent_id; ?>">
                            </div>

                            <div class="col-12" id="received_amount_div" style="<?php echo in_array($delivery_type, ['cash', 'bank_transfer']) ? '' : 'display:none;'; ?>">
                                <label class="form-label small fw-bold text-success">المبلغ الواصل (المقبوض)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-light text-primary"><i class="fas fa-hand-holding-usd"></i></span>
                                    <input type="number" step="0.01" name="amount_received" id="amount_received" class="form-control fw-bold border-primary text-primary" value="<?php echo $sales_invoice ? $sales_invoice['amount_received'] : '0.00'; ?>">
                                </div>
                            </div>

                            <hr class="my-1">
                            
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">بيان العملية</label>
                                <textarea class="form-control rounded-3 form-control-sm" name="description" rows="1"><?php echo htmlspecialchars($trx['description']); ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">ملاحظات إضافية</label>
                                <textarea class="form-control rounded-3 form-control-sm" name="notes" rows="1"><?php echo htmlspecialchars($trx['notes']); ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary rounded-pill py-3 fw-bold shadow">
                        <i class="fas fa-save me-2"></i> تحديث بيانات المعاملة
                    </button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Select2 Initialization
    if ($.fn.select2) {
        $('.select2-financial').select2({
            width: '100%',
            dropdownAutoWidth: true
        });
    }

    const typeRadios = document.querySelectorAll('input[name="transaction_type"]');
    const cardSection = document.getElementById('card_section');
    const passportSection = document.getElementById('passport_section');

    function toggleSections() {
        const checkedRadio = document.querySelector('input[name="transaction_type"]:checked');
        const type = checkedRadio ? checkedRadio.value : (document.querySelector('input[name="transaction_type"]') ? document.querySelector('input[name="transaction_type"]').value : 'both');
        
        if (cardSection && passportSection) {
            if (type === 'both') {
                cardSection.style.display = 'block';
                passportSection.style.display = 'block';
            } else if (type === 'card_only') {
                cardSection.style.display = 'block';
                passportSection.style.display = 'none';
            } else if (type === 'passport_only') {
                cardSection.style.display = 'none';
                passportSection.style.display = 'block';
            }
        }
    }

    typeRadios.forEach(radio => radio.addEventListener('change', toggleSections));
    toggleSections();

    // Financial Logic Variables
    const deliveryTypeSelect = document.getElementById('delivery_type');
    const accountSelect = document.getElementById('account_id');
    const accountLabel = document.getElementById('account_label');
    const receivedDiv = document.getElementById('received_amount_div');
    const customerAgentSelect = document.getElementById('customer_agent_id');
    const customerIdHidden = document.getElementById('customer_id_hidden');
    const agentIdHidden = document.getElementById('agent_id_hidden');

    const entitiesData = {
        cashboxes: <?php echo json_encode($cashboxes_entities); ?>,
        customers: <?php echo json_encode($customers_entities); ?>,
        banks: <?php echo json_encode($banks_entities); ?>,
        agents: <?php echo json_encode($agents_entities); ?>
    };

    function handleDeliveryType(type) {
        let list = [], label = 'الحساب المتأثر';
        const $sel = $(accountSelect);

        if (!type || type === '' || type === 'draft') {
            $sel.prop('disabled', true).empty().append('<option value="">-- ترحيل يدوي لاحقاً --</option>').trigger('change');
            accountLabel.innerText = 'الحساب المتأثر';
            receivedDiv.style.display = 'none';
            customerIdHidden.value = '';
            agentIdHidden.value = '';
            return;
        }

        $sel.prop('disabled', false);
        if (type === 'cash') {
            list = entitiesData.cashboxes;
            label = 'الحساب المتأثر: الصناديق';
            receivedDiv.style.display = 'block';
        } else if (type === 'credit') {
            list = entitiesData.customers;
            label = 'الحساب المتأثر: العملاء';
            receivedDiv.style.display = 'none';
        } else if (type === 'bank_transfer') {
            list = entitiesData.banks;
            label = 'الحساب المتأثر: البنوك';
            receivedDiv.style.display = 'block';
        } else if (type === 'agent') {
            list = entitiesData.agents;
            label = 'الحساب المتأثر: الوكلاء';
            receivedDiv.style.display = 'none';
        }

        accountLabel.innerText = label;
        $sel.empty().append('<option value="">-- اختر الحساب --</option>');
        
        if (list && list.length > 0) {
            list.forEach(item => {
                const entityId = item.id || '';
                $sel.append(`<option value="${item.account_id}" data-entity-id="${entityId}">${item.account_code} - ${item.name}</option>`);
            });
        }
        
        $sel.trigger('change');
    }

    $('#delivery_type').on('change', function() {
        handleDeliveryType(this.value);
    });

    $('#account_id').on('change', function() {
        const type = deliveryTypeSelect.value;
        const selectedOpt = $(this).find(':selected');
        const entityId = selectedOpt.attr('data-entity-id');
        
        customerIdHidden.value = (type === 'credit' ? entityId : '');
        agentIdHidden.value = (type === 'agent' ? entityId : '');
    });

    const transactionTypeSelect = document.getElementById('transaction_type_id');
    const salePriceInput = document.getElementById('sale_price');
    const purchasePriceInput = document.getElementById('purchase_price');
    const saleCurrencySelect = document.getElementById('sale_currency_id');
    const mainCurrencySelect = document.getElementById('main_currency_id');
    const exchangeRateInput = document.getElementById('invoice_exchange_rate');
    const exchangeContainer = document.getElementById('exchange_rate_container');
    const recordPurchaseSwitch = document.getElementById('record_purchase');
    const supplierDiv = document.getElementById('supplier_select_div');

    const initialTypeOpt = transactionTypeSelect.options[transactionTypeSelect.selectedIndex];
    let baseSalePrice = initialTypeOpt ? (parseFloat(initialTypeOpt.getAttribute('data-sale')) || 0) : 0;
    let basePurchasePrice = initialTypeOpt ? (parseFloat(initialTypeOpt.getAttribute('data-cost')) || 0) : 0;
    let baseCurrencyId = initialTypeOpt ? initialTypeOpt.getAttribute('data-currency') : null;

    function convertPrice(price, fromCurrencyId, toCurrencyId) {
        if (!fromCurrencyId || !toCurrencyId || fromCurrencyId == toCurrencyId) return price;
        const fromOpt = Array.from(saleCurrencySelect.options).find(opt => opt.value == fromCurrencyId);
        const toOpt = Array.from(saleCurrencySelect.options).find(opt => opt.value == toCurrencyId);
        if (!fromOpt || !toOpt) return price;
        const fromRate = parseFloat(fromOpt.getAttribute('data-rate')) || 1;
        const toRate = parseFloat(toOpt.getAttribute('data-rate')) || 1;
        return price * (fromRate / toRate);
    }

    function handleDeliveryType(type) {
        let list = [], label = 'الحساب المتأثر';
        const $sel = $(accountSelect);

        console.log("Handling delivery type:", type);

        if (!type || type === '' || type === 'draft') {
            $sel.prop('disabled', true).empty().append('<option value="">-- اختر نوع التوصيل أولاً --</option>').trigger('change');
            accountLabel.innerText = 'الحساب المتأثر';
            receivedDiv.style.display = 'none';
            return;
        }

        $sel.prop('disabled', false);
        if (type === 'cash') {
            list = entitiesData.cashboxes;
            label = 'الحساب: الصناديق';
            receivedDiv.style.display = 'block';
        } else if (type === 'credit') {
            list = entitiesData.customers;
            label = 'الحساب: العملاء';
            receivedDiv.style.display = 'none';
        } else if (type === 'bank_transfer') {
            list = entitiesData.banks;
            label = 'الحساب: البنوك';
            receivedDiv.style.display = 'block';
        } else if (type === 'agent') {
            list = entitiesData.agents;
            label = 'الحساب: الوكلاء';
            receivedDiv.style.display = 'none';
        } else {
            receivedDiv.style.display = 'none';
        }

        accountLabel.innerText = label;
        $sel.empty().append('<option value="">-- اختر --</option>');
        
        if (list && list.length > 0) {
            list.forEach(item => {
                const entityId = item.id || '';
                const selected = (
                    (type === 'cash' || type === 'bank_transfer') && item.account_id == "<?php echo $current_account_id; ?>" ||
                    (type === 'credit' && entityId == "<?php echo $current_customer_id; ?>") ||
                    (type === 'agent' && entityId == "<?php echo $current_agent_id; ?>")
                ) ? 'selected' : '';
                $sel.append(`<option value="${item.account_id}" data-entity-id="${entityId}" ${selected}>${item.account_code} - ${item.name}</option>`);
            });
        } else {
            $sel.append('<option value="">لا توجد حسابات متاحة</option>');
        }
        
        $sel.trigger('change');
    }

    function updateFinancialLogic() {
        const recordPurchase = recordPurchaseSwitch.checked;
        const purCurrencyId = mainCurrencySelect.value;
        const saleCurrencyId = saleCurrencySelect.value;

        // supplierDiv.style.display = recordPurchase ? 'block' : 'none'; // Always show as requested

        if (purCurrencyId && saleCurrencyId && purCurrencyId != saleCurrencyId) {
            exchangeContainer.style.display = 'block';
            const purOpt = mainCurrencySelect.options[mainCurrencySelect.selectedIndex];
            const saleOpt = saleCurrencySelect.options[saleCurrencySelect.selectedIndex];
            
            const purSymbol = purOpt.getAttribute('data-symbol') || '---';
            const saleSymbol = saleOpt.getAttribute('data-symbol') || '---';
            const purBuy = parseFloat(purOpt.getAttribute('data-buy')) || 1;
            const saleSell = parseFloat(saleOpt.getAttribute('data-sell')) || 1;
            
            const rate = purBuy / saleSell;
            
            document.querySelectorAll('.pur-symbol').forEach(el => el.textContent = purSymbol);
            document.querySelectorAll('.sale-symbol').forEach(el => el.textContent = saleSymbol);
            document.getElementById('exchange_rate_label').innerHTML = `1 ${purSymbol} = ? ${saleSymbol}`;
            
            exchangeRateInput.value = rate.toFixed(6);
        } else {
            exchangeRateInput.value = '1.000000';
            exchangeContainer.style.display = 'none';
        }
        calculateEquivalent();
    }

    function calculateEquivalent() {
        const cost = parseFloat(purchasePriceInput.value) || 0;
        const sale = parseFloat(salePriceInput.value) || 0;
        const saleCurrencyId = saleCurrencySelect.value;
        const mainCurrencyId = mainCurrencySelect.value;
        const rate = parseFloat(exchangeRateInput.value) || 1;
        
        const saleOpt = saleCurrencySelect.options[saleCurrencySelect.selectedIndex];
        const mainOpt = mainCurrencySelect.options[mainCurrencySelect.selectedIndex];
        const saleSymbol = saleOpt ? saleOpt.getAttribute('data-symbol') : '';
        const mainSymbol = mainOpt ? mainOpt.getAttribute('data-symbol') : '';

        // Cost in Sale Currency
        let costInSale = (saleCurrencyId != mainCurrencyId) ? cost * rate : cost;
        const formattedCostInSale = costInSale.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' ' + saleSymbol;
        
        // Sale in Cost Currency
        let saleInCost = (saleCurrencyId != mainCurrencyId) ? sale / rate : sale;
        const formattedSaleInCost = saleInCost.toLocaleString(undefined, {minimumFractionDigits: 2}) + ' ' + mainSymbol;

        document.getElementById('equivalent_cost_display').textContent = formattedCostInSale;
        
        const saleHint = document.getElementById('sale_price_equivalent_hint');
        const costHint = document.getElementById('cost_price_equivalent_hint');
        
        if (saleCurrencyId != mainCurrencyId) {
            if (cost > 0) {
                saleHint.textContent = `التكلفة: ${formattedCostInSale}`;
                saleHint.style.display = 'block';
            } else {
                saleHint.style.display = 'none';
            }

            if (sale > 0) {
                costHint.textContent = `البيع: ${formattedSaleInCost}`;
                costHint.style.display = 'block';
            } else {
                costHint.style.display = 'none';
            }
        } else {
            saleHint.style.display = 'none';
            costHint.style.display = 'none';
        }
    }

    // Removed redundant listeners since they are handled in handleDeliveryType
    // and the new customer_agent_id listener.

    // Event Listeners
    $('#transaction_type_id').on('select2:select change', function(e) {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption && selectedOption.value) {
            baseSalePrice = parseFloat(selectedOption.getAttribute('data-sale')) || 0;
            basePurchasePrice = parseFloat(selectedOption.getAttribute('data-cost')) || 0;
            baseCurrencyId = selectedOption.getAttribute('data-currency');
            
            // تعبئة الأسعار الأصلية
            salePriceInput.value = baseSalePrice.toFixed(2);
            purchasePriceInput.value = basePurchasePrice.toFixed(2);
            
            // تعبئة العملات الأصلية للتسعيرة (مع مراعاة Select2)
            if (baseCurrencyId) {
                $('#sale_currency_id').val(baseCurrencyId).trigger('change');
                $('#main_currency_id').val(baseCurrencyId).trigger('change');
            }
            
            // تحديث المنطق المالي (سعر الصرف، التلميحات، إلخ)
            updateFinancialLogic();
        }
    });

    $(saleCurrencySelect).on('change', function() {
        if (baseCurrencyId && baseSalePrice) {
            const newPrice = convertPrice(baseSalePrice, baseCurrencyId, this.value);
            salePriceInput.value = newPrice.toFixed(2);
        }
        updateFinancialLogic();
    });

    $(mainCurrencySelect).on('change', function() {
        if (baseCurrencyId && basePurchasePrice) {
            const newPrice = convertPrice(basePurchasePrice, baseCurrencyId, this.value);
            purchasePriceInput.value = newPrice.toFixed(2);
        }
        updateFinancialLogic();
    });

    recordPurchaseSwitch.addEventListener('change', updateFinancialLogic);

    [purchasePriceInput, salePriceInput, exchangeRateInput].forEach(el => {
        el.addEventListener('input', calculateEquivalent);
    });

    // Travel Date Day Name Logic
    const travelDateInput = document.getElementById('travel_date');
    const travelDayName = document.getElementById('travel_day_name');
    
    function updateTravelDay() {
        if (travelDateInput.value) {
            const date = new Date(travelDateInput.value);
            const days = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
            travelDayName.textContent = days[date.getDay()];
        } else {
            travelDayName.textContent = '---';
        }
    }
    
    travelDateInput.addEventListener('change', updateTravelDay);

    // Initial Trigger
    updateFinancialLogic();
    updateTravelDay();
});
</script>

<?php require_once 'footer.php'; ?>
