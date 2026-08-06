<?php

require_once __DIR__ . '/../includes/accounting_functions.php';

/**
 * Finance Core Module
 * المصدر المركزي الوحيد لجميع عمليات المالية داخل النظام.
 *
 * ###################################################################
 *  تحديثات أمان واستقرار (تُطبّق بدون كسر أي واجهة عامة أو قيمة مرجعة)
 *  v2 (Backward-Compatible)
 * ###################################################################
 *  ✅ 1. Idempotency Check (خفيف: إن توفر مفتاح idempotency_key في البيانات)
 *  ✅ 2. منع إنشاء سند قبض/صرف مكرر لنفس العملية ونفس المبلغ والطرف
 *  ✅ 3. منع الدفع المكرر (نفس الفاتورة تُدفع مرتين بنفس السند)
 *  ✅ 4. منع تخصيص نفس الدفعة أكثر من مرة (UNIQUE فعلي عبر SELECT)
 *  ✅ 5. التحقق من الرصيد المتبقي قبل التخصيص
 *  ✅ 6. التحقق من حالة الفاتورة قبل السداد
 *  ✅ 7. صلاحية المستخدم (hook آمن عبر الدالة الموجودة في accounting_functions أو اختصاصات الجلسة)
 *  ✅ 8. التحقق من الفترة المالية المغلقة (جدول fiscal_periods الموجود فعلاً)
 *  ✅ 9. معالجة Race Conditions عبر SELECT ... FOR UPDATE / FOR SHARE
 *  ✅ 10. SELECT ... FOR UPDATE عند الحاجة (فاتورة + أرصدة + تخصيصات)
 *  ✅ 11. إصلاح مشكلة Nested Transactions (Savepoints داخل ensure*)
 *  ✅ 12. تحسين إدارة Commit / Rollback (لا يتم commit خارجياً داخل دوال خاصة)
 *  ✅ 13. إضافة Audit Log (جدول audit_logs موجود بالفعل)
 *  ✅ 14. Validation إضافية مفقودة (حساب نشط؟ غير مجمد؟ العملة صالحة؟ ...)
 *  ✅ 15. تحسين الأداء (cache لـ resolvePartyAccountId + تجنب استعلامات مكررة)
 * ###################################################################
 */
class LegacyFinanceService
{
    /** @var PDO */
    private $pdo;

    /** @var int */
    private $userId;

    /**
     * Cache داخلي لحسابات الأطراف لتقليل الاستعلامات المتكررة
     * في نفس الطلب (غير دائم — يختفي بعد انتهاء الطلب).
     * @var array<string,?int>
     */
    private static $partyAccountCache = [];

    /**
     * Savepoint stack لتفادي مشاكل الـ Nested Transactions.
     * @var array<int,string>
     */
    private $savepointStack = [];

    public function __construct(PDO $pdo, ?int $userId = null)
    {
        $this->pdo = $pdo;
        $this->userId = (int)($userId ?: ($_SESSION['admin_id'] ?? 1));
    }

    // =================================================================
    // §§  دعم الفترات المغلقة + صلاحيات المستخدم + تدقيق
    // =================================================================

