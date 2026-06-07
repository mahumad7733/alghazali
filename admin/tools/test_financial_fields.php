<?php
ob_start();
require_once 'header.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

// جلب الإعدادات
$settings = getSettings($pdo);

// جلب العملات
$currencies = $pdo->query("SELECT id, currency_code, currency_name, currency_symbol, exchange_rate, exchange_rate_buy, exchange_rate_sell, is_default FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, currency_name ASC")->fetchAll();

// جلب الفروع
$branches = $pdo->query("SELECT id, branch_name FROM branches WHERE deleted_at IS NULL AND status = 'active' ORDER BY branch_name ASC")->fetchAll();

// جلب الخدمات
$services = $pdo->query("SELECT id, service_name FROM services WHERE status = 'active' ORDER BY service_name ASC")->fetchAll();

// جلب الموردين مع حساباتهم
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

// دالة مساعدة لجلب الحسابات تحت حساب أب معين
function get_accounts_under_parent($pdo, $parent_account_code, $entity_type = null) {
    $stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
    $stmt_parent->execute([$parent_account_code]);
    $parent_id = $stmt_parent->fetchColumn();
    if (!$parent_id) return [];
    
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
    while ($row = $stmt->fetch()) {
        $row['display_name'] = $row['account_code'] . ' - ' . $row['account_name_ar'];
        $row['name'] = $row['account_name_ar'];
        $accounts[] = $row;
    }
    return $accounts;
}

$cashboxes_entities = get_accounts_under_parent($pdo, '11101');
$banks_entities = get_accounts_under_parent($pdo, '11102');
$customers_entities = get_accounts_under_parent($pdo, '11201');
$agents_entities = get_accounts_under_parent($pdo, '11203');

// معالجة النموذج عند الإرسال
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo '<div class="container mt-4 alert alert-success">';
    echo '<h4 class="mb-3">✅ البيانات التي تم إرسالها:</h4>';
    echo '<pre style="font-size: 14px;">';
    print_r($_POST);
    echo '</pre>';
    echo '</div>';
}
?>

<div class="container mt-4">
    <h2 class="mb-4">🧪 اختبار الحقول المالية الموحدة (financial_fields.php)</h2>
    
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">نموذج اختبار الحقول المالية</h5>
        </div>
        <div class="card-body">
            <form method="POST">
                <!-- إضافة بعض الحقول الأساسية قبل الحقول المالية -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">اسم العميل (اختبار)</label>
                        <input type="text" name="customer_name" class="form-control" placeholder="اسم العميل">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">رقم الطلب (اختبار)</label>
                        <input type="text" name="order_number" class="form-control" placeholder="رقم الطلب">
                    </div>
                </div>
                
                <!-- الحقول المالية الموحدة -->
                <?php
                // تعيين $current_invoice كقيمة مثال لاختبار الحقول المعبأة
                $current_invoice = [
                    'invoice_date' => date('Y-m-d'),
                    'delivery_type' => 'cash',
                    'total_amount' => 1000,
                    'discount' => 50,
                    'tax_rate' => 10,
                    'cost_amount' => 700
                ];
                $financial_fields_api_url = '../invoices.php';
                include '../../includes/financial_fields.php';
                ?>
                
                <div class="mt-4 text-center">
                    <button type="submit" class="btn btn-primary rounded-pill px-5 py-3">
                        <i class="fas fa-save"></i> إرسال النموذج للاختبار
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>
