<?php
require_once 'includes/db.php';
require_once 'includes/accounting_functions.php';

echo "=== إنشاء فاتورة شراء تجريبية ===\n";

try {
    $pdo->beginTransaction();

    $invoice_date = date('Y-m-d');
    $branch_id = 1;
    $source_type = 'general';
    $source_id = 0;
    $supplier_id = 6; // supplier with account_id=273!
    $currency_id = 1;
    $total_amount = 500.00;
    $discount = 0;
    $cost_amount = 500.00;
    $payment_type = 'credit';
    $delivery_type = 'credit';
    $account_id = null;
    $admin_id = 1; // assuming admin_id=1 exists!

    // Get supplier_account_id
    $stmt_sup = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
    $stmt_sup->execute([$supplier_id]);
    $supplier_account_id = $stmt_sup->fetchColumn();
    echo "Supplier ID: $supplier_id → Supplier Account ID: " . var_export($supplier_account_id, true) . "\n";

    // Generate purchase invoice number
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

    $inv_config = getServiceInvoiceConfig($source_type, $settings);
    $p_pref = $inv_config['purchase_prefix'];
    $stmt_seq = $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(invoice_number, '-', -1) AS UNSIGNED)), 0) FROM invoices WHERE invoice_number LIKE '$p_pref%'");
    $next_num = $stmt_seq->fetchColumn() + 1;
    $purchase_invoice_num = $p_pref . str_pad($next_num, 6, '0', STR_PAD_LEFT);
    echo "Purchase Invoice Number: $purchase_invoice_num\n";

    // Insert purchase invoice
    $stmt_purchase = $pdo->prepare("INSERT INTO invoices (
        invoice_number, invoice_date, branch_id, invoice_category,
        source_type, source_id, supplier_id,
        currency_id, total_amount, discount, cost_amount, payment_type,
        delivery_type, account_id, supplier_account_id, amount_received, description,
        invoice_status, created_by
    ) VALUES (?, ?, ?, 'purchase', ?, ?, ?, ?, ?, 0, ?, 'credit', 'credit', ?, ?, 0, ?, 'draft', ?)");

    $stmt_purchase->execute([
        $purchase_invoice_num,
        $invoice_date,
        $branch_id,
        $source_type,
        $source_id,
        $supplier_id,
        $currency_id,
        $total_amount,
        $cost_amount,
        $supplier_account_id,
        $supplier_account_id,
        "فاتورة شراء تجريبية",
        $admin_id
    ]);
    $new_pur_id = $pdo->lastInsertId();
    echo "Inserted Purchase Invoice ID: $new_pur_id\n";

    $pdo->commit();
    echo "✅ تم إنشاء فاتورة شراء تجريبية بنجاح! رقم الفاتورة: $purchase_invoice_num, المعرف: $new_pur_id\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "❌ خطأ: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
?>