<?php

namespace Core\Finance;

use Core\Finance\Contracts\PaymentInterface;

class PaymentService implements PaymentInterface
{
    private \LegacyFinanceService $legacy;

    public function __construct(\LegacyFinanceService $legacy)
    {
        $this->legacy = $legacy;
    }

    public function createPaymentVoucherDraft(array $data): int
    {
        return $this->legacy->createPaymentVoucherDraft($data);
    }

    public function postPaymentVoucher(int $voucherId): void
    {
        $this->legacy->postPaymentVoucher($voucherId);
    }
}
