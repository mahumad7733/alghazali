# Finance Support Guide

Verified on 2026-08-07. Use the isolated database diagnostics before any production action.

## First response to a finance error

1. Stop the affected deployment or repeated operation; do not retry a posting blindly.
2. Record the exact message, timestamp, user, branch, voucher/invoice number, and operation.
3. Check whether the document is draft, posted, cancelled, or pending approval.
4. Compare the document, financial transaction, journal lines, account balances, and audit records.
5. Review `REFACTORING_ISSUES.md` and application logs before proposing a data correction.

## Safe verification

Run diagnostics against an isolated database first:

```powershell
& C:\xampp\php\php.exe tools\finance_architecture_smoke.php
& C:\xampp\php\php.exe tools\finance_facade_compatibility_test.php
& C:\xampp\php\php.exe tools\finance_voucher_services_integration_test.php
```

The broad isolated acceptance suite currently covers invoice, receipt, allocation, payment status, currency behavior, audit records, and posting safety.

## Common checks

- A closed fiscal period must reject new or posted operations.
- An inactive, deleted, or incompatible account must be rejected.
- A posted voucher must have balanced journal lines.
- A payment must reference the correct supplier account and currency.
- An expense posting creates a financial transaction linked by `reference_type = 'expense_voucher'` and `reference_id`.
- Posting IP fields may be populated by the service layer when a stored procedure leaves them blank.

## Database changes and rollback

Do not run ad-hoc corrective SQL on production. Use the reviewed migration, take a verified backup, obtain deployment approval, and execute during a maintenance window. If a release causes a financial invariant failure, stop the release and use the documented Git rollback path; preserve logs and evidence before cleanup.
