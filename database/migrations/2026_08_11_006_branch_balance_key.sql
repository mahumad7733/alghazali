ALTER TABLE account_balances_unified
    DROP INDEX uq_account_currency_global;

CALL sp_rebuild_balances();
