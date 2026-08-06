Exit code: 0
Wall time: 1.7 seconds
Output:
# Refactoring Issues

## Resolved migration slice - 2026-08-06

The former gradual-delegation issue is resolved for the public `FinanceService` operations. Invoice, receipt, payment, expense, service-operation, and cash-customer flows are implemented in `core/Finance` and verified on the isolated database. The legacy adapter files remain intentionally for backward compatibility.

## ISSUE-001: MySQL ط؛ظٹط± ظ…طھط§ط­ ط£ط«ظ†ط§ط، ط§ظ„طھط­ظ‚ظ‚

- ط§ظ„ظˆطµظپ: ط§ط®طھط¨ط§ط± `tools/test_signature_compat.php` طھظˆظ‚ظپ ط¨ط³ط¨ط¨ ط±ظپط¶ ط§ظ„ط§طھطµط§ظ„ ط¨ظ€ `127.0.0.1:3306`.
- ط§ظ„ط³ط¨ط¨: ط®ط¯ظ…ط© MySQL ط§ظ„ظ…ط­ظ„ظٹط© ط؛ظٹط± ظ…طھط§ط­ط© ظˆظ‚طھ ط§ظ„طھط­ظ‚ظ‚.
- ط§ظ„ط£ط«ط±: طھط¹ط°ط± ط¥ط«ط¨ط§طھ ط¹ظ…ظ„ظٹط§طھ Stored Procedures ظˆط§ظ„طھظƒط§ظ…ظ„ ط§ظ„ظ…ط§ظ„ظٹ ط¹ظ„ظ‰ ظ‚ط§ط¹ط¯ط© ط§ط®طھط¨ط§ط±.
- ط§ظ„ط­ظ„: طھط´ط؛ظٹظ„ MySQLطŒ ط£ط®ط° ظ†ط³ط®ط© ط§ط®طھط¨ط§ط±طŒ ط«ظ… طھط´ط؛ظٹظ„ ط§ط®طھط¨ط§ط±ط§طھ ط§ظ„طھظƒط§ظ…ظ„ ظ‚ط¨ظ„ ط§ط¹طھظ…ط§ط¯ ط§ظ„ظ…ط±ط§ط­ظ„ ط§ظ„ظ†ظ‡ط§ط¦ظٹط©.
- ط§ظ„ط­ط§ظ„ط©: ظ…ظپطھظˆط­.

## ISSUE-003: طھط¹ط§ط±ط¶ Collation ط¯ط§ط®ظ„ Stored Procedure

- ط§ظ„ظˆطµظپ: `tools/run_integration_test.php` ظٹظپط´ظ„ ط¹ظ†ط¯ `sp_create_invoice` ط¨ط®ط·ط£ `Illegal mix of collations` ط¨ظٹظ† `utf8mb4_unicode_ci` ظˆ`utf8mb4_general_ci`.
- ط§ظ„طھط­ظ‚ظ‚: طھظ… طھط´ط؛ظٹظ„ MySQL ط¹ظ„ظ‰ ط§ظ„ظ…ظ†ظپط° 3307 ط¨ط§ط³طھط®ط¯ط§ظ… ظ‚ط§ط¹ط¯ط© `alghazali` ظ…ظ† `.env`طŒ ظˆط¥ط¹ط§ط¯ط© ط§ظ„ظ…ط­ط§ظˆظ„ط© ط¨ط¹ط¯ ط¶ط¨ط· Collation ظ„ط¬ظ„ط³ط© ط§ظ„ط§ط®طھط¨ط§ط±طŒ ظˆط§ط³طھظ…ط± ط§ظ„ط®ط·ط£.
- ط§ظ„ط£ط«ط±: ظ„ط§ ظٹظ…ظƒظ† ط§ط¹طھظ…ط§ط¯ ط§ظ„طھظƒط§ظ…ظ„ ط§ظ„ظ…ط§ظ„ظٹ ط£ظˆ ظ…ظ‚ط§ط±ظ†ط© ط§ظ„ط£ط±طµط¯ط© ظ‚ط¨ظ„ ظ…ط¹ط§ظ„ط¬ط© Collation ظپظٹ ط¨ظٹط¦ط© ط§ط®طھط¨ط§ط±.
- ط§ظ„ط­ظ„ ط§ظ„ط¢ظ…ظ†: طھط¯ظ‚ظٹظ‚ Collation ظ„ظ„ط¥ط¬ط±ط§ط،ط§طھ ظˆط§ظ„ط¬ط¯ط§ظˆظ„ ط«ظ… ط¥ط¹ط¯ط§ط¯ Migration/ط®ط·ط© طھطµط­ظٹط­ ظ…ظ†ظپطµظ„ط© ظ…ط¹ Backup ظˆRollbackط› ظ…ظ…ظ†ظˆط¹ ط§ظ„ط¥طµظ„ط§ط­ ط§ظ„ظ…ط¨ط§ط´ط± ط¹ظ„ظ‰ ط§ظ„ط¥ظ†طھط§ط¬ ط£ط«ظ†ط§ط، ط¥ط¹ط§ط¯ط© ط§ظ„ظ‡ظٹظƒظ„ط©.
- ط§ظ„ط­ط§ظ„ط©: ظ…ظپطھظˆط­طŒ ظˆظٹظ…ظ†ط¹ ط§ط¹طھظ…ط§ط¯ ط§ظ„ط§ط®طھط¨ط§ط±ط§طھ ط§ظ„ظ…ط§ظ„ظٹط© ط§ظ„ظ†ظ‡ط§ط¦ظٹط©.

