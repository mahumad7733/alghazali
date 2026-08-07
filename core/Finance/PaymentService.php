<?php

namespace Core\Finance;

use Core\Finance\Contracts\PaymentInterface;

class PaymentService implements PaymentInterface
{
    private FinanceContext $context;
    private FinancePostingAdapter $postingAdapter;
    public function __construct(FinanceContext $context, FinancePostingAdapter $postingAdapter)
    {
        $this->context = $context;
        $this->postingAdapter = $postingAdapter;
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
        return (int)$this->context->executeAtomically(function () use ($data, $partyAccount): int {
            $id = $this->postingAdapter->createPaymentVoucher($this->context->pdo(), [
                'branch_id' => $data['branch_id'], 'party_type' => 'supplier',
                'party_id' => $data['supplier_id'], 'amount' => $data['paid_amount'],
                'currency_id' => $data['purchase_currency_id'], 'exchange_rate' => $data['exchange_rate'],
                'cash_account_id' => $data['account_id'], 'party_account_id' => $partyAccount,
                'reference' => $data['source_number'] ?? $data['source_id'],
                'description' => $data['description'],
            ], $this->context->userId());
            $this->context->audit('create_payment_voucher_draft', 'payment_voucher', $id, [
                'supplier_id' => $data['supplier_id'], 'amount' => $data['paid_amount'],
            ]);
            return $id;
        });
    }

    public function postPaymentVoucher(int $voucherId): void
    {
        if ($voucherId <= 0) {
            throw new \InvalidArgumentException('Invalid payment voucher id');
        }
        $this->context->assertUserCan('post_payment_voucher', 'post payment voucher');
        $this->context->executeAtomically(function () use ($voucherId): void {
            $this->postingAdapter->postPaymentVoucher($this->context->pdo(), $voucherId, $this->context->userId());
            $this->context->pdo()->prepare(
                "UPDATE financial_transactions
                    SET posted_ip = COALESCE(NULLIF(posted_ip, ''), ?), updated_ip = ?
                  WHERE id = ?"
            )->execute([$this->context->requestIp(), $this->context->requestIp(), $voucherId]);
            $this->context->audit('post_payment_voucher', 'payment_voucher', $voucherId);
        });
    }
}
