<?php
require_once 'CurrencyExchange.php';

// Initialize currency exchange when needed
function get_currency_exchange($pdo) {
    static $currencyExchange = null;
    if ($currencyExchange === null) {
        $currencyExchange = new CurrencyExchange($pdo);
    }
    return $currencyExchange;
}

function get_base_currency($pdo) {
    static $baseCurrency = null;
    static $base_currency_id = null;
    if ($baseCurrency === null) {
        $currencyExchange = get_currency_exchange($pdo);
        $baseCurrency = $currencyExchange->getBaseCurrency();
        $base_currency_id = $baseCurrency['id'] ?? null;
    }
    return ['currency' => $baseCurrency, 'id' => $base_currency_id];
}

/**
 * النظام المحاسبي الموحد - وكالة الغزالي للسفريات والسياحة
 * النسخة النهائية الموحدة المتوافقة مع الإجراءات المخزنة الجديدة
 */

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_start();
}

/**
 * التحقق مما إذا كان التاريخ يقع ضمن فترة مالية مغلقة
 */
function is_period_closed($pdo, $date)
{
    $start_date = get_setting('fiscal_start_date');
    $end_date = get_setting('fiscal_end_date');

    $check_time = strtotime($date);

    // إذا تم تحديد تاريخ بداية، يجب ألا تكون العملية قبله
    if ($start_date) {
        $start_time = strtotime($start_date);
        if ($check_time < $start_time) return true;
    }

    // إذا تم تحديد تاريخ نهاية، يجب ألا تكون العملية بعده
    if ($end_date) {
        $end_time = strtotime($end_date);
        if ($check_time > $end_time) return true;
    }

    return false;
}

/**
 * التحقق مما إذا كان يمكن حذف حساب محاسبي
 */
function can_delete_account($account_id)
{
    global $pdo;
    try {
        // 1. التحقق من وجود حركات في دفتر اليومية
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM journal_lines WHERE account_id = ?");
        $stmt->execute([$account_id]);
        if ($stmt->fetchColumn() > 0) return false;

        // 2. التحقق من وجود حركات مالية (سندات) مرتبطة مباشرة
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM financial_transactions WHERE party_account_id = ? OR cash_bank_account_id = ?");
        $stmt->execute([$account_id, $account_id]);
        if ($stmt->fetchColumn() > 0) return false;

        // 3. التحقق من وجود أرصدة غير صفرية
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM account_balances_unified WHERE account_id = ? AND (opening_balance != 0 OR current_balance != 0)");
        $stmt->execute([$account_id]);
        if ($stmt->fetchColumn() > 0) return false;

        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * جلب قيمة إعداد معين
 */
function get_setting($key, $default = null)
{
    global $pdo;
    static $settings_cache = null;
    if ($settings_cache === null) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'system_settings'");
            if ($stmt->rowCount() > 0) {
                $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
                $settings_cache = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
            } else {
                $settings_cache = [];
            }
        } catch (PDOException $e) {
            $settings_cache = [];
        }
    }
    return $settings_cache[$key] ?? $default;
}

/**
 * جلب رقم تسلسلي جديد من النظام الموحد بناءً على الخدمة ونوع الحركة
 */
function generate_unified_number($pdo, $type, $service_type = null)
{
    $prefix = '';
    $digits = 6;

    // جلب الإعدادات بناءً على الخدمة
    if ($service_type) {
        $srv_map = [
            'النقل البري' => 'flight',
            'تذاكر طيران وبصات' => 'flight',
            'الطيران' => 'flight',
            'bus' => 'flight',
            'flight' => 'flight',
            'تأشيرة عمل' => 'work_visa',
            'work_visa' => 'work_visa',
            'قسم العمرة' => 'umrah',
            'زيارة عائلية' => 'family_visit',
            'الزيارة العائلية' => 'family_visit',
            'family_visit' => 'family_visit',
            'معاملات الجوازات' => 'passport'
        ];

        $srv_key = $srv_map[$service_type] ?? null;
        if ($srv_key) {
            $prefix = get_setting("srv_{$srv_key}_" . ($type == 'purchase' ? 'purchase' : 'sales') . "_prefix");
            $digits = get_setting("srv_{$srv_key}_digits", 6);
        }
    }

    // إذا لم تتوفر إعدادات خاصة بالخدمة، نستخدم الإعدادات العامة
    if (empty($prefix)) {
        $prefix = get_setting($type == 'purchase' ? 'purchase_invoice_prefix' : 'sales_invoice_prefix', ($type == 'purchase' ? 'PI-' : 'SI-'));
        $digits = get_setting('invoice_number_digits', 6);
    }

    $year = date('y');
    $full_prefix = str_replace('{year}', $year, $prefix);

    // البحث عن آخر رقم مستخدم بهذا البادئة
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(invoice_number, LENGTH(?) + 1) AS UNSIGNED)) FROM invoices WHERE invoice_number LIKE ?");
    $stmt->execute([$full_prefix, $full_prefix . '%']);
    $last_num = (int)$stmt->fetchColumn();

    return $full_prefix . str_pad($last_num + 1, $digits, '0', STR_PAD_LEFT);
}

/**
 * إنشاء فاتورة موحدة (بدون ترحيل تلقائي) - للترحيل اليدوي
 */
