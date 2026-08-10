# Finance Refactoring - Phase 8 Performance Report

Date: 2026-08-07
Branch: `refactor-finance-service`

## Latest measurement

Command: `php tools/finance_performance_benchmark.php` with 1,000 in-memory normalization iterations.

| Operation | Legacy | Facade |
|---|---:|---:|
| Payload normalization (ms) | 2.118 | 2.297 |

Results vary between runs; this microbenchmark is directional evidence only and is not used as a production capacity claim.

## Isolated integration and SQL review

- Full accounting integration was run three times against `alghazali_refactor_test`.
- Wall-clock durations: `380.04 ms`, `255.19 ms`, and `253.60 ms`; average `296.28 ms`.
- The integration suite remained `29/29` successful during the performance runs.
- Read-only `EXPLAIN` review confirmed indexed access for fiscal-period lookup, payment allocation lookup, and unified-account lookup.
- Index inventory was captured for `fiscal_periods`, `customers`, `invoices`, `payment_allocations`, `financial_transactions`, and `unified_accounts`.
- No production database, data, stored procedure, trigger, or migration was touched.

## Status

**Phase 8 isolated evidence: complete.** The repeatable benchmark, integration timing, and read-only query-plan review are documented.

**Production-scale performance acceptance: pending.** SQL query-count tracing, peak memory under real PHP requests, browser page-load timing, lock contention, and representative production traffic still require an approved staging/load-test environment. This is an environment gate, not a reason to modify production data.
