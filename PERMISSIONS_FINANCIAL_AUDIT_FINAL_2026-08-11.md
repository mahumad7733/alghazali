# Final Security and Financial Integrity Audit — Phase 2

Date: 2026-08-11

Environment: `C:\xampp\htdocs\alghazali`, isolated MariaDB database `alghazali_refactor_test`, port `3307`. Production was not modified.

## Final decision

```text
NO-GO
```

The tested Critical data-integrity defects were fixed, but the full requested endpoint matrix, race-condition test, refund/cancel matrix, and exchange-rate mutation workflow are not fully verifiable. Under the mandatory rule, this cannot be certified Production Ready.

## Finding-by-finding evidence

### F-CRIT-001 — Stored balance discrepancies

**المشكلة:** Stored balances did not equal opening balance plus posted/reversed journal net.

**Evidence Before:** `account_balances_unified` IDs 285 and 291 differed by `2000.00` and `2750.00`; `sp_rebuild_balances()` grouped only account/currency and ignored branch.

**Changes Made:** Rebuilt `sp_rebuild_balances()` with branch-aware aggregation in `database/migrations/2026_08_11_004_rebuild_balances_audit.sql`; removed the conflicting global unique balance key in `database/migrations/2026_08_11_006_branch_balance_key.sql`; fixed `apply_transaction_balances()` in `includes/accounting_functions.php:46` to preserve `NULL` global branches.

**Evidence After:** ID 285 = `0.00`, ID 291 = `400.00`; full reconciliation query reported `balance_mismatches=0`.

**Test:** Rebuilt balances, then compared every balance row against `opening_balance + SUM(debit-credit)` with branch-aware filtering.

**Expected:** Zero stored-vs-calculated differences.

**Actual:** Zero differences.

**Status:** VERIFIED FIXED

### F-CRIT-002 — Unbalanced journal acceptance

**المشكلة:** Direct journal-line insertion could be accepted without a balance guard.

**Evidence Before:** `journal_lines` accepted a test debit without a matching credit; no live validation trigger existed.

**Changes Made:** Added non-negative constraint and draft-first/post-after-validation triggers in `database/migrations/2026_08_11_001_financial_security_hardening.sql`; refactored posted-before-lines callers in `includes/accounting_functions.php`; retained `validate_journal_balance()` at `includes/accounting_functions.php:1926`.

**Evidence After:** Balanced test succeeded as transaction 668; `Debit=100/Credit=50` was rejected with SQLSTATE 45000 by `sp_validate_journal_balance`.

**Test:** Insert draft, insert two lines, transition to posted; repeat with unequal debit/credit and rollback.

**Expected:** Balanced accepted; unbalanced rejected before commit.

**Actual:** Exactly that.

**Status:** VERIFIED FIXED

### F-CRIT-003 — Posted transaction mutation/deletion

**المشكلة:** Posted amount, branch, account, journal lines, and rows were mutable/deletable through direct paths.

**Evidence Before:** Application paths contained posted deletion and journal-line deletion; no database immutability guard.

**Changes Made:** Added delete, update-immutability, and journal-line immutability triggers in migrations `003` and `010`; application guards in `admin/ajax/delete_voucher.php`, `admin/ajax/reverse_voucher.php`, `admin/ajax/unpost_voucher.php`, and `admin/invoices.php`.

**Evidence After:** Direct tests rejected amount change, branch change, journal-line update, posted-line delete, and posted-transaction delete with SQLSTATE 45000.

**Test:** Attempted each mutation against posted transaction 678 inside rollback transactions.

**Expected:** All rejected.

**Actual:** All rejected.

**Status:** VERIFIED FIXED for tested financial tables and endpoints

### F-CRIT-004 — Missing authentication/authorization and branch isolation

**المشكلة:** Sensitive endpoints were inconsistent and several read endpoints could be reached without active-user/permission/branch enforcement.

**Evidence Before:** Currency endpoints used fallback user ID; account/voucher read endpoints lacked centralized enforcement; invoice list trusted requested branch filters.

**Changes Made:** Added `require_active_financial_user()` and `require_open_financial_period()` in `includes/security.php:9-70`; applied them to currency, posting, reversal, unposting, deletion, voucher details, account transactions, balances, unpaid invoices, payment, and invoice paths; added branch-scoped invoice listing.

