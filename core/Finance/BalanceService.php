<?php

namespace Core\Finance;

class BalanceService
{
    private \LegacyFinanceService $legacy;

    public function __construct(\LegacyFinanceService $legacy)
    {
        $this->legacy = $legacy;
    }

    public function getOrCreateDefaultCashCustomer(?int $branchId = null): int
    {
        return $this->legacy->getOrCreateDefaultCashCustomer($branchId);
    }
}
