<?php

namespace Core\Finance;


class ExpenseService
{
    private FinanceContext $context;
    public function __construct(FinanceContext $context)
    {
        $this->context = $context;
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
        $stmt = $this->context->pdo()->prepare(
            'CALL sp_create_expense_voucher(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, @v_id, @v_num)'
        );
        $stmt->execute([
            $data['branch_id'], $data['expense_account_id'], $data['account_id'],
            $data['paid_amount'], $data['currency_id'], (float)($data['equivalent_amount'] ?? 0),
            $data['voucher_date'] ?: date('Y-m-d'), $data['description'] ?: null,
            $data['reference_number'] ?: null, $data['cost_center_id'] ?: null,
            $data['supplier_id'] ?: null, $data['budget_id'] ?: null, $this->context->userId(),
        ]);
        $stmt->closeCursor();
        $id = (int)$this->context->pdo()->query('SELECT @v_id')->fetchColumn();
        $this->context->audit('create_expense_voucher_draft', 'expense_voucher', $id, [
            'expense_account_id' => $data['expense_account_id'], 'amount' => $data['paid_amount'],
        ]);
        return $id;
    }

    public function postExpenseVoucher(int $voucherId): void
    {
        if ($voucherId <= 0) {
            throw new \InvalidArgumentException('Invalid expense voucher id');
        }
        $this->context->assertUserCan('post_expense_voucher', 'post expense voucher');
        $stmt = $this->context->pdo()->prepare('CALL sp_post_expense_voucher(?, ?)');
        $stmt->execute([$voucherId, $this->context->userId()]);
        $stmt->closeCursor();
        $this->context->audit('post_expense_voucher', 'expense_voucher', $voucherId);
    }

    public function processExpenseApproval(int $voucherId, int $level, bool $approved, ?string $comment = null): void
    {
        if ($voucherId <= 0) {
            throw new \InvalidArgumentException('Invalid expense voucher id');
        }
        $this->context->assertUserCan('approve_expense_voucher', 'approve expense voucher');
        $stmt = $this->context->pdo()->prepare('CALL sp_process_expense_approval(?, ?, ?, ?, ?)');
        $stmt->execute([$voucherId, $this->context->userId(), $level, $approved ? 1 : 0, $comment]);
        $stmt->closeCursor();
        $this->context->audit('expense_voucher_approval', 'expense_voucher', $voucherId, [
            'level' => $level, 'approved' => $approved, 'comment' => $comment,
        ]);
    }
}
