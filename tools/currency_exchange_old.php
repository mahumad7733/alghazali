<?php
// currency_exchange.php - دوال تصريف العملات

class CurrencyExchange
{
    private $conn;

    public function __construct($connection)
    {
        $this->conn = $connection;
    }

    /**
     * تحويل مبلغ من عملة إلى أخرى
     */
    public function convert($amount, $from_currency_id, $to_currency_id)
    {
        $sql = "SELECT fn_convert_currency(?, ?, ?) as converted";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$amount, $from_currency_id, $to_currency_id]);
        return $stmt->fetchColumn();
    }

    /**
     * تحويل المبلغ للعملة الأساسية
     */
    public function convertToBase($amount, $currency_id)
    {
        $sql = "SELECT fn_convert_to_base_currency(?, ?) as converted";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$amount, $currency_id]);
        return $stmt->fetchColumn();
    }

    /**
     * جلب سعر الصرف الحالي
     */
    public function getExchangeRate($currency_id, $type = 'sell')
    {
        $sql = "SELECT fn_get_exchange_rate(?, ?) as rate";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$currency_id, $type]);
        return $stmt->fetchColumn();
    }

    /**
     * تنفيذ عملية تصريف عملات
     */
    public function executeExchange($data)
    {
        $sql = "CALL sp_currency_exchange(
            :branch_id,
            :from_currency_id,
            :from_amount,
            :to_currency_id,
            :to_amount,
            :exchange_rate,
            :from_account_id,
            :to_account_id,
            :notes,
            :created_by,
            @transaction_id,
            @transaction_number,
            @profit_loss
        )";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([
            'branch_id' => $data['branch_id'],
            'from_currency_id' => $data['from_currency_id'],
            'from_amount' => $data['from_amount'],
            'to_currency_id' => $data['to_currency_id'],
            'to_amount' => $data['to_amount'],
            'exchange_rate' => $data['exchange_rate'],
            'from_account_id' => $data['from_account_id'],
            'to_account_id' => $data['to_account_id'],
            'notes' => $data['notes'] ?? '',
            'created_by' => $data['created_by']
        ]);

        // استرجاع النتائج
        $result = $this->conn->query("
            SELECT @transaction_id as id,
                   @transaction_number as number,
                   @profit_loss as profit_loss
        ")->fetch();

        return $result;
    }

    /**
     * جلب جميع العملات النشطة
     */
    public function getAllCurrencies()
    {
        $sql = "SELECT * FROM currencies WHERE is_active = 1 ORDER BY is_default DESC, currency_name";
        return $this->conn->query($sql)->fetchAll();
    }

    /**
     * جلب العملة الأساسية
     */
    public function getBaseCurrency()
    {
        $sql = "SELECT * FROM currencies WHERE is_default = 1 LIMIT 1";
        return $this->conn->query($sql)->fetch();
    }

    /**
     * جلب صناديق/بنوك متاحة لعملة معينة
     */
    public function getAccountsByCurrency($currency_id)
    {
        $sql = "SELECT ua.*
                FROM unified_accounts ua
                WHERE (ua.account_code LIKE '101%' OR ua.account_code LIKE '102%')
                ORDER BY ua.account_code";
        return $this->conn->query($sql)->fetchAll();
    }

    /**
     * جلب سجل تصريف العملات
     */
    public function getExchangeHistory($limit = 50)
    {
        $sql = "SELECT
                    cet.*,
                    fc.currency_code as from_currency_code,
                    fc.currency_symbol as from_currency_symbol,
                    tc.currency_code as to_currency_code,
                    tc.currency_symbol as to_currency_symbol,
                    u.full_name as created_by_name
                FROM currency_exchange_transactions cet
                JOIN currencies fc ON cet.from_currency_id = fc.id
                JOIN currencies tc ON cet.to_currency_id = tc.id
                LEFT JOIN users u ON cet.created_by = u.id
                ORDER BY cet.created_at DESC
                LIMIT ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    /**
     * التحقق من صحة بيانات عملية التصريف
     */
    public function validateExchangeData($data)
    {
        $errors = [];

        if (empty($data['branch_id'])) {
            $errors[] = "يجب تحديد الفرع";
        }

        if (empty($data['from_currency_id']) || empty($data['to_currency_id'])) {
            $errors[] = "يجب تحديد العملات المطلوبة";
        }

        if ($data['from_currency_id'] == $data['to_currency_id']) {
            $errors[] = "لا يمكن تصريف العملة لنفسها";
        }

        if (empty($data['from_amount']) || $data['from_amount'] <= 0) {
            $errors[] = "يجب إدخال مبلغ صحيح من العملة الأولى";
        }

        if (empty($data['to_amount']) || $data['to_amount'] <= 0) {
            $errors[] = "يجب إدخال مبلغ صحيح من العملة الثانية";
        }

        if (empty($data['exchange_rate']) || $data['exchange_rate'] <= 0) {
            $errors[] = "يجب إدخال سعر صرف صحيح";
        }

        if (empty($data['from_account_id']) || empty($data['to_account_id'])) {
            $errors[] = "يجب تحديد الحسابات المطلوبة";
        }

        if ($data['from_account_id'] == $data['to_account_id']) {
            $errors[] = "لا يمكن استخدام نفس الحساب للعمليتين";
        }

        return $errors;
    }

    /**
     * حساب المبلغ المحول بناءً على سعر الصرف
     */
    public function calculateConvertedAmount($amount, $exchange_rate, $direction = 'to')
    {
        if ($direction === 'to') {
            return $amount * $exchange_rate;
        } else {
            return $amount / $exchange_rate;
        }
    }

    /**
     * حساب الربح/الخسارة من عملية التصريف
     */
    public function calculateProfitLoss($from_amount, $to_amount, $exchange_rate)
    {
        $expected_to_amount = $from_amount * $exchange_rate;
        return $to_amount - $expected_to_amount;
    }

    /**
     * جلب إحصائيات تصريف العملات
     */
    public function getExchangeStatistics($date_from = null, $date_to = null)
    {
        $where = "";
        $params = [];

        if ($date_from) {
            $where .= " AND DATE(cet.created_at) >= ?";
            $params[] = $date_from;
        }

        if ($date_to) {
            $where .= " AND DATE(cet.created_at) <= ?";
            $params[] = $date_to;
        }

        $sql = "SELECT
                    COUNT(*) as total_exchanges,
                    SUM(from_amount) as total_from_amount,
                    SUM(to_amount) as total_to_amount,
                    SUM(profit_loss) as total_profit_loss,
                    AVG(exchange_rate) as avg_exchange_rate
                FROM currency_exchange_transactions cet
                WHERE 1=1 $where";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * جلب تفاصيل عملية تصريف محددة
     */
    public function getExchangeDetails($transaction_id)
    {
        $sql = "SELECT
                    cet.*,
                    fc.currency_name as from_currency_name,
                    fc.currency_code as from_currency_code,
                    fc.currency_symbol as from_currency_symbol,
                    tc.currency_name as to_currency_name,
                    tc.currency_code as to_currency_code,
                    tc.currency_symbol as to_currency_symbol,
                    fa.account_name_ar as from_account_name,
                    ta.account_name_ar as to_account_name,
                    u.full_name as created_by_name,
                    b.branch_name
                FROM currency_exchange_transactions cet
                JOIN currencies fc ON cet.from_currency_id = fc.id
                JOIN currencies tc ON cet.to_currency_id = tc.id
                JOIN unified_accounts fa ON cet.from_account_id = fa.id
                JOIN unified_accounts ta ON cet.to_account_id = ta.id
                LEFT JOIN users u ON cet.created_by = u.id
                LEFT JOIN branches b ON cet.branch_id = b.id
                WHERE cet.id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$transaction_id]);
        return $stmt->fetch();
    }

    /**
     * إلغاء عملية تصريف
     */
    public function cancelExchange($transaction_id, $cancelled_by)
    {
        try {
            $this->conn->beginTransaction();

            // جلب تفاصيل العملية
            $exchange = $this->getExchangeDetails($transaction_id);
            if (!$exchange) {
                throw new Exception("عملية التصريف غير موجودة");
            }

            if ($exchange['status'] !== 'active') {
                throw new Exception("لا يمكن إلغاء عملية تم إلغاؤها مسبقاً");
            }

            // إلغاء العملية
            $sql = "UPDATE currency_exchange_transactions
                    SET status = 'cancelled',
                        cancelled_at = NOW(),
                        cancelled_by = ?
                    WHERE id = ?";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([$cancelled_by, $transaction_id]);

            // عكس القيود المحاسبية إذا كانت مرحلة
            if ($exchange['posted']) {
                // يمكن إضافة منطق عكس القيود هنا
                // للآن سنترك تعليق فقط
            }

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }
}