function php_create_invoice(
    $pdo,
    $category, // 'sales' or 'purchase'
    $branch_id,
    $source_type,
    $source_id,
    $party_id, // customer_id or supplier_id
    $currency_id,
    $total_amount,
    $discount = 0,
    $cost_amount = 0,
    $payment_type = 'cash',
    $description = '',
    $created_by = null,
    $agent_id = null,
    $branch_entity_id = null,
    $cost_center_id = null
) {
    try {
        // التحقق من إغلاق الفترة المالية
        $invoice_date = date('Y-m-d');
        if (is_period_closed($pdo, $invoice_date)) {
            throw new Exception("تنبيه: لا يمكن إنشاء الفاتورة. التاريخ المحدد ($invoice_date) يقع ضمن فترة مالية مغلقة.");
        }

        $customer_id = ($category == 'sales') ? $party_id : null;
        $supplier_id = ($category == 'purchase') ? $party_id : null;
        $user_id = $created_by ?: ($_SESSION['admin_id'] ?? 1);

        // جلب الإعدادات لتوليد رقم الفاتورة
        $settings = [];
        $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        while ($row = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        // توليد رقم الفاتورة
        $invoice_data = generateInvoiceNumber($pdo, $source_type, $category, $settings);
        $invoice_number = $invoice_data['number'];

        // إنشاء الفاتورة في وضع المسودة (بدون ترحيل)
        $stmt = $pdo->prepare("CALL sp_create_invoice(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @invoice_id)");
        $stmt->execute([
            $category,
            $branch_id,
            $source_type,
            $source_id,
            $customer_id,
            $supplier_id,
            $agent_id,
            $branch_entity_id,
            $currency_id,
            $total_amount,
            $discount,
            $cost_amount,
            $payment_type,
            $description,
            $user_id,
            $cost_center_id,
            $invoice_number
        ]);
        $stmt->closeCursor();

        $invoice_id = $pdo->query("SELECT @invoice_id")->fetchColumn();

        // جلب حساب العميل أو المورد إذا لم يكن محدداً
        $customer_account_id = null;
        $supplier_account_id = null;
        if ($customer_id) {
            $stmt = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
            $stmt->execute([$customer_id]);
            $customer_account_id = $stmt->fetchColumn() ?: null;
        }
        if ($supplier_id) {
            $stmt = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
            $stmt->execute([$supplier_id]);
            $supplier_account_id = $stmt->fetchColumn() ?: null;
        }

        // تحديث الحسابات في الفاتورة
        $update_sql = "UPDATE invoices SET account_id = ?, customer_account_id = ?, supplier_account_id = ? WHERE id = ?";
        $account_id_for_update = null;
        if ($category == 'sales') {
            $account_id_for_update = $customer_account_id ?: $branch_entity_id;
        } else {
            $account_id_for_update = $supplier_account_id ?: $branch_entity_id;
        }
        $pdo->prepare($update_sql)->execute([$account_id_for_update, $customer_account_id, $supplier_account_id, $invoice_id]);

        // الفاتورة تبقى في وضع المسودة - الترحيل يدوي لاحقاً
        return $invoice_id;
    } catch (Exception $e) {
        error_log("Error in php_create_invoice: " . $e->getMessage());
        throw $e;
    }
}

/**
 * ترحيل فاتورة يدوياً (POST)
 * يتم استدعاء هذه الدالة عند الترحيل اليدوي
 */
function php_post_invoice($pdo, $invoice_id, $posted_by = null, $use_outer_transaction = false) {
    try {
        $user_id = $posted_by ?: ($_SESSION['admin_id'] ?? 1);

        // التحقق من حالة الفاتورة
        $stmt = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $stmt->execute([$invoice_id]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$invoice) {
            throw new Exception("الفاتورة غير موجودة");
        }

        if ($invoice['invoice_status'] === 'posted') {
            throw new Exception("الفاتورة مُرحلة بالفعل");
        }

        // جلب الإعدادات
        $settings = [];
        $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        while ($row = $stmt_settings->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        // جلب تكوين الخدمة لحساب الإيرادات/التكاليف/الأرباح
        $srv_config = getServiceInvoiceConfig($invoice['source_type'], $settings);
        
        // التحقق من الحدود المالية قبل الترحيل
        if ($invoice['customer_id']) {
            $stmt_acc = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
            $stmt_acc->execute([$invoice['customer_id']]);
            $acc_id = $stmt_acc->fetchColumn();
            if ($acc_id) check_account_limits($pdo, $acc_id, $invoice['currency_id'], $invoice['total_amount']);
        } elseif ($invoice['supplier_id']) {
            $stmt_acc = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
            $stmt_acc->execute([$invoice['supplier_id']]);
            $acc_id = $stmt_acc->fetchColumn();
            if ($acc_id) check_account_limits($pdo, $acc_id, $invoice['currency_id'], $invoice['total_amount']);
        }

        // بدء معاملة فقط إذا لم يكن هناك معاملة بالفعل
        if (!$use_outer_transaction) {
            $pdo->beginTransaction();
        }

        // 1. تحديث حالة الفاتورة إلى posted أولاً
        $pdo->prepare("UPDATE invoices SET invoice_status = 'posted', posted_by = ?, posted_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$user_id, $invoice_id]);

        // 2. إنشاء المعاملة المالية والقيود اليومية
        $invoice_date = $invoice['invoice_date'] ?? date('Y-m-d');
        $description = $invoice['description'] ?? 'فاتورة ' . ($invoice['invoice_category'] == 'sales' ? 'بيع' : 'شراء') . ' رقم ' . $invoice['invoice_number'];

        // --- لفواتير البيع ---
        if ($invoice['invoice_category'] == 'sales') {
            // جلب حسابات الخدمة (الإيرادات فقط - تكاليف وأرباح ترحل مع فاتورة الشراء)
            $revenue_account_id = $srv_config['revenue_account_id'] ?? ($settings['default_sales_account_id'] ?? null);
            
            // جلب حساب العميل أو الوكيل أو الفرع أو الصندوق/البنك
            $party_account_id = null;
            if ($invoice['customer_account_id']) {
                $party_account_id = $invoice['customer_account_id'];
            } elseif ($invoice['customer_id']) {
                $stmt_customer = $pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
                $stmt_customer->execute([$invoice['customer_id']]);
                $party_account_id = $stmt_customer->fetchColumn();
            } elseif ($invoice['agent_id']) {
                $stmt_agent = $pdo->prepare("SELECT account_id FROM agents WHERE id = ?");
                $stmt_agent->execute([$invoice['agent_id']]);
                $party_account_id = $stmt_agent->fetchColumn();
            } elseif ($invoice['account_id']) {
                // Fallback: use account_id from invoice if it's a customer/agent account
                $party_account_id = $invoice['account_id'];
            }
            
            $cash_bank_account_id = $invoice['account_id'];
            
            $net_amount = (float)$invoice['total_amount'] - (float)$invoice['discount'];

            // توليد رقم المعاملة
            $trx_num = fn_get_next_sequence($pdo, 'invoice');
            
            // إنشاء المعاملة المالية
            $stmt_trx = $pdo->prepare("INSERT INTO financial_transactions (transaction_number, transaction_date, reference_type, reference_id, description, transaction_type, amount, currency_id, branch_id, created_by, status) VALUES (?, ?, 'invoice', ?, ?, 'invoice', ?, ?, ?, ?, 'posted')");
            $stmt_trx->execute([$trx_num, $invoice_date, $invoice_id, $description, $net_amount, $invoice['currency_id'], $invoice['branch_id'], $user_id]);
            $trx_id = $pdo->lastInsertId();

            // --- إنشاء قيود اليومية ---
            
            // 1. إذا كان الدفع نقداً أو تحويل بنكي: مدين للصندوق/البنك
            if (($invoice['delivery_type'] == 'cash' || $invoice['delivery_type'] == 'bank_transfer') && $cash_bank_account_id) {
                $received = (float)$invoice['amount_received'] ?? $net_amount;
                if ($received > 0) {
                    $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, ?, 0, ?, ?, ?)");
                    $stmt_jrl->execute([$trx_id, $cash_bank_account_id, $received, $invoice['currency_id'], $invoice['branch_id'], 'إيداع نقدي/تحويل بنكي لفواتير البيع']);
                }
                // 2. إذا كان هناك باقي: مدين للعميل
                if ($net_amount > $received) {
                    $remaining = $net_amount - $received;
                    if ($party_account_id) {
                        $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, ?, 0, ?, ?, ?)");
                        $stmt_jrl->execute([$trx_id, $party_account_id, $remaining, $invoice['currency_id'], $invoice['branch_id'], 'مديونية العميل (المتبقي)']);
                    }
                }
            }
            // 3. إذا كان دفعة آجلة بالكامل
            elseif ($invoice['delivery_type'] == 'credit' || $invoice['delivery_type'] == 'on_account') {
                if ($party_account_id) {
                    $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, ?, 0, ?, ?, ?)");
                    $stmt_jrl->execute([$trx_id, $party_account_id, $net_amount, $invoice['currency_id'], $invoice['branch_id'], 'مديونية العميل']);
                }
            }

            // 4. دائن للإيرادات
            if ($revenue_account_id) {
                $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, 0, ?, ?, ?, ?)");
                $stmt_jrl->execute([$trx_id, $revenue_account_id, $net_amount, $invoice['currency_id'], $invoice['branch_id'], 'إيرادات فاتورة بيع']);
            }
        }
        // --- لفواتير الشراء ---
        else {
            // جلب حساب المورد
            $party_account_id = null;
            if ($invoice['supplier_account_id']) {
                $party_account_id = $invoice['supplier_account_id'];
            } elseif ($invoice['supplier_id']) {
                $stmt_supp = $pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
                $stmt_supp->execute([$invoice['supplier_id']]);
                $party_account_id = $stmt_supp->fetchColumn();
            }

            // جلب حساب التكاليف للخدمة
            $cost_account_id = $srv_config['cost_account_id'] ?? ($settings['default_cost_account_id'] ?? null);
            
            $total_amount = (float)$invoice['total_amount'];
            
            // توليد رقم المعاملة
            $trx_num = fn_get_next_sequence($pdo, 'purchase');
            
            // إنشاء المعاملة المالية
            $stmt_trx = $pdo->prepare("INSERT INTO financial_transactions (transaction_number, transaction_date, reference_type, reference_id, description, transaction_type, amount, currency_id, branch_id, created_by, status) VALUES (?, ?, 'invoice', ?, ?, 'purchase', ?, ?, ?, ?, 'posted')");
            $stmt_trx->execute([$trx_num, $invoice_date, $invoice_id, $description, $total_amount, $invoice['currency_id'], $invoice['branch_id'], $user_id]);
            $trx_id = $pdo->lastInsertId();

            // --- إنشاء قيود اليومية ---
            // 1. مدين للتكاليف
            if ($cost_account_id) {
                $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, ?, 0, ?, ?, ?)");
                $stmt_jrl->execute([$trx_id, $cost_account_id, $total_amount, $invoice['currency_id'], $invoice['branch_id'], 'تكلفة فاتورة شراء']);
            }

            // 2. دائن للمورد
            if ($party_account_id) {
                $stmt_jrl = $pdo->prepare("INSERT INTO journal_lines (financial_transaction_id, account_id, debit, credit, currency_id, branch_id, description) VALUES (?, ?, 0, ?, ?, ?, ?)");
                $stmt_jrl->execute([$trx_id, $party_account_id, $total_amount, $invoice['currency_id'], $invoice['branch_id'], 'مديونية للمورد']);
            }
        }

        if (!$use_outer_transaction) {
            $pdo->commit();
        }
        return true;
    } catch (Exception $e) {
        if (!$use_outer_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in php_post_invoice: " . $e->getMessage());
        throw $e;
    }
}

