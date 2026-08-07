<?php

namespace Core\Finance\Contracts;

interface PaymentInterface
{
    public function createPaymentVoucherDraft(array $data): int;
    public function postPaymentVoucher(int $voucherId): void;
}
