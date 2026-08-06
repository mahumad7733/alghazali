<?php

namespace Core\Finance;

use Core\Finance\Contracts\FinanceGatewayInterface;

class BalanceService
{
    private FinanceGatewayInterface $gateway;

    public function __construct(FinanceGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function getOrCreateDefaultCashCustomer(?int $branchId = null): int
    {
        return $this->gateway->getOrCreateDefaultCashCustomer($branchId);
    }
}