## ISSUE-004: ط¹ط¯ظ… طھط·ط§ط¨ظ‚ ظ…ط®ط·ط· ط§ظ„ظ‚ظٹظˆط¯ ظ…ط¹ ط§ظ„ط¥ط¬ط±ط§ط،ط§طھ ط§ظ„ظ…ط®ط²ظ†ط©

- ط§ظ„ظˆطµظپ: ط¨ط¹ط¶ ط§ظ„ط¥ط¬ط±ط§ط،ط§طھ طھط³طھط®ط¯ظ… `invoices.exchange_rate` ظˆط­ظ‚ظˆظ„ظ‹ط§ ظپظٹ `journal_lines` ط؛ظٹط± ظ…ظˆط¬ظˆط¯ط© ظپظٹ ظ…ط®ط·ط· ظ‚ط§ط¹ط¯ط© ط§ظ„ط¹ظ…ظ„.
- ط§ظ„طھط­ظ‚ظ‚: ط¸ظ‡ط± ط°ظ„ظƒ ظپظٹ `sp_post_invoice` ط£ط«ظ†ط§ط، ط§ط®طھط¨ط§ط± ط§ظ„طھظƒط§ظ…ظ„.
- ط§ظ„ط¥ط¬ط±ط§ط،: ط£ط¶ظٹظپطھ Migration ظ…ظˆط«ظ‚ط© ظپظٹ `database/migrations/2026_08_06_001_finance_schema_compatibility.sql`طŒ ظˆظ„ظ… طھظڈط·ط¨ظ‚ ط¹ظ„ظ‰ ظ‚ط§ط¹ط¯ط© ط§ظ„ط¹ظ…ظ„.
- ط§ظ„ط­ط§ظ„ط©: ط¬ط§ظ‡ط² ظ„ظ„ظ…ط±ط§ط¬ط¹ط© ظˆط§ظ„طھط·ط¨ظٹظ‚ ط¹ظ„ظ‰ ظ†ط³ط®ط© ط§ط®طھط¨ط§ط± ط£ظˆظ„ظ‹ط§.

## ISSUE-002: ظ†ظ‚ظ„ ط§ظ„ظ…ظ†ط·ظ‚ ط§ظ„ظ…ط§ظ„ظٹ طھط¯ط±ظٹط¬ظٹظ‹ط§

- ط§ظ„ظˆطµظپ: ط§ظ„ط®ط¯ظ…ط§طھ ط§ظ„ط¬ط¯ظٹط¯ط© طھط¹ظ…ظ„ ظƒط·ط¨ظ‚ط© طھظپظˆظٹط¶ ط¥ظ„ظ‰ `LegacyFinanceService` ظ„ظ„ط­ظپط§ط¸ ط¹ظ„ظ‰ ط§ظ„طھظˆط§ظپظ‚.
- ط§ظ„ط³ط¨ط¨: ظ…ظ†ط¹ ظ†ظ‚ظ„ ظ…ظ†ط·ظ‚ ظ…ط§ظ„ظٹ ط­ط³ط§ط³ ط¯ظپط¹ط© ظˆط§ط­ط¯ط© ظ‚ط¨ظ„ ط§ظƒطھظ…ط§ظ„ ط§ط®طھط¨ط§ط±ط§طھ ط§ظ„طھظƒط§ظ…ظ„.
- ط§ظ„ط£ط«ط±: ط§ظ„ظ…ط±ط­ظ„ط© ط§ظ„ظ…ط¹ظ…ط§ط±ظٹط© ظ…ظƒطھظ…ظ„ط© ظˆط¸ظٹظپظٹظ‹ط§طŒ ظ„ظƒظ† ط§ظ„ظ†ظ‚ظ„ ط§ظ„ط¯ط§ط®ظ„ظٹ ط§ظ„ظƒط§ظ…ظ„ ظ„ظ„ظ…ظ†ط·ظ‚ ظ„ظ… ظٹظڈط¹طھظ…ط¯ ط¨ط¹ط¯.
- ط§ظ„ط­ظ„: ظ†ظ‚ظ„ ظƒظ„ ظ…ط¬ظ…ظˆط¹ط© ط¹ظ…ظ„ظٹط§طھ ط¥ظ„ظ‰ ط®ط¯ظ…طھظ‡ط§ ظ…ط¹ ط§ط®طھط¨ط§ط± ظ…ظ‚ط§ط±ظ†ط© ظ‚ط¨ظ„/ط¨ط¹ط¯ ظˆCommit ظ…ط³طھظ‚ظ„.
- ط§ظ„ط­ط§ظ„ط©: ظ…ظپطھظˆط­.



## Current verification status - 2026-08-06

- ISSUE-001: Resolved for the current environment; MariaDB is verified on port 3307 and signature/integration tests pass.
- ISSUE-002: Resolved for the public facade surface; migrated services no longer call the legacy gateway.
- ISSUE-003: Resolved for isolated acceptance; test sessions use explicit UTF-8 collation and the broad integration test passes 29/29. A production-wide collation migration remains a separately reviewed database deployment.
- ISSUE-004: The compatibility migration is now idempotent and passes `tools/database/verify_finance_schema_migration.php` on `alghazali_refactor_test`. It remains unapplied to production pending backup and deployment approval.