/**
 * إنشاء فاتورة موحدة وترحيلها محاسبياً (للترحيل التلقائي عند الحاجة)
 * @deprecated استخدم php_create_invoice + php_post_invoice للترحيل اليدوي
 */
function php_create_invoice_and_post(
    $pdo,
    $category, // 'sales' or 'purchase'
    $branch_id,
    $source_type,
    $source_id,
    $party_id, // customer_id or supplier_id
    $currency_id,
    $total_amount,
    $discount = 0,
    $cost_amount = 0,
    $payment_type = 'cash',
    $description = '',
    $created_by = null,
    $agent_id = null,
    $branch_entity_id = null,
    $cost_center_id = null
) {
    // إنشاء الفاتورة أولاً
    $invoice_id = php_create_invoice(
        $pdo,
        $category,
        $branch_id,
        $source_type,
        $source_id,
        $party_id,
        $currency_id,
        $total_amount,
        $discount,
        $cost_amount,
        $payment_type,
        $description,
        $created_by,
        $agent_id,
        $branch_entity_id,
        $cost_center_id
    );

    // ثم ترحيلها تلقائياً إذا لم تكن مسودة
    if ($payment_type !== 'draft') {
        php_post_invoice($pdo, $invoice_id, $created_by);
    }

    return $invoice_id;
}

/**
 * تسجيل سجل تغييرات للحركات المالية
 */
function log_financial_transaction_change($pdo, $transaction_id, $type, $details = null)
{
    try {
        // التحقق من إغلاق الفترة المالية
        $trx_date = date('Y-m-d');
        if (is_period_closed($pdo, $trx_date)) {
            throw new Exception("تنبيه: لا يمكن تنفيذ العملية. التاريخ المحدد ($trx_date) يقع ضمن فترة مالية مغلقة.");
        }

        $user_id = $_SESSION['admin_id'] ?? 1;
        $stmt = $pdo->prepare("INSERT INTO financial_transaction_logs (transaction_id, changed_by, change_type, details) VALUES (?, ?, ?, ?)");
        $stmt->execute([$transaction_id, $user_id, $type, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null]);
    } catch (Exception $e) {
        error_log("Error in log_financial_transaction_change: " . $e->getMessage());
    }
}

/**
 * إنشاء أو تعديل سند قبض/صرف موحد وترحيله
 */