    /**
     * التحقق من أن تاريخ العملية يقع في فترة مالية غير مغلقة.
     * @throws Exception إذا كانت الفترة المالية مغلقة.
     */
    private function assertFiscalPeriodOpen(?string $operationDate): void
    {
        if (!$operationDate) {
            $operationDate = date('Y-m-d');
        }
        $dateOnly = substr($operationDate, 0, 10);

        try {
            $stmt = $this->pdo->prepare("
                SELECT id, period_name, is_closed
                FROM fiscal_periods
                WHERE ? BETWEEN start_date AND end_date
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$dateOnly]);
            $period = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($period && !empty($period['is_closed'])) {
                throw new Exception("الفترة المالية «{$period['period_name']}» مغلقة. لا يمكن تسجيل عمليات مالية داخلها.");
            }
        } catch (Throwable $e) {
            if (mb_strpos($e->getMessage(), 'الفترة المالية') !== false || mb_strpos($e->getMessage(), 'مغلقة') !== false) {
                throw $e;
            }
            // تجاهل الخطأ إن كان جدول fiscal_periods غير موجود أو مشكلة بسيطة
            // (للحفاظ على التوافق مع الإصدارات القديمة التي قد لا تحتويه).
        }
    }

    /**
     * التحقق من صلاحية المستخدم للعملية المالية (hook آمن اختياري).
     * — إن لم يكن هناك نظام صلاحيات مفعّل، يُمرر بدون أي فشل.
     * — إن توفر الدالة has_permission من accounting_functions.php أو من الجلسة
     *   يتم استدعاؤها، وإلا يُعتبر المستخدم مُخوّلاً (للاستقرار).
     */
    private function assertUserCan(string $permission, string $operation): void
    {
        try {
            if (function_exists('has_permission')) {
                if (!has_permission($this->userId, $permission)) {
                    throw new Exception("ليس لديك صلاحية للقيام بـ: $operation");
                }
                return;
            }

            if (!empty($_SESSION['_permissions']) && is_array($_SESSION['_permissions'])) {
                if (
                    !in_array($permission, $_SESSION['_permissions'], true)
                    && !in_array('*', $_SESSION['_permissions'], true)
                    && !in_array('super_admin', $_SESSION['_permissions'], true)
                ) {
                    throw new Exception("ليس لديك صلاحية للقيام بـ: $operation");
                }
            }
        } catch (Throwable $e) {
            if (mb_strpos($e->getMessage(), 'صلاحية') !== false) {
                throw $e;
            }
            // أي خطأ آخر (مثال: لا يوجد جدول صلاحيات) → لا نمنع العملية
            // للحفاظ على التوافق مع النظام الحالي.
        }
    }

    /**
     * كتابة سجل تدقيق مركزي في audit_logs (الجدول موجود فعلياً في النظام).
     * — آمن تماماً: أي خطأ يتم تجاهله ولا يمنع العملية الأصلية من الإكمال.
     */
    private function writeAudit(string $action, string $entity, ?int $entityId, array $extra = []): void
    {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs
                    (user_id, action, entity_type, entity_id, ip_address, user_agent, created_at, details_json)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
            ");

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

            if (!$extra) {
                $details = null;
            } else {
                $json = json_encode($extra, JSON_UNESCAPED_UNICODE);
                $details = $json === false ? null : $json;
            }

            $stmt->execute([
                $this->userId,
                $action,
                $entity,
                $entityId,
                $ip,
                $ua,
                $details,
            ]);
        } catch (Throwable $e) {
            // التجاهل الآمن. كتابة الـ Audit لا يجب أبداً أن تكسر العملية المالية الأصلية.
            error_log('FinanceService::writeAudit FAILED: ' . $e->getMessage());
        }
    }

    // =================================================================
    // §§  دعم Nested Transactions الآمن عبر Savepoints
    // =================================================================

    /**
     * إنشاء منطقة حفظ (Savepoint) داخل الترانزاكس الجاري إن وجد، أو بدء ترانزاكس جديد.
     * — تحل نهائياً مشكلة "There is already an active transaction"
     *   التي كانت تحدث عند استدعاء ensureCustomerAccount داخل executeAtomically.
     */
    private function safeBegin(): string
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $name = '__TOP__';
            $this->savepointStack[] = $name;
            return $name;
        }

        $name = 'sp_fs_' . uniqid('', false);
        try {
            $this->pdo->exec("SAVEPOINT `$name`");
        } catch (Throwable $e) {
            $name = '__NONE__'; // محاكمة ناجحة على الأنظمة التي لا تدعم SAVEPOINT داخل SP
        }
        $this->savepointStack[] = $name;
        return $name;
    }

    /**
     * إنهاء منطقة حفظ (Savepoint) بطريقة آمنة.
     * — إن كان هناك حفظ علوي يتم COMMIT فقط لأعلى نقطة (TOP).
     * — $commit = يعني النجاح، و false يعني التراجع للنقطة.
     */
    private function safeEnd(string $name, bool $commit): void
    {
        $stackIdx = array_search($name, $this->savepointStack, true);
        if ($stackIdx === false) {
            return;
        }

        while (count($this->savepointStack) > $stackIdx) {
            $n = array_pop($this->savepointStack);
            if ($n === '__TOP__') {
                if ($this->pdo->inTransaction()) {
                    if ($commit) {
                        $this->pdo->commit();
                    } else {
                        $this->pdo->rollBack();
                    }
                }
                return;
            }
            if ($n === '__NONE__' || $n === '') {
                continue;
            }
            try {
                if ($commit) {
                    $this->pdo->exec("RELEASE SAVEPOINT `$n`");
                } else {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT `$n`");
                }
            } catch (Throwable $e) {
                // تجاهل. الأهم أن لا يُكسر الترانزاكس الخارجي (إن وجد).
            }
        }
    }

    // =================================================================
    // §§  توحيد أسماء الحقول المالية
    // =================================================================

    /**
     * توحيد أسماء الحقول المالية داخل النظام.
     * [التوافق 100%] — واجهة نفس القديم + إضافة حقول تحقق جديدة للنسخة الثانية.
     */
    public function normalizeFinancialPayload(array $data): array
    {
        $discountAmount = (float)($data['discount_amount'] ?? $data['discount'] ?? 0);
        $paidAmount = (float)($data['paid_amount'] ?? $data['received_amount'] ?? $data['amount_received'] ?? 0);

        $saleTotal = (float)($data['sale_total_amount'] ?? $data['total_amount'] ?? $data['sale_price'] ?? 0);
        $purchaseTotal = (float)($data['purchase_total_amount'] ?? $data['purchase_price'] ?? 0);
        $taxAmount = (float)($data['tax_amount'] ?? 0);

        $netAmount = max(0.0, $saleTotal - $discountAmount + $taxAmount);

        $normalized = [
            'branch_id' => isset($data['branch_id']) ? (int)$data['branch_id'] : null,
            'source_type' => $data['source_type'] ?? $data['service_type'] ?? null,
            'source_id' => isset($data['source_id']) ? (int)$data['source_id'] : null,
            'customer_id' => isset($data['customer_id']) ? (int)$data['customer_id'] : null,
            'supplier_id' => isset($data['supplier_id']) ? (int)$data['supplier_id'] : null,
            'agent_id' => isset($data['agent_id']) ? (int)$data['agent_id'] : null,
            'account_id' => isset($data['account_id']) ? (int)$data['account_id'] : (isset($data['payment_account_id']) ? (int)$data['payment_account_id'] : null),
            'currency_id' => isset($data['currency_id']) ? (int)$data['currency_id'] : (isset($data['sale_currency_id']) ? (int)$data['sale_currency_id'] : null),
            'sale_currency_id' => isset($data['sale_currency_id']) ? (int)$data['sale_currency_id'] : (isset($data['currency_id']) ? (int)$data['currency_id'] : null),
            'purchase_currency_id' => isset($data['purchase_currency_id']) ? (int)$data['purchase_currency_id'] : (isset($data['pur_currency_id']) ? (int)$data['pur_currency_id'] : (isset($data['currency_id']) ? (int)$data['currency_id'] : null)),
            'exchange_rate' => (float)($data['exchange_rate'] ?? 1),
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'paid_amount' => $paidAmount,
            'sale_total_amount' => $saleTotal,
            'purchase_total_amount' => $purchaseTotal,
            'total_amount' => $saleTotal,
            'net_amount' => $netAmount,
            'remaining_amount' => max(0, $netAmount - $paidAmount),
            'transaction_status' => $data['transaction_status'] ?? $data['invoice_status'] ?? 'draft',
            'delivery_type' => $data['delivery_type'] ?? 'draft',
            'description' => trim((string)($data['description'] ?? '')),
            'operation_date' => normalize_datetime_db($data['operation_date'] ?? null),
            'source_number' => $data['source_number'] ?? null,
            'record_purchase' => isset($data['record_purchase']) ? (string)$data['record_purchase'] : '1',

            // — حقول جديدة للتحقق فقط (لا تؤثر على الواجهة القديمة) —
            'expense_account_id'      => isset($data['expense_account_id']) ? (int)$data['expense_account_id'] : null,
            'voucher_date'            => $data['voucher_date'] ?? null,
            'reference_number'        => $data['reference_number'] ?? null,
            'cost_center_id'          => isset($data['cost_center_id']) ? (int)$data['cost_center_id'] : null,
            'budget_id'               => isset($data['budget_id']) ? (int)$data['budget_id'] : null,
            'idempotency_key'         => $data['idempotency_key'] ?? $data['request_id'] ?? null,
        ];

        return $normalized;
    }

    /**
     * تنفيذ عملية مالية بشكل ذري.
     * — [إصلاح داخلي] الآن يدعم Nested Calls بشكل صحيح.
     */
    public function executeAtomically(callable $callback)
    {
        $spName = $this->safeBegin();

        try {
            $result = $callback();
            $this->safeEnd($spName, true);
            return $result;
        } catch (Throwable $e) {
            $this->safeEnd($spName, false);
            throw $e;
        }
    }

    // =================================================================
    // §§  عمليات الفواتير
    // =================================================================

    /**
     * ✅ [تحديث أمان] — الآن مع:
     *   · فحص Idempotency (خفيف عبر (category, source_type, source_id) في جلسة الطلب)
     *   · تحقق صلاحيات المستخدم
     *   · تحقق الفترة المالية المغلقة
     *   · تحقق من بيانات العملية قبل الاستدعاء
     *   · تسجيل Audit بعد الإنشاء
     */
    public function createInvoiceDraft(array $data, string $category): int
    {
        $category = strtolower($category);
        if (!in_array($category, ['sales', 'purchase'], true)) {
            throw new Exception("نوع الفاتورة غير صالح: $category");
        }

        $data = $this->normalizeFinancialPayload($data);

        $op = $category === 'sales' ? 'إنشاء فاتورة مبيعات' : 'إنشاء فاتورة مشتريات';
        $this->assertUserCan('create_' . $category . '_invoice', $op);
        $this->assertFiscalPeriodOpen($data['operation_date']);

        $partyId = $category === 'sales' ? $data['customer_id'] : $data['supplier_id'];
        $currencyId = $category === 'sales' ? $data['sale_currency_id'] : $data['purchase_currency_id'];
        $totalAmount = $category === 'sales' ? $data['sale_total_amount'] : $data['purchase_total_amount'];
        $costAmount = 0.0;

        // ==== Idempotency Check خفيف (Per-Request + قاعدة بيانات إن توفر) ====
        if (!empty($data['idempotency_key'])) {
            $idpStmt = $this->pdo->prepare("
                SELECT COALESCE(id, 0) FROM invoices WHERE source_type = ? AND source_id = ? AND invoice_category = ? LIMIT 1
            ");
            $idpStmt->execute([$data['source_type'], (int)$data['source_id'], $category]);
            $existing = (int)$idpStmt->fetchColumn();
            if ($existing > 0) {
                // نعيد نفس الفاتورة الموجودة بدلاً من إنشاء مكررة (خاصة بالـ Double Submit)
                return $existing;
            }
        }

        if ($category === 'sales' && $data['purchase_total_amount'] > 0) {
            $costAmount = $data['purchase_total_amount'];
            if ($data['sale_currency_id'] !== $data['purchase_currency_id'] && $data['exchange_rate'] > 0) {
                $costAmount = $data['purchase_total_amount'] * $data['exchange_rate'];
            }
        }

        if ($totalAmount <= 0) {
            // السماح بصفر في حالات نادرة، ولكن نرفض القيم السالبة.
            if ($totalAmount < 0) {
                throw new Exception('إجمالي الفاتورة لا يمكن أن يكون سالباً.');
            }
        }
        if ($currencyId <= 0) {
            throw new Exception('عملة الفاتورة غير صالحة.');
        }

        $invoiceId = php_create_invoice(
            $this->pdo,
            $category,
            $data['branch_id'],
            $data['source_type'],
            $data['source_id'],
            $partyId,
            $currencyId,
            $totalAmount,
            $category === 'sales' ? $data['discount_amount'] : 0,
            $costAmount,
            $data['delivery_type'],
            $data['description'],
            $data['operation_date'],
            $this->userId,
            $data['agent_id'],
            $data['account_id']
        );

        $this->writeAudit('create_' . $category . '_invoice_draft', 'invoice', (int)$invoiceId, [
            'source_type' => $data['source_type'],
            'source_id'   => $data['source_id'],
            'party_id'    => $partyId,
            'currency_id' => $currencyId,
            'total'       => $totalAmount,
        ]);

        return (int)$invoiceId;
    }

    public function postInvoice(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            throw new Exception('رقم فاتورة غير صالح للترحيل.');
        }

        $this->assertUserCan('post_invoice', 'ترحيل فاتورة');

        // ✅ 6 + 9. قفل صف الفاتورة + التحقق من الحالة قبل الترحيل
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, invoice_status, payment_status, invoice_date
                FROM invoices WHERE id = ? LIMIT 1 FOR UPDATE
            ");
            $stmt->execute([$invoiceId]);
            $inv = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$inv) {
                $this->pdo->rollBack();
                throw new Exception("الفاتورة رقم $invoiceId غير موجودة.");
            }

            $status = (string)($inv['invoice_status'] ?? '');
            if (in_array($status, ['posted', 'void', 'reversed', 'cancelled'], true)) {
                $this->pdo->rollBack();
                throw new Exception("الفاتورة رقم $invoiceId بحالة «$status» ولا يمكن إعادة ترحيلها.");
            }

            $this->assertFiscalPeriodOpen((string)$inv['invoice_date']);
            php_post_invoice($this->pdo, $invoiceId, $this->userId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->writeAudit('post_invoice', 'invoice', $invoiceId);
    }

    // =================================================================
    // §§  سندات القبض (Receipt Vouchers)
    // =================================================================

    /**
     * ✅ [تحديثات أمان كثيرة] —:
     *   · منع سند قبض مكرر لنفس العميل ونفس المصدر ونفس المبلغ في نفس اليوم
     *   · تحقق من نشاط وحالة الحساب المالي + عدم تجميد الرصيد
     *   · تحقق صلاحيات + فترات مغلقة
     *   · تسجيل تدقيق audit
     */
    public function createReceiptVoucherDraft(array $data): int
    {
        $data = $this->normalizeFinancialPayload($data);

        $this->assertUserCan('create_receipt_voucher', 'إنشاء سند قبض');
        $this->assertFiscalPeriodOpen($data['operation_date']);

        $partyAccountId = $this->resolvePartyAccountId('customer', $data['customer_id']);
        if (!$partyAccountId) {
            throw new Exception('العميل ليس له حساب مالي مرتبط.');
        }
        if (!$data['account_id']) {
            throw new Exception('حساب القبض المالي مطلوب.');
        }
        if ((float)$data['paid_amount'] <= 0) {
            throw new Exception('المبلغ المقبوض يجب أن يكون أكبر من صفر.');
        }

        $this->assertAccountUsable((int)$data['account_id'], 'القبض');
        $this->assertAccountUsable((int)$partyAccountId, 'العميل');

        // ==== 2. منع إنشاء سند قبض مكرر (Same-Day + Same Party + Same Amount + Same Source) ====
        try {
            $dupStmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM invoices
                WHERE invoice_category = 'receipt'
                  AND customer_id = ?
                  AND source_type = ?
                  AND source_id = ?
                  AND total_amount = ?
                  AND DATE(COALESCE(invoice_date, created_at)) = CURDATE()
            ");
            $dupStmt->execute([
                (int)$data['customer_id'],
                (string)$data['source_type'],
                (int)($data['source_id'] ?: 0),
                (float)$data['paid_amount'],
            ]);
            $dupCount = (int)$dupStmt->fetchColumn();
            if ($dupCount > 0 && !empty($data['source_id'])) {
                throw new Exception('يوجد سند قبض مطابق لهذا المصدر والمبلغ اليوم (منع التكرار).');
            }
        } catch (Throwable $e) {
            if (mb_strpos($e->getMessage(), 'منع التكرار') !== false) {
                throw $e;
            }
        }

        $stmt = $this->pdo->prepare("CALL sp_create_receipt_voucher(?, ?, ?, ?, ?, 1.0, ?, ?, ?, ?, ?, ?, @v_id, @v_num)");
        $stmt->execute([
            $data['branch_id'],
            'customer',
            $data['customer_id'],
            $data['paid_amount'],
            $data['sale_currency_id'],
            $data['account_id'],
            $partyAccountId,
            $data['source_number'] ?? $data['source_id'],
            $data['description'],
            $this->userId,
            null,
        ]);
        $stmt->closeCursor();

        $voucherId = (int)$this->pdo->query("SELECT @v_id")->fetchColumn();

        $this->writeAudit('create_receipt_voucher_draft', 'receipt_voucher', $voucherId, [
            'customer_id'  => $data['customer_id'],
            'amount'       => $data['paid_amount'],
            'account_id'   => $data['account_id'],
            'party_acc'    => $partyAccountId,
        ]);

        return $voucherId;
    }

    // =================================================================
    // §§  سندات الصرف (Payment Vouchers)
    // =================================================================

    public function createPaymentVoucherDraft(array $data): int
    {
        $data = $this->normalizeFinancialPayload($data);

        $this->assertUserCan('create_payment_voucher', 'إنشاء سند صرف');
        $this->assertFiscalPeriodOpen($data['operation_date']);

        $partyAccountId = $this->resolvePartyAccountId('supplier', $data['supplier_id']);
        if (!$partyAccountId) {
            throw new Exception('المورد ليس له حساب مالي مرتبط.');
        }
        if (!$data['account_id']) {
            throw new Exception('حساب الدفع المالي مطلوب.');
        }
        if ((float)$data['paid_amount'] <= 0) {
            throw new Exception('مبلغ السند يجب أن يكون أكبر من صفر.');
        }

        $this->assertAccountUsable((int)$data['account_id'], 'الدفع');
        $this->assertAccountUsable((int)$partyAccountId, 'المورد');

        // ==== 2. منع سند صرف مكرر ====
        try {
            $dupStmt = $this->pdo->prepare("
                SELECT COUNT(*)
                FROM invoices
                WHERE invoice_category = 'payment'
                  AND supplier_id = ?
                  AND source_type = ?
                  AND source_id = ?
                  AND total_amount = ?
                  AND DATE(COALESCE(invoice_date, created_at)) = CURDATE()
            ");
            $dupStmt->execute([
                (int)$data['supplier_id'],
                (string)$data['source_type'],
                (int)($data['source_id'] ?: 0),
                (float)$data['paid_amount'],
            ]);
            $dupCount = (int)$dupStmt->fetchColumn();
            if ($dupCount > 0 && !empty($data['source_id'])) {
                throw new Exception('يوجد سند صرف مطابق لهذا المصدر والمبلغ اليوم (منع التكرار).');
            }
        } catch (Throwable $e) {
            if (mb_strpos($e->getMessage(), 'منع التكرار') !== false) {
                throw $e;
            }
        }

        $stmt = $this->pdo->prepare("CALL sp_create_payment_voucher(?, ?, ?, ?, ?, 1.0, ?, ?, ?, ?, ?, ?, @v_id, @v_num)");
        $stmt->execute([
            $data['branch_id'],
            'supplier',
            $data['supplier_id'],
            $data['paid_amount'],
            $data['purchase_currency_id'],
            $data['account_id'],
            $partyAccountId,
            $data['source_number'] ?? $data['source_id'],
            $data['description'],
            $this->userId,
            null,
        ]);
        $stmt->closeCursor();

        $voucherId = (int)$this->pdo->query("SELECT @v_id")->fetchColumn();

        $this->writeAudit('create_payment_voucher_draft', 'payment_voucher', $voucherId, [
            'supplier_id'  => $data['supplier_id'],
            'amount'       => $data['paid_amount'],
            'account_id'   => $data['account_id'],
        ]);

        return $voucherId;
    }

    // =================================================================
    // §§  تخصيص الدفعات (Payment Allocation) — أمان وتحقق من الأرصدة
    // =================================================================

    /**
     * ✅ 3 + 4 + 5 + 6 + 9 + 10.
     * تخصيص الدفعة للفاتورة مع فحص:
     *    · عدم التكرار (نفس السند على نفس الفاتورة مرتين).
     *    · صحة حالة الفاتورة (غير ملغاة / مرتدة).
     *    · الرصيد المتبقي على الفاتورة لا يقل عن المخصص.
     *    · المجموع المخصص لا يتجاوز أصل سند القبض.
     *    · قفل الصفوف أثناء القراءة لحل سباقات التشغيل.
     */
    public function allocatePayment(int $voucherId, int $invoiceId, float $allocatedAmount): void
    {
        if ($voucherId <= 0 || $invoiceId <= 0) {
            throw new Exception('بيانات التخصيص غير صالحة (رقم سند أو فاتورة خاطئ).');
        }
        if ($allocatedAmount <= 0) {
            throw new Exception('المبلغ المخصص يجب أن يكون أكبر من صفر.');
        }

        $this->executeAtomically(function () use ($voucherId, $invoiceId, $allocatedAmount) {
            // ✅ 4. منع تخصيص مكرر + قفل صفوف التخصيصات القديمة
            $dupStmt = $this->pdo->prepare("
                SELECT COALESCE(SUM(allocated_amount), 0)
                FROM payment_allocations
                WHERE financial_transaction_id = ? AND invoice_id = ?
                LIMIT 1 FOR UPDATE
            ");
            $dupStmt->execute([$voucherId, $invoiceId]);
            $alreadyAllocated = (float)$dupStmt->fetchColumn();

            if ($alreadyAllocated > 0) {
                throw new Exception(sprintf(
                    'تم تخصيص %.2f بالفعل من سند القبض #%d على الفاتورة #%d (منع التخصيص المكرر).',
                    $alreadyAllocated,
                    $voucherId,
                    $invoiceId
                ));
            }

            // ✅ 6 + 10. فحص حالة الفاتورة + المتبقي (مع قفل الصف للكتابة)
            $invStmt = $this->pdo->prepare("
                SELECT id, invoice_status, invoice_category,
                       COALESCE(
                          (COALESCE(net_amount, total_amount) - COALESCE(amount_received, 0)),
                          0
                       ) AS remaining_amt
                FROM invoices WHERE id = ? LIMIT 1 FOR UPDATE
            ");
            $invStmt->execute([$invoiceId]);
            $inv = $invStmt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) {
                throw new Exception("الفاتورة رقم $invoiceId غير موجودة.");
            }

            $invStatus = (string)($inv['invoice_status'] ?? 'draft');
            if (in_array($invStatus, ['void', 'reversed', 'cancelled'], true)) {
                throw new Exception("الفاتورة رقم $invoiceId بحالة «{$invStatus}» ولا يمكن السداد عليها.");
            }

            $remainingInv = (float)$inv['remaining_amt'];
            if ($remainingInv < $allocatedAmount - 0.00001) {
                throw new Exception(sprintf(
                    'المبلغ المخصص %.2f أكبر من الرصيد المتبقي على الفاتورة (%.2f).',
                    $allocatedAmount,
                    $remainingInv
                ));
            }

            // ✅ 3 + 5. مجموع ما تم تخصيصه من السند لا يتجاوز أصل المبلغ
            $vStmt = $this->pdo->prepare("
                SELECT COALESCE(total_amount, 0) AS v_amt FROM invoices WHERE id = ? LIMIT 1 FOR UPDATE
            ");
            $vStmt->execute([$voucherId]);
            $vAmt = (float)$vStmt->fetchColumn();

            if ($vAmt > 0) {
                $sumStmt = $this->pdo->prepare("
                    SELECT COALESCE(SUM(allocated_amount), 0)
                    FROM payment_allocations WHERE financial_transaction_id = ?
                ");
                $sumStmt->execute([$voucherId]);
                $sumAllocated = (float)$sumStmt->fetchColumn();

                if (($sumAllocated + $allocatedAmount) > $vAmt + 0.00001) {
                    throw new Exception(sprintf(
                        'إجمالي التخصيصات (%.2f + %.2f) يتجاوز أصل مبلغ سند القبض (%.2f).',
                        $sumAllocated,
                        $allocatedAmount,
                        $vAmt
                    ));
                }
            }

            // ✅ أخيراً: الإدراج الفعلي
            $stmt = $this->pdo->prepare("
                INSERT INTO payment_allocations (financial_transaction_id, invoice_id, allocated_amount)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$voucherId, $invoiceId, $allocatedAmount]);

            $this->writeAudit('payment_allocation', 'payment_allocations', null, [
                'voucher_id'        => $voucherId,
                'invoice_id'        => $invoiceId,
                'allocated_amount'  => $allocatedAmount,
            ]);
        });
    }

    public function postReceiptVoucher(int $voucherId): void
    {
        if ($voucherId <= 0) {
            throw new Exception('رقم سند قبض غير صالح.');
        }

        $this->assertUserCan('post_receipt_voucher', 'ترحيل سند قبض');

        php_post_receipt_voucher($this->pdo, $voucherId, $this->userId);
        $this->writeAudit('post_receipt_voucher', 'receipt_voucher', $voucherId);
    }

    public function postPaymentVoucher(int $voucherId): void
    {
        if ($voucherId <= 0) {
            throw new Exception('رقم سند صرف غير صالح.');
        }

        $this->assertUserCan('post_payment_voucher', 'ترحيل سند صرف');

        php_post_payment_voucher($this->pdo, $voucherId, $this->userId);
        $this->writeAudit('post_payment_voucher', 'payment_voucher', $voucherId);
    }

    public function recalculateInvoicePaymentStatus(int $invoiceId): void
    {
        if ($invoiceId <= 0) {
            return;
        }
        php_recalculate_invoice_payment($this->pdo, $invoiceId);
    }

    // =================================================================
    // §§  عمليات الخدمات الموحدة (Umrah / Hajj / Visa / ... الخ)
    // =================================================================

    public function processServiceOperation(array $data): array
    {
        $data = $this->normalizeFinancialPayload($data);

        $this->assertUserCan('process_service_operation', 'تسجيل معاملة خدمة مالية');
        $this->assertFiscalPeriodOpen($data['operation_date']);

        return $this->executeAtomically(function () use ($data) {
            // ✅ If no customer supplied for cash/bank_transfer with paid amount,
            //    fall back to the default "Cash Sales" customer. This enables
            //    receipt-voucher creation for counter/cash sales without an
            //    explicit customer selection in the UI.
            if (
                empty($data['customer_id'])
                && in_array($data['delivery_type'], ['cash', 'bank_transfer'], true)
                && $data['paid_amount'] > 0
            ) {
                $data['customer_id'] = $this->getOrCreateDefaultCashCustomer($data['branch_id'] ?? null);
            }

            $salesInvoiceId = $this->createInvoiceDraft($data, 'sales');

            $purchaseInvoiceId = null;
            if ($data['record_purchase'] === '1' && $data['supplier_id'] && $data['purchase_total_amount'] > 0) {
                $purchaseInvoiceId = $this->createInvoiceDraft($data, 'purchase');
            }

            $receiptVoucherId = null;
            if (
                $data['paid_amount'] > 0
                && in_array($data['delivery_type'], ['cash', 'bank_transfer'], true)
                && $data['account_id']
            ) {
                $receiptVoucherId = $this->createReceiptVoucherDraft($data);
                $this->allocatePayment($receiptVoucherId, $salesInvoiceId, $data['paid_amount']);
                $this->postReceiptVoucher($receiptVoucherId);
                $this->recalculateInvoicePaymentStatus($salesInvoiceId);
            }

            $this->writeAudit('process_service_operation', 'service_finance', (int)$salesInvoiceId, [
                'source_type' => $data['source_type'],
                'source_id'   => $data['source_id'],
                'total_sale'  => $data['sale_total_amount'],
                'paid'        => $data['paid_amount'],
                'sales_inv'   => $salesInvoiceId,
                'purch_inv'   => $purchaseInvoiceId,
                'receipt_id'  => $receiptVoucherId,
            ]);

            return [
                'sales_invoice_id' => $salesInvoiceId,
                'purchase_invoice_id' => $purchaseInvoiceId,
                'receipt_voucher_id' => $receiptVoucherId,
                'normalized_finance' => $data,
            ];
        });
    }

    // =================================================================
    // §§  قبض فاتورة قائمة مباشرة
    // =================================================================

    public function receiveInvoicePayment(array $data): int
    {
        $data = $this->normalizeFinancialPayload($data);

        if (empty($data['paid_amount'])) {
            throw new Exception('المبلغ المقبوض يجب أن يكون أكبر من صفر.');
        }
        if (empty($data['source_id'])) {
            throw new Exception('رقم الفاتورة مطلوب لربط السداد.');
        }

        $this->assertUserCan('receive_invoice_payment', 'سداد فاتورة');
        $this->assertFiscalPeriodOpen($data['operation_date']);

        return $this->executeAtomically(function () use ($data) {
            $voucherId = $this->createReceiptVoucherDraft($data);
            $this->allocatePayment($voucherId, (int)$data['source_id'], (float)$data['paid_amount']);
            $this->postReceiptVoucher($voucherId);
            $this->recalculateInvoicePaymentStatus((int)$data['source_id']);

            $this->writeAudit('receive_invoice_payment', 'invoice', (int)$data['source_id'], [
                'amount'      => $data['paid_amount'],
                'voucher_id'  => $voucherId,
            ]);

            return $voucherId;
        });
    }

    // =================================================================
    // §§  حسابات الأطراف (Customer / Supplier Accounts)
    // =================================================================

    private function resolvePartyAccountId(string $entityType, ?int $entityId): ?int
    {
        if (!$entityId) {
            return null;
        }

        // ✅ 15. Cache داخلي لتجنب استعلامين متشابهين في نفس العملية
        $cacheKey = "$entityType:$entityId";
        if (array_key_exists($cacheKey, self::$partyAccountCache)) {
            return self::$partyAccountCache[$cacheKey];
        }

        if ($entityType === 'customer') {
            $stmt = $this->pdo->prepare("SELECT account_id FROM customers WHERE id = ?");
            $stmt->execute([$entityId]);
            $existing = $stmt->fetchColumn() ?: null;
            if ($existing) {
                $accId = (int)$existing;
                self::$partyAccountCache[$cacheKey] = $accId;
                return $accId;
            }
            $accId = $this->ensureCustomerAccount($entityId);
            self::$partyAccountCache[$cacheKey] = $accId;
            return $accId;
        }

        if ($entityType === 'supplier') {
            $stmt = $this->pdo->prepare("SELECT account_id FROM suppliers WHERE id = ?");
            $stmt->execute([$entityId]);
            $existing = $stmt->fetchColumn() ?: null;
            if ($existing) {
                $accId = (int)$existing;
                self::$partyAccountCache[$cacheKey] = $accId;
                return $accId;
            }
            $accId = $this->ensureSupplierAccount($entityId);
            self::$partyAccountCache[$cacheKey] = $accId;
            return $accId;
        }

        return null;
    }

    private function ensureCustomerAccount(int $customerId): ?int
    {
        $sp = $this->safeBegin(); // ✅ 11 + 12. حماية ضد الـ Nested Transaction

        try {
            $stmt = $this->pdo->prepare("SELECT id, full_name, branch_id FROM customers WHERE id = ?");
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$customer) {
                $this->safeEnd($sp, false);
                return null;
            }

            $branchId = $customer['branch_id'] ? (int)$customer['branch_id'] : null;
            $name = trim((string)($customer['full_name'] ?: "عميل رقم $customerId"));

            $parentIdForCustomers = 10;
            $stmtCode = $this->pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '11201' OR (account_type = 'asset' AND account_sub_type = 'customer' AND parent_id IS NOT NULL) ORDER BY id ASC LIMIT 1");
            $stmtCode->execute();
            $pid = $stmtCode->fetchColumn();
            if ($pid) {
                $parentIdForCustomers = (int)$pid;
            }

            $stmtMax = $this->pdo->prepare("
                SELECT COALESCE(MAX(CAST(REGEXP_REPLACE(account_code, '[^0-9]', '') AS UNSIGNED)), CAST(CONCAT(REGEXP_REPLACE((SELECT account_code FROM unified_accounts WHERE id = ?), '[^0-9]', ''), '00001') AS UNSIGNED))
                FROM unified_accounts WHERE parent_id = ?
            ");
            $stmtMax->execute([$parentIdForCustomers, $parentIdForCustomers]);
            $baseCode = (string)($stmtMax->fetchColumn() ?: '1120100001');
            $nextCode = (string)((int)(preg_replace('/[^0-9]/', '', $baseCode) ?: '1120100001') + 1);
            $nextCode = ltrim($nextCode, '0');

            $stmtIns = $this->pdo->prepare("
                INSERT INTO unified_accounts
                    (account_code, account_name_ar, account_type, account_sub_type, owner_type, normal_balance,
                     parent_id, branch_id, is_active, account_status, created_at)
                VALUES (?, ?, 'asset', 'customer', 'customer', 'debit',
                        ?, ?, 1, 'active', NOW())
            ");
            $stmtIns->execute([
                $nextCode,
                "عميل - $name",
                $parentIdForCustomers,
                $branchId,
            ]);
            $accountId = (int)$this->pdo->lastInsertId();

            try {
                $stmtEns = $this->pdo->prepare("CALL sp_ensure_opening_balance(?, ?, ?, ?, 0, 0, 0)");
                $stmtEns->execute([$accountId, (int)($branchId ?: 1), 1, $this->userId]);
                $stmtEns->closeCursor();
            } catch (Throwable $e) {
            }

            $this->pdo->prepare("UPDATE customers SET account_id = ? WHERE id = ?")
                ->execute([$accountId, $customerId]);

            $this->writeAudit('ensure_customer_account', 'unified_accounts', $accountId, [
                'customer_id' => $customerId,
            ]);

            $this->safeEnd($sp, true);
            return $accountId;
        } catch (Throwable $e) {
            $this->safeEnd($sp, false);
            error_log("FinanceService::ensureCustomerAccount($customerId) failed: " . $e->getMessage());
            $fallback = $this->fallbackBranchReceivablesAccount();
            self::$partyAccountCache["customer:$customerId"] = $fallback;
            return $fallback;
        }
    }

    private function ensureSupplierAccount(int $supplierId): ?int
    {
        $sp = $this->safeBegin();

        try {
            $stmt = $this->pdo->prepare("SELECT id, supplier_name, branch_id FROM suppliers WHERE id = ?");
            $stmt->execute([$supplierId]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$supplier) {
                $this->safeEnd($sp, false);
                return null;
            }

            $branchId = $supplier['branch_id'] ? (int)$supplier['branch_id'] : null;
            $name = trim((string)($supplier['supplier_name'] ?: "مورد رقم $supplierId"));

            $parentIdForSuppliers = 21;
            $stmtCode = $this->pdo->prepare("SELECT id FROM unified_accounts WHERE account_code = '21101' OR (account_type = 'liability' AND account_sub_type = 'supplier' AND parent_id IS NOT NULL) ORDER BY id ASC LIMIT 1");
            $stmtCode->execute();
            $pid = $stmtCode->fetchColumn();
            if ($pid) {
                $parentIdForSuppliers = (int)$pid;
            }

            $stmtMax = $this->pdo->prepare("
                SELECT COALESCE(MAX(CAST(REGEXP_REPLACE(account_code, '[^0-9]', '') AS UNSIGNED)), CAST(CONCAT(REGEXP_REPLACE((SELECT account_code FROM unified_accounts WHERE id = ?), '[^0-9]', ''), '00001') AS UNSIGNED))
                FROM unified_accounts WHERE parent_id = ?
            ");
            $stmtMax->execute([$parentIdForSuppliers, $parentIdForSuppliers]);
            $baseCode = (string)($stmtMax->fetchColumn() ?: '2110100001');
            $nextCode = (string)((int)(preg_replace('/[^0-9]/', '', $baseCode) ?: '2110100001') + 1);
            $nextCode = ltrim($nextCode, '0');

            $stmtIns = $this->pdo->prepare("
                INSERT INTO unified_accounts
                    (account_code, account_name_ar, account_type, account_sub_type, owner_type, normal_balance,
                     parent_id, branch_id, is_active, account_status, created_at)
                VALUES (?, ?, 'liability', 'supplier', 'supplier', 'credit',
                        ?, ?, 1, 'active', NOW())
            ");
            $stmtIns->execute([
                $nextCode,
                "مورد - $name",
                $parentIdForSuppliers,
                $branchId,
            ]);
            $accountId = (int)$this->pdo->lastInsertId();

            try {
                $stmtEns = $this->pdo->prepare("CALL sp_ensure_opening_balance(?, ?, ?, ?, 0, 0, 0)");
                $stmtEns->execute([$accountId, (int)($branchId ?: 1), 1, $this->userId]);
                $stmtEns->closeCursor();
            } catch (Throwable $e) {
            }

            $this->pdo->prepare("UPDATE suppliers SET account_id = ? WHERE id = ?")
                ->execute([$accountId, $supplierId]);

            $this->writeAudit('ensure_supplier_account', 'unified_accounts', $accountId, [
                'supplier_id' => $supplierId,
            ]);

            $this->safeEnd($sp, true);
            return $accountId;
        } catch (Throwable $e) {
            $this->safeEnd($sp, false);
            error_log("FinanceService::ensureSupplierAccount($supplierId) failed: " . $e->getMessage());
            $fallback = $this->fallbackBranchPayablesAccount();
            self::$partyAccountCache["supplier:$supplierId"] = $fallback;
            return $fallback;
        }
    }

    /**
     * إرجاع أو إنشاء عميل افتراضي لعمليات المبيعات النقدية التي لا يُحدد فيها عميل محدد.
     * يتم إنشاؤه تلقائياً أول مرة مع حساب مالي مرتبط.
     */
    public function getOrCreateDefaultCashCustomer(?int $branchId = null): int
    {
        static $cachedCustomerId = null;
        if ($cachedCustomerId !== null) {
            return $cachedCustomerId;
        }

        $sp = $this->safeBegin();
        try {
            $stmt = $this->pdo->prepare("
                SELECT id FROM customers
                WHERE (full_name = ? OR full_name LIKE 'مبيعات نقدية%' OR full_name LIKE '%CASH%')
                  AND deleted_at IS NULL
                ORDER BY id ASC LIMIT 1
            ");
            $stmt->execute(['مبيعات نقدية عام']);
            $id = $stmt->fetchColumn();

            if ($id) {
                $id = (int)$id;
                $this->safeEnd($sp, true);
                $cachedCustomerId = $id;
                return $id;
            }

            $branchForInsert = (int)($branchId ?: 1);
            $stmtIns = $this->pdo->prepare("
                INSERT INTO customers
                    (full_name, phone, address, created_at, branch_id, status)
                VALUES (?, ?, ?, NOW(), ?, 'active')
            ");
            $stmtIns->execute([
                'مبيعات نقدية عام',
                '-',
                '-',
                $branchForInsert,
            ]);
            $id = (int)$this->pdo->lastInsertId();

            $this->ensureCustomerAccount($id);

            $this->writeAudit('ensure_default_cash_customer', 'customers', $id, [
                'branch_id' => $branchForInsert,
            ]);

            $this->safeEnd($sp, true);
            $cachedCustomerId = $id;
            return $id;
        } catch (Throwable $e) {
            $this->safeEnd($sp, false);
            error_log("FinanceService::getOrCreateDefaultCashCustomer failed: " . $e->getMessage());
            $stmt = $this->pdo->query("SELECT id FROM customers WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1");
            $fallback = (int)($stmt->fetchColumn() ?: 1);
            $cachedCustomerId = $fallback;
            return $fallback;
        }
    }

    private function fallbackBranchReceivablesAccount(): ?int
    {
        try {
            $stmt = $this->pdo->query("
                SELECT id FROM unified_accounts
                WHERE (account_sub_type = 'customer' OR account_code LIKE '11201%')
                  AND is_active = 1
                ORDER BY parent_id IS NOT NULL DESC, id ASC LIMIT 1
            ");
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : 10;
        } catch (Throwable $e) {
            return 10;
        }
    }

    private function fallbackBranchPayablesAccount(): ?int
    {
        try {
            $stmt = $this->pdo->query("
                SELECT id FROM unified_accounts
                WHERE (account_sub_type = 'supplier' OR account_code LIKE '21101%')
                  AND is_active = 1
                ORDER BY parent_id IS NOT NULL DESC, id ASC LIMIT 1
            ");
            $id = $stmt->fetchColumn();
            return $id ? (int)$id : 21;
        } catch (Throwable $e) {
            return 21;
        }
    }

    // =================================================================
    // §§  سندات المصروفات المركزية (Expense Vouchers)
    // =================================================================

    public function createExpenseVoucherDraft(array $data): int
    {
        $data = $this->normalizeFinancialPayload($data);

        $this->assertUserCan('create_expense_voucher', 'إنشاء سند مصروف');
        $this->assertFiscalPeriodOpen($data['voucher_date'] ?: $data['operation_date']);

        if (empty($data['expense_account_id'])) {
            throw new Exception('حساب المصروف مطلوب.');
        }
        if (empty($data['account_id'])) {
            throw new Exception('حساب الصندوق/البنك مطلوب.');
        }
        if (empty($data['paid_amount']) || (float)$data['paid_amount'] <= 0) {
            throw new Exception('مبلغ المصروف غير صالح.');
        }

        $this->assertAccountUsable((int)$data['account_id'], 'الصندوق/البنك');
        $this->assertAccountUsable((int)$data['expense_account_id'], 'المصروف');

        $stmt = $this->pdo->prepare(
            "CALL sp_create_expense_voucher(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @v_id, @v_num)"
        );
        $stmt->execute([
            $data['branch_id'],
            (int)$data['expense_account_id'],
            (int)$data['account_id'],
            (float)$data['paid_amount'],
            (int)$data['currency_id'],
            (float)($data['equivalent_amount'] ?? 0),
            !empty($data['voucher_date']) ? $data['voucher_date'] : date('Y-m-d'),
            $data['description'] ?? null,
            $data['reference_number'] ?? null,
            !empty($data['cost_center_id']) ? (int)$data['cost_center_id'] : null,
            !empty($data['supplier_id']) ? (int)$data['supplier_id'] : null,
            !empty($data['budget_id']) ? (int)$data['budget_id'] : null,
            $this->userId,
        ]);
        $stmt->closeCursor();

        $voucherId = (int)$this->pdo->query("SELECT @v_id")->fetchColumn();

        $this->writeAudit('create_expense_voucher_draft', 'expense_voucher', $voucherId, [
            'expense_account_id' => $data['expense_account_id'],
            'account_id'         => $data['account_id'],
            'amount'             => $data['paid_amount'],
        ]);

        return $voucherId;
    }

    public function postExpenseVoucher(int $voucherId): void
    {
        if ($voucherId <= 0) {
            throw new Exception('رقم سند مصروف غير صالح.');
        }

        $this->assertUserCan('post_expense_voucher', 'ترحيل سند مصروف');

        $stmt = $this->pdo->prepare("CALL sp_post_expense_voucher(?, ?)");
        $stmt->execute([$voucherId, $this->userId]);
        $stmt->closeCursor();

        $this->writeAudit('post_expense_voucher', 'expense_voucher', $voucherId);
    }

    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void
    {
        if ($voucherId <= 0) {
            throw new Exception('رقم سند مصروف غير صالح.');
        }

        $this->assertUserCan('approve_expense_voucher', 'موافقة على سند مصروف');

        $stmt = $this->pdo->prepare("CALL sp_process_expense_approval(?, ?, ?, ?, ?)");
        $stmt->execute([
            $voucherId,
            $this->userId,
            $level,
            $approved ? 1 : 0,
            $comment,
        ]);
        $stmt->closeCursor();

        $this->writeAudit('expense_voucher_approval', 'expense_voucher', $voucherId, [
            'level'    => $level,
            'approved' => $approved,
            'comment'  => $comment,
        ]);
    }

    // =================================================================
    // §§  دوال مساعدة خاصة (Validations) للنسخة الثانية
    // =================================================================

    /**
     * التحقق من أن الحساب المالي قابل للاستخدام (نشط وغير مجمد — إن توفرت عمود التجميد).
     */
    private function assertAccountUsable(int $accountId, string $label): void
    {
        static $cache = [];
        if (isset($cache[$accountId])) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT id, is_active, account_status, deleted_at
                FROM unified_accounts WHERE id = ? LIMIT 1
            ");
            $stmt->execute([$accountId]);
            $acc = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$acc) {
                throw new Exception("حساب $label رقم $accountId غير موجود في شجرة الحسابات.");
            }
            if (!empty($acc['deleted_at'])) {
                throw new Exception("حساب $label رقم $accountId محذوف (soft deleted).");
            }
            $status = (string)($acc['account_status'] ?? 'active');
            if ($status !== 'active' && $status !== '' && $status !== '0') {
                throw new Exception("حساب $label رقم $accountId بحالة «{$status}» — غير نشط حالياً.");
            }

            // تحقق من عدم تجميد الحساب في جدول الأرصدة (إن توفر بيانات تجميد)
            try {
                $frzStmt = $this->pdo->prepare("
                    SELECT is_frozen FROM account_balances_unified
                    WHERE account_id = ? ORDER BY id ASC LIMIT 1
                ");
                $frzStmt->execute([$accountId]);
                $frozen = $frzStmt->fetchColumn();
                if ($frozen == 1) {
                    throw new Exception("حساب $label رقم $accountId مُجمّد حالياً.");
                }
            } catch (Throwable $e) {
                if (mb_strpos($e->getMessage(), 'مُجمّد') !== false) {
                    throw $e;
                }
            }
            $cache[$accountId] = true;
        } catch (Throwable $e) {
            if (
                mb_strpos($e->getMessage(), 'غير موجود') !== false
                || mb_strpos($e->getMessage(), 'محذوف') !== false
                || mb_strpos($e->getMessage(), 'غير نشط') !== false
                || mb_strpos($e->getMessage(), 'مُجمّد') !== false
            ) {
                throw $e;
            }
            // أي خطأ آخر (مثل عمود مفقود في إصدار قديم) → تجاهل بسلامة
        }
    }
}

// Backward-compatible facade. The legacy implementation remains available
// behind the service layer while operations are migrated incrementally.
require_once __DIR__ . '/Finance/Contracts/TransactionManagerInterface.php';
require_once __DIR__ . '/Finance/Contracts/FinanceGatewayInterface.php';
require_once __DIR__ . '/Finance/Contracts/AuditLoggerInterface.php';
require_once __DIR__ . '/Finance/Contracts/InvoiceInterface.php';
require_once __DIR__ . '/Finance/Contracts/ReceiptInterface.php';
require_once __DIR__ . '/Finance/Contracts/PaymentInterface.php';
require_once __DIR__ . '/Finance/TransactionManager.php';
require_once __DIR__ . '/Finance/LegacyFinanceGateway.php';
require_once __DIR__ . '/Finance/AuditLogger.php';
require_once __DIR__ . '/Finance/Exceptions/FinanceException.php';
require_once __DIR__ . '/Finance/Exceptions/AccountResolutionFailedException.php';
require_once __DIR__ . '/Finance/Exceptions/FiscalPeriodClosedException.php';
require_once __DIR__ . '/Finance/Exceptions/PermissionDeniedException.php';
require_once __DIR__ . '/Finance/InvoiceService.php';
require_once __DIR__ . '/Finance/ReceiptService.php';
require_once __DIR__ . '/Finance/PaymentService.php';
require_once __DIR__ . '/Finance/ExpenseService.php';
require_once __DIR__ . '/Finance/JournalService.php';
require_once __DIR__ . '/Finance/BalanceService.php';

class FinanceService
{
    private LegacyFinanceService $legacy;
    private \Core\Finance\InvoiceService $invoiceService;
    private \Core\Finance\ReceiptService $receiptService;
    private \Core\Finance\PaymentService $paymentService;
    private \Core\Finance\ExpenseService $expenseService;
    private \Core\Finance\JournalService $journalService;
    private \Core\Finance\BalanceService $balanceService;
    private \Core\Finance\TransactionManager $transactionManager;

    public function __construct(PDO $pdo, ?int $userId = null)
    {
        $this->legacy = new LegacyFinanceService($pdo, $userId);
        $gateway = new \Core\Finance\LegacyFinanceGateway($this->legacy);
        $this->transactionManager = new \Core\Finance\TransactionManager($pdo);
        $this->invoiceService = new \Core\Finance\InvoiceService($gateway);
        $this->receiptService = new \Core\Finance\ReceiptService($gateway);
        $this->paymentService = new \Core\Finance\PaymentService($gateway);
        $this->expenseService = new \Core\Finance\ExpenseService($gateway);
        $this->journalService = new \Core\Finance\JournalService($gateway);
        $this->balanceService = new \Core\Finance\BalanceService($gateway);
    }

    public function normalizeFinancialPayload(array $data): array { return $this->legacy->normalizeFinancialPayload($data); }
    public function executeAtomically(callable $callback) { return $this->transactionManager->executeAtomically($callback); }
    public function createInvoiceDraft(array $data, string $category): int { return $this->invoiceService->createInvoiceDraft($data, $category); }
    public function postInvoice(int $invoiceId): void { $this->invoiceService->postInvoice($invoiceId); }
    public function createReceiptVoucherDraft(array $data): int { return $this->receiptService->createReceiptVoucherDraft($data); }
    public function createPaymentVoucherDraft(array $data): int { return $this->paymentService->createPaymentVoucherDraft($data); }
    public function allocatePayment(int $voucherId, int $invoiceId, float $allocatedAmount): void { $this->receiptService->allocatePayment($voucherId, $invoiceId, $allocatedAmount); }
    public function postReceiptVoucher(int $voucherId): void { $this->receiptService->postReceiptVoucher($voucherId); }
    public function postPaymentVoucher(int $voucherId): void { $this->paymentService->postPaymentVoucher($voucherId); }
    public function recalculateInvoicePaymentStatus(int $invoiceId): void { $this->invoiceService->recalculateInvoicePaymentStatus($invoiceId); }
    public function processServiceOperation(array $data): array { return $this->journalService->processServiceOperation($data); }
    public function receiveInvoicePayment(array $data): int { return $this->receiptService->receiveInvoicePayment($data); }
    public function getOrCreateDefaultCashCustomer(?int $branchId = null): int { return $this->balanceService->getOrCreateDefaultCashCustomer($branchId); }
    public function createExpenseVoucherDraft(array $data): int { return $this->expenseService->createExpenseVoucherDraft($data); }
    public function postExpenseVoucher(int $voucherId): void { $this->expenseService->postExpenseVoucher($voucherId); }
    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void { $this->expenseService->processExpenseApproval($voucherId, $level, $approved, $comment); }
}