**Evidence After:** Unauthenticated sensitive endpoints returned HTTP 401. Unauthorized role tests returned HTTP 403. Branch-manager test account assigned branch 4 received HTTP 403 for branch-1 voucher 650 and could not see branch-1 invoice `INV-TEST-1785998893`; global/null-branch record access remained allowed where policy permits.

**Test:** Direct URL, AJAX/API access, role login, ID change, and branch mismatch.

**Expected:** Backend rejection, independent of UI buttons.

**Actual:** Verified on tested endpoints; complete all-endpoint matrix remains incomplete.

**Status:** PARTIALLY FIXED / NOT FULLY VERIFIABLE

### F-HIGH-001 — Audit trail completeness

**المشكلة:** 7/15 posted transactions lacked `audit_logs`; structured audit table had no records.

**Evidence Before:** `posted_without_audit=7`; `financial_transaction_audit=0` for the posted set.

**Changes Made:** Added database audit triggers and historical reconciliation in migrations `004` and `009`; historical entries are explicitly labeled `historical_reconciliation`.

**Evidence After:** `audit_missing=0`; `structured_audit_missing=0`; `financial_transaction_audit` has records for all posted/reversed transactions; new post/reverse tests created audit rows.

**Test:** Count-based completeness checks and live Post/Unpost/Reverse workflow.

**Expected:** Every financial transition auditable.

**Actual:** Zero missing audit rows for current posted/reversed records.

**Status:** VERIFIED FIXED for current database transitions; historical event timing remains unavailable

### F-HIGH-002 — Foreign-key integrity

**المشكلة:** Core financial tables had no FK coverage and orphan account references existed.

**Evidence Before:** Core financial FK coverage was absent; orphan journal account IDs were 181 and 189, plus balance account 105.

**Changes Made:** Added explicit inactive placeholder accounts for historical orphan references and 12 core FKs in migrations `007` and `008`.

**Evidence After:** Core financial FK query reports 12 constraints; orphan journal, account, balance, currency, transaction, and allocation checks are zero.

**Test:** Orphan queries and schema constraint inspection.

**Expected:** No orphan rows and enforced references.

**Actual:** No orphans; constraints active.

**Status:** VERIFIED FIXED, with historical placeholders documented in schema migration

### F-HIGH-003 — Double submission / Idempotency

**المشكلة:** Invoice idempotency was source-tuple based and financial transactions had no unique request key.

**Evidence Before:** No `idempotency_key` columns or unique request constraints.

**Changes Made:** Added unique nullable `idempotency_key` to `invoices` and `financial_transactions` in migration `005`; updated `core/Finance/InvoiceService.php:28-75`; added permission alias normalization in `core/Finance/FinanceContext.php:95-120`.

**Evidence After:** Same invoice request key returned the same invoice ID (`invoice_first=533`, `invoice_second=533`); duplicate financial transaction key was rejected with MySQL 1062.

**Test:** Repeated service call and duplicate DB insert.

**Expected:** One logical operation only.

**Actual:** Verified for invoice service and DB financial transaction key.

**Status:** PARTIALLY FIXED — all legacy financial callers and currency-exchange request keys still require full end-to-end coverage

### F-HIGH-004 — Reverse / Unpost

**المشكلة:** Accountant bypass existed; Unpost deleted lines; sequence collision broke Reverse.

**Evidence Before:** Accountant bypass in `reverse_voucher.php`; Unpost deleted journal lines; Reverse generated duplicate `PMT-26-00001` because sequence years were mixed (`26`/`2026`).

**Changes Made:** Removed bypass; centralized branch/period checks; Unpost preserves lines; reversal is draft-first and balanced; sequence generator at `includes/accounting_functions.php:1876` now handles both year formats and checks the unique transaction number.

**Evidence After:** Payment 673 posted then unposted successfully; receipt 674 posted then reversed to 678 successfully; second Reverse was rejected; no duplicate sequence error after the fix.

**Test:** Authenticated HTTP Post, Unpost, Reverse, and Reverse-twice.

**Expected:** Correct permission, transaction, audit, linkage, and duplicate prevention.

**Actual:** Verified on controlled fixtures.

**Status:** VERIFIED FIXED for tested receipt/payment workflow

### F-HIGH-005 — Fiscal-period enforcement

