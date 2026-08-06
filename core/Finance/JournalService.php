<?php

namespace Core\Finance;

use Core\Finance\Contracts\FinanceGatewayInterface;

class JournalService
{
    private FinanceGatewayInterface $gateway;

    public function __construct(FinanceGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function processServiceOperation(array $data): array
    {
        return $this->gateway->processServiceOperation($data);
    }
}
