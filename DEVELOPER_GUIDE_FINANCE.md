# Finance Developer Guide

## Entry point

Use the backward-compatible facade for application calls:

```php
require_once __DIR__ . '/core/FinanceService.php';
$finance = new FinanceService($pdo, $userId);
$invoiceId = $finance->createInvoiceDraft($data, 'sales');
$finance->postInvoice($invoiceId);
```

Do not instantiate `LegacyFinanceService` from new code. It is retained only as an isolated compatibility and rollback implementation.

## Service ownership

- `InvoiceService`: invoice drafts, posting, and payment-status recalculation.
- `ReceiptService`: receipt drafts, allocations, posting, and invoice-payment flows.
- `PaymentService`: supplier payment drafts and posting.
- `ExpenseService`: expense drafts, posting, and approvals.
- `JournalService`: composed service operations.
- `BalanceService`: cash customer/account resolution.
- `FinanceContext`: normalization, permissions, fiscal periods, account checks, and audit context.

## Adding a financial operation

1. Add the operation to the responsible service under `core/Finance/`.
2. Preserve procedure signatures, transaction boundaries, permission checks, fiscal-period checks, and audit logging.
3. Add or update a contract when the operation is a stable boundary.
4. Delegate it through `FinanceService` without changing existing public signatures.
5. Add an isolated integration test and update the relevant report.
6. Run PHP lint, architecture smoke, facade compatibility, and the integration suite before committing.

## Verification commands

Use the isolated database variables; do not point acceptance tests at production:

```powershell
$env:FINANCE_TEST_DB='alghazali_refactor_test'
$env:FINANCE_TEST_DB_PORT='3307'
& C:\xampp\php\php.exe tools\finance_architecture_smoke.php
& C:\xampp\php\php.exe tools\finance_facade_compatibility_test.php
& C:\xampp\php\php.exe tools\finance_voucher_services_integration_test.php
& C:\xampp\php\php.exe tools\run_integration_test.php
```

## Safety rules

Use a migration for schema changes, keep production secrets out of artifacts, and create a separate commit for each coherent change. Never bypass the facade for ordinary callers.
