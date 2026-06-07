<?php
/**
 * المحرك المالي الموحد لخدمات وكالة الغزالي
 * يوحد منطق الفواتير، المدفوعات، والترحيل المحاسبي لجميع الخدمات
 */

class ServiceFinancialEngine {
    /** @var PDO */
    private $pdo;
    /** @var int|null */
    private $user_id;

    /**
     * @param PDO $pdo
     * @param int|null $user_id
     */
    public function __construct(PDO $pdo, ?int $user_id = null) {
        $this->pdo = $pdo;
        $this->user_id = $user_id ?: ($_SESSION['admin_id'] ?? 1);
    }

    /**
     * معالجة العملية المالية الكاملة للخدمة (إنشاء فواتير + دفعات + ترحيل)
     * @param array $data
     * @param bool $skip_transaction Whether to skip transaction handling (if you want to handle it yourself)
     * @return array
     * @throws Exception
     */
    public function processServiceFinance(array $data, bool $skip_transaction = false): array {
        $started_transaction = false;
        try {
            if (!$skip_transaction && !$this->pdo->inTransaction()) {
                $this->pdo->beginTransaction();
                $started_transaction = true;
            }

            // 1. استخراج البيانات الأساسية
            $service_type = $data['service_type']; // 'passport', 'umrah', 'flight', 'visa', 'family'
            $source_id    = $data['source_id'];
            $branch_id    = $data['branch_id'];
            $customer_id  = $data['customer_id'] ?? null;
            $agent_id     = $data['agent_id'] ?? null;
            $supplier_id  = $data['supplier_id'] ?? null;
            
            $sale_price   = (float)($data['sale_price'] ?? 0);
            $discount     = (float)($data['discount'] ?? 0);
            $purchase_price = (float)($data['purchase_price'] ?? 0);
            $net_sale     = $sale_price - $discount;

            $sale_currency_id = $data['sale_currency_id'];
            $pur_currency_id  = $data['pur_currency_id'] ?? $sale_currency_id;
            $exchange_rate    = (float)($data['exchange_rate'] ?? 1); // سعر صرف الشراء مقابل البيع

            $amount_received = (float)($data['amount_received'] ?? 0);
            $payment_account_id = $data['payment_account_id'] ?? null; // حساب الصندوق أو البنك
            $delivery_type   = $data['delivery_type'] ?? 'draft'; // cash, credit, bank_transfer, draft

            $description     = $data['description'] ?? "";
            $operation_date  = $data['operation_date'] ?? date('Y-m-d');

            // حساب التكلفة بعملة البيع للتقارير والربحية
            $cost_in_sale_currency = $purchase_price;
            if ($sale_currency_id != $pur_currency_id && $exchange_rate > 0) {
                $cost_in_sale_currency = $purchase_price * $exchange_rate;
            }

            // 2. إنشاء فاتورة البيع
            $sales_invoice_id = $this->createInvoice([
                'category'    => 'sales',
                'branch_id'   => $branch_id,
                'source_type' => $service_type,
                'source_id'   => $source_id,
                'party_id'    => $customer_id,
                'agent_id'    => $agent_id,
                'currency_id' => $sale_currency_id,
                'total_amount'=> $sale_price,
                'discount'    => $discount,
                'cost_amount' => $cost_in_sale_currency,
                'delivery_type'=> $delivery_type,
                'description' => $description,
                'account_id'  => ($delivery_type === 'cash' || $delivery_type === 'bank_transfer') ? $payment_account_id : null
            ]);

            // 3. إنشاء فاتورة الشراء (إذا وجد مورد وتكلفة)
            $purchase_invoice_id = null;
            if ($supplier_id && $purchase_price > 0) {
                $purchase_invoice_id = $this->createInvoice([
                    'category'    => 'purchase',
                    'branch_id'   => $branch_id,
                    'source_type' => $service_type,
                    'source_id'   => $source_id,
                    'party_id'    => $supplier_id,
                    'currency_id' => $pur_currency_id,
                    'total_amount'=> $purchase_price,
                    'discount'    => 0,
                    'cost_amount' => 0,
                    'delivery_type'=> 'credit',
                    'description' => "تكلفة: " . $description
                ]);
            }

            // 4. معالجة الدفعة المستلمة (إن وجدت)
            if ($amount_received > 0 && ($delivery_type === 'cash' || $delivery_type === 'bank_transfer') && $payment_account_id) {
                $this->processPayment([
                    'invoice_id'   => $sales_invoice_id,
                    'amount'       => $amount_received,
                    'currency_id'  => $sale_currency_id,
                    'account_id'   => $payment_account_id,
                    'customer_id'  => $customer_id,
                    'branch_id'    => $branch_id,
                    'description'  => "دفعة من: " . $description,
                    'source_ref'   => $data['source_number'] ?? $source_id
                ]);
            }

            // 5. لا ترحيل تلقائي (النظام يترك الفواتير كمسودة ويتم الترحيل لاحقاً من قبل المستخدم)
        // لقد تم تعطيل الترحيل التلقائي بناءً على طلب المستخدم
        // إذا أردت ترحيل تلقائي في المستقبل، فقط قم بإلغاء التعليق على الكود التالي:
        /*
        if ($delivery_type !== 'draft') {
            require_once 'accounting_functions.php';
            php_post_invoice($this->pdo, $sales_invoice_id, $this->user_id, $skip_transaction);
            if ($purchase_invoice_id) {
                php_post_invoice($this->pdo, $purchase_invoice_id, $this->user_id, $skip_transaction);
            }
        }
        */

            if (!$skip_transaction && $started_transaction) {
                $this->pdo->commit();
            }
            return [
                'sales_invoice_id' => $sales_invoice_id,
                'purchase_invoice_id' => $purchase_invoice_id
            ];

        } catch (Exception $e) {
            if (!$skip_transaction && $started_transaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * إنشاء فاتورة باستخدام الدوال المحاسبية الموحدة
     * @param array $params
     * @return int
     */
    private function createInvoice(array $params): int {
        require_once 'accounting_functions.php';
        
        // جلب حساب الطرف (عميل أو مورد)
        $party_account_id = null;
        if ($params['category'] == 'sales' && $params['party_id']) {
            $stmt = $this->pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
            $stmt->execute([$params['party_id']]);
            $party_account_id = $stmt->fetchColumn();
        } elseif ($params['category'] == 'purchase' && $params['party_id']) {
            $stmt = $this->pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
            $stmt->execute([$params['party_id']]);
            $party_account_id = $stmt->fetchColumn();
        }

        return php_create_invoice(
            $this->pdo,
            $params['category'],
            $params['branch_id'],
            $params['source_type'],
            $params['source_id'],
            $params['party_id'],
            $params['currency_id'],
            $params['total_amount'],
            $params['discount'],
            $params['cost_amount'],
            $params['delivery_type'],
            $params['description'],
            $this->user_id,
            $params['agent_id'] ?? null,
            $params['account_id'] ?? $party_account_id
        );
    }

    /**
     * معالجة السند وربطه بالفاتورة
     * @param array $params
     * @return void
     * @throws Exception
     */
    private function processPayment(array $params): void {
        require_once 'accounting_functions.php';
        
        // جلب حساب العميل
        $stmt = $this->pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
        $stmt->execute([$params['customer_id']]);
        $party_account_id = $stmt->fetchColumn();

        if (!$party_account_id) throw new Exception("العميل ليس له حساب مالي مرتبط.");

        // إنشاء سند قبض
        $stmt_sp = $this->pdo->prepare("CALL sp_create_receipt_voucher(?, ?, ?, ?, ?, 1.0, ?, ?, ?, ?, ?, ?, @v_id, @v_num)");
        $stmt_sp->execute([
            $params['branch_id'],
            'customer',
            $params['customer_id'],
            $params['amount'],
            $params['currency_id'],
            $params['account_id'],
            $party_account_id,
            $params['source_ref'],
            $params['description'],
            $this->user_id,
            null
        ]);
        $stmt_sp->closeCursor();
        $voucher_id = $this->pdo->query("SELECT @v_id")->fetchColumn();

        if ($voucher_id) {
            // ربط السند بالفاتورة (Allocation)
            $this->pdo->prepare("INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount) VALUES (?, ?, ?)")
                      ->execute([$voucher_id, $params['invoice_id'], $params['amount']]);
            
            // ترحيل السند
            $this->pdo->prepare("UPDATE financial_transactions SET status = 'posted' WHERE id = ?")->execute([$voucher_id]);
            
            // تحديث المبلغ المستلم في الفاتورة
            $this->updateInvoicePaymentStatus($params['invoice_id']);
        }
    }

    /**
     * تحديث حالة الدفع للفاتورة بناءً على التخصيصات
     * @param int $invoice_id
     * @return void
     */
    public function updateInvoicePaymentStatus(int $invoice_id): void {
        $stmt = $this->pdo->prepare("
            SELECT 
                i.net_amount,
                IFNULL(SUM(pa.allocated_amount), 0) as total_allocated
            FROM invoices i
            LEFT JOIN payment_allocations pa ON i.id = pa.invoice_id
            WHERE i.id = ?
            GROUP BY i.id
        ");
        $stmt->execute([$invoice_id]);
        $inv = $stmt->fetch();

        if ($inv) {
            $status = 'unpaid';
            if ($inv['total_allocated'] >= $inv['net_amount'] - 0.01) {
                $status = 'fully_paid';
            } elseif ($inv['total_allocated'] > 0) {
                $status = 'partial';
            }

            $this->pdo->prepare("UPDATE invoices SET amount_received = ?, payment_status = ? WHERE id = ?")
                      ->execute([$inv['total_allocated'], $status, $invoice_id]);
        }
    }
}
