# Finance Refactoring - Maintenance Plan

Date: 2026-08-07
Branch: `refactor-finance-service`

## Adding a service

1. Create the service under `core/Finance/`.
2. Add a Contract when the boundary is public and stable.
3. Inject `FinanceContext` and preserve transaction, audit, permission, fiscal-period, currency, and branch rules.
4. Delegate the public operation through `FinanceService` without changing existing signatures.
5. Add isolated integration coverage and update the phase report.

## Modifying a service

1. Create a dedicated branch and verified backup for database work.
2. Run static, smoke, compatibility, and isolated integration tests.
3. Compare journal lines, balances, currencies, statuses, and audit records.
4. Review the diff and commit the coherent change.
5. Keep rollback evidence until acceptance is complete.

## Monitoring

- Review financial and audit logs.
- Monitor failed postings, unbalanced journals, and repeated voucher attempts.
- Review query plans and indexes after measured workload changes.
- Re-run the isolated acceptance suite after every finance deployment.
- Never apply a production migration without backup, approval, and a maintenance window.
