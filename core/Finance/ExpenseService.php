<?php

namespace Core\Finance;

use Core\Finance\Contracts\FinanceGatewayInterface;

class ExpenseService
{
    private FinanceGatewayInterface $gateway;

    public function __construct(FinanceGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function createExpenseVoucherDraft(array $data): int
    {
        return $this->gateway->createExpenseVoucherDraft($data);
    }

    public function postExpenseVoucher(int $voucherId): void
    {
        $this->gateway->postExpenseVoucher($voucherId);
    }

    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void
    {
        $this->gateway->processExpenseApproval($voucherId, $level, $approved, $comment);
    }
}
