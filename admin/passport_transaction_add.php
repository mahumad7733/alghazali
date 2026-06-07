<?php
ob_start();
require_once 'header.php';

// Check permissions
if (!has_permission('passport_transactions_create')) {
    echo "<script>alert('ليس لديك صلاحية للوصول لهذه الصفحة'); location.href='index.php';</script>";
    exit();
}

// Check if module is enabled
if (!get_module_status($pdo, 'enable_passport_transactions')) {
    $_SESSION['flash_message'] = ['type' => 'warning', 'title' => 'تنبيه', 'body' => 'وحدة معاملات الجوازات معطلة حالياً.'];
    header('Location: index.php');
    exit();
}

$page_title = "إضافة معاملة جوازات جديدة";
$settings = getSettings($pdo);

// Generate transaction number
$transaction_number = '';
if ($settings['passport_auto_numbering'] ?? 1) {
    $prefix = $settings['passport_number_prefix'] ?? 'PAS-';
    $start_number = $settings['passport_start_number'] ?? 1001;
    $number_digits = $settings['passport_number_digits'] ?? 6;

    $stmt_last_num = $pdo->query("SELECT transaction_number FROM passport_transactions ORDER BY id DESC LIMIT 1");
    $last_transaction = $stmt_last_num->fetch(PDO::FETCH_ASSOC);

    if ($last_transaction) {
        $last_num = (int)substr($last_transaction['transaction_number'], strlen($prefix));
        $next_num = $last_num + 1;
    } else {
        $next_num = $start_number;
    }
    $transaction_number = $prefix . str_pad($next_num, $number_digits, '0', STR_PAD_LEFT);
}

// Fetch auxiliary data
$cities = $pdo->query("SELECT id, city_name FROM cities ORDER BY city_name ASC")->fetchAll();
$currencies = $pdo->query("SELECT id, currency_name, currency_symbol, is_default, exchange_rate, exchange_rate_buy, exchange_rate_sell FROM currencies WHERE is_active = 1 ORDER BY currency_name ASC")->fetchAll();
$passport_types = $pdo->query("SELECT id, type_name, default_cost, default_sale_price, currency_id, print_terms FROM passport_transaction_types WHERE is_active = 1 ORDER BY type_name ASC")->fetchAll();
$services = $pdo->query("SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name ASC")->fetchAll();

// Get service id for "معاملات جوازات"
$passportService = $pdo->prepare("SELECT id FROM services WHERE service_name = ? LIMIT 1");
$passportService->execute(['معاملات جوازات']);
$passportServiceId = $passportService->fetchColumn();

// جلب الموردين مع أكواد حساباتهم (مثل suppliers.php!)
$parent_stmt_suppliers = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101'");
$parent_stmt_suppliers->execute();
$suppliers_parent_id = $parent_stmt_suppliers->fetchColumn();

