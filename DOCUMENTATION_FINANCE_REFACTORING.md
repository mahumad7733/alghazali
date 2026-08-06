# Finance Refactoring Documentation

## Current verified state (2026-08-06)

The facade now delegates its public operations to independent services with shared context and transaction handling. `LegacyFinanceService` remains in the file as a compatibility class, but it is not constructed by the current facade path. `LegacyFinanceGateway.php` remains only as an adapter artifact for compatibility and future integrations.

Verified flows:

- invoice draft creation and posting;
- receipt draft creation, allocation, posting, and partial-payment recalculation;
- payment and expense service entry points;
- composed service-finance operation and default cash-customer/account resolution.

The authoritative isolated tests are `tools/finance_facade_integration_test.php` and `tools/finance_service_operation_integration_test.php`.

## المعمارية الحالية

أصبح `core/FinanceService.php` Facade يحافظ على الـ API القديم، ويحقن الخدمات التالية:

- `InvoiceService`
- `ReceiptService`
- `PaymentService`
- `ExpenseService`
- `JournalService`
- `BalanceService`
- `TransactionManager`

يحتوي `LegacyFinanceService` على المنطق الحالي مؤقتًا، وتفوض الخدمات إليه خلال مرحلة النقل التدريجي. هذا يمنع كسر الاستدعاءات القديمة ويجعل كل عملية قابلة للنقل والاختبار بشكل مستقل.

## قواعد النقل

1. نقل عملية واحدة أو مجموعة مترابطة في كل Commit.
2. عدم حذف دوال الـ Facade القديمة.
3. الحفاظ على Transactions وAudit والصلاحيات والعملات وعزل الفروع.
4. تشغيل الاختبارات الساكنة والتكاملية قبل كل Commit.
5. تنفيذ Rollback عند أي اختلاف في القيود أو الأرصدة أو الحالات.

## الاختبارات

- `tools/finance_architecture_smoke.php`: اختبار تحميل العقود والـ Facade والمعاملات على SQLite.
- `tools/run_integration_test.php`: اختبار التكامل الحقيقي بعد تشغيل MySQL على قاعدة اختبار.
- `tools/test_signature_compat.php`: اختبار توقيعات Stored Procedures.