function php_create_voucher_and_post(
    $pdo,
    $type, // 'receipt' or 'payment'
    $branch_id,
    $entity_type,
    $entity_id,
    $amount,
    $currency_id,
    $cash_bank_account_id,
    $party_account_id,
    $description = '',
    $reference = '',
    $allocations_json = null,
    $cost_center_id = null,
    $edit_id = null
) {
    try {
        $user_id = $_SESSION['admin_id'] ?? 1;
        $old_data = null;

        if ($edit_id) {
            // جلب البيانات القديمة قبل التعديل
            $stmt_old = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
            $stmt_old->execute([$edit_id]);
            $old_data = $stmt_old->fetch(PDO::FETCH_ASSOC);

            // إلغاء القيد القديم قبل التعديل (عكس الأرصدة)
            // First, reverse the balances
            if ($old_data['status'] == 'posted') {
                $stmt_lines = $pdo->prepare("SELECT account_id, debit, credit, currency_id, branch_id FROM journal_lines WHERE financial_transaction_id = ?");
                $stmt_lines->execute([$edit_id]);
                $lines = $stmt_lines->fetchAll(PDO::FETCH_ASSOC);

                $default_branch_id = $old_data['branch_id'];

                foreach ($lines as $line) {
                    $account_id = $line['account_id'];
                    $curr_id = $line['currency_id'];
                    $line_branch_id = $line['branch_id'] ?? $default_branch_id;
                    $change_amount = $line['credit'] - $line['debit']; // reverse

                    $stmt_curr = $pdo->prepare("SELECT exchange_rate, currency_code FROM currencies WHERE id = ?");
                    $stmt_curr->execute([$curr_id]);
                    $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
                    $rate = (float)($curr['exchange_rate'] ?? 1);
                    $currency_code = $curr['currency_code'] ?? '';
                    $change_base = $change_amount * $rate;

                    // First check if the row exists
                    if ($line_branch_id === null) {
                        $stmt_check = $pdo->prepare("
                            SELECT id FROM account_balances_unified 
                            WHERE account_id = ? AND branch_id IS NULL AND currency_id = ?
                        ");
                        $stmt_check->execute([$account_id, $curr_id]);
                    } else {
                        $stmt_check = $pdo->prepare("
                            SELECT id FROM account_balances_unified 
                            WHERE account_id = ? AND branch_id = ? AND currency_id = ?
                        ");
                        $stmt_check->execute([$account_id, $line_branch_id, $curr_id]);
                    }
                    $exists = $stmt_check->fetch(PDO::FETCH_ASSOC);

                    if ($exists) {
                        // Update existing row
                        $stmt_upd = $pdo->prepare("
                            UPDATE account_balances_unified 
                            SET 
                                current_balance = current_balance + ?, 
                                current_balance_base = current_balance_base + ?,
                                currency_code = ?
                            WHERE id = ?
                        ");
                        $stmt_upd->execute([$change_amount, $change_base, $currency_code, $exists['id']]);
                    } else {
                        // Insert new row with all required columns
                        $stmt_ins = $pdo->prepare("
                            INSERT INTO account_balances_unified (
                                account_id, branch_id, currency_id, currency_code,
                                opening_balance, current_balance, current_balance_base,
                                opening_balance_base, credit_limit, debit_limit, is_frozen
                            ) VALUES (?, ?, ?, ?, 0, ?, ?, 0, 0, 0, 0)
                        ");
                        $stmt_ins->execute([$account_id, $line_branch_id, $curr_id, $currency_code, $change_amount, $change_base]);
                    }
                }

                // Delete old journal lines
                $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$edit_id]);
            }

            // تحديث حالة السند إلى مسودة
            $pdo->prepare("UPDATE financial_transactions SET status = 'cancelled' WHERE id = ?")->execute([$edit_id]);
        }

        if ($type == 'receipt') {
            // التحقق من الحدود المالية (سند القبض هو Credit للطرف الآخر)
            $stmt_norm = $pdo->prepare("SELECT normal_balance FROM unified_accounts WHERE id = ?");
            $stmt_norm->execute([$party_account_id]);
            $norm = $stmt_norm->fetchColumn();
            $change = ($norm == 'debit') ? -$amount : $amount;
            check_account_limits($pdo, $party_account_id, $currency_id, $change);
        } else {
            // التحقق من الحدود المالية (سند الصرف هو Debit للطرف الآخر)
            $stmt_norm = $pdo->prepare("SELECT normal_balance FROM unified_accounts WHERE id = ?");
            $stmt_norm->execute([$party_account_id]);
            $norm = $stmt_norm->fetchColumn();
            $change = ($norm == 'debit') ? $amount : -$amount;
            check_account_limits($pdo, $party_account_id, $currency_id, $change);
        }

        // Get exchange rate for the currency
        $stmt_curr = $pdo->prepare("SELECT exchange_rate FROM currencies WHERE id = ?");
        $stmt_curr->execute([$currency_id]);
        $exchange_rate = (float)($stmt_curr->fetchColumn() ?: 1);

        if ($edit_id) {
            // تحديث السند بدلاً من إنشائه
            $stmt = $pdo->prepare("UPDATE financial_transactions SET
                transaction_date = CURRENT_DATE, branch_id = ?, entity_type = ?, entity_id = ?,
                amount = ?, currency_id = ?, cash_bank_account_id = ?, party_account_id = ?,
                reference_number = ?, description = ?, updated_at = CURRENT_TIMESTAMP, updated_by = ?,
                status = 'draft', cost_center_id = ?, exchange_rate = ?
                WHERE id = ?");
            $stmt->execute([$branch_id, $entity_type, $entity_id, $amount, $currency_id, $cash_bank_account_id, $party_account_id, $reference, $description, $user_id, $cost_center_id, $exchange_rate, $edit_id]);
            $voucher_id = $edit_id;
        } else {
            // إنشاء سند جديد
            $transaction_number = fn_get_next_sequence($pdo, $type);
            $stmt = $pdo->prepare("INSERT INTO financial_transactions (
                transaction_number, transaction_date, branch_id, transaction_type,
                entity_type, entity_id, amount, currency_id, cash_bank_account_id, party_account_id,
                reference_number, description, cost_center_id, created_by, exchange_rate, status
            ) VALUES (?, CURRENT_DATE, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $transaction_number, $branch_id, $type,
                $entity_type, $entity_id, $amount, $currency_id, $cash_bank_account_id, $party_account_id,
                $reference, $description, $cost_center_id, $user_id, $exchange_rate, 'draft'
            ]);
            $voucher_id = $pdo->lastInsertId();
        }

        // ترحيل السند
        if ($type == 'receipt') {
            php_post_receipt_voucher($pdo, $voucher_id, $user_id);
        } else {
            php_post_payment_voucher($pdo, $voucher_id, $user_id);
        }

        // تسجيل السجل
        if ($edit_id) {
            $new_data = ['amount' => $amount, 'description' => $description, 'currency_id' => $currency_id, 'account_id' => $cash_bank_account_id];
            $changes = [];
            foreach ($new_data as $key => $val) {
                if (isset($old_data[$key]) && $old_data[$key] != $val) {
                    $changes[$key] = ['old' => $old_data[$key], 'new' => $val];
                }
            }
            log_financial_transaction_change($pdo, $voucher_id, 'update', ['changes' => $changes]);
        } else {
            log_financial_transaction_change($pdo, $voucher_id, 'create');
        }

        // Handle allocations if provided
        if ($allocations_json && !$edit_id) {
            $allocations = json_decode($allocations_json, true);
            if (is_array($allocations)) {
                foreach ($allocations as $alloc) {
                    if (isset($alloc['invoice_id']) && isset($alloc['amount'])) {
                        $stmt_alloc = $pdo->prepare("INSERT INTO payment_allocations (financial_transaction_id, invoice_id, amount) VALUES (?, ?, ?)");
                        $stmt_alloc->execute([$voucher_id, $alloc['invoice_id'], $alloc['amount']]);
                        php_recalculate_invoice_payment($pdo, $alloc['invoice_id']);
                    }
                }
            }
        }

        return $voucher_id;
    } catch (Exception $e) {
        error_log("Error in php_create_voucher_and_post: " . $e->getMessage());
        throw $e;
    }
}

/**
 * إنشاء حساب تلقائي للكيانات في شجرة الحسابات الموحدة
 */
function php_handle_entity_account_creation($pdo, $entity_type, $entity_id, $entity_name)
{
    try {
        $parent_code = '';
        $type = '';
        $normal = '';

        switch ($entity_type) {
            case 'customer':
                $parent_code = '11201';
                $type = 'receivable';
                $normal = 'debit';
                break;
            case 'agent':
                $parent_code = '11203';
                $type = 'agent';
                $normal = 'debit';
                break;
            case 'branch':
                $parent_code = '11202';
                $type = 'branch';
                $normal = 'debit';
                break;
            case 'supplier':
                $parent_code = '21101';
                $type = 'payable';
                $normal = 'credit';
                break;
            case 'employee':
                $parent_code = '21103';
                $type = 'liability';
                $normal = 'credit';
                break;
        }

        if (!$parent_code) return false;

        $stmt_parent = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $stmt_parent->execute([$parent_code]);
        $parent_id = $stmt_parent->fetchColumn();

        if (!$parent_id) return false;

        $account_code = $parent_code . str_pad($entity_id, 4, '0', STR_PAD_LEFT);

        // التحقق من وجود الحساب
        $stmt_check = $pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = ?");
        $stmt_check->execute([$account_code]);
        $existing_id = $stmt_check->fetchColumn();
        if ($existing_id) return $existing_id;

        $stmt = $pdo->prepare("INSERT INTO unified_accounts (parent_id, account_code, account_name_ar, account_type, owner_type, normal_balance) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$parent_id, $account_code, $entity_name, $type, $entity_type, $normal]);
$account_id = $pdo->lastInsertId();

        // Add the base currency to the account balances unified table
        $base = get_base_currency($pdo);
        $base_currency_id = $base['id'];
        if ($base_currency_id) {
            $stmt_curr = $pdo->prepare("SELECT currency_code FROM currencies WHERE id = ?");
            $stmt_curr->execute([$base_currency_id]);
            $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
            $currency_code = $curr['currency_code'] ?? '';
            
            $stmt_base_balance = $pdo->prepare("INSERT IGNORE INTO account_balances_unified (account_id, branch_id, currency_id, currency_code, opening_balance, current_balance, opening_balance_base, current_balance_base, is_frozen, credit_limit, debit_limit) VALUES (?, NULL, ?, ?, 0, 0, 0, 0, 0, 0, 0)");
            $stmt_base_balance->execute([$account_id, $base_currency_id, $currency_code]);
        }

        $table = ($entity_type == 'customer') ? 'customers' : (($entity_type == 'agent') ? 'agents' : (($entity_type == 'branch') ? 'branches' : (($entity_type == 'supplier') ? 'suppliers' : 'employees')));
        $pdo->prepare("UPDATE `$table` SET account_id = ? WHERE id = ?")->execute([$account_id, $entity_id]);

        return $account_id;
    } catch (PDOException $e) {
        error_log("Error: " . $e->getMessage());
        return false;
    }
}

/**
 * جلب سعر الصرف بين عملتين
 */
function get_exchange_rate($from_currency_id, $to_currency_id, $type = 'sell')
{
    global $pdo;

    if ($from_currency_id == $to_currency_id) return 1.0;

    $stmt = $pdo->prepare("SELECT id, exchange_rate, exchange_rate_sell, exchange_rate_buy FROM currencies WHERE id IN (?, ?)");
    $stmt->execute([$from_currency_id, $to_currency_id]);
    $currencies = $stmt->fetchAll(PDO::FETCH_UNIQUE);

    if (count($currencies) < 2) return 0;

    $from = $currencies[$from_currency_id];
    $to = $currencies[$to_currency_id];

    // السعر المستخدم (بيع أو شراء أو افتراضي)
    $from_rate = ($type == 'sell' && $from['exchange_rate_sell'] > 0) ? (float)$from['exchange_rate_sell'] : (float)$from['exchange_rate'];
    $to_rate = ($type == 'sell' && $to['exchange_rate_sell'] > 0) ? (float)$to['exchange_rate_sell'] : (float)$to['exchange_rate'];

    if ($type == 'buy') {
        if ($from['exchange_rate_buy'] > 0) $from_rate = (float)$from['exchange_rate_buy'];
        if ($to['exchange_rate_buy'] > 0) $to_rate = (float)$to['exchange_rate_buy'];
    }

    // التحويل: (1 وحدة من المصدر = كم وحدة من الهدف)
    // القاعدة: (سعر المصدر مقابل الريال) / (سعر الهدف مقابل الريال)
    if ($to_rate == 0) return 0;
    return round($from_rate / $to_rate, 6);
}

/**
 * التحقق من الحدود المالية للحساب قبل تنفيذ العملية (موحد بالعملة الأساسية)
 */
function check_account_limits($pdo, $account_id, $currency_id, $amount_change)
{
    // جلب الإعدادات العامة للتحقق هل الرقابة مفعلة أم لا
    $stmt_settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('enable_customer_limit_check', 'enable_supplier_limit_check', 'enable_debit_limit_check')");
    $settings = $stmt_settings->fetchAll(PDO::FETCH_KEY_PAIR);

    $enable_customer_check = (bool)($settings['enable_customer_limit_check'] ?? true);
    $enable_supplier_check = (bool)($settings['enable_supplier_limit_check'] ?? true);
    $enable_debit_limit_check = (bool)($settings['enable_debit_limit_check'] ?? true);

    // 1. التحقق من حالة التجميد للعملة المحددة (دائماً يتم التحقق منها بغض النظر عن الإعدادات)
    $stmt_freeze = $pdo->prepare("SELECT is_frozen, currency_code FROM account_balances_unified WHERE account_id = ? AND currency_id = ?");
    $stmt_freeze->execute([$account_id, $currency_id]);
    $freeze_info = $stmt_freeze->fetch(PDO::FETCH_ASSOC);
    if (!$freeze_info) {
        // If account balance doesn't exist, create it!
        $stmt_curr = $pdo->prepare("SELECT currency_code FROM currencies WHERE id = ?");
        $stmt_curr->execute([$currency_id]);
        $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
        $currency_code = $curr['currency_code'] ?? '';
        
        $stmt_ins_balance = $pdo->prepare("INSERT IGNORE INTO account_balances_unified (account_id, branch_id, currency_id, currency_code, opening_balance, current_balance, opening_balance_base, current_balance_base, is_frozen, credit_limit, debit_limit) VALUES (?, NULL, ?, ?, 0, 0, 0, 0, 0, 0, 0)");
        $stmt_ins_balance->execute([$account_id, $currency_id, $currency_code]);
    } else {
        if ($freeze_info['is_frozen'] == 1) {
            $stmt_curr = $pdo->prepare("SELECT currency_code FROM currencies WHERE id = ?");
            $stmt_curr->execute([$currency_id]);
            $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
            $currency_code = $curr['currency_code'] ?? '';
            throw new Exception("تنبيه: لا يمكن تنفيذ العملية. التعامل بعملة " . $currency_code . " مجمد حالياً لهذا الحساب.");
        }
    }

    // 2. جلب الحد الائتماني والدائن الموحد وطبيعة الحساب من جدول الحسابات
    $stmt_ua = $pdo->prepare("SELECT credit_limit_base, debit_limit_base, normal_balance, account_type FROM unified_accounts WHERE id = ?");
    $stmt_ua->execute([$account_id]);
    $ua_info = $stmt_ua->fetch(PDO::FETCH_ASSOC);
    if (!$ua_info) return true;

    // التحقق من الإعدادات العامة قبل المتابعة
    if ($ua_info['normal_balance'] == 'debit' && !$enable_customer_check) return true;
    if ($ua_info['normal_balance'] == 'credit' && !$enable_supplier_check) return true;

    $limit = (float)$ua_info['credit_limit_base'];
    $debit_limit = (float)$ua_info['debit_limit_base'];

    // 3. حساب إجمالي الرصيد الحالي لكل العملات بالعملة الأساسية
    $stmt_total = $pdo->prepare("SELECT SUM(current_balance_base) FROM account_balances_unified WHERE account_id = ?");
    $stmt_total->execute([$account_id]);
    $current_total_base = (float)$stmt_total->fetchColumn();

    // 4. تحويل مبلغ الحركة الجديد للعملة الأساسية
    $stmt_curr = $pdo->prepare("SELECT exchange_rate, exchange_rate_sell, exchange_rate_buy FROM currencies WHERE id = ?");
    $stmt_curr->execute([$currency_id]);
    $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
    if (!$curr) return true;

    $rate = 1.0;
    if ($amount_change > 0) {
        $rate = (float)($curr['exchange_rate_sell'] > 0 ? $curr['exchange_rate_sell'] : $curr['exchange_rate']);
    } else {
        $rate = (float)($curr['exchange_rate_buy'] > 0 ? $curr['exchange_rate_buy'] : $curr['exchange_rate']);
    }
    $amount_change_base = $amount_change * $rate;

    $new_total_base = $current_total_base + $amount_change_base;

    // 5. التحقق من الحدود الموحدة (بالعملة الأساسية)
    $abs_credit_limit = abs($limit);
    $abs_debit_limit = abs($debit_limit);

    if ($ua_info['normal_balance'] == 'debit') {
        // حساب مدين (مثل العملاء): الرصيد الموجب يعني مديونية للعميل لنا
        // إذا كان الرصيد الجديد سيصبح موجباً (مديونية) ولم يكن هناك حد ائتماني مسموح به، أو تجاوز الحد
        if ($new_total_base > 0.01) { // 0.01 لتجنب مشاكل الكسور البسيطة
            if ($abs_credit_limit <= 0) {
                // إذا لم يوجد رصيد دائن سابق يغطي الفاتورة، ولا يوجد حد ائتماني
                throw new Exception("تنبيه: لا يمكن تنفيذ العملية. العميل لا يمتلك رصيد كافٍ ولا يوجد له حد ائتماني مسموح به.");
            } elseif ($new_total_base > $abs_credit_limit) {
                throw new Exception("تنبيه: تجاوز الحد الائتماني الموحد (مديونية العميل). الرصيد الإجمالي الجديد (" . number_format($new_total_base, 2) . ") سيتجاوز الحد المسموح به (" . number_format($abs_credit_limit, 2) . ") بالعملة الأساسية.");
            }
        }

        // الحد الدائن (debit_limit): يمنع زيادة مديونيتنا للعميل (الرصيد السالب - فائض الإيداع)
        if ($enable_debit_limit_check && $abs_debit_limit > 0 && $new_total_base < -$abs_debit_limit) {
            throw new Exception("تنبيه: تجاوز الحد الدائن الموحد (فائض إيداع العميل). الرصيد الإجمالي الجديد (" . number_format($new_total_base, 2) . ") سيتجاوز الحد المسموح به (" . number_format($abs_debit_limit, 2) . ") بالعملة الأساسية.");
        }
    } else {
        // حساب دائن (مثل الموردين): الرصيد الموجب يعني مديونيتنا نحن للمورد
        // إذا كان الرصيد سيصبح موجباً (مديونية علينا للمورد)
        if ($new_total_base > 0.01 && $enable_debit_limit_check) {
            if ($abs_debit_limit <= 0) {
                throw new Exception("تنبيه: لا يمكن تنفيذ العملية. لا يوجد رصيد كافٍ ولا يوجد حد مديونية مسموح به للمورد.");
            } elseif ($new_total_base > $abs_debit_limit) {
                throw new Exception("تنبيه: تجاوز الحد الدائن الموحد (مديونية المكتب للمورد). الرصيد الإجمالي الجديد (" . number_format($new_total_base, 2) . ") سيتجاوز الحد المسموح به (" . number_format($abs_debit_limit, 2) . ") بالعملة الأساسية.");
            }
        }

        // الحد الائتماني (credit_limit): يمنع زيادة مديونية المورد لنا (الرصيد السالب - دفعات مقدمة للمورد)
        if ($abs_credit_limit > 0 && $new_total_base < -$abs_credit_limit) {
            throw new Exception("تنبيه: تجاوز الحد الائتماني الموحد (مديونية المورد للمكتب). الرصيد الإجمالي الجديد (" . number_format($new_total_base, 2) . ") سيتجاوز الحد المسموح به (" . number_format($abs_credit_limit, 2) . ") بالعملة الأساسية.");
        }
    }

    return true;
}

/**
 * إلغاء حركة مالية وعكس تأثيرها على الأرصدة
 */
function php_cancel_transaction($pdo, $id)
{
    try {
        $user_id = $_SESSION['admin_id'] ?? 1;

        // 1. جلب بيانات الحركة
        $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
        $stmt->execute([$id]);
        $trx = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$trx || $trx['status'] == 'cancelled') return false;

        // 2. عكس الأرصدة (إذا كانت المرحلة 'posted')
        if ($trx['status'] == 'posted') {
            // جلب أسطر القيد المرتبطة
            $stmt_lines = $pdo->prepare("SELECT * FROM journal_lines WHERE financial_transaction_id = ?");
            $stmt_lines->execute([$id]);
            $lines = $stmt_lines->fetchAll(PDO::FETCH_ASSOC);

            foreach ($lines as $line) {
                // عكس المبلغ: إذا كان مدين نطرحه، وإذا كان دائن نضيفه (أو العكس حسب طبيعة الحساب)
                $amount_to_reverse = ($line['debit'] > 0) ? -$line['debit'] : $line['credit'];
                
                // Get exchange rate to calculate base amount
                $stmt_curr = $pdo->prepare("SELECT exchange_rate FROM currencies WHERE id = ?");
                $stmt_curr->execute([$trx['currency_id']]);
                $rate = (float)($stmt_curr->fetchColumn() ?: 1);
                $amount_to_reverse_base = $amount_to_reverse * $rate;
                
                $stmt_bal = $pdo->prepare("UPDATE account_balances_unified SET current_balance = current_balance + ?, current_balance_base = current_balance_base + ? WHERE account_id = ? AND currency_id = ?");
                $stmt_bal->execute([$amount_to_reverse, $amount_to_reverse_base, $line['account_id'], $trx['currency_id']]);
            }
        }

        // 3. تحديث حالة الحركة
        $stmt_upd = $pdo->prepare("UPDATE financial_transactions SET status = 'cancelled', updated_at = CURRENT_TIMESTAMP, updated_by = ? WHERE id = ?");
        $stmt_upd->execute([$user_id, $id]);

        // 4. تسجيل في السجل
        log_financial_transaction_change($pdo, $id, 'cancel');

        return true;
    } catch (Exception $e) {
        error_log("Error in php_cancel_transaction: " . $e->getMessage());
        throw $e;
    }
}

/**
 * تنسيق عرض رصيد الحساب
 */
function format_account_balance($balance, $normal_balance = 'debit', $currency = 'YER')
{
    $abs_balance = abs($balance);
    $status = '';
    $class = '';

    if ($balance == 0) {
        return '<span class="text-muted">0.00</span>';
    }

    if ($normal_balance == 'debit') {
        $status = ($balance > 0) ? 'له (مدين)' : 'عليه (دائن)';
        $class = ($balance > 0) ? 'text-success' : 'text-danger';
    } else {
        $status = ($balance > 0) ? 'عليه (مدين)' : 'له (دائن)';
        $class = ($balance > 0) ? 'text-danger' : 'text-success';
    }

    return '<span class="fw-bold ' . $class . '">' . number_format($abs_balance, 2) . ' <small>' . $currency . '</small> <small class="text-muted opacity-75">' . $status . '</small></span>';
}

/**
 * جلب حالة إجمالي الأرصدة لعرضها في الهيدر
 */
function get_total_balance_status($total, $type = 'asset', $currency = 'YER')
{
    $class = ($total >= 0) ? 'text-success' : 'text-danger';
    $label = ($type == 'asset') ? ($total >= 0 ? 'إجمالي لنا' : 'إجمالي علينا') : ($total >= 0 ? 'إجمالي علينا' : 'إجمالي لنا');

    return '<span class="' . $class . ' fw-bold">' . $label . ': ' . number_format(abs($total), 2) . ' ' . $currency . '</span>';
}

/**
 * جلب شجرة الحسابات بشكل هرمي (قائمة مسطحة مرتبة)
 */
function get_hierarchical_accounts($pdo, $filters = [])
{
    $where = "WHERE 1=1";
    $params = [];
    
    if (!empty($filters['account_type'])) {
        $where .= " AND ua.account_type = ?";
        $params[] = $filters['account_type'];
    }
    
    if (!empty($filters['account_code_like'])) {
        $where .= " AND ua.account_code LIKE ?";
        $params[] = $filters['account_code_like'];
    }
    
    if (!empty($filters['account_status'])) {
        $where .= " AND ua.account_status = ?";
        $params[] = $filters['account_status'];
    }

    $stmt = $pdo->prepare("
        SELECT ua.id, ua.account_code, ua.account_name_ar, ua.parent_id,
               c.id as customer_id,
               a.id as agent_id,
               s.id as supplier_id
        FROM unified_accounts ua
        LEFT JOIN customers c ON ua.id = c.account_id
        LEFT JOIN agents a ON ua.id = a.account_id
        LEFT JOIN suppliers s ON ua.id = s.account_id
        $where ORDER BY ua.account_code
    ");
    $stmt->execute($params);
    $accounts = $stmt->fetchAll();

    $tree = [];
    build_flat_tree($accounts, null, 0, $tree);
    return $tree;
}

/**
 * التحقق من صحة الحساب (نشط، لا يحتوي على أبناء)
 */
function validate_postable_account($pdo, $account_id) {
    // التحقق من أن الحساب موجود
    $stmt = $pdo->prepare("SELECT account_status FROM unified_accounts WHERE id = ?");
    $stmt->execute([$account_id]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$account) {
        return ['valid' => false, 'message' => 'الحساب المحدد غير موجود.'];
    }

    // التحقق من أن الحساب نشط
    if ($account['account_status'] !== 'active') {
        return ['valid' => false, 'message' => 'الحساب المحدد غير نشط.'];
    }

    // التحقق من أن الحساب لا يحتوي على أبناء
    $stmt_children = $pdo->prepare("SELECT COUNT(*) FROM unified_accounts WHERE parent_id = ?");
    $stmt_children->execute([$account_id]);
    if ($stmt_children->fetchColumn() > 0) {
        return ['valid' => false, 'message' => 'الحساب المحدد يحتوي على حسابات فرعية ولا يمكن استخدامه.'];
    }

    return ['valid' => true];
}

/**
 * جلب الحسابات التشغيلية فقط للصناديق والبنوك
 */
function get_cash_bank_postable_accounts($pdo) {
    // جلب الحسابات التي تبدأ ب11101 (صناديق) أو 11102 (بنوك)، نشطة، ولا تُستخدم كـ parent_id لأي حساب آخر
    $stmt = $pdo->prepare("
        SELECT ua.id, ua.account_code, ua.account_name_ar, 
               ua.account_name_ar as name,
               ua.account_name_ar as account_name,
               CONCAT(ua.account_code, ' - ', ua.account_name_ar) as display_name
        FROM unified_accounts ua
        WHERE (ua.account_code LIKE '11101%' OR ua.account_code LIKE '11102%')
        AND ua.account_status = 'active'
        AND ua.id NOT IN (SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL)
        ORDER BY ua.account_code
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * تصفية الحسابات لتكون فقط الحسابات التشغيلية (لا تحتوي على أبناء)
 */
function filter_postable_accounts($accounts, $pdo) {
    // جلب جميع الأرقام id التي تُستخدم كـ parent_id
    $stmt = $pdo->prepare("SELECT DISTINCT parent_id FROM unified_accounts WHERE parent_id IS NOT NULL");
    $stmt->execute();
    $parent_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // تصفية الحسابات التي ليست في parent_ids
    return array_values(array_filter($accounts, function($account) use ($parent_ids) {
        return !in_array($account['id'], $parent_ids);
    }));
}

/**
 * التحقق التلقائي من وجود أعمدة العملات والحدود المالية
 */
function ensure_multi_currency_columns($pdo)
{
    try {
        $table = 'account_balances_unified';
        $columns = [
            'currency_code' => "VARCHAR(10) AFTER currency_id",
            'credit_limit' => "DECIMAL(18,2) DEFAULT 0.00 AFTER current_balance",
            'debit_limit' => "DECIMAL(18,2) DEFAULT 0.00 AFTER credit_limit"
        ];

        foreach ($columns as $col => $def) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
            $stmt->execute([$table, $col]);
            if ($stmt->fetchColumn() == 0) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
            }
        }

        // التأكد من وجود المفتاح الفريد
        $check_index = $pdo->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?");
        $check_index->execute([$table, 'acc_curr_code']);
        if ($check_index->fetchColumn() == 0) {
            $pdo->exec("ALTER TABLE `$table` ADD UNIQUE KEY `acc_curr_code` (account_id, currency_code)");
        }
    } catch (Exception $e) {
        // فشل صامت في البيئة الحية لتجنب تعطل النظام
    }
}

// تنفيذ التحقق عند التحميل (مُعطل مؤقتاً لتفادي مشاكل النطاق)
// ensure_multi_currency_columns($pdo);

function build_flat_tree($accounts, $parentId, $level, &$tree)
{
    foreach ($accounts as $account) {
        if ($account['parent_id'] == $parentId) {
            $account['level'] = $level;
            $account['display_name'] = str_repeat('    ', $level) . ($level > 0 ? '↳ ' : '') . $account['account_code'] . ' - ' . $account['account_name_ar'];
            $tree[] = $account;
            build_flat_tree($accounts, $account['id'], $level + 1, $tree);
        }
    }
}

/**
 * إنشاء أسطر القيد لحركة مالية موجودة مسبقاً وتحديث الأرصدة
 */
function php_create_journal_lines(
    $pdo,
    $transaction_id,
    $debit_account_id,
    $credit_account_id,
    $amount,
    $currency_id,
    $description,
    $cost_center_id = null
) {
    try {
        // 1. إنشاء أسطر القيد (journal_lines)
        $stmt_line = $pdo->prepare("INSERT INTO journal_lines
            (financial_transaction_id, account_id, debit, credit, currency_id, description, cost_center_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)");

        // الطرف المدين
        if ($debit_account_id) {
            $stmt_line->execute([$transaction_id, $debit_account_id, $amount, 0, $currency_id, $description, $cost_center_id]);
        }

        // الطرف الدائن
        if ($credit_account_id) {
            $stmt_line->execute([$transaction_id, $credit_account_id, 0, $amount, $currency_id, $description, $cost_center_id]);
        }

        // 2. تحديث أرصدة الحسابات عبر الإجراء المخزن
        // $stmt_upd = $pdo->prepare("CALL sp_update_account_balances(?)");
        $this_pdo = $pdo; $this_trx_id = $transaction_id; include 'update_balances_logic.php';

        return true;
    } catch (Exception $e) {
        error_log("Error in php_create_journal_lines: " . $e->getMessage());
        throw $e;
    }
}

/**
 * إنشاء قيد مالي يدوي أو آلي (طرف مدين وطرف دائن)
 */
function php_create_financial_entry(
    $pdo,
    $date,
    $type,
    $entity_type,
    $entity_id,
    $debit_account_id,
    $credit_account_id,
    $amount,
    $currency_id,
    $description,
    $user_id,
    $branch_id = null,
    $agent_id = null,
    $cost_center_id = null,
    $ref_type = null,
    $ref_id = null,
    $use_outer_transaction = false
) {
    try {
        if (!$use_outer_transaction) {
            $pdo->beginTransaction();
        }

        // 1. توليد رقم العملية
        $stmt_seq = $pdo->prepare("SELECT IFNULL(MAX(id), 0) + 1 as seq FROM financial_transactions");
        $stmt_seq->execute();
        $trx_number = 'JRN-' . str_pad($stmt_seq->fetchColumn(), 6, '0', STR_PAD_LEFT);

        // 2. إنشاء رأس العملية (financial_transactions)
        $stmt_trx = $pdo->prepare("INSERT INTO financial_transactions
            (transaction_number, transaction_date, branch_id, transaction_type, status,
             entity_type, entity_id, currency_id, amount, reference_type, reference_id,
             description, created_by, posted_at, posted_by, cost_center_id, created_at)
            VALUES (?, ?, ?, ?, 'posted', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, NOW())");

        $stmt_trx->execute([
            $trx_number,
            $date,
            $branch_id,
            $type,
            $entity_type,
            $entity_id,
            $currency_id,
            $amount,
            $ref_type,
            $ref_id,
            $description,
            $user_id,
            $user_id,
            $cost_center_id
        ]);

        $transaction_id = $pdo->lastInsertId();

        // 3. إنشاء أسطر القيد (journal_lines)
        $stmt_line = $pdo->prepare("INSERT INTO journal_lines
            (financial_transaction_id, account_id, debit, credit, currency_id, description, cost_center_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)");

        // الطرف المدين
        if ($debit_account_id) {
            $stmt_line->execute([$transaction_id, $debit_account_id, $amount, 0, $currency_id, $description, $cost_center_id]);
        }

        // الطرف الدائن
        if ($credit_account_id) {
            $stmt_line->execute([$transaction_id, $credit_account_id, 0, $amount, $currency_id, $description, $cost_center_id]);
        }

        // 4. تحديث أرصدة الحسابات
        // $stmt_upd = $pdo->prepare("CALL sp_update_account_balances(?)");
        $this_pdo = $pdo; $this_trx_id = $transaction_id; include 'update_balances_logic.php';

        if (!$use_outer_transaction) {
            $pdo->commit();
        }
        return $transaction_id;
    } catch (Exception $e) {
        if (!$use_outer_transaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in php_create_financial_entry: " . $e->getMessage());
        // عرض الخطأ للمستخدم في البيئة الحالية لتسهيل التشخيص
        if (isset($_SESSION['admin_id'])) {
            echo "<script>console.error('Accounting Error: " . addslashes($e->getMessage()) . "');</script>";
        }
        if ($use_outer_transaction) {
            throw $e;
        }
        return false;
    }
}

/**
 * حذف حركة مالية مع عكس أثرها على الأرصدة (منطق متوافق مع حذف السندات).
 */
function php_delete_financial_transaction_and_reverse($pdo, $transaction_id)
{
    $transaction_id = (int)$transaction_id;
    if ($transaction_id < 1) {
        return;
    }

    $stmt = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt->execute([$transaction_id]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) {
        return;
    }

    if ($voucher['status'] === 'posted') {
        $stmt_alloc = $pdo->prepare("SELECT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
        $stmt_alloc->execute([$transaction_id]);
        $invoice_ids = $stmt_alloc->fetchAll(PDO::FETCH_COLUMN);

        // Get journal lines before modifying them
        $stmt_lines = $pdo->prepare("SELECT account_id, debit, credit, currency_id, branch_id FROM journal_lines WHERE financial_transaction_id = ?");
        $stmt_lines->execute([$transaction_id]);
        $lines = $stmt_lines->fetchAll(PDO::FETCH_ASSOC);
        
        // Get branch_id from transaction
        $stmt_branch = $pdo->prepare("SELECT branch_id FROM financial_transactions WHERE id = ?");
        $stmt_branch->execute([$transaction_id]);
        $default_branch_id = $stmt_branch->fetchColumn();
        
        // Reverse balances using the original lines (swap debit/credit)
        foreach ($lines as $line) {
            $account_id = $line['account_id'];
            $currency_id = $line['currency_id'];
            $line_branch_id = $line['branch_id'] ?? $default_branch_id;
            $amount = $line['credit'] - $line['debit'];
            
            // Get exchange rate and currency code
            $stmt_curr = $pdo->prepare("SELECT exchange_rate, currency_code FROM currencies WHERE id = ?");
            $stmt_curr->execute([$currency_id]);
            $curr = $stmt_curr->fetch(PDO::FETCH_ASSOC);
            $rate = (float)($curr['exchange_rate'] ?? 1);
            $currency_code = $curr['currency_code'] ?? '';
            $amount_base = $amount * $rate;
            
            // First check if the row exists
            if ($line_branch_id === null) {
                $stmt_check = $pdo->prepare("
                    SELECT id FROM account_balances_unified 
                    WHERE account_id = ? AND branch_id IS NULL AND currency_id = ?
                ");
                $stmt_check->execute([$account_id, $currency_id]);
            } else {
                $stmt_check = $pdo->prepare("
                    SELECT id FROM account_balances_unified 
                    WHERE account_id = ? AND branch_id = ? AND currency_id = ?
                ");
                $stmt_check->execute([$account_id, $line_branch_id, $currency_id]);
            }
            $exists = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                // Update existing row
                $stmt_upd = $pdo->prepare("
                    UPDATE account_balances_unified 
                    SET 
                        current_balance = current_balance + ?, 
                        current_balance_base = current_balance_base + ?,
                        currency_code = ?
                    WHERE id = ?
                ");
                $stmt_upd->execute([$amount, $amount_base, $currency_code, $exists['id']]);
            } else {
                // Insert new row with all required columns
                $stmt_ins = $pdo->prepare("
                    INSERT INTO account_balances_unified (
                        account_id, branch_id, currency_id, currency_code,
                        opening_balance, current_balance, current_balance_base,
                        opening_balance_base, credit_limit, debit_limit, is_frozen
                    ) VALUES (?, ?, ?, ?, 0, ?, ?, 0, 0, 0, 0)
                ");
                $stmt_ins->execute([$account_id, $line_branch_id, $currency_id, $currency_code, $amount, $amount_base]);
            }
        }

        // Now delete the journal lines
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$transaction_id]);

        // Recalculate invoice payment statuses
        foreach ($invoice_ids as $invoice_id) {
            if ($invoice_id) {
                try {
                    php_recalculate_invoice_payment($pdo, $invoice_id);
                } catch (Exception $e) {
                    error_log("php_delete_financial_transaction_and_reverse recalc: " . $e->getMessage());
                }
            }
        }
    } else {
        $pdo->prepare("DELETE FROM journal_lines WHERE financial_transaction_id = ?")->execute([$transaction_id]);
    }

    $pdo->prepare("DELETE FROM payment_allocations WHERE financial_transaction_id = ?")->execute([$transaction_id]);
    $pdo->prepare("DELETE FROM financial_transactions WHERE id = ?")->execute([$transaction_id]);
}

/**
 * PHP implementation of sp_recalculate_invoice_payment
 */
function php_recalculate_invoice_payment($pdo, $invoice_id) {
    $invoice_id = (int)$invoice_id;
    if ($invoice_id <1) {
        return;
    }

    // Get the invoice's currency and total amount
    $stmt_inv = $pdo->prepare("SELECT currency_id, net_amount, total_amount FROM invoices WHERE id = ?");
    $stmt_inv->execute([$invoice_id]);
    $invoice = $stmt_inv->fetch(PDO::FETCH_ASSOC);
    if (!$invoice) {
        return;
    }

    $total_amount = $invoice['net_amount'] ?? $invoice['total_amount'] ?? 0;

    // Calculate total amount received from payment allocations
    $stmt_alloc = $pdo->prepare("
        SELECT COALESCE(SUM(pa.amount), 0) as total_paid
        FROM payment_allocations pa
        WHERE pa.invoice_id = ?
    ");
    $stmt_alloc->execute([$invoice_id]);
    $total_paid = $stmt_alloc->fetchColumn();

    // Determine payment status
    if ($total_paid <= 0) {
        $payment_status = 'unpaid';
    } elseif ($total_paid >= $total_amount) {
        $payment_status = 'paid';
    } else {
        $payment_status = 'partially_paid';
    }

    // Update invoice
    $stmt_upd = $pdo->prepare("
        UPDATE invoices
        SET amount_received = ?, payment_status = ?
        WHERE id = ?
    ");
    $stmt_upd->execute([$total_paid, $payment_status, $invoice_id]);
}

function php_post_receipt_voucher($pdo, $voucher_id, $user_id) {
    // 1. Get the voucher details
    $stmt_voucher = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt_voucher->execute([$voucher_id]);
    $voucher = $stmt_voucher->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) {
        throw new Exception("السند غير موجود");
    }

    // 2. Create journal lines: Debit cash/bank account, Credit party account
    php_create_journal_lines(
        $pdo,
        $voucher_id,
        $voucher['cash_bank_account_id'],
        $voucher['party_account_id'],
        $voucher['amount'],
        $voucher['currency_id'],
        $voucher['description'] ?? '',
        $voucher['cost_center_id']
    );

    // 3. Update voucher status to posted
    $stmt_update = $pdo->prepare("
        UPDATE financial_transactions 
        SET status = 'posted', 
            posted_by = ?, 
            posted_at = NOW(), 
            posted_ip = ?
        WHERE id = ?
    ");
    $stmt_update->execute([$user_id, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $voucher_id]);

    // 4. Recalculate any linked invoices
    $stmt_alloc = $pdo->prepare("SELECT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
    $stmt_alloc->execute([$voucher_id]);
    $invoice_ids = $stmt_alloc->fetchAll(PDO::FETCH_COLUMN);
    foreach ($invoice_ids as $inv_id) {
        php_recalculate_invoice_payment($pdo, $inv_id);
    }

    return true;
}

function fn_get_next_sequence($pdo, $type) {
    // Simple sequence generator: get max id and add 1, pad with zeros
    $stmt = $pdo->query("SELECT MAX(id) as max_id FROM financial_transactions");
    $max = $stmt->fetch(PDO::FETCH_ASSOC);
    $next = ($max['max_id'] ?? 0) + 1;
    return strtoupper(substr($type, 0, 3)) . '-' . str_pad($next, 6, '0', STR_PAD_LEFT);
}

function php_post_payment_voucher($pdo, $voucher_id, $user_id) {
    // 1. Get the voucher details
    $stmt_voucher = $pdo->prepare("SELECT * FROM financial_transactions WHERE id = ?");
    $stmt_voucher->execute([$voucher_id]);
    $voucher = $stmt_voucher->fetch(PDO::FETCH_ASSOC);
    if (!$voucher) {
        throw new Exception("السند غير موجود");
    }

    // 2. Create journal lines: Credit cash/bank account, Debit party account
    php_create_journal_lines(
        $pdo,
        $voucher_id,
        $voucher['party_account_id'],
        $voucher['cash_bank_account_id'],
        $voucher['amount'],
        $voucher['currency_id'],
        $voucher['description'] ?? '',
        $voucher['cost_center_id']
    );

    // 3. Update voucher status to posted
    $stmt_update = $pdo->prepare("
        UPDATE financial_transactions 
        SET status = 'posted', 
            posted_by = ?, 
            posted_at = NOW(), 
            posted_ip = ?
        WHERE id = ?
    ");
    $stmt_update->execute([$user_id, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', $voucher_id]);

    // 4. Recalculate any linked invoices
    $stmt_alloc = $pdo->prepare("SELECT invoice_id FROM payment_allocations WHERE financial_transaction_id = ?");
    $stmt_alloc->execute([$voucher_id]);
    $invoice_ids = $stmt_alloc->fetchAll(PDO::FETCH_COLUMN);
    foreach ($invoice_ids as $inv_id) {
        php_recalculate_invoice_payment($pdo, $inv_id);
    }

    return true;
}

/**
 * جلب تسمية حالة الحساب (نشط، خامل، مغلق)
 */
function get_account_status_label($status)
{
    switch ($status) {
        case 'active':
            return '<span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3">نشط</span>';
        case 'dormant':
        case 'inactive':
            return '<span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill px-3">خامل</span>';
        case 'closed':
            return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-3">مغلق</span>';
        default:
            return '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill px-3">' . htmlspecialchars($status) . '</span>';
    }
}

/**
 * ترحيل فاتورة خدمة محاسبياً باستخدام الإجراء المخزن
 */
function php_post_service_invoice($pdo, $invoice_id, $posted_by)
{
    try {
        $stmt = $pdo->prepare("CALL sp_post_invoice(?, ?)");
        $stmt->execute([$invoice_id, $posted_by]);
        return true;
    } catch (Exception $e) {
        error_log("خطأ في ترحيل الفاتورة: " . $e->getMessage());
        return false;
    }
}

/**
 * جلب الحسابات المتاحة لكيان معين (مثلاً فئات المصاريف)
 */
function get_available_accounts_for_entity($entity_type)
{
    global $pdo;
    $parent_code = '';
    switch ($entity_type) {
        case 'expense_category':
            $parent_code = '501';
            break;
        case 'income_category':
            $parent_code = '401';
            break;
    }

    if (!$parent_code) return [];

    $stmt = $pdo->prepare("SELECT id, account_code, account_name_ar FROM unified_accounts WHERE account_code LIKE ? AND account_code != ? ORDER BY account_code");
    $stmt->execute([$parent_code . '%', $parent_code]);
    return $stmt->fetchAll();
}

/**
 * جلب كود الحساب الأب لكيان معين
 */
function get_parent_account_code_by_entity($entity_type)
{
    switch ($entity_type) {
        case 'expense_category':
            return '501';
        case 'income_category':
            return '401';
        default:
            return null;
    }
}

/**
 * إنشاء حساب فرعي جديد
 */
function create_sub_account($parent_code, $account_name, $entity_id = null, $entity_type = null)
{
    global $pdo;
    try {
        $stmt_parent = $pdo->prepare("SELECT id, account_type, normal_balance FROM unified_accounts WHERE account_code = ?");
        $stmt_parent->execute([$parent_code]);
        $parent = $stmt_parent->fetch();

        if (!$parent) return false;

        $stmt_last = $pdo->prepare("SELECT MAX(account_code) FROM unified_accounts WHERE parent_id = ?");
        $stmt_last->execute([$parent['id']]);
        $last_code = $stmt_last->fetchColumn();

        if ($last_code) {
            $new_code = (int)$last_code + 1;
        } else {
            $new_code = $parent_code . '001';
        }

        $stmt = $pdo->prepare("INSERT INTO unified_accounts (account_code, account_name_ar, account_type, normal_balance, parent_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$new_code, $account_name, $parent['account_type'], $parent['normal_balance'], $parent['id']]);

        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error in create_sub_account: " . $e->getMessage());
        return false;
    }
}

/**
 * ترحيل الخدمات للنظام الموحد
 * محدث: يقرأ الأسعار من service_prices بدلاً من الأعمدة المحذوفة
 */
function post_service_to_unified($pdo, $type, $id, $user_id)
{
    if ($type == 'passport') {
        // جلب بيانات الجواز الأساسية
        $stmt = $pdo->prepare("SELECT p.*, s.id as service_id
                              FROM passports p
                              LEFT JOIN services s ON s.service_key = p.transaction_type
                              WHERE p.id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();

        if (!$p || $p['invoice_id']) return;

        // جلب بيانات التسعير من جدول service_prices
        $price_stmt = $pdo->prepare("SELECT sp.*, c.id as currency_id
                                     FROM service_prices sp
                                     LEFT JOIN currencies c ON c.id = sp.currency_id
                                     WHERE sp.service_id = ?
                                     AND (sp.branch_id = ? OR sp.agent_id = ?)
                                     ORDER BY sp.created_at DESC
                                     LIMIT 1");
        $price_stmt->execute([$p['service_id'], $p['branch_id'], $p['agent_id']]);
        $price_data = $price_stmt->fetch();

        // إذا لم يوجد تسعير محدد، استخدم العملة الافتراضية والقيم الصفرية
        $currency_id = $price_data['currency_id'] ?? 1; // العملة الافتراضية
        $sale_price = $price_data['sale_price'] ?? 0;
        $purchase_price = $price_data['purchase_price'] ?? 0;

        $inv_id = php_create_invoice_and_post(
            $pdo,
            'sales',
            $p['branch_id'],
            'passport',
            $p['id'],
            $p['customer_id'],
            $currency_id,
            $sale_price,
            0, // discount
            $purchase_price,
            'credit',
            "فاتورة جواز: " . $p['full_name'],
            $user_id
        );

        $pdo->prepare("UPDATE passports SET invoice_id = ? WHERE id = ?")
            ->execute([$inv_id, $id]);
    }
    // يمكن إضافة الحالات الأخرى هنا (bus_flight, family_visit)
}
