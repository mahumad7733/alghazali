=======================================================
 تقرير المرحلة الثانية - Dependency Mapping
 نظام AlGhazali ERP - إعادة هيكلة FinanceService
 التاريخ: 2026-08-06
=======================================================

الهدف: رسم خريطة كاملة لجميع التبعيات، الاستدعاءات، الجداول،
       ونقاط الخطر الخاصة بـ core/FinanceService.php
       قبل أي تعديل برمجي.

=======================================================
 الفصل الأول: ملخص تنفيذي Executive Summary
=======================================================

• الحجم الإجمالي لـ FinanceService.php: 1290 سطرًا
• عدد الدوال العامة (Public API): 16 دالة
• عدد الدوال الخاصة (Internal Helpers): 11 دالة
• عدد الجداول التي يتم الوصول إليها مباشرة: 8 جداول
• عدد الجداول التي يتم الوصول إليها عبر accounting_functions.php: +8 جداول
• عدد الملفات التي تستخدم FinanceService مباشرة: 4 ملفات
• عدد الملفات التي تستخدم FinanceService عبر ServiceFinancialEngine: 6 ملفات
• عدد الملفات التي تتجاوز FinanceService وتستخدم الدوال القديمة / SP مباشرة: 8+ ملفات
• عدد الـ Stored Procedures مستدعاة داخل FinanceService: 6 إجراءات

⚠️ تحدي رئيسي: يوجد نظامين ماليين يعملان بالتوازي داخل المشروع:
  1- المسار الحديث: يمر عبر FinanceService (Service Layer)
  2- المسار القديم: يستدعي accounting_functions.php أو Stored Procedures مباشرة
     من صفحات admin مثل invoices.php, post_voucher.php, reverse_voucher.php ... الخ
     ولهذا السبب فإن أي Facade لاحق يجب أن يوفر التوافق مع كلا النهجين.

=======================================================
 الفصل الثاني: جميع دوال FinanceService (Public API)
=======================================================

ترتيبها حسب المسؤولية (سيتم استخدام هذا التقسيم لاحقاً لتوزيعها على الـ Services):

--- [A] دوال مساعدة عامة (Cross-Cutting Helpers) ---
  A1. normalizeFinancialPayload(array $data): array                      [سطر 241]
      -> توحيد أسماء الحقول المالية + الحسابات الافتراضية
  A2. executeAtomically(callable $callback): mixed                       [سطر 295]
      -> تنفيذ كود ضمن Transaction مع دعم Nested Calls بـ Savepoints
  A3. getOrCreateDefaultCashCustomer(?int $branchId): int                [سطر 1044]
      -> إرجاع أو إنشاء عميل افتراضي للمبيعات النقدية (مخزون static)

--- [B] دوال الفواتير (Invoice Operations) ---
  B1. createInvoiceDraft(array $data, string $category): int             [سطر 321]
      -> فئة (sales | purchase). يعتمد على php_create_invoice()
  B2. postInvoice(int $invoiceId): void                                  [سطر 399]
      -> ترحيل الفاتورة مع قفل FOR UPDATE. يعتمد على php_post_invoice()

--- [C] دوال سندات القبض (Receipt Vouchers) ---
  C1. createReceiptVoucherDraft(array $data): int                        [سطر 452]
      -> ينشئ سند قبض عبر sp_create_receipt_voucher()
  C2. postReceiptVoucher(int $voucherId): void                           [سطر 720]
      -> يعتمد على php_post_receipt_voucher()

--- [D] دوال سندات الصرف (Payment Vouchers) ---
  D1. createPaymentVoucherDraft(array $data): int                        [سطر 533]
      -> ينشئ سند صرف عبر sp_create_payment_voucher()
  D2. postPaymentVoucher(int $voucherId): void                           [سطر 732]
      -> يعتمد على php_post_payment_voucher()

--- [E] دوال سندات المصروفات (Expense Vouchers) ---
  E1. createExpenseVoucherDraft(array $data): int                        [سطر 1138]
      -> ينشئ مصروف عبر sp_create_expense_voucher()
  E2. postExpenseVoucher(int $voucherId): void                           [سطر 1189]
      -> يرحل المصروف عبر sp_post_expense_voucher()
  E3. processExpenseApproval(int $voucherId, int $level, bool $approved,
                             ?string $comment): void                     [سطر 1204]
      -> موافقة على المصروف عبر sp_process_expense_approval()

--- [F] تخصيص الدفعات وحالة الدفع (Payment Allocation) ---
  F1. allocatePayment(int $voucherId, int $invoiceId, float $amount): void [سطر 622]
      -> تخصيص سند على فاتورة مع 3 قفول FOR UPDATE لحل سباقات السرعة
  F2. recalculateInvoicePaymentStatus(int $invoiceId): void              [سطر 744]
      -> يعتمد على php_recalculate_invoice_payment()

--- [G] سير العمل المعقد (Complex Orchestrators) ---
  G1. processServiceOperation(array $data): array                        [سطر 756]
      -> الأوركستريتور الرئيسي: يُنشئ فاتورة بيع + شراء + سند قبض + تخصيص + ترحيل
         كلها ضمن Transaction واحدة عبر executeAtomically()
  G2. receiveInvoicePayment(array $data): int                            [سطر 818]
      -> سداد فاتورة قائمة: سند قبض + تخصيص + ترحيل + إعادة حساب حالة الدفع

=======================================================
 الفصل الثالث: دوال الداخلية (Private Helpers)
=======================================================

--- أمان وصلاحيات وتدقيق (Security / Audit) ---
  P1. assertFiscalPeriodOpen(?string $operationDate): void               [سطر 65]
      -> تحقق من أن تاريخ العملية ليس في فترة مالية مغلقة
  P2. assertUserCan(string $permission, string $operation): void         [سطر 100]
      -> فحص صلاحيات عبر has_permission() أو $_SESSION['_permissions']
  P3. writeAudit(string $action, string $entity, ?int $entityId,
                  array $extra = []): void                               [سطر 132]
      -> كتابة سجل تدقيق في جدول audit_logs (آمن: لا يرمي exceptions)

--- إدارة المعاملات المتداخلة (Nested Transactions) ---
  P4. safeBegin(): string                                                [سطر 175]
      -> يبدأ TOP Transaction أو يُنشئ SAVEPOINT حسب الحالة
  P5. safeEnd(string $name, bool $commit): void                          [سطر 199]
      -> ينهي TOP Transaction أو يُطلق SAVEPOINT

--- حسابات الأطراف (Customer / Supplier Accounts) ---
  P6. resolvePartyAccountId(string $entityType, ?int $entityId): ?int   [سطر 851]
      -> يحضر account_id من جدول customers/suppliers أو يُنشئ حساب جديد
         (يستخدم static cache $partyAccountCache)
  P7. ensureCustomerAccount(int $customerId): ?int                       [سطر 894]
      -> يُنشئ حسابًا ماليًا داخل unified_accounts للعميل ويستدعي
         sp_ensure_opening_balance() + يحدّث customers.account_id
         الفشل يُعيد fallbackBranchReceivablesAccount()
  P8. ensureSupplierAccount(int $supplierId): ?int                       [سطر 967]
      -> نفس الشيء للموردين. الفشل يُعيد fallbackBranchPayablesAccount()
  P9. fallbackBranchReceivablesAccount(): ?int                           [سطر 1102]
      -> حساب احتياطي عام للعملاء إذا فشل إنشاء حساب مخصص
  P10. fallbackBranchPayablesAccount(): ?int                             [سطر 1118]
      -> حساب احتياطي عام للموردين إذا فشل إنشاء حساب مخصص

--- تحقق من الحسابات (Account Validation) ---
  P11. assertAccountUsable(int $accountId, string $label): void          [سطر 1236]
       -> يفحص: وجود الحساب، عدم حذفه (soft delete)، الحالة نشطة،
          عدم تجميده في account_balances_unified (static cache)

=======================================================
 الفصل الرابع: جميع الملفات التي تستخدم FinanceService
=======================================================

==============================================
 (أ) استخدام مباشر لـ new FinanceService (4 ملفات)
==============================================

1. core/bookings/BookingFinancialUpdater.php                        [سطر 3, 20]
   الدوال المستدعاة:
     - executeAtomically()                     [سطر 25]
     - recalculateInvoicePaymentStatus()       [سطر 184]
     - receiveInvoicePayment()                 [سطر 202]
   الاستخدام: تحديث حجز موجود مع تعديل الفاتورة المسودة
              واستبدال سندات القبض المرتبطة.

2. core/bookings/BookingCreatorService.php                       [سطر 3, 24]
   الدوال المستدعاة:
     - executeAtomically()                     [سطر 29]
     - processServiceOperation()               [سطر 90]
   الاستخدام: إنشاء حجز جديد (باص/طائرة) مع معالجة
              العملية المالية الكاملة (بيع + شراء + سند قبض).

3. admin/passports.php                                              [سطر 197-199]
   الدوال المستدعاة:
     - createInvoiceDraft()                      [سطر 199]
   الاستخدام: فقط لإنشاء فاتورة مسودة (Upsert) عند إنشاء/تعديل معاملات
              جواز السفر. ملف passports.php يظل يستخدم الدالة القديمة
              upsertPassportInvoiceDraft() مباشرة للـ UPDATE.

4. includes/ServiceFinancialEngine.php                       [سطر 1-2, 31-73]
   الدوال المستدعاة:
     - processServiceOperation()                [سطر 32]
     - createInvoiceDraft()                      [سطر 42]
     - receiveInvoicePayment()                   [سطر 53]
     - recalculateInvoicePaymentStatus()         [سطر 73]
   الاستخدام: وسيط (Wrapper) موحّد لجميع خدمات الوكالة السياحية
              (جوازات / بريد / زيارة عائلية / عمرة / حج).

==============================================
 (ب) استخدام غير مباشر عبر ServiceFinancialEngine (6 ملفات)
==============================================

5. admin/process_passport_transaction.php                 [سطر 184, 369]
   -> new ServiceFinancialEngine(...) → processServiceFinance()
   الاستخدام: تسجيل/تعديل معاملات جوازات السفر المالية.

6. admin/process_postal_service.php
   -> new ServiceFinancialEngine(...) → processServiceFinance()
   الاستخدام: معاملات الخدمات البريدية.

7. admin/process_family_visit.php
   -> new ServiceFinancialEngine(...) → processServiceFinance()
   الاستخدام: معاملات زيارة العائلة.

8. admin/ajax_umrah.php
   -> new ServiceFinancialEngine(...) → processServiceFinance()
   الاستخدام: حجوزات العمرة (نقطة نهاية AJAX).

9. admin/ajax_hajj.php
   -> new ServiceFinancialEngine(...) → processServiceFinance()
   الاستخدام: حجوزات الحج (نقطة نهاية AJAX).

10. admin/passports.php  (إضافةً للاستخدام المباشر السابق)
    -> process_passport_transaction.php المُدرَج ضمنه

=======================================================
 الفصل الخامس: ملفات تتجاوز FinanceService تماماً
 =       وتستخدم الدوال القديمة / SP مباشرة        =
=======================================================
⚠️ هذه أهم نتيجة في التقرير لأنها تبيّن أن FinanceService
   لا يغطي 100% من التدفقات المالية — ولهذا أي Refactoring
   يجب أن يترك accounting_functions.php سليمة كما هي:

--- صفحات الإدارة الرئيسية (admin/*.php) ---
1. admin/invoices.php
   -> php_post_invoice($pdo, $st_id, $user_id)          [سطر 1241]

2. admin/ajax_work_visa.php
   -> php_post_invoice($pdo, $invoiceId, $user_id, true) [سطر 605]

--- نقاط نهاية AJAX للسندات (admin/ajax/*.php) ---
3. admin/ajax/post_voucher.php
   -> php_post_receipt_voucher()                         [سطر 64]
   -> php_post_payment_voucher()                         [سطر 66]

4. admin/ajax/unpost_voucher.php
   -> php_recalculate_invoice_payment()                  [سطر 65]
   (يستخدم أيضاً استعلامات SQL مباشرة على financial_transactions)

5. admin/ajax/reverse_voucher.php
   -> php_recalculate_invoice_payments()                 [سطر 192]
   (يستخدم استعلامات SQL مباشرة لإنشاء السند العكسي)

6. admin/ajax/delete_voucher.php
   -> php_delete_financial_transaction_and_reverse()
   (من accounting_functions.php، سطر 1542)

--- الصفحات التي لا تحتوي أي استدعاء مالي مباشر وتعتمد بالكامل على AJAX: ---
7. admin/receipts.php           → لا يوجد استدعاء PHP مباشر (كل العمليات عبر JS + AJAX)
8. admin/payments.php           → لا يوجد استدعاء PHP مباشر (كل العمليات عبر JS + AJAX)
9. admin/expenses.php           → دوال JS فقط (postExpense, unpostExpense) بدون أي PHP مباشر
10. admin/bus_bookings.php      → لا استخدام مباشر (يبدو أنه يعتمد على BookingCreatorService)
11. admin/umrah.php             → لا استخدام مباشر (يعتمد على ajax_umrah.php)
12. admin/hajj.php              → لا استخدام مباشر (يعتمد على ajax_hajj.php)
13. admin/bus_flight_bookings_details.php → لا استخدام مباشر لمسارات الحجز القديمة

=======================================================
 الفصل السادس: الجداول المستخدمة + Foreign Keys
=======================================================

==============================================
 (أ) الجداول المستخدمة مباشرة داخل كود FinanceService (8 جداول)
==============================================

 الجدول                   | الوصف                                   | الاستخدام الرئيسي داخل FinanceService
--------------------------|-----------------------------------------|------------------------------------------
 1. fiscal_periods        | الفترات المالية                        | assertFiscalPeriodOpen() → فحص الفترة المغلقة
 2. audit_logs            | سجلات التدقيق (Audit)                   | writeAudit() → INSERT كل عملية مالية
 3. invoices              | الفواتير والسندات (sales/purchase/receipt/payment/expense) | فحص Idempotency, منع التكرار, FOR UPDATE
 4. payment_allocations   | تخصيص الدفعات على الفواتير              | allocatePayment() → INSERT + SELECT FOR UPDATE
 5. customers             | العملاء                                 | account_id, إنشاء حساب عميل تلقائي, عميل نقدي افتراضي
 6. suppliers             | الموردون                                | account_id, إنشاء حساب مورد تلقائي
 7. unified_accounts      | شجرة الحسابات المحاسبية الموحدة         | البحث عن حسابات الأب، إنشاء حسابات عملاء/موردين، حسابات احتياطية
 8. account_balances_unified | أرصدة الحسابات وعلامات التجميد     | assertAccountUsable() → فحص is_frozen

==============================================
 (ب) الجداول المستخدمة عبر accounting_functions.php (تؤثر بشكل غير مباشر على صحة FinanceService)
==============================================

 الجدول                           | الوصف
----------------------------------|-----------------------------------------
 9. financial_transactions        | المعاملات المالية (ترحيل الفواتير/السندات)
10. journal_lines                 | قيود اليومية (قيد مدين + قيد دائين لكل معاملة)
11. currencies                    | العملات وأسعار الصرف
12. services                      | الخدمات (لحساب إيرادات كل خدمة)
13. agents                        | الوكلاء السياحيون وحساباتهم المالية
14. system_settings               | إعدادات النظام العامة
15. financial_transaction_logs    | سجلات تغييرات المعاملات المالية
16. information_schema.TRIGGERS   | فحص تفاعيل balances_triggers_enabled()

==============================================
 (ج) Foreign Keys الحرجة (من ملف alghazali.sql)
==============================================

 FK الاسم                     | الجدول الابن            | الحقل            | الجدول الأب   | الحقل | الإجراء
------------------------------|-------------------------|------------------|---------------|-------|----------
 fk_booking_invoice           | bus_flight_bookings     | invoice_id       | invoices      | id    | ON DELETE SET NULL
 invoices_ibfk_1              | invoices                | reversed_from    | invoices      | id    | ON DELETE SET NULL  (self-referencing: سند عكسي)
 fk_passports_supplier        | passports               | supplier_id      | suppliers     | id    | ON DELETE SET NULL / ON UPDATE CASCADE

⚠️ ملاحظة هامة: البحث في ملف الـ SQL لا يكشف عن FKs كثيرة بين الجداول المالية
(invoices ↔ payment_allocations ↔ financial_transactions ↔ journal_lines ↔ unified_accounts)
وهو ما يعني غالباً أن هذه العلاقات تُحفظ منطقياً من كود PHP دون قيود قاعدة بيانات فعلية.
هذا يزيد من مسؤولية Refactoring: لا تعتمد على قاعدة البيانات لحماية التكامل المرجعي.

=======================================================
 الفصل السابع: الـ Stored Procedures المستدعاة من FinanceService
=======================================================

 اسم الإجراء                     | سطر الاستدعاء | عدد بارامترات | الموقع (الإرجاع)
---------------------------------|---------------|---------------|--------------------------
 sp_create_receipt_voucher       | 501           | 12 + 2 out    | @v_id, @v_num
 sp_create_payment_voucher       | 582           | 12 + 2 out    | @v_id, @v_num
 sp_ensure_opening_balance       | 943 / 1016    | 7             | void (لأرصدة الافتتاحية)
 sp_create_expense_voucher       | 1159          | 13 + 2 out    | @v_id, @v_num
 sp_post_expense_voucher         | 1197          | 2             | void
 sp_process_expense_approval     | 1212          | 5             | void

⚠️ هذه الـ SPs تقع خارج مسؤولية كود PHP — لذا أي
   Refactoring يجب أن يترك توقيعها وتصرفها كما هو دون
   أي تغيير حتى لا ينكسر التوافق.

=======================================================
 الفصل الثامن: الدوال القديمة المستدعاة من FinanceService
 =  (من includes/accounting_functions.php)           =
=======================================================

 اسم الدالة                          | سطر الاستدعاء | الوصف المختصر
-------------------------------------|---------------|------------------------------------------
 php_create_invoice(...)             | 369           | إنشاء فاتورة مسودة في جدول invoices + إنشاء رقم فاتورة
 php_post_invoice(...)               | 429           | ترحيل فاتورة + إنشاء financial_transaction + 2..4 journal_lines + تحديث الأرصدة
 php_post_receipt_voucher(...)       | 728           | ترحيل سند قبض + قيود مدين/دائين + أرصدة
 php_post_payment_voucher(...)       | 740           | ترحيل سند صرف + قيود مدين/دائين + أرصدة
 php_recalculate_invoice_payment(...) | 749          | إعادة حساب payment_status للفاتورة بناءً على التخصيصات

➕ دوال مساعدة خارجية (من functions.php):
 normalize_datetime_db($data)        | 275           | توحيد تنسيق التاريخ لقاعدة البيانات
 has_permission($userId, $perm)      | 103           | فحص صلاحيات المستخدم (hook اختياري)

=======================================================
 الفصل التاسع: الاعتماديات الخارجية (غير الدوال)
=======================================================

 ملف                                 | سطر الاستيراد
-------------------------------------|-----------------
 includes/accounting_functions.php   | السطر 3 (require_once)

 متغيرات سوبر جلوبال يُستخدمون مباشرة:
 - $_SESSION['admin_id']       → سطر 54   (في الـ constructor)
 - $_SESSION['_permissions']   → سطر 110  (assertUserCan)
 - $_SERVER['REMOTE_ADDR']     → سطر 141  (writeAudit)
 - $_SERVER['HTTP_USER_AGENT'] → سطر 142  (writeAudit)

 static Caches داخل الكائن / الكلاس:
 - self::$partyAccountCache    → private static (حسابات الأطراف)
 - $savepointStack             → private instance (حالة الـ Savepoints)
 - static $cache                في assertAccountUsable()  → تحقق نشاط الحساب
 - static $cachedCustomerId    في getOrCreateDefaultCashCustomer() → عميل نقدي افتراضي

=======================================================
 الفصل العاشر: العمليات الحرجة ونقاط الخطورة (Risk Matrix)
=======================================================

 المخاطر مُصنّفة حسب التأثير × الاحتمال.

 درجة الخطورة | الوصف                                                                 | المكان داخل الملف                       | الإجراء المقترح
--------------|-----------------------------------------------------------------------|-----------------------------------------|-------------------------------------------
 🔴 عالية     | طبقتين ماليتين تعملان معاً: المسار الحديث + المسار القديم              | الفصل الخامس كاملاً                     | لا تحذف accounting_functions.php أبداً. اجعل الـ Facade يلف المسار القديم + الجديد معاً.
 🔴 عالية     | فشل إنشاء حساب العميل يُعيد حسابًا احتياطيًا عامًا. المبالغ تذهب       | fallbackBranchReceivablesAccount        | لاحقاً في الـ BalanceService: إضافة تنبيه audit خاص عندما يتم استخدام الـ Fallback
              | إلى حساب شامل لا يخص العميل الفعلي، مما يُشوّه تقارير الذمم المدينة.    | (سطر 1102-1115) و P7/P8                 |
 🔴 عالية     | تخصيص الدفعات (allocatePayment) يتعامل مع 3 جداول مختلفة + 3 أقفال      | F1 allocatePayment (سطر 622-718)        | ابقها كـ Unit واحدة داخل PaymentService. لا تشق منطقها لعدة ملفات.
              | FOR UPDATE + FOR SHARE داخل nested transactions. أي خلط بين الدوال     |                                         |
              | أثناء Refactoring يسبب سباق سرعة.                                     |                                         |
 🔴 عالية     | processServiceOperation يُنفّذ حتى 7 عمليات فرعية في transaction واحدة. | G1 (سطر 756-812)                        | كل من InvoiceService + ReceiptService + PaymentService يجب أن يدعموا التمرير في نفس Transaction الخارجي عبر executeAtomically.
 🟠 متوسطة    | Duplicate Detection منع التكرار يعتمد فقط على المبلغ + اليوم + المصدر   | C1 سطر 474-494 و D1 سطر 555-580         | لاحقاً تحسينه باستخدام Idempotency Key حقيقي (موجود فعلياً في payload).
 🟠 متوسطة    | فشل الـ SP sp_ensure_opening_balance يتم تجاهله silently.             | P7 سطر 942-947 و P8 سطر 1015-1020        | Logging للفشل (لا يوجد حالياً سوى الـ default catch)
 🟠 متوسطة    | Static Caches (self::$partyAccountCache و $cachedCustomerId و           | P6 و A3 و P11                           | بعد التفكيك، اجعل الكاش كـ Singleton عبر Container واحد لتفادي التضارب بين الـ Services
              | assertAccountUsable::$cache) لا تُشفّر بين الـ Services الجديدة.      |                                         |
 🟡 منخفضة    | فحص الفترة المالية يُحاط بـ try/catch ويُتجاهل إذا لم يكن الجدول موجوداً | P1 (سطر 72-91)                          | تبقى كما هي لدواعي التوافق العكسي مع النسخ القديمة
 🟡 منخفضة    | فحص الصلاحيات assertUserCan يمر بدون فعل إذا لم تجد نظام صلاحيات.      | P2 (سطر 100-126)                        | تبقى كما هي.

=======================================================
 الفصل الحادي عشر: خريطة التوزيع المقترحة على الـ Services الجديدة
 =  (خطة توجيهية للمرحلة الرابعة)                                    =
=======================================================

 core/Finance/
 ├── InvoiceService.php
 │     - Public:  B1 (createInvoiceDraft), B2 (postInvoice)
 │     - Private: helpers للفواتير (Idempotency check, FOR UPDATE logic)
 │
 ├── ReceiptService.php
 │     - Public:  C1 (createReceiptVoucherDraft), C2 (postReceiptVoucher),
 │                F1 (allocatePayment)  [نقل allocatePayment هنا مع السندات]
 │     - Private: duplicate check (C1 logic)
 │
 ├── PaymentService.php
 │     - Public:  D1 (createPaymentVoucherDraft), D2 (postPaymentVoucher)
 │     - Private: duplicate check (D1 logic)
 │
 ├── ExpenseService.php
 │     - Public:  E1 (createExpenseVoucherDraft), E2 (postExpenseVoucher),
 │                E3 (processExpenseApproval)
 │
 ├── JournalService.php
 │     - ستُبقَى فارغة في هذه المرحلة لأن منطق القيود يقع بالكامل داخل
 │       accounting_functions.php + SPs.
 │     - المهمة المستقبلية: توحيد php_post_receipt_voucher / php_post_payment_voucher
 │       داخل هذه الخدمة عندما نقرر نقل الدوال القديمة.
 │
 ├── BalanceService.php
 │     - Public:  F2 (recalculateInvoicePaymentStatus)
 │     - Private: P11 (assertAccountUsable),
 │                P9 / P10 (fallback accounts)
 │
 ├── PartyAccountService.php  [اختياري، يُقترح إضافته]
 │     - Public:  A3 (getOrCreateDefaultCashCustomer)
 │     - Private: P6 (resolvePartyAccountId),
 │                P7 (ensureCustomerAccount),
 │                P8 (ensureSupplierAccount),
 │                self::$partyAccountCache
 │
 ├── FinanceException.php     [اختياري: Exception مخصص للطبقة المالية]
 │
 ├── Interfaces/
 │     ├── InvoiceInterface.php    (createInvoiceDraft, postInvoice)
 │     ├── ReceiptInterface.php    (createReceiptVoucherDraft, postReceiptVoucher)
 │     └── PaymentInterface.php    (createPaymentVoucherDraft, postPaymentVoucher)
 │
 └── FinanceSecurityTrait.php  [اختياري]
       → يحتوي A1 (normalizeFinancialPayload),
             A2 (executeAtomically),
             P1 (assertFiscalPeriodOpen),
             P2 (assertUserCan),
             P3 (writeAudit),
             P4+P5 (safeBegin/safeEnd)
       → بسبب أن هذه الدوال تُستخدم من قبل جميع الخدمات، نضعها Trait.

=======================================================
 الفصل الثاني عشر: قائمة الـ Backward Compatibility الضرورية
=======================================================

لا يُسمح بحذف أو تغيير توقيع أي من هذه العناصر:

1. اسم الكلاس: class FinanceService { ... }
2. الدوال العامة كلها (قائمة الفصل الثاني A1 → G2)
3. نوع القيم الراجعة:
   - createInvoiceDraft → int (ID الفاتورة)
   - processServiceOperation → array ['sales_invoice_id', 'purchase_invoice_id', 'receipt_voucher_id', 'normalized_finance']
   - receiveInvoicePayment → int (ID سند القبض)
   - executeAtomically → mixed (يعيد ما يعيده الـ callback)
4. $pdo في الـ constructor هو PDO عادي (ليس wrapper خاص)
5. الدوال الخاصة ليست جزءاً من الـ API العام، ولكن من الضروري إبقاء السلوك
   نفسه للـ fallbacks و static caches لأن هناك كود يعتمد على النتائج غير الرسمية.

=======================================================
 الفصل الثالث عشر: الأسئلة المفتوحة قبل المرحلة الثالثة (Database Safety)
=======================================================

هذه النقاط تحتاج تأكيدًا منك قبل المتابعة:

 Q1. هل يوجد أي ملفات إضافية غير المذكورة أعلاه تستدعي
     FinanceService بشكل غير مباشر؟ (مثل ملفات _test أو scripts داخل tools/)

 Q2. الـ Stored Procedures (الستة) — هل تُدير من
     tools/database/migrations أم يتم تعديلها يدوياً أحياناً على الإنتاج؟

 Q3. ما سياسة التعامل مع الحسابات الاحتياطية
     (fallbackBranchReceivablesAccount / Payables):
     هل يُفضل إيقافها تماماً ورمي Exception عند فشل إنشاء حساب العميل؟

 Q4. هل تريد نقل allocatePayment إلى ReceiptService كما هو مقترح
     أو تفضله في خدمة مستقلة (AllocationService)؟

 Q5. هل يجب في هذه المرحلة استخراج static::$partyAccountCache
     إلى واجهة مستقلة (أو ابق كما هو داخل Trait أو PartyAccountService)؟

=======================================================
 نهاية التقرير | حالة المرحلة الثانية: مكتملة بانتظار الموافقة
=======================================================

أي سؤال أو تعديل على مضمون هذا التقرير قبل الانتقال إلى:
   ↓ المرحلة الثالثة (Database Safety ← ممنوع أي تعديل على قاعدة البيانات)
   ↓ المرحلة الرابعة   (إنشاء Architecture الجديدة في core/Finance/)
   ↓ المرحلة الخامسة   (تحويل FinanceService إلى Facade)
