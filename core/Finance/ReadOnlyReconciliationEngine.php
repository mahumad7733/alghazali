<?php
declare(strict_types=1);

/**
 * Read-only accounting reconciliation engine.
 *
 * This class intentionally contains SELECT-only statements. It must not be
 * used as a posting, repair, migration, or balance mutation service.
 */
final class ReadOnlyReconciliationEngine
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function run(): array
    {
        return [
            'account_balance_reconciliation' => $this->accountBalances(),
            'invoice_reconciliation' => $this->invoices(),
            'customer_balance_reconciliation' => $this->partyBalances('customer'),
            'supplier_balance_reconciliation' => $this->partyBalances('supplier'),
            'cashbox_reconciliation' => $this->cashbox(),
            'bank_reconciliation' => $this->bank(),
            'currency_reconciliation' => $this->currencies(),
            'branch_reconciliation' => $this->branches(),
            'fiscal_period_reconciliation' => $this->fiscalPeriods(),
            'reversal_reconciliation' => $this->reversals(),
            'payment_allocation_reconciliation' => $this->allocations(),
            'audit_trail_reconciliation' => $this->auditTrail(),
        ];
    }

    private function select(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function accountBalances(): array
    {
        return $this->select("SELECT ab.account_id, ua.account_name_ar, ab.currency_id, ab.currency_code,
            ab.branch_id, ab.current_balance stored_balance,
            COALESCE(ab.opening_balance,0) + COALESCE(SUM(CASE WHEN ft.status='posted'
                THEN CASE WHEN ua.normal_balance='credit' THEN jl.credit-jl.debit ELSE jl.debit-jl.credit END ELSE 0 END),0) calculated_balance,
            ab.current_balance - (COALESCE(ab.opening_balance,0) + COALESCE(SUM(CASE WHEN ft.status='posted'
                THEN CASE WHEN ua.normal_balance='credit' THEN jl.credit-jl.debit ELSE jl.debit-jl.credit END ELSE 0 END),0)) difference
            FROM account_balances_unified ab
            JOIN unified_accounts ua ON ua.id=ab.account_id
            LEFT JOIN journal_lines jl ON jl.account_id=ab.account_id AND jl.currency_id=ab.currency_id
            LEFT JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id
            GROUP BY ab.id,ab.account_id,ua.account_name_ar,ua.normal_balance,ab.currency_id,ab.currency_code,ab.branch_id,ab.current_balance,ab.opening_balance
            ORDER BY ABS(difference) DESC");
    }

    private function invoices(): array
    {
        return $this->select("SELECT i.id,i.invoice_number,i.invoice_status,i.total_amount,i.net_amount,
            COUNT(DISTINCT ft.id) financial_transactions_count,
            COUNT(DISTINCT jl.id) journal_lines_count
            FROM invoices i
            LEFT JOIN financial_transactions ft ON ft.reference_type='invoice' AND ft.reference_id=i.id
            LEFT JOIN journal_lines jl ON jl.financial_transaction_id=ft.id
            GROUP BY i.id,i.invoice_number,i.invoice_status,i.total_amount,i.net_amount
            ORDER BY i.id");
    }

    private function partyBalances(string $party): array
    {
        $column = $party === 'customer' ? 'customer_id' : 'supplier_id';
        $category = $party === 'customer' ? 'sales' : 'purchase';
        return $this->select("SELECT i.$column party_id,i.currency_id,
            SUM(i.net_amount) invoice_total,
            COALESCE(SUM(a.allocated_total),0) allocated_total,
            SUM(i.net_amount)-COALESCE(SUM(a.allocated_total),0) difference
            FROM invoices i
            LEFT JOIN (
                SELECT pa.invoice_id,SUM(pa.allocated_amount) allocated_total
                FROM payment_allocations pa
                JOIN financial_transactions ft ON ft.id=pa.financial_transaction_id AND ft.status='posted'
                GROUP BY pa.invoice_id
            ) a ON a.invoice_id=i.id
            WHERE i.$column IS NOT NULL AND i.invoice_category=?
            GROUP BY i.$column,i.currency_id ORDER BY ABS(difference) DESC", [$category]);
    }

    private function cashbox(): array
    {
        return $this->select("SELECT ft.cash_bank_account_id account_id,ft.currency_id,
            SUM(CASE WHEN ft.status='posted' AND ft.transaction_type='receipt' THEN ft.amount
                     WHEN ft.status='posted' AND ft.transaction_type='payment' THEN -ft.amount ELSE 0 END) movement
            FROM financial_transactions ft JOIN unified_accounts ua ON ua.id=ft.cash_bank_account_id
            WHERE ua.account_type='asset' GROUP BY ft.cash_bank_account_id,ft.currency_id");
    }

    private function bank(): array
    {
        return $this->select("SELECT ft.cash_bank_account_id account_id,ft.currency_id,
            SUM(CASE WHEN ft.status='posted' AND ft.transaction_type='receipt' THEN ft.amount
                     WHEN ft.status='posted' AND ft.transaction_type='payment' THEN -ft.amount ELSE 0 END) movement
            FROM financial_transactions ft JOIN unified_accounts ua ON ua.id=ft.cash_bank_account_id
            WHERE LOWER(COALESCE(ua.account_name_ar,'')) LIKE '%بنك%' OR LOWER(COALESCE(ua.account_name_ar,'')) LIKE '%bank%'
            GROUP BY ft.cash_bank_account_id,ft.currency_id");
    }

    private function currencies(): array
    {
        return $this->select("SELECT jl.currency_id,c.currency_code,
            SUM(jl.debit) debit,SUM(jl.credit) credit,
            SUM((jl.debit-jl.credit)*COALESCE(ft.exchange_rate,1)) calculated_base
            FROM journal_lines jl JOIN financial_transactions ft ON ft.id=jl.financial_transaction_id AND ft.status='posted'
            LEFT JOIN currencies c ON c.id=jl.currency_id GROUP BY jl.currency_id,c.currency_code");
    }

    private function branches(): array
    {
        return $this->select("SELECT ft.branch_id,COUNT(*) transactions_count,SUM(CASE WHEN ft.status='posted' THEN ft.amount ELSE 0 END) posted_amount
            FROM financial_transactions ft GROUP BY ft.branch_id ORDER BY ft.branch_id IS NULL DESC,ft.branch_id");
    }

    private function fiscalPeriods(): array
    {
        return $this->select("SELECT fp.id,fp.period_name,fp.start_date,fp.end_date,fp.status,
            COUNT(ft.id) transaction_count
            FROM fiscal_periods fp LEFT JOIN financial_transactions ft ON ft.transaction_date BETWEEN fp.start_date AND fp.end_date
            GROUP BY fp.id,fp.period_name,fp.start_date,fp.end_date,fp.status ORDER BY fp.start_date");
    }

    private function reversals(): array
    {
        return $this->select("SELECT o.id original_id,o.transaction_number original_number,o.status original_status,
            r.id reversal_id,r.transaction_number reversal_number,r.status reversal_status,
            COALESCE(ol.debit,0) original_debit,COALESCE(ol.credit,0) original_credit,
            COALESCE(rl.debit,0) reversal_debit,COALESCE(rl.credit,0) reversal_credit,
            o.posted_at original_posted_at,r.posted_at reversal_posted_at
            FROM financial_transactions o LEFT JOIN financial_transactions r ON r.id=o.reversal_voucher_id
            LEFT JOIN (SELECT financial_transaction_id,SUM(debit) debit,SUM(credit) credit FROM journal_lines GROUP BY financial_transaction_id) ol ON ol.financial_transaction_id=o.id
            LEFT JOIN (SELECT financial_transaction_id,SUM(debit) debit,SUM(credit) credit FROM journal_lines GROUP BY financial_transaction_id) rl ON rl.financial_transaction_id=r.id
            WHERE o.reversal_voucher_id IS NOT NULL");
    }

    private function allocations(): array
    {
        return $this->select("SELECT pa.id,pa.financial_transaction_id,pa.invoice_id,pa.allocated_amount,
            ft.status transaction_status,i.invoice_status,
            CASE WHEN ft.id IS NULL OR i.id IS NULL THEN 'ORPHAN' ELSE 'LINKED' END linkage
            FROM payment_allocations pa LEFT JOIN financial_transactions ft ON ft.id=pa.financial_transaction_id
            LEFT JOIN invoices i ON i.id=pa.invoice_id ORDER BY pa.id");
    }

    private function auditTrail(): array
    {
        return $this->select("SELECT ft.id,ft.transaction_number,ft.status,ft.created_at,
            COUNT(al.id) direct_audit_count
            FROM financial_transactions ft LEFT JOIN audit_logs al ON al.table_name='financial_transactions' AND al.record_id=ft.id
            GROUP BY ft.id,ft.transaction_number,ft.status,ft.created_at ORDER BY direct_audit_count,ft.id");
    }
}
