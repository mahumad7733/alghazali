# Finance Production Readiness Report

Date: 2026-08-07
Branch: `refactor-finance-service`
Decision: **NO-GO**

## Executive decision

The isolated refactoring tests pass, but production deployment is not approved. The blockers below can affect permission enforcement, fiscal-period protection, audit completeness, and the requirement that all production financial calls pass through the Facade.

No production database, migration, Stored Procedure, Trigger, or financial record was changed.

## Completed review scope

- Reviewed `core/FinanceService.php`, all `core/Finance` Services, Contracts, Context, Transaction Manager, and Audit Logger.
- Searched application paths under `admin`, `api`, `core`, and `includes` for Legacy and direct financial calls.
- Ran static, smoke, compatibility, acceptance, integration, schema-verification, and read-only `EXPLAIN` checks against `alghazali_refactor_test`.
- Reviewed Git state and rollback evidence.

## Test results

All requested isolated checks passed:

- Facade caller acceptance: PASS.
- Phase 6 service migration acceptance: PASS.
- Public compatibility and Phase 5 acceptance: PASS.
- Architecture smoke: PASS.
- Facade, service-operation, and payment/expense integration: PASS.
- Schema migration verification: PASS on `alghazali_refactor_test`.
- Broad accounting integration: 29/29, 100%.
- PHP lint for Finance code and readiness tools: 0 failures.
- `git diff --check`: PASS.

These results prove isolated behavior only; they do not prove production readiness.

## Findings blocking Go

### P0 - Permission checks can fail open

`core/Finance/FinanceContext.php:88` catches every `Throwable` and rethrows only errors whose message contains `permission`. A permission-provider/database/runtime failure with another message can be swallowed and the financial operation can continue.

Required fix: permission-provider failures must fail closed and be converted to a finance security exception; only explicitly handled compatibility cases may be ignored.

### P0 - Fiscal-period checks can fail open

`core/Finance/FinanceContext.php:128` swallows database and query failures unless the message contains `closed`. If `fiscal_periods` cannot be checked, posting may continue without proving that the period is open.

Required fix: missing period, query failure, or unavailable fiscal-period service must reject the operation. Add an isolated regression test for database failure and closed periods.

### P0 - Audit failures are silent

`core/Finance/AuditLogger.php:15` catches and logs failures at `:36` without failing or rolling back the financial operation. This violates the requirement for reliable Audit Logging when the audit record is mandatory evidence.

Required fix: define audit as transactional for posting and voucher operations; an audit insert failure must abort the operation, or document and test an approved durable outbox design.

### P0 - Production still uses old financial paths

The new services still call legacy helper functions from `includes/accounting_functions.php`, including:

- `core/Finance/InvoiceService.php:87-90` calls `php_post_invoice`.
- `core/Finance/ReceiptService.php:89-92` calls `php_post_receipt_voucher`.
- `core/Finance/PaymentService.php:49-52` calls `php_post_payment_voucher`.

Additional direct production calls exist in `admin/invoices.php:1241`, `admin/ajax_family_visit.php:398`, `admin/ajax_postal_services.php:184`, `admin/ajax_work_visa.php:605`, `admin/ajax/post_voucher.php:64-66`, and `admin/ajax/pay_invoice_remaining.php:133-149`.

Required fix: route these operations through the Facade and isolate the old helpers behind an explicitly tested adapter before claiming that all production financial calls use the new path.

### P1 - Full production caller closure is not proven

The Phase 5 caller test correctly proves there is no direct `LegacyFinanceService` or `LegacyFinanceGateway` construction in the scanned application roots. It does not prove that old accounting helpers and direct Stored Procedure calls are absent; the search above proves the opposite.

### P1 - Performance acceptance is incomplete

The latest microbenchmark is directional only: Legacy `4.120ms`, Facade `3.887ms` for 1,000 payload normalizations. Read-only `EXPLAIN` on the isolated database showed the payment-status query using `fk_pa_inv` and account lookup using `PRIMARY`, but the fixture did not contain `id = 1` rows for two lookup plans, and no production-sized workload was measured.

Required fix: run a controlled benchmark with representative data, query counts, transaction duration, memory, and page/API latency before approving Go.

## Security review result

- Permissions: **FAIL / blocker** due to fail-open exception handling.
- Transactions and savepoints: **implemented and tested**, but not sufficient to compensate for audit and security fail-open behavior.
- Audit logging: **FAIL / blocker** because failures are swallowed.
- Fiscal periods: **FAIL / blocker** because lookup failures are swallowed.
- Branch and currency handling: **partially evidenced** by isolated integration tests; production-scale and direct-caller coverage remain incomplete.
- SQL injection review: prepared statements are used in the reviewed Finance services; dynamic table selection in `resolvePartyAccountId` is restricted to a fixed supplier/customer choice.

## Go / No-Go checklist

| Check | Decision | Reason |
|---|---|---|
| No LegacyFinanceService in application runtime | No-Go | Legacy helper layer remains active through `accounting_functions.php`. |
| All production calls through Facade | No-Go | Direct calls remain in `admin` and helper code. |
| Isolated tests | Go for isolated environment | All executed checks passed, including 29/29 integration checks. |
| Permission enforcement | No-Go | Unexpected permission-provider failures can be swallowed. |
| Fiscal-period enforcement | No-Go | Fiscal lookup failures can be swallowed. |
| Audit durability | No-Go | Audit insert failures do not abort financial work. |
| Performance | No-Go for final production approval | Only a microbenchmark and limited test-database EXPLAIN are available. |
| Production database safety | No-Go for deployment now | Migration and production acceptance were intentionally not executed. |

## Required remediation sequence

1. Fix fail-closed permission and fiscal-period handling.
2. Make posting audit behavior transactional and add failure tests.
3. Inventory and migrate every direct `php_post_*` and financial Stored Procedure caller to the Facade or a reviewed adapter.
4. Re-run static caller closure and all isolated acceptance tests.
5. Run representative performance and query-plan measurements.
6. Review the final diff, create a clean phase commit, take a verified production backup, and obtain explicit deployment approval.

## Final rollback plan

- Stop deployment and preserve logs, test output, and database evidence.
- Keep production untouched until the Go checklist is fully green.
- For the branch, use the verified baseline and rollback procedures in `GIT_SAFETY_PHASE1_REPORT_20260806.txt`; the documented baseline is commit `db1c2e1`.
- If a deployment has already changed schema, restore from the verified database backup using the approved migration rollback procedure; do not use ad-hoc corrective SQL.
- Re-run the isolated acceptance suite before any retry.

## Git and deployment state

- Branch: `refactor-finance-service`.
- Working tree: not clean; documentation and readiness changes are uncommitted.
- Production database: not modified by this review.
- Production migrations, Stored Procedures, Triggers, and data: not modified.

## Conclusion

The refactoring is suitable for continued isolated development and testing, but it is **not production-ready**. The P0 findings must be resolved and retested before any production deployment or migration approval.
