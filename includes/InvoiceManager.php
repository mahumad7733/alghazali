<?php
/**
 * InvoiceManager.php - مدير الفواتير الموحد
 * يقوم بإنشاء الفواتير في جدول invoices وربطها بالخدمات
 */

require_once __DIR__ . '/functions.php';

class InvoiceManager {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * جلب رقم الفاتورة التالي
     */
    public function getNextInvoiceNumber($type, $branch_id = null) {
        $prefix = ($type == 'sales') ? 'SI' : 'PI';
        $year = date('y');
        $branch_code = $branch_id ? str_pad($branch_id, 2, '0', STR_PAD_LEFT) : '00';
        
        $stmt = $this->pdo->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTRING(invoice_number, 9) AS UNSIGNED)), 0) + 1 as next_num 
            FROM invoices 
            WHERE invoice_number LIKE ?
        ");
        $stmt->execute([$prefix . '-' . $year . '-' . $branch_code . '%']);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $next_num = $result['next_num'] ?? 1;
        
        return $prefix . '-' . $year . '-' . $branch_code . '-' . str_pad($next_num, 5, '0', STR_PAD_LEFT);
    }
    
    /**
     * إنشاء فاتورة مبيعات
     */
    public function createSalesInvoice($data) {
        $invoice_number = $this->getNextInvoiceNumber('sales', $data['branch_id'] ?? null);
        
        // حساب صافي المبلغ
        $net_amount = $data['total_amount'] - ($data['discount'] ?? 0);
        $tax_amount = $net_amount * (($data['tax_rate'] ?? 0) / 100);
        $final_net = $net_amount + $tax_amount;
        
        // جلب حساب العميل
        $customer_account_id = null;
        if (!empty($data['customer_id'])) {
            $stmt = $this->pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
            $stmt->execute([$data['customer_id']]);
            $customer_account_id = $stmt->fetchColumn();
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO invoices (
                invoice_number, invoice_date, due_date, branch_id, invoice_category,
                source_type, source_id, service_id, customer_id, agent_id,
                currency_id, total_amount, discount, tax_rate, tax_amount, net_amount,
                amount_received, payment_type, payment_status, invoice_status,
                description, created_by, created_at
            ) VALUES (
                :invoice_number, :invoice_date, :due_date, :branch_id, 'sales',
                :source_type, :source_id, :service_id, :customer_id, :agent_id,
                :currency_id, :total_amount, :discount, :tax_rate, :tax_amount, :net_amount,
                :amount_received, :payment_type, :payment_status, :invoice_status,
                :description, :created_by, NOW()
            )
        ");
        
        $stmt->execute([
            ':invoice_number' => $invoice_number,
            ':invoice_date' => normalize_datetime_db($data['invoice_date'] ?? null),
            ':due_date' => $data['due_date'] ?? null,
            ':branch_id' => $data['branch_id'] ?? null,
            ':source_type' => $data['source_type'],
            ':source_id' => $data['source_id'],
            ':service_id' => $data['service_id'],
            ':customer_id' => $data['customer_id'] ?? null,
            ':agent_id' => $data['agent_id'] ?? null,
            ':currency_id' => $data['currency_id'],
            ':total_amount' => $data['total_amount'],
            ':discount' => $data['discount'] ?? 0,
            ':tax_rate' => $data['tax_rate'] ?? 0,
            ':tax_amount' => $tax_amount,
            ':net_amount' => $final_net,
            ':amount_received' => $data['amount_received'] ?? 0,
            ':payment_type' => $data['payment_type'],
            ':payment_status' => $data['payment_status'] ?? 'unpaid',
            ':invoice_status' => $data['invoice_status'] ?? 'draft',
            ':description' => $data['description'] ?? null,
            ':created_by' => $data['created_by']
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * إنشاء فاتورة شراء (تكلفة)
     */
    public function createPurchaseInvoice($data) {
        $invoice_number = $this->getNextInvoiceNumber('purchase', $data['branch_id'] ?? null);
        
        // جلب حساب المورد
        $supplier_account_id = null;
        if (!empty($data['supplier_id'])) {
            $stmt = $this->pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
            $stmt->execute([$data['supplier_id']]);
            $supplier_account_id = $stmt->fetchColumn();
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO invoices (
                invoice_number, invoice_date, due_date, branch_id, invoice_category,
                source_type, source_id, service_id, supplier_id,
                currency_id, total_amount, cost_amount, payment_type,
                payment_status, invoice_status, description, created_by, created_at
            ) VALUES (
                :invoice_number, :invoice_date, :due_date, :branch_id, 'purchase',
                :source_type, :source_id, :service_id, :supplier_id,
                :currency_id, :total_amount, :total_amount, :payment_type,
                'unpaid', 'draft', :description, :created_by, NOW()
            )
        ");
        
        $stmt->execute([
            ':invoice_number' => $invoice_number,
            ':invoice_date' => normalize_datetime_db($data['invoice_date'] ?? null),
            ':due_date' => $data['due_date'] ?? null,
            ':branch_id' => $data['branch_id'] ?? null,
            ':source_type' => $data['source_type'],
            ':source_id' => $data['source_id'],
            ':service_id' => $data['service_id'],
            ':supplier_id' => $data['supplier_id'],
            ':currency_id' => $data['currency_id'],
            ':total_amount' => $data['total_amount'],
            ':payment_type' => $data['payment_type'] ?? 'credit',
            ':description' => $data['description'] ?? null,
            ':created_by' => $data['created_by']
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    /**
     * ترحيل الفاتورة (تغيير الحالة إلى posted)
     */
    public function postInvoice($invoice_id, $user_id) {
        // ===== مركز إدارة النظام: لقطة قبل الترحيل =====
        $before = null; $amountBefore = null; $statusBefore = null; $currencyBefore = null;
        try {
            $qBefore = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $qBefore->execute([(int)$invoice_id]);
            $before = $qBefore->fetch(PDO::FETCH_ASSOC);
            if ($before) {
                $amountBefore = $before['total_amount'] ?? null;
                $statusBefore = $before['invoice_status'] ?? null;
                $currencyBefore = $before['currency_id'] ?? null;
            }
        } catch (\Throwable $e) { /* ignore */ }

        $stmt = $this->pdo->prepare("
            UPDATE invoices 
            SET invoice_status = 'posted', posted_at = NOW(), posted_by = ?
            WHERE id = ? AND invoice_status = 'draft'
        ");
        $res = $stmt->execute([$user_id, $invoice_id]);

        // ===== مركز إدارة النظام: لقطة بعد الترحيل + تسجيل الأثر =====
        if ($res) try {
            $after = null; $amountAfter = null; $statusAfter = null; $currencyAfter = null; $rate = null;
            $qAfter = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $qAfter->execute([(int)$invoice_id]);
            $after = $qAfter->fetch(PDO::FETCH_ASSOC);
            if ($after) {
                $amountAfter = $after['total_amount'] ?? null;
                $statusAfter = $after['invoice_status'] ?? null;
                $currencyAfter = $after['currency_id'] ?? null;
                $rate = $after['exchange_rate'] ?? null;
            }
            if (function_exists('record_financial_transaction_snapshot')) {
                record_financial_transaction_snapshot('invoice_posted', (array)($before ?? []), (array)($after ?? []), [
                    'target_table' => 'invoices', 'target_record_id' => (int)$invoice_id,
                    'invoice_id' => (int)$invoice_id,
                    'amount_before' => $amountBefore, 'amount_after' => $amountAfter,
                    'currency_id_before' => $currencyBefore, 'currency_id_after' => $currencyAfter,
                    'exchange_rate_applied' => $rate,
                    'status_before' => $statusBefore, 'status_after' => $statusAfter,
                    'change_reason' => 'ترحيل فاتورة بواسطة المستخدم ID:' . (int)$user_id,
                ]);
            }
        } catch (\Throwable $e) { /* ignore */ }

        return $res;
    }
    
    /**
     * إلغاء الفاتورة
     */
    public function cancelInvoice($invoice_id, $user_id, $reason = null) {
        // ===== مركز إدارة النظام: لقطة قبل الإلغاء =====
        $before = null; $amountBefore = null; $statusBefore = null; $currencyBefore = null;
        try {
            $qBefore = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $qBefore->execute([(int)$invoice_id]);
            $before = $qBefore->fetch(PDO::FETCH_ASSOC);
            if ($before) {
                $amountBefore = $before['total_amount'] ?? null;
                $statusBefore = $before['invoice_status'] ?? null;
                $currencyBefore = $before['currency_id'] ?? null;
            }
        } catch (\Throwable $e) { /* ignore */ }

        $stmt = $this->pdo->prepare("
            UPDATE invoices 
            SET invoice_status = 'cancelled', updated_by = ?, 
                description = CONCAT(COALESCE(description, ''), ' | ملغي: ', ?)
            WHERE id = ? AND invoice_status IN ('draft', 'posted')
        ");
        $res = $stmt->execute([$user_id, $reason, $invoice_id]);

        // ===== مركز إدارة النظام: لقطة بعد الإلغاء + تسجيل الأثر =====
        if ($res) try {
            $after = null; $amountAfter = null; $statusAfter = null; $currencyAfter = null; $rate = null;
            $qAfter = $this->pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $qAfter->execute([(int)$invoice_id]);
            $after = $qAfter->fetch(PDO::FETCH_ASSOC);
            if ($after) {
                $amountAfter = $after['total_amount'] ?? null;
                $statusAfter = $after['invoice_status'] ?? null;
                $currencyAfter = $after['currency_id'] ?? null;
                $rate = $after['exchange_rate'] ?? null;
            }
            if (function_exists('record_financial_transaction_snapshot')) {
                record_financial_transaction_snapshot('invoice_cancel', (array)($before ?? []), (array)($after ?? []), [
                    'target_table' => 'invoices', 'target_record_id' => (int)$invoice_id,
                    'invoice_id' => (int)$invoice_id,
                    'amount_before' => $amountBefore, 'amount_after' => $amountAfter,
                    'currency_id_before' => $currencyBefore, 'currency_id_after' => $currencyAfter,
                    'exchange_rate_applied' => $rate,
                    'status_before' => $statusBefore, 'status_after' => $statusAfter,
                    'change_reason' => mb_substr('إلغاء فاتورة: ' . ($reason ?? 'بدون سبب') . ' - المستخدم ID:' . (int)$user_id, 0, 400),
                ]);
            }
        } catch (\Throwable $e) { /* ignore */ }

        return $res;
    }
}
