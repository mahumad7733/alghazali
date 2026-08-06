<?php

namespace Core\Finance;

class ExpenseService
{
    private \LegacyFinanceService $legacy;

    public function __construct(\LegacyFinanceService $legacy)
    {
        $this->legacy = $legacy;
    }

    public function createExpenseVoucherDraft(array $data): int
    {
        return $this->legacy->createExpenseVoucherDraft($data);
    }

    public function postExpenseVoucher(int $voucherId): void
    {
        $this->legacy->postExpenseVoucher($voucherId);
    }

    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void
    {
        $this->legacy->processExpenseApproval($voucherId, $level, $approved, $comment);
    }
}
