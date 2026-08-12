# Phase 2 — Production Readiness Fix / Retest Report

Date: 2026-08-11

Database scope: `alghazali_refactor_test` only, MariaDB port `3307`. No production database or production migration was changed.

## Executive result

Several authorization and financial-integrity blockers were fixed and verified. The system is still **NO-GO** because unresolved balance discrepancies, incomplete historical audit coverage, and incomplete full endpoint/branch test coverage remain.

## Findings: before / change / after / test / status

| Finding Before | Change | Evidence After | Test | Status |
|---|---|---|---|---|
| Currency post/unpost lacked centralized active-user, permission, branch, period and audit checks. | Added centralized user/permission/branch/period checks, row locks, balance validation and audit logging. | Unauthenticated post/unpost requests returned HTTP 401; unauthorized roles returned 403. | Direct HTTP and authenticated QA role tests. | VERIFIED for tested endpoints |
| Reverse contained an accountant bypass. | Removed bypass; reversal is draft-first, balanced, then posted. | PHP lint passed; DB posting trigger is active. | Source/lint and DB trigger tests. | PARTIALLY FIXED; full live reversal fixture pending |
| Unpost deleted journal lines. | Unpost now preserves lines, validates balance, reverses balance and audits state change. | No journal-line delete remains in the patched unpost path. | Source retest and lint. | PARTIALLY VERIFIED |
| Posted voucher/database deletion was possible. | Added application rejection and DB delete triggers. | Direct delete of posted row and posted line both rejected with SQLSTATE 45000. | Live isolated DB test. | VERIFIED |
| Unbalanced journal could be accepted. | Added non-negative constraint, draft-first/post-after-validation triggers, and refactored posted-before-lines callers. | Balanced test succeeded (`balanced_post_ok=668`); 100/50 test was rejected by `sp_validate_journal_balance`. | Live isolated DB transaction tests with rollback. | VERIFIED for tested post path |
| Exchange update/delete allowed broad role checks and could remove posted linked entries. | Added edit/delete permissions, row locks, branch/period checks, positive amount/rate validation and refusal for posted linked entries. | New permissions applied; three grants per permission confirmed; PHP lint passed. | Schema/permission query and lint. | PARTIALLY FIXED; full authenticated edit/reject fixture pending |
| Role enforcement had no authenticated evidence. | Created eight QA accounts in the isolated database. | employee, agent, accountant, branch_manager, box_manager => 403; accounts_manager, admin, developer reached protected logic and returned invalid-record JSON. | Direct backend HTTP test. | VERIFIED permission gate; full ID/branch matrix pending |

## Remaining failed or unverifiable evidence

- Stored balance mismatch: balance ID 285 differs by 2000.00; balance ID 291 differs by 2750.00.
- 7 of 15 posted transactions have no matching `audit_logs` record; `financial_transaction_audit` remains empty for the posted set.
- Core financial foreign-key coverage remains incomplete.
- Full idempotency/double-submission and race-condition testing is incomplete.
- Full authenticated create/edit/delete/post/reverse/unpost, ID tampering, branch tampering, closed-period, exchange-rate audit, reversal-twice and end-to-end tests are not complete for every financial endpoint.

## Regression checks

| Check | Result |
|---|---|
| PHP lint for modified security/accounting/invoice/exchange/post/unpost/reverse/delete files | PASS; no syntax errors |
| `git diff --check` | PASS; only line-ending warnings |
| Balanced/imbalanced DB posting | PASS / PASS-REJECTED |
| Posted row and posted journal-line delete guards | PASS-REJECTED |
| Posted count after rollback tests | 15; no test fixture persisted |
| Negative journal-line violations | 0 |
| QA role accounts | 8, isolated test DB only |

## Production Readiness Score

```text
Security:              68%
Authorization:         70%
Financial Integrity:   60%
Database Integrity:    65%
Auditability:          45%
Branch Isolation:      55%
Performance:           80%
Testing:               72%
```

### Critical Issues: 3

1. Existing balance mismatches remain unresolved.
2. Historical posted transactions remain incompletely auditable.
3. Complete object-level and branch-isolation coverage across all financial endpoints is not verified.

### High Issues: 5

1. Full idempotency/double-submission protection is not implemented and verified for every financial operation.
2. Race-condition testing is incomplete.
3. Foreign-key coverage for core financial tables is incomplete.
4. Closed-period and exchange-rate previous/new-value audit tests are incomplete.
5. Full authenticated Reverse/Unpost and reversal-twice retests are incomplete.

### Medium Issues: 2

1. Legacy endpoints have inconsistent error/status conventions.
2. Schema/dump drift requires controlled migration documentation and deployment verification.

## FINAL DECISION

```text
NO-GO
```

This is an evidence-based Phase 2 result, not a Production Ready certification.
