<?php

namespace Core\Finance;


class ExpenseService
{
    private FinanceContext $context;
    private FinancePostingAdapter $postingAdapter;
    public function __construct(FinanceContext $context, FinancePostingAdapter $postingAdapter)
    {
        $this->context = $context;
        $this->postingAdapter = $postingAdapter;
    }

    public function createExpenseVoucherDraft(array $data): int
    {
        $data = $this->context->normalize($data);
        $this->context->assertUserCan('create_expense_voucher', 'create expense voucher');
        $this->context->assertFiscalPeriodOpen($data['voucher_date'] ?: $data['operation_date']);
        if (empty($data['expense_account_id']) || empty($data['account_id']) || (float)$data['paid_amount'] <= 0) {
            throw new \InvalidArgumentException('Expense account, cash account and positive amount are required');
        }
        $this->context->assertAccountUsable((int)$data['account_id'], 'cash/bank');
        $this->context->assertAccountUsable((int)$data['expense_account_id'], 'expense');
        return (int)$this->context->executeAtomically(function () use ($data): int {
            $id = $this->postingAdapter->createExpenseVoucher(
                $this->context->pdo(), $data, $this->context->userId()
            );
            $this->context->audit('create_expense_voucher_draft', 'expense_voucher', $id, [
                'expense_account_id' => $data['expense_account_id'], 'amount' => $data['paid_amount'],
            ]);
            return $id;
        });
    }

    public function postExpenseVoucher(int $voucherId): void
    {
        if ($voucherId <= 0) {
            throw new \InvalidArgumentException('Invalid expense voucher id');
        }
        $this->context->assertUserCan('post_expense_voucher', 'post expense voucher');
        $this->context->executeAtomically(function () use ($voucherId): void {
            $this->postingAdapter->postExpenseVoucher($this->context->pdo(), $voucherId, $this->context->userId());
            $this->context->pdo()->prepare(
                "UPDATE financial_transactions
                    SET posted_ip = COALESCE(NULLIF(posted_ip, ''), ?), updated_ip = ?
                  WHERE reference_type = ? AND reference_id = ?"
            )->execute([$this->context->requestIp(), $this->context->requestIp(), 'expense_voucher', $voucherId]);
            $this->context->audit('post_expense_voucher', 'expense_voucher', $voucherId);
        });
    }

    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void
    {
        if ($voucherId <= 0) {
            throw new \InvalidArgumentException('Invalid expense voucher id');
        }
        $this->context->assertUserCan('approve_expense_voucher', 'approve expense voucher');
        $this->context->executeAtomically(function () use ($voucherId, $level, $approved, $comment): void {
            $this->postingAdapter->processExpenseApproval(
                $this->context->pdo(), $voucherId, $this->context->userId(), $level, $approved, $comment
            );
            $this->context->audit('expense_voucher_approval', 'expense_voucher', $voucherId, [
                'level' => $level, 'approved' => $approved, 'comment' => $comment,
            ]);
        });
    }
}
