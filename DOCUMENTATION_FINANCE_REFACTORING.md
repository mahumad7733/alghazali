# Finance Refactoring Documentation

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