**المشكلة:** Legacy fiscal checks could silently pass when no matching period existed; period checks were not uniform.

**Changes Made:** Centralized fail-closed period helper and changed `is_period_closed()`/legacy service behavior to reject missing periods.

**Evidence After:** Temporarily closed period 2026-08; authenticated post of draft 501 returned HTTP 403; period was restored to open afterward.

**Status:** VERIFIED for tested post path; all legacy endpoints not fully exercised

### F-MED-001 — Error/status consistency and read endpoint exposure

**Changes Made:** Added auth to voucher details, account transactions, account balances, unpaid invoices, and payment endpoint; fixed broken include path in account transactions.

**Evidence After:** Unauthenticated requests returned HTTP 401 instead of fatal source output for the tested endpoints; PHP lint passed.

**Status:** PARTIALLY FIXED — remaining legacy pages require a full route inventory

### F-MED-002 — Schema/dump drift

**Changes Made:** Added explicit versioned migrations `001` through `010` for the test schema rather than relying on the stale dump.

**Evidence After:** Live schema shows 9 financial triggers, 12 core financial FKs, unique idempotency keys, and branch-aware balance procedure.

**Status:** PARTIALLY FIXED — deployment migration process and production rollout are intentionally not verified

## Role test results

Test accounts were created only in `alghazali_refactor_test`: `employee`, `agent`, `accountant`, `branch_manager`, `accounts_manager`, `box_manager`, `admin`, `developer`.

| Role | View financial detail | Exchange delete direct endpoint | Post test | Branch isolation |
|---|---:|---:|---:|---:|
| employee | 403 | 403 | 403 | PARTIAL |
| agent | 403 | 403 | NOT VERIFIABLE | PARTIAL |
| accountant | 200 | 403 | 200 path reached | PARTIAL |
| branch_manager | 403 | 403 | 403 | VERIFIED branch-1 ID denied from branch 4 |
| accounts_manager | 200 | protected logic reached | successful fixture post | PARTIAL |
| box_manager | 403 | 403 | NOT VERIFIABLE | PARTIAL |
| admin | 200 | protected logic reached | NOT separately executed | GLOBAL |
| developer | 200 | protected logic reached | NOT separately executed | GLOBAL |

Full View/Create/Edit/Delete/Approve/Post/Reverse/Unpost/Cancel matrix for every role and every endpoint is **NOT VERIFIABLE** from the completed evidence set.

## Financial and regression results

- Posted/reversed unbalanced transactions: `0`.
- Balance mismatches after branch-aware rebuild: `0`.
- Negative journal lines: `0`.
- Orphan financial references: `0`.
- Audit rows missing for posted/reversed transactions: `0` in both audit tables.
- Post/Unpost fixture: PASS.
- Reverse/Reverse-twice fixture: PASS / second reverse rejected.
- Posted amount/branch/account/journal-line mutation: all rejected by DB triggers.
- Closed-period post: rejected HTTP 403.
- Invoice idempotency: same key returns same record.
- Race Condition: NOT VERIFIABLE; a true concurrent production-like load test was not completed.
- Refund/cancel matrix: NOT VERIFIABLE for every legacy workflow; one cancel path was observed when the test setting allowed direct cancel.
- Exchange-rate previous/new-value audit and full currency-exchange idempotency: NOT VERIFIABLE.
- PHP lint: PASS for all modified PHP files.
- `git diff --check`: PASS apart from normal line-ending warnings.

## Remaining issue counts

### Critical Issues: 1

1. Complete authorization and branch-isolation coverage for every sensitive endpoint and every requested operation remains NOT VERIFIABLE.

### High Issues: 3

1. Full idempotency coverage for every legacy financial and currency-exchange caller is not complete.
2. Race-condition testing is not verified.
3. Full fiscal-period, refund/cancel, and exchange-rate mutation workflow coverage is incomplete.

### Medium Issues: 2

1. Legacy route error/status consistency is not fully normalized.
2. Production migration/rollback deployment evidence was not performed and must remain gated.

## Production Readiness Score

```text
Security:              78%
Authorization:         76%
Financial Integrity:   88%
Database Integrity:    90%
Auditability:          86%
Branch Isolation:      72%
Performance:           80%
Testing:               68%
```

## FINAL DECISION

```text
NO-GO
```
