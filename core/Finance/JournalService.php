<?php

namespace Core\Finance;

class JournalService
{
    private \LegacyFinanceService $legacy;

    public function __construct(\LegacyFinanceService $legacy)
    {
        $this->legacy = $legacy;
    }

    public function processServiceOperation(array $data): array
    {
        return $this->legacy->processServiceOperation($data);
    }
}
