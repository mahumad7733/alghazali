<?php

namespace Core\Finance;

use Core\Finance\Contracts\PaymentInterface;

class PaymentService implements PaymentInterface
{
    private FinanceContext $context;
    public function __construct(FinanceContext $context)
    {
        $this->context = $context;
    }

    public function createPaymentVoucherDraft(array $data): int
    {
        $data = $this->context->normalize($data);
        $this->context->assertUserCan('create_payment_voucher', 'create payment voucher');
        $this->context->assertFiscalPeriodOpen($data['operation_date']);
        $partyAccount = $this->context->resolvePartyAccountId('supplier', $data['supplier_id']);
        if (!$partyAccount || empty($data['account_id']) || (float)$data['paid_amount'] <= 0) {
            throw new \InvalidArgumentException('Payment requires a supplier account, cash account and positive amount');
        }
        $this->context->assertAccountUsable((int)$data['account_id'], 'payment');
        $this->context->assertAccountUsable($partyAccount, 'supplier');
        $stmt = $this->context->pdo()->prepare(
            'CALL sp_create_payment_voucher(?, ?, ?, ?, ?, 1.0, ?, ?, ?, ?, ?, ?, @v_id, @v_num)'
        );
        $stmt->execute([
            $data['branch_id'], 'supplier', $data['supplier_id'], $data['paid_amount'],
            $data['purchase_currency_id'], $data['account_id'], $partyAccount,
            $data['source_number'] ?? $data['source_id'], $data['description'],
            $this->context->userId(), null,
        ]);
        $stmt->closeCursor();
        $id = (int)$this->context->pdo()->query('SELECT @v_id')->fetchColumn();
        $this->context->audit('create_payment_voucher_draft', 'payment_voucher', $id, [
            'supplier_id' => $data['supplier_id'], 'amount' => $data['paid_amount'],
        ]);
        return $id;
    }

    public function postPaymentVoucher(int $voucherId): void
    {
        if ($voucherId <= 0) {
            throw new \InvalidArgumentException('Invalid payment voucher id');
        }
        $this->context->assertUserCan('post_payment_voucher', 'post payment voucher');
        if (!function_exists('php_post_payment_voucher')) {
            throw new \RuntimeException('php_post_payment_voucher is not loaded');
        }
        php_post_payment_voucher($this->context->pdo(), $voucherId, $this->context->userId());
        $this->context->audit('post_payment_voucher', 'payment_voucher', $voucherId);
    }
}
