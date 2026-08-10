# Finance Performance Comparison Report

**Date:** 2026-08-07
**Scope:** Phase 1 pre-deployment verification
**Environment:** Isolated MariaDB `alghazali_refactor_test` on port `3307`; SQLite microbenchmark
**Production access:** None

## Executive Summary

The isolated finance test suite passed and no performance regression was demonstrated by the available microbenchmarks. This is pre-deployment evidence only; it is not production-scale acceptance.

## Test Results

| Check | Result |
|---|---:|
| PHP lint for `core/` | PASS |
| Architecture smoke | PASS |
| P0 fail-closed regression | PASS |
| Phase 5 facade acceptance | PASS |
| Phase 5 caller/static acceptance | PASS |
| Phase 6 service migration acceptance | PASS |
| Facade compatibility | PASS |
| Facade integration | PASS |
| Service-operation integration | PASS |
| Payment/expense voucher integration | PASS |
| Full accounting integration | 29/29 PASS (100%) |

## Timing Measurements

### Normalization microbenchmark

Each run executed 1,000 payload-normalization iterations in SQLite memory:

| Run | Legacy (ms) | Facade (ms) |
|---:|---:|---:|
| 1 | 5.892 | 3.857 |
| 2 | 4.284 | 5.402 |
| 3 | 11.274 | 8.537 |

The variance is expected for a small PHP microbenchmark. It must not be interpreted as a production capacity or latency guarantee.

### Full accounting integration

| Run | Wall time |
|---:|---:|
| 1 | 416.95 ms |
| 2 | 355.94 ms |
| 3 | 436.26 ms |
| Average | 403.05 ms |

All three runs retained 29/29 successful accounting assertions.

### Memory

The 1,000-iteration normalization check reported zero measured memory delta and a 4 MiB PHP peak for both service objects. This is a microbenchmark and does not represent a complete HTTP request or concurrent worker profile.

## SQL and Index Review

Read-only `EXPLAIN` was executed against the isolated MariaDB database:

- Fiscal-period lookup used the primary index and examined one row.
- Payment allocation lookup used `fk_pa_inv` with reference access and one row.
- Unified-account lookup used the primary key with `const` access.
- The test schema contains indexes on `fiscal_periods`, `customers`, `invoices`, `payment_allocations`, `financial_transactions`, and `unified_accounts`.
- Customer and invoice ID probes returned impossible-where plans because the requested fixture IDs were absent in the isolated database; this is a fixture limitation, not evidence about production plans.

## Not Yet Measured

The following gates remain open and require approved Staging or a representative non-production environment:

1. Exact SQL query count per HTTP request.
2. Peak PHP memory for real finance pages under representative data.
3. Browser/page-load timings for finance screens.
4. Lock wait time, transaction contention, and concurrent posting behavior.
5. Production-scale cardinality and query plans.

No production database, stored procedure, trigger, migration, or live data was accessed or changed during this phase.

## Phase 1 Decision

**Code and isolated-test gate: GO.**
**Production deployment gate: NO-GO.** Production-scale performance evidence is still environment-gated, so the deployment phases must not start from this report alone.
