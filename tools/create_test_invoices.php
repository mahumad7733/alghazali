<?php
require_once 'includes/db.php';

echo "=== إنشاء فواتير تجريبية ===\n\n";

try {
    $pdo->beginTransaction();
    $admin_id = 1;
    $branch_id = 1;
    $invoice_date = date('Y-m-d');
    $currency_id = 1;

    // 1. Get system settings
    $settings_sql = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $settings = [];
    while ($row = $settings_sql->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    function getServiceInvoiceConfig($source_type, $settings) {
        $default_prefs = [
            'sales_prefix' => $settings['sales_invoice_prefix'] ?? 'SI-',
            'purchase_prefix' => $settings['purchase_invoice_prefix'] ?? 'PI-',
            'revenue_account_id' => null,
            'cost_account_id' => null
        ];
        return $default_prefs;
    }

    // ======================================================================
    // 2. Create Credit Sale Invoice (فاتورة بيع آجل)
    // ======================================================================
    echo "1. إنشاء فاتورة بيع آجل...\n";

    $customer_id = 1; // assuming customer 1 exists!
    $stmt_customer = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
    $stmt_customer->execute([$customer_id]);
    $customer_account_id = $stmt_customer->fetchColumn();
    echo "   Customer ID: $customer_id, Account ID: " . var_export($customer_account_id, true) . "\n";

    $inv_config_sale = getServiceInvoiceConfig('general', $settings);
    $s_pref = $inv_config_sale['sales_prefix'];
    $stmt_seq_s = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)), 0) FROM invoices WHERE invoice_number LIKE '$s_pref%'");
    $next_num_s = $stmt_seq_s->fetchColumn() + 1;
    $sale_invoice_num = $s_pref . str_pad($next_num_s, 6, '0', STR_PAD_LEFT);

    $stmt_sale = $pdo->prepare("INSERT INTO invoices (
        invoice_number, invoice_date, branch_id, invoice_category,
        source_type, source_id, customer_id,
        currency_id, total_amount, discount, amount_received, description,
        payment_type, delivery_type, account_id, customer_account_id,
        invoice_status, created_by
    ) VALUES (?, ?, ?, 'sales', 'general', 0, ?, ?, 1000, 0, 0, 'فاتورة بيع آجل تجريبية',
        'credit', 'credit', ?, ?, 'draft', ?)");
    $stmt_sale->execute([
        $sale_invoice_num,
        $invoice_date,
        $branch_id,
        $customer_id,
        $currency_id,
        $customer_account_id,
        $customer_account_id,
        $admin_id
    ]);
    $sale_invoice_id = $pdo->lastInsertId();
    echo "   ✅ تم إنشاء فاتورة بيع آجل: رقم $sale_invoice_num, المعرف: $sale_invoice_id\n";

    // ======================================================================
    // 3. Create Credit Purchase Invoice (فاتورة شراء آجل)
    // ======================================================================
    echo "\n2. إنشاء فاتورة شراء آجل...\n";

    $supplier_id = 6; // supplier we used earlier!
    $stmt_supplier = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
    $stmt_supplier->execute([$supplier_id]);
    $supplier_account_id = $stmt_supplier->fetchColumn();
    echo "   Supplier ID: $supplier_id, Account ID: " . var_export($supplier_account_id, true) . "\n";

    $inv_config_purchase = getServiceInvoiceConfig('general', $settings);
    $p_pref = $inv_config_purchase['purchase_prefix'];
    $stmt_seq_p = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)), 0) FROM invoices WHERE invoice_number LIKE '$p_pref%'");
    $next_num_p = $stmt_seq_p->fetchColumn() + 1;
    $purchase_invoice_num = $p_pref . str_pad($next_num_p, 6, '0', STR_PAD_LEFT);

    $stmt_purchase = $pdo->prepare("INSERT INTO invoices (
        invoice_number, invoice_date, branch_id, invoice_category,
        source_type, source_id, supplier_id,
        currency_id, total_amount, discount, cost_amount, amount_received, description,
        payment_type, delivery_type, account_id, supplier_account_id,
        invoice_status, created_by
    ) VALUES (?, ?, ?, 'purchase', 'general', 0, ?, ?, 800, 0, 800, 0, 'فاتورة شراء آجل تجريبية',
        'credit', 'credit', ?, ?, 'draft', ?)");
    $stmt_purchase->execute([
        $purchase_invoice_num,
        $invoice_date,
        $branch_id,
        $supplier_id,
        $currency_id,
        $supplier_account_id,
        $supplier_account_id,
        $admin_id
    ]);
    $purchase_invoice_id = $pdo->lastInsertId();
    echo "   ✅ تم إنشاء فاتورة شراء آجل: رقم $purchase_invoice_num, المعرف: $purchase_invoice_id\n";

    $pdo->commit();

    echo "\n=== كل شيء تم بنجاح! ===\n";
    echo "يمكنك الآن زيارة http://localhost:8000/ghazali/admin/invoices.php ورؤية الفواتير!\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "❌ خطأ: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>