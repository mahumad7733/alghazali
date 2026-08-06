# برومبت إعادة هيكلة FinanceService - حالة التنفيذ

التاريخ: 2026-08-06
النظام: AlGhazali ERP
الفرع: `refactor-finance-service`
مصدر البرومبت: `C:\Users\mahum\.codex\attachments\6c08f171-df77-4d06-af89-64c8bd4aa0da\pasted-text.txt`

## التقرير المقابل لكل مرحلة

| المرحلة | الحالة | التقرير أو ملف الإثبات |
|---|---|---|
| 1 - Git Safety | مكتملة | `GIT_SAFETY_PHASE1_REPORT_20260806.txt` + سجل Git والـ Commits |
| 2 - Dependency Mapping | مكتملة | `FINANCE_DEPENDENCY_MAPPING.md` |
| 3 - Database Safety | مكتملة على قاعدة الاختبار | `DATABASE_SAFETY_REPORT.md` + `database/migrations/2026_08_06_001_finance_schema_compatibility.sql` + `tools/database/verify_finance_schema_migration.php` |
| 4 - Architecture | مكتملة | `PHASE4_IMPLEMENTATION_REPORT_20260806.md` |
| 5 - Facade | منفذة جزئيًا | تم إنشاء Facade واختبار التوافق، لكن الإغلاق الكامل للمرحلة لم يعتمد بعد |
| 6 - نقل المنطق المالي | متبقية | توجد خدمات أولية، لكن النقل المرحلي الكامل لم يعتمد بعد |
| 7 - الاختبارات | لم تُغلق بعد | توجد اختبارات تمهيدية، وسيتم اعتمادها ضمن المرحلة 7 بعد تنفيذ المرحلة 6 رسميًا |
| 8 - الأداء | لم تُغلق بعد | `PERFORMANCE_COMPARISON_REPORT.md` موجود كمسودة قياس، والاعتماد النهائي مؤجل |
| 9 - إدارة الأخطاء | لم تُغلق بعد | `REFACTORING_ISSUES.md` موجود، وسيتم اعتماده عند بدء المرحلة 9 |
| 10 - التوثيق النهائي | لم تُغلق بعد | التقارير الحالية مسودات مرحلية وليست إغلاقًا نهائيًا للمراحل اللاحقة |
| 11 - تسليم المعرفة | لم تُغلق بعد | الأدلة موجودة، لكن تسليمها النهائي مؤجل إلى المرحلة 11 |
| 12 - خطة الصيانة | لم تُغلق بعد | ستُعتمد بعد إغلاق المراحل 6–11 |

## البرومبت الكامل

أنت الآن مهندس برمجيات Senior Software Architect وخبير في بناء وصيانة أنظمة ERP المالية باستخدام PHP 8.x وMySQL وApache وMVC/Service Architecture.

لديك نظام ERP قائم باسم AlGhazali يحتوي على عمليات مالية حساسة تشمل:

- الفواتير.
- سندات القبض.
- سندات الصرف.
- المصروفات.
- القيود اليومية.
- تحديث الأرصدة.
- العملات المتعددة وأسعار الصرف.
- الفروع والصلاحيات.
- سجل العمليات والتدقيق Audit Logs.

الملف الرئيسي الحالي هو `core/FinanceService.php`. المطلوب إعادة هيكلته باحترافية باستخدام Service Layer وClean Architecture وDependency Injection وFacade، مع الحفاظ الكامل على:

- التوافق مع الاستدعاءات القديمة.
- صحة البيانات والقيود المحاسبية.
- العمليات الحالية ونتائجها.
- Transactions وCOMMIT وROLLBACK.
- Idempotency Checks.
- Audit Logging.
- Permission Checks.
- Fiscal Period Validation.
- Currency Handling وExchange Rates.
- Branch Isolation.

### قواعد الأمان الإلزامية

1. لا تعدّل بيانات الإنتاج مباشرة.
2. لا تحذف جداول أو أعمدة أو علاقات أو دوال عامة.
3. أي تعديل على قاعدة البيانات يجب أن يكون عبر Migration موثق يحتوي على السبب والتاريخ وطريقة التنفيذ وRollback.
4. ابدأ بفحص Git وإنشاء نقطة رجوع آمنة قبل التعديل.
5. حافظ على جميع التوقيعات العامة والاستدعاءات القديمة.
6. لا تغيّر نتائج العمليات المالية أو القيود أو الأرصدة أو العملات أو الصلاحيات.
7. استخدم قاعدة اختبار معزولة للاختبارات التكاملية.
8. أنشئ Commit مستقلًا بعد كل مرحلة مكتملة.
9. عند ظهور خطأ مالي، أوقف الترحيل واحتفظ بالأدلة قبل أي تصحيح.

### مراحل العمل المطلوبة

#### المرحلة الأولى: Git Safety

- فحص حالة Git.
- إنشاء فرع مستقل.
- إنشاء نقاط رجوع وCommits مستقلة.
- توثيق طريقة Rollback.

#### المرحلة الثانية: تحليل النظام قبل التعديل

- إعداد Dependency Mapping كامل لـ `FinanceService.php`.
- حصر الدوال والاستدعاءات والملفات والجداول والإجراءات المخزنة والعمليات المالية.
- تحديد الاعتماديات ونقاط الخطورة.

#### المرحلة الثالثة: Database Safety

- إعداد تقرير سلامة قاعدة البيانات.
- عدم تنفيذ تعديل مباشر على الإنتاج.
- إنشاء Migration متوافق وقابل لإعادة التنفيذ وRollback.
- الحفاظ على Foreign Keys وIndexes وConstraints وStored Procedures وViews.

#### المرحلة الرابعة: إنشاء Architecture جديدة

إنشاء `core/Finance/` وتقسيم المسؤوليات إلى:

- `InvoiceService.php`
- `ReceiptService.php`
- `PaymentService.php`
- `ExpenseService.php`
- `JournalService.php`
- `BalanceService.php`
- `FinanceContext.php`
- `TransactionManager.php`
- `AuditLogger.php`
- Contracts وExceptions عند الحاجة.

#### المرحلة الخامسة: تحويل FinanceService إلى Facade

- جعل `core/FinanceService.php` نقطة الدخول العامة المتوافقة.
- تفويض كل عملية إلى الخدمة المسؤولة عنها.
- الحفاظ على أسماء الدوال والتوقيعات وعدد المعاملات.
- إبقاء التنفيذ القديم معزولًا للرجوع والمقارنة.
- منع إنشاء `LegacyFinanceGateway` من مسار الواجهة العامة.

#### المرحلة السادسة: نقل المنطق المالي

- نقل منطق الفواتير إلى `InvoiceService`.
- نقل منطق القبض والتخصيص إلى `ReceiptService`.
- نقل منطق الصرف للموردين إلى `PaymentService`.
- نقل منطق المصروفات والموافقات إلى `ExpenseService`.
- نقل القيود والعمليات المركبة إلى `JournalService`.
- نقل الأرصدة وحساب العميل النقدي إلى `BalanceService`.
- الحفاظ على المعاملات والتدقيق والصلاحيات والفترات والعملات.

#### المرحلة السابعة: الاختبارات

اختبار إنشاء وترحيل وإلغاء الفواتير، وإنشاء وترحيل سندات القبض والصرف والمصروفات، وتحديث الأرصدة، والقيود اليومية، والعملات، والصلاحيات، وسجل التدقيق، والتوافق مع الواجهة القديمة.

#### المرحلة الثامنة: قياس الأداء

- مقارنة التنفيذ القديم والجديد.
- قياس زمن العمليات واستهلاك الاستعلامات عند الإمكان.
- عدم اعتماد أي تحسين أداء يغيّر النتائج المالية.

#### المرحلة التاسعة: إدارة الأخطاء

- تسجيل كل خطأ في `REFACTORING_ISSUES.md`.
- توثيق السبب والملف والحل والحالة.
- استخدام Rollback عند وجود أثر على البيانات المالية.

#### المرحلة العاشرة: التوثيق النهائي

- توثيق الملفات الجديدة والمعدلة.
- توثيق الاعتماديات والاختبارات ونتائجها.
- تحديث التقرير النهائي ومقارنة الأداء.

#### المرحلة الحادية عشرة: تسليم المعرفة

- إعداد دليل للمطورين.
- إعداد دليل للدعم الفني.
- توثيق طريقة إضافة خدمة مالية جديدة وتشغيل الاختبارات والتراجع الآمن.

#### المرحلة الثانية عشرة: خطة الصيانة المستقبلية

- إضافة الخدمات الجديدة عبر Service وContract واختبار معزول وتفويض Facade وتوثيق.
- مراقبة Logs والأداء والفهارس وسلامة العمليات المالية.
- عدم تنفيذ Migration إنتاجية دون Backup وموافقة ونافذة صيانة.

## حالة التنفيذ الفعلية

### ✅ المرحلة 1: Git Safety - مكتملة

- تم إنشاء الفرع `refactor-finance-service`.
- تم إنشاء Commits مستقلة قابلة للرجوع.
- التقرير: `GIT_SAFETY_PHASE1_REPORT_20260806.txt`.

### ✅ المرحلة 2: Dependency Mapping - مكتملة

- تم حصر الاعتماديات والاستدعاءات والجداول والإجراءات المالية.
- التقرير: `FINANCE_DEPENDENCY_MAPPING.md`.

### ✅ المرحلة 3: Database Safety - مكتملة على قاعدة الاختبار