if ($suppliers_parent_id) {
    $suppliers_stmt = $pdo->prepare("
        SELECT coa.*, s.id as supplier_id, s.supplier_name, coa.id as account_id
        FROM unified_accounts coa
        LEFT JOIN suppliers s ON s.account_id = coa.id
        WHERE coa.parent_id = ? AND (coa.account_status = 'active' OR coa.account_status = 'dormant')
        ORDER BY coa.account_code ASC
    ");
    $suppliers_stmt->execute([$suppliers_parent_id]);
    $suppliers_with_codes = $suppliers_stmt->fetchAll();
    
    // Add display_name to each row
    foreach ($suppliers_with_codes as &$s) {
        $s['display_name'] = $s['account_code'] . ' - ' . $s['account_name_ar'];
    }
    unset($s);
} else {
    $suppliers_with_codes = [];
}

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

// Get default status
$default_status_id = null;
if (!empty($settings['passport_default_status'])) {
    $stmt_default_status = $pdo->prepare("SELECT id FROM statuses WHERE status_name = ? LIMIT 1");
    $stmt_default_status->execute([$settings['passport_default_status']]);
    $default_status_id = $stmt_default_status->fetchColumn();
}
if (!$default_status_id) {
    $default_status_id = $pdo->query("SELECT id FROM statuses WHERE status_name = 'معاملة جديدة' LIMIT 1")->fetchColumn();
}

?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="fw-bold text-primary mb-1"><i class="fas fa-plus-circle me-2"></i> إضافة معاملة جوازات جديدة</h3>
            <p class="text-muted small mb-0">إنشاء سجل جديد لمعاملة جواز أو بطاقة</p>
        </div>
        <a href="passport_transactions.php" class="btn btn-light border rounded-pill px-4 shadow-sm">
            <i class="fas fa-arrow-right me-1"></i> عودة للقائمة
        </a>
    </div>

    <form action="process_passport_transaction.php" method="POST" id="transactionForm">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="status_id" value="<?php echo $default_status_id; ?>">
        <input type="hidden" name="workflow_id" value="<?php echo ($settings['passport_workflow_enabled'] ?? 1) ? get_workflow_id_by_transaction_type($pdo, 'passport_transactions') : ''; ?>">
        <input type="hidden" name="created_by" value="<?php echo $_SESSION['admin_id']; ?>">
        <input type="hidden" name="branch_id" value="<?php echo $_SESSION['branch_id'] ?? $currentUser['branch_id'] ?? null; ?>">

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
                                <input type="text" class="form-control rounded-3" name="full_name" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">رقم الهاتف <span class="text-danger">*</span></label>
                                <input type="text" class="form-control rounded-3" name="phone_number" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">مكان الميلاد</label>
                                <input type="text" class="form-control rounded-3" name="place_of_birth">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">تاريخ الميلاد</label>
                                <input type="date" class="form-control rounded-3" name="date_of_birth">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">نوع الهوية</label>
                                <select class="form-select rounded-3" name="id_type">
                                    <option value="passport">جواز سفر</option>
                                    <option value="national_id">هوية وطنية</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">رقم الهوية</label>
                                <input type="text" class="form-control rounded-3" name="id_number">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">من مدينة <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3" name="from_city_id" required>
                                    <option value="">اختر مدينة...</option>
                                    <?php foreach($cities as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">إلى مدينة <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3" name="to_city_id" required>
                                    <option value="">اختر مدينة...</option>
                                    <?php foreach($cities as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['city_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold">تاريخ السفر</label>
                                <div class="input-group">
                                    <input type="date" class="form-control rounded-start-3" name="travel_date" id="travel_date">
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
                                <input type="radio" class="btn-check" name="transaction_type" id="type_both" value="both" checked>
                                <label class="btn btn-outline-primary" for="type_both">بطاقة وجواز</label>
                                
                                <input type="radio" class="btn-check" name="transaction_type" id="type_card" value="card_only">
                                <label class="btn btn-outline-primary" for="type_card">بطاقة فقط</label>
                                
                                <input type="radio" class="btn-check" name="transaction_type" id="type_passport" value="passport_only">
                                <label class="btn btn-outline-primary" for="type_passport">جواز فقط</label>
                            </div>
                        </div>

                        <div id="card_section">
                            <h6 class="fw-bold small text-muted mb-3"><i class="fas fa-id-card me-2"></i> بيانات البطاقة</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم معاملة البطاقة</label>
                                    <input type="text" class="form-control rounded-3" name="card_transaction_number">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ معاملة البطاقة</label>
                                    <input type="date" class="form-control rounded-3" name="card_transaction_date">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم البطاقة</label>
                                    <input type="text" class="form-control rounded-3" name="card_number">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ إصدار البطاقة</label>
                                    <input type="date" class="form-control rounded-3" name="card_issue_date">
                                </div>
                            </div>
                        </div>

                        <div id="passport_section">
                            <h6 class="fw-bold small text-muted mb-3"><i class="fas fa-passport me-2"></i> بيانات الجواز</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم معاملة الجواز</label>
                                    <input type="text" class="form-control rounded-3" name="passport_transaction_number">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ معاملة الجواز</label>
                                    <input type="date" class="form-control rounded-3" name="passport_transaction_date">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">رقم الجواز</label>
                                    <input type="text" class="form-control rounded-3" name="passport_number">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small fw-bold">تاريخ إصدار الجواز</label>
                                    <input type="date" class="form-control rounded-3" name="passport_issue_date">
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-bold">اسم العميل المستلم</label>
                            <input type="text" class="form-control rounded-3" name="delivery_receiver_name">
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <!-- Hidden inputs to maintain form integrity when workflow is enabled -->
                    <input type="hidden" name="transaction_type" value="both">
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
                            <input type="hidden" name="transaction_number" value="<?php echo $transaction_number; ?>">
                            
                            <div class="col-md-12">
                                <label class="form-label small fw-bold text-primary">نوع المعاملة (التسعيرة) <span class="text-danger">*</span></label>
                                <select class="form-select rounded-3 border-primary" name="transaction_type_id[]" id="transaction_type_id" multiple required>
                                    <?php foreach($passport_types as $type): ?>
                                        <option value="<?php echo $type['id']; ?>" 
                                                data-cost="<?php echo $type['default_cost']; ?>" 
                                                data-sale="<?php echo $type['default_sale_price']; ?>"
                                                data-currency="<?php echo $type['currency_id']; ?>"
                                                data-terms="<?php echo htmlspecialchars($type['print_terms'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($type['type_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-12" id="supplier_select_div">
                            <label class="form-label small fw-bold text-danger">المورد <span class="text-danger">*</span></label>
                            <select class="form-select select2-financial" name="supplier_id" id="supplier_id">
                                <option value="">اختر المورد...</option>
                                <?php foreach($suppliers_with_codes as $sup): ?>
                                    <option value="<?php echo $sup['supplier_id']; ?>" data-account="<?php echo $sup['id']; ?>">
                                        <?php echo htmlspecialchars($sup['display_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        </div>

                        <?php
                        // التأكد من تعريف المتغيرات قبل تضمين financial_fields.php
                        $current_invoice = [
                            'invoice_date' => date('Y-m-d'),
                            'branch_id' => $_SESSION['branch_id'] ?? null,
                            'source_type' => 'معاملات جوازات',
                            'delivery_type' => 'cash',
                            'total_amount' => 0,
                            'discount' => 0,
                            'cost_amount' => 0,
                            'amount_received' => 0,
                            'currency_id' => 1,
                            'description' => ''
                        ];
                        $financial_fields_show_service_select = false;
                        include '../includes/financial_fields.php';
                        ?>

                        <div class="row g-3 mt-3">
                            <div class="col-12">
                                <label class="form-label small fw-bold text-muted">ملاحظات إضافية</label>
                                <textarea class="form-control rounded-3 form-control-sm" name="notes" rows="1" placeholder="أي ملاحظات إدارية أخرى..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary rounded-pill py-3 fw-bold shadow">
                        <i class="fas fa-save me-2"></i> حفظ بيانات المعاملة
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

    // Financial Logic Variables (using financial_fields.php fields: payment_type, account_id, etc.)
    const paymentTypeSelect = document.querySelector('select[name="payment_type"]');
    const accountSelect = document.querySelector('select[name="account_id"]');
    const accountLabel = document.getElementById('account_label');
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

        if (!type || type === '') {
            $sel.prop('disabled', true).empty().append('<option value="">-- اختر الحساب --</option>').trigger('change');
            if (accountLabel) accountLabel.innerText = 'الحساب المتأثر';
            if (customerIdHidden) customerIdHidden.value = '';
            if (agentIdHidden) agentIdHidden.value = '';
            return;
        }

        $sel.prop('disabled', false);
        if (type === 'cash') {
            list = entitiesData.cashboxes;
            label = 'الصندوق';
        } else if (type === 'credit') {
            list = entitiesData.customers;
            label = 'العميل';
        } else if (type === 'bank_transfer') {
            list = entitiesData.banks;
            label = 'البنك';
        } else if (type === 'agent') {
            list = entitiesData.agents;
            label = 'الوكيل';
        }

        if (accountLabel) accountLabel.innerText = label;
        $sel.empty().append('<option value="">-- اختر الحساب --</option>');
        
        if (list && list.length > 0) {
            list.forEach(item => {
                const entityId = item.id || '';
                $sel.append(`<option value="${item.account_id}" data-customer-id="${(type === 'credit') ? entityId : ''}" data-agent-id="${(type === 'agent') ? entityId : ''}">${item.account_code} - ${item.name}</option>`);
            });
        }
        
        $sel.trigger('change');
    }

    $(document).on('change', 'select[name="payment_type"]', function() {
        handleDeliveryType(this.value);
    });

    $(document).on('change', 'select[name="account_id"]', function() {
        const type = paymentTypeSelect.value;
        const selectedOpt = $(this).find(':selected');
        const entityId = selectedOpt.attr('data-customer-id') || selectedOpt.attr('data-agent-id') || selectedOpt.attr('data-entity-id');
        
        if (customerIdHidden) customerIdHidden.value = (type === 'credit' ? entityId : '');
        if (agentIdHidden) agentIdHidden.value = (type === 'agent' ? entityId : '');
    });

    const transactionTypeSelect = document.getElementById('transaction_type_id');
    const totalAmountInput = document.querySelector('input[name="total_amount"]');
    const costAmountInput = document.querySelector('input[name="cost_amount"]');

    // Event Listeners for transaction type select
    function updateTotalsFromTransactionType() {
        let totalSale = 0;
        let totalCost = 0;
        
        const selectedOptions = transactionTypeSelect.selectedOptions;
        for (let i = 0; i < selectedOptions.length; i++) {
            const opt = selectedOptions[i];
            totalSale += parseFloat(opt.getAttribute('data-sale')) || 0;
            totalCost += parseFloat(opt.getAttribute('data-cost')) || 0;
        }
        
        if (totalAmountInput) {
            totalAmountInput.value = totalSale.toFixed(2);
            totalAmountInput.dispatchEvent(new Event('input'));
        }
        
        if (costAmountInput) {
            costAmountInput.value = totalCost.toFixed(2);
            costAmountInput.dispatchEvent(new Event('input'));
        }
    }
    
    $('#transaction_type_id').on('select2:select select2:unselect change', function() {
        updateTotalsFromTransactionType();
        fetchPricing(); // Also fetch service pricing when transaction type changes
    });
    transactionTypeSelect.addEventListener('change', function() {
        updateTotalsFromTransactionType();
        fetchPricing();
    });

    // Fetch service pricing function
    function fetchPricing() {
        const serviceId = <?php echo json_encode($passportServiceId); ?>;
        const customerId = $('#customer_id_hidden').val();
        const agentId = $('#agent_id_hidden').val();
        const branchId = <?php echo json_encode($_SESSION['branch_id'] ?? null); ?>;
        const supplierId = $('select[name="supplier_id"]').val();

        if (!serviceId) return;

        $.ajax({
            url: 'ajax_get_service_price.php',
            data: {
                service_id: serviceId,
                customer_id: customerId,
                agent_id: agentId,
                branch_id: branchId,
                supplier_id: supplierId
            },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    // Update fields from service prices
                    $('input[name="total_amount"]').val(res.sale_price).attr('data-original-price', res.sale_price).attr('data-service-currency-id', res.currency_id);
                    $('input[name="cost_amount"]').val(res.purchase_price).attr('data-original-cost', res.purchase_price).attr('data-cost-service-currency-id', res.currency_id);

                    if (res.currency_id) {
                        // Check if financial_fields.php has currency selectors
                        const currencySelect = $('select[name="currency_id"]');
                        if (currencySelect.length > 0) {
                            currencySelect.val(res.currency_id).trigger('change');
                        }
                    }
                }
            }
        });
    }

    // Call fetchPricing when account or supplier changes
    $(document).on('change', 'select[name="account_id"]', fetchPricing);
    $(document).on('change', 'select[name="supplier_id"]', fetchPricing);
    
    // Initial fetch
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(fetchPricing, 500);
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
    
    if (travelDateInput) {
        travelDateInput.addEventListener('change', updateTravelDay);
        updateTravelDay();
    }
});
</script>

<?php require_once 'footer.php'; ?>
