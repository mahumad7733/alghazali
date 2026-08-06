<?php

namespace Core\Finance;

use Core\Finance\Contracts\PaymentInterface;
use Core\Finance\Contracts\FinanceGatewayInterface;

class PaymentService implements PaymentInterface
{
    private FinanceGatewayInterface $gateway;

    public function __construct(FinanceGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function createPaymentVoucherDraft(array $data): int
    {
        return $this->gateway->createPaymentVoucherDraft($data);
    }

    public function postPaymentVoucher(int $voucherId): void
    {
        $this->gateway->postPaymentVoucher($voucherId);
    }
}