- تم إعداد تقرير سلامة قاعدة البيانات.
- تم إنشاء Migration متوافق وقابل لإعادة التنفيذ:
  `database/migrations/2026_08_06_001_finance_schema_compatibility.sql`.
- تم التحقق من Migration على `alghazali_refactor_test` بنجاح.
- قاعدة الإنتاج لم يتم تعديلها.
- التقرير: `DATABASE_SAFETY_REPORT.md`.

### ✅ المرحلة 4: Architecture - مكتملة

- تم إنشاء الخدمات المتخصصة تحت `core/Finance/`.
- تم عزل التنفيذ القديم في `core/LegacyFinanceService.php`.
- تم إنشاء Context وTransaction Manager وAudit Logger وContracts وExceptions.
- التقرير: `PHASE4_IMPLEMENTATION_REPORT_20260806.md`.

### 🟡 المرحلة 5: Facade - منفذة جزئيًا

- `core/FinanceService.php` أصبح Facade متوافقًا.
- جميع الدوال العامة القديمة موجودة بنفس التوقيعات.
- لا يتم إنشاء `LegacyFinanceGateway` من الواجهة العامة.
- اختبار التوافق: PASS كاختبار تمهيدي.
- اختبار Architecture Smoke: PASS كاختبار تمهيدي.
- التقرير: `PHASE5_FACADE_IMPLEMENTATION_REPORT_20260806.md`.

المتبقي لإغلاق المرحلة الخامسة: مراجعة جميع الاستدعاءات القديمة واعتماد اختبار قبول كامل للـ Facade قبل الانتقال الرسمي إلى المرحلة السادسة.

### ⏳ المرحلة 6: نقل المنطق المالي - لم تُغلق بعد

- الفواتير في `InvoiceService`.
- سندات القبض والتخصيص في `ReceiptService`.
- سندات الصرف في `PaymentService`.
- المصروفات والموافقات في `ExpenseService`.
- العمليات المركبة في `JournalService`.
- الأرصدة والعميل النقدي في `BalanceService`.

### ⏳ المرحلة 7: الاختبارات - لم تُغلق بعد

- `tools/finance_facade_compatibility_test.php`: ناجح.
- `tools/finance_architecture_smoke.php`: ناجح.
- `tools/finance_facade_integration_test.php`: ناجح.
- `tools/finance_service_operation_integration_test.php`: ناجح.
- `tools/finance_voucher_services_integration_test.php`: ناجح.
- `tools/run_integration_test.php`: عدد 29 ناجح، 0 فاشل، 100%.

### ⏳ المرحلة 8: الأداء - لم تُغلق بعد

- تم تشغيل `tools/finance_performance_benchmark.php`.
- تم تحديث `PERFORMANCE_COMPARISON_REPORT.md`.
- القياس لا يغيّر أي نتيجة مالية ولا يستبدل اختبار القبول التكاملي.

### ⏳ المرحلة 9: إدارة الأخطاء - لم تُغلق بعد

- تم تحديث `REFACTORING_ISSUES.md`.
- تم توثيق المشكلات والحلول وحالة Migration وحدود النشر.

### ⏳ المرحلة 10: التوثيق - لم تُغلق بعد

- `FINAL_REFACTORING_REPORT.md`.
- `DOCUMENTATION_FINANCE_REFACTORING.md`.
- `PHASE4_IMPLEMENTATION_REPORT_20260806.md`.
- `PHASE5_FACADE_IMPLEMENTATION_REPORT_20260806.md`.
- `REFACTORING_COMPLETION_CHECKLIST_20260806.md`.

### ⏳ المرحلة 11: دليل المطور والدعم - لم تُغلق بعد

- تم تحديث `DEVELOPER_GUIDE_FINANCE.md`.
- تم تحديث `SUPPORT_GUIDE_FINANCE.md`.

### ⏳ المرحلة 12: خطة الصيانة - لم تُغلق بعد

- تم توثيق طريقة إضافة الخدمات والاختبارات والتفويض والتراجع.
- أي نشر على الإنتاج يبقى إجراءً منفصلًا يحتاج Backup وموافقة ونافذة صيانة.

## الخلاصة والحد المتبقي

المرحلة الرابعة مكتملة، والمرحلة الخامسة منفذة جزئيًا فقط. المراحل 6–12 متبقية ولم تُنفذ كمسار تسلسلي معتمد. بعض الملفات والاختبارات الموجودة هي أعمال تمهيدية ولا تعني إغلاق المرحلة. تطبيق Migration على قاعدة الإنتاج متبقٍ أيضًا ويحتاج موافقة نشر ونسخة Backup ونافذة صيانة معتمدة.

## آخر Commits المهمة

- `b6a0b0d` - complete phase5 finance facade
- `7bb093d` - refresh finance developer and support guides
- `7d16d0c` - document finance refactoring completion checklist
- `f07ea70` - fix phase4 voucher service integration
- `8de2ba9` - verify idempotent finance schema migration
