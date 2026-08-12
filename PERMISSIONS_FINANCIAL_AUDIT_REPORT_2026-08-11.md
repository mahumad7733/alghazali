# تقرير تدقيق الصلاحيات والعمليات المالية

**المشروع:** AlGhazali
**تاريخ التدقيق:** 2026-08-11
**قاعدة الاختبار:** `alghazali_refactor_test`
**الاتصال:** MariaDB 10.4.28 على `127.0.0.1:3307`
**الحكم النهائي:** `NO-GO` — غير جاهز للإنتاج

## 1. نطاق التدقيق ومنهجيته

تم فحص:

- ملفات PHP الخاصة بالمصادقة والصلاحيات والواجهات المالية وواجهات AJAX.
- الجداول الفعلية والإجراءات المخزنة والقيود في قاعدة الاختبار.
- توازن القيود المحاسبية والأرصدة والحركات المرحّلة.
- وجود سجلات التدقيق وربطها بالحركات المالية.
- مسارات الفواتير، سندات القبض، سندات الصرف، المصروفات، Reverse، Unpost، صرف العملات، الفترات المالية، العملات والفروع.

لم يتم تنفيذ عمليات مالية فعلية أو تغييرات إنتاجية. اختبار إدخال القيد غير المتوازن تم داخل Transaction ثم `ROLLBACK`.

لم تتوفر جلسة مستخدم اختبارية مصادق عليها للمتصفح؛ لذلك نتائج تجاوز الصلاحيات المصادق عليها مبنية على تحليل Endpoint والكود وقاعدة البيانات، بينما اختبار A/B الفعلي يحتاج حسابات اختبار مخصصة.

## 2. ملخص قاعدة البيانات

| العنصر | النتيجة |
|---|---:|
| المستخدمون | 13 |
| الأدوار | 12 |
| الصلاحيات | 132 |
| ربط الأدوار بالصلاحيات | 432 |
| الفروع | 2 |
| العملات | 3 |
| الحركات المالية | 22 |
| الحركات المرحّلة | 15 |
| الحركات المسودة | 4 |
| الحركات الملغاة | 3 |
| أسطر القيود | 39 |
| سجلات `audit_logs` | 2465 |
| سجلات `financial_transaction_audit` | 0 |
| الفترات المغلقة حاليًا | 0 |
| Triggers في المخطط الحي | 0 |

## 3. الأدوار الفعلية

| Role | الاسم الظاهر | عدد الصلاحيات |
|---|---|---:|
| `admin` | مدير عام | 132 |
| `developer` | مطور | 132 |
| `branch_manager` | مدير فرع | 25 |
| `employee` | موظف | 11 |
| `accountant` | محاسب | 22 |
| `data_entry_relayer` | مدخل بيانات / مرحل | 18 |
| `agent` | وكيل | 12 |
| `accounts_manager` | مدير الحسابات | 30 |
| `system_manager` | مدير النظام | 22 |
| `dept_manager` | مدير القسم | 15 |
| `box_manager` | مدير الصناديق | 8 |
| `hr_manager` | مدير الموظفين | 5 |

المستخدمون الحاليون في قاعدة الاختبار مرتبطون بالأدوار أعلاه، ومنهم مستخدمون بحالات `active` وفروع ونطاقات مختلفة.

## 4. الصلاحيات المالية الفعلية

تم استخراج الصلاحيات من `unified_permissions`، وليس من أسماء الأزرار.

### صلاحيات السندات والحركات

`voucher_create`, `voucher_edit`, `voucher_delete`, `voucher_post`, `voucher_reverse`, `voucher_edit_posted`, `vouchers_unpost`, `receipt_reverse`, `payment_reverse`, `receipt_delete_original`, `receipt_delete_reversal`, `payment_delete_original`, `payment_delete_reversal`, `transactions_edit_amount`.

### صلاحيات العرض المالي

`receipts_view`, `payments_view`, `invoices_view`, `unified_payments_view`, `expenses_view`, `financial_hub_view`, `view_all_transactions`, `view_financial_data`, `col_amount_view`, `col_balance_view`.

### صلاحيات الحسابات والتقارير

`accounts_view`, `accounts_create`, `accounts_edit`, `accounts_delete`, `account_statement_view`, `general_ledger_view`, `trial_balance_view`, `income_statement_view`, `manage_cost_centers`, `view_cost_center_reports`, `manage_financial_accounts`, `manage_expenses`, `banks_view`, `boxes_view`, `currencies_view`, `currency_exchange_view`, `financial_reports_view`, `view_reports`, `edit_financial_prices`.

### أهم توزيعات الصلاحيات

| Permission | الأدوار المالكة فعليًا |
|---|---|
| `voucher_create` | accountant, accounts_manager, admin, box_manager, developer |
| `voucher_edit` | accountant, accounts_manager, admin, developer |
| `voucher_delete` | admin, developer |
| `voucher_post` | accountant, accounts_manager, admin, developer |
| `voucher_reverse` | admin, developer |
| `voucher_edit_posted` | admin, developer |
| `receipt_reverse` | admin, developer |
| `payment_reverse` | admin, developer |
| `vouchers_unpost` | accountant, accounts_manager, admin, developer |
| `transactions_edit_amount` | admin, developer |
| `manage_financial_accounts` | accounts_manager, admin, box_manager, developer |
| `manage_expenses` | accounts_manager, admin, developer |
| `view_all_transactions` | admin, data_entry_relayer, developer |

## 5. مصفوفة Role → Permission → Page → Endpoint → Action

| Role | Permission | Page | Endpoint / Function | Backend Protected | Database Protected | Result |
|---|---|---|---|---|---|---|
| accountant | `voucher_post` | سندات القبض/الصرف | `admin/ajax/post_voucher.php` → `FinanceService::postReceiptVoucher()` / `postPaymentVoucher()` | نعم | جزئي | محمي جزئيًا |
| admin/developer | `voucher_reverse` | السندات | `admin/ajax/reverse_voucher.php` | نعم | لا يوجد قيد DB مستقل | High |
| accountant | لا يملك `voucher_reverse` | السندات | `admin/ajax/reverse_voucher.php` | يوجد تجاوز صريح للدور `accountant` في السطر 79 | لا | High |
| admin/developer | `voucher_delete` | السندات | `admin/ajax/delete_voucher.php` | نعم | لا يمنع حذف posted | Critical |
| أي مستخدم جلسة | غير محددة | صرف العملات | `admin/ajax_post_exchange.php` | لا يوجد Permission أو فحص جلسة | لا | Critical |
| أي مستخدم جلسة | غير محددة | صرف العملات | `admin/ajax_unpost_exchange.php` | لا يوجد Permission أو فحص جلسة | لا | Critical |
| مستخدم مسجل | غير محددة | الفواتير | `admin/invoices.php` تحديث | فحص جلسة وCSRF فقط | لا | Critical |
| مستخدم مسجل | غير محددة | الفواتير | `admin/invoices.php` حذف | فحص جلسة وCSRF فقط | لا | Critical |
| مستخدم مسجل | غير محددة | الفواتير | `admin/invoices.php` ترحيل | لا يوجد فحص مباشر؛ تعتمد العملية على الخدمة | جزئي | High |

## 6. نتائج Backend Authorization

### 6.1 صرف العملات — حماية ناقصة

في [admin/ajax_post_exchange.php:8-15](C:/xampp/htdocs/alghazali/admin/ajax_post_exchange.php:8):

- يتم تطبيق rate limit وCSRF فقط.
- لا يوجد `isset($_SESSION['admin_id'])`.
- لا يوجد `has_permission()` أو `has_permission_v3()`.
- يتم استخدام القيمة الافتراضية `$_SESSION['admin_id'] ?? 1`.
- يتم تحديث `financial_transactions.status = 'posted'` في الأسطر 38-40.

ينطبق نفس الخلل على [admin/ajax_unpost_exchange.php:8-37](C:/xampp/htdocs/alghazali/admin/ajax_unpost_exchange.php:8)، حيث يمكن حذف `journal_lines` وتغيير الحركة إلى `draft` دون فحص صلاحية.

### 6.2 الفواتير — مسارات مباشرة بلا Permission

في [admin/invoices.php:13-16](C:/xampp/htdocs/alghazali/admin/invoices.php:13) يوجد فحص جلسة فقط.

مسار التحديث يبدأ في السطر 542 ويقرأ الفاتورة بالـ ID فقط في الأسطر 549-558، ثم يقبل `branch_id` من POST في السطر 595 ويحدثه في السطر 660.

مسار الترحيل يبدأ في السطر 1172، ولا يحتوي قبل تنفيذ العملية على فحص صريح لـ `voucher_post` أو Permission خاص بالفاتورة. صحيح أن `InvoiceService::postInvoice()` يتحقق من `post_invoice` في [core/Finance/InvoiceService.php:58-80](C:/xampp/htdocs/alghazali/core/Finance/InvoiceService.php:58)، لكن المسار الخارجي ليس موحدًا مع كل عمليات الفواتير.

مسار الحذف يبدأ في السطر 1264. توجد قيود حالة ومنع حذف الفاتورة المرتبطة بمدفوعات مرحّلة، لكن لا يوجد فحص صلاحية قبل بدء العملية.

### 6.3 حذف السندات المالية

في [admin/ajax/delete_voucher.php:117-142](C:/xampp/htdocs/alghazali/admin/ajax/delete_voucher.php:117):

```text
DELETE FROM journal_lines
DELETE FROM payment_allocations
DELETE FROM financial_transactions
```

المسار يسمح بحذف حركة `posted` إذا امتلك المستخدم Permission الحذف، ثم يعكس الأرصدة يدويًا. هذا يخالف متطلب الحفاظ على الأصل المالي واستخدام Reverse بدل الحذف، خصوصًا للحركات المعتمدة.

### 6.4 Reverse

في [admin/ajax/reverse_voucher.php:41-90](C:/xampp/htdocs/alghazali/admin/ajax/reverse_voucher.php:41):

- يمنع Reverse مرتين عبر `is_reversed` و`original_voucher_id` وفحص السند العكسي.
- ينشئ حركة عكسية ويربطها بالأصل.
- يستخدم Transaction.
- يسجل في `audit_logs`.
- لكنه يمنح دور `accountant` تجاوزًا مباشرًا في السطر 79، حتى لو لم يملك صلاحية `voucher_reverse` أو `receipt_reverse` أو `payment_reverse`.

## 7. التدقيق المالي

| Operation | Table / Service | Transaction | Accounting Entry | Balance Update | Audit Log | Duplicate Protection | Result |
|---|---|---|---|---|---|---|---|
| إنشاء فاتورة | `invoices`, `InvoiceService` | نعم في المسار الجديد | عبر `FinancePostingAdapter` | نعم | جزئي | Idempotency جزئية | Partial |
| ترحيل فاتورة | `InvoiceService::postInvoice` | نعم | نعم | نعم | نعم في الخدمة | فحص الحالة | Partial |
| سند قبض | `financial_transactions`, `ReceiptService` | نعم | نعم | نعم | جزئي | تخصيص الدفعة يمنع التكرار الجزئي | Partial |
| سند صرف | `financial_transactions`, `PaymentService` | نعم | نعم | نعم | جزئي | غير موحد | Partial |
| مصروف | `expenses`, `FinancePostingAdapter` | نعم في بعض المسارات | نعم عند الترحيل | نعم | غير متسق | غير مثبت بالكامل | High |
| Reverse | `financial_transactions`, `journal_lines` | نعم | ينشئ قيدًا عكسيًا | نعم | `audit_logs` | يمنع التكرار بالكود فقط | Partial |
| Unpost | `admin/ajax/unpost_voucher.php` | نعم | يحذف القيود | نعم | موجود في هذا المسار | فحص حالة | High |
| صرف عملات | `currency_exchange_transactions` | نعم | موجود | موجود | غير موحد | لا يوجد Idempotency | Critical |
| الفترات المالية | `fiscal_periods` و`FinanceContext` | تحقق بالكود | جزئي | جزئي | جزئي | — | Partial |

## 8. توازن القيود المحاسبية

### النتيجة الحالية

الاستعلام الحالي على قاعدة الاختبار لم يجد حركة موجودة غير متوازنة:

```sql
SELECT ft.id,
       SUM(jl.debit) AS debit,
       SUM(jl.credit) AS credit
FROM financial_transactions ft
LEFT JOIN journal_lines jl
  ON jl.financial_transaction_id = ft.id
GROUP BY ft.id
HAVING ABS(debit - credit) > 0.005;
```

لكن هذا لا يثبت الحماية.

### اختبار الحماية

تم تنفيذ إدخال داخل Transaction ثم التراجع عنه:

```sql
INSERT INTO journal_lines
    (financial_transaction_id, account_id, debit, credit, currency_id)
VALUES
    (433, 1, 123.45, 0, 1);
ROLLBACK;
```

النتيجة: الإدخال قُبل ولم يوجد رفض من Database. كما أن `SHOW CREATE TABLE journal_lines` لا يحتوي على Constraint يمنع عدم تساوي المدين والدائن، ولا توجد Triggers في المخطط الحي.

**الحكم:** Critical Financial Integrity Issue.

## 9. الأرصدة ومصدر الحقيقة

توجد جداول ومسارات متعددة:

- `journal_lines`
- `financial_transactions`
- `account_balances_unified`
- دوال مثل `fn_get_balance_at_date`
- دوال PHP مثل `apply_transaction_balances()`

هذا يعني أن الرصيد ليس مصدر حقيقة واحدًا. بعض الصفحات تحسب الرصيد من القيود، وبعضها يقرأ `current_balance` أو `opening_balance`. لا توجد آلية Database موحدة تمنع اختلاف الرصيد المخزن عن مجموع الحركات.

في [admin/account_statement.php:273-285](C:/xampp/htdocs/alghazali/admin/account_statement.php:273) يتم حساب حركة فعلية من `journal_lines`، بينما يتم أيضًا قراءة أرصدة من `account_balances_unified`.

## 10. Audit Trail

### الجداول

- `audit_logs`: يحتوي على المستخدم، العملية، الجدول، السجل، القيم السابقة والجديدة، IP، route، severity.
- `financial_transaction_audit`: موجود بنيويًا، لكنه يحتوي على 0 سجل في قاعدة الاختبار.

### النتائج الفعلية

- الحركات المرحّلة: 15.
- الحركات المرحّلة التي لا تملك سجلًا في `audit_logs` مرتبطًا بـ `table_name='financial_transactions'` و`record_id`: 7.
- الحركات التي لا تملك سجلًا في `financial_transaction_audit`: 15.

**الحكم:** Audit Trail غير مكتمل ولا يمكن ضمان Who/What/When/Before/After/Why لكل حركة مالية.

## 11. الفترات المالية

يوجد جدول `fiscal_periods` ويحتوي على 12 فترة شهرية لعام 2026، وكلها حاليًا `is_closed = 0`.

في [core/Finance/FinanceContext.php:144-178](C:/xampp/htdocs/alghazali/core/Finance/FinanceContext.php:144) يوجد تحقق من الفترة المفتوحة في الخدمات الجديدة.

لكن المسارات القديمة مثل صرف العملات وبعض مسارات الفواتير لا تستخدم فحصًا موحدًا من `FinanceContext`. لذلك لا يمكن إعلان حماية إغلاق الفترة على مستوى النظام بالكامل.

## 12. العملات وأسعار الصرف

توجد أعمدة:

- `financial_transactions.currency_id`
- `financial_transactions.exchange_rate`
- `invoices.currency_id`
- `invoices.exchange_rate`
- `currencies.exchange_rate_buy`
- `currencies.exchange_rate_sell`

في [admin/edit_invoice.php:86-105](C:/xampp/htdocs/alghazali/admin/edit_invoice.php:86) يقبل المسار `exchange_rate` من POST ويحسب القيمة باستخدامه، مع حماية حالة الفاتورة فقط.

لا توجد حماية Database موحدة تمنع تغيير سعر الصرف أو تضمن تسجيل القيمة السابقة والجديدة لكل تغيير.

## 13. الفروع والعزل الأفقي

يوجد `branch_id` في المستخدمين والفواتير والحركات وأسطر القيود والأرصدة.

توجد بنية `BranchMiddleware` في [includes/BranchMiddleware.php:77-105](C:/xampp/htdocs/alghazali/includes/BranchMiddleware.php:77)، لكن الفواتير لا تستخدمها في جميع الاستعلامات الحساسة.

في [admin/invoices.php:132-135](C:/xampp/htdocs/alghazali/admin/invoices.php:132)، يتم استخدام `GET branch_id` كفلتر يرسله العميل. وفي الأسطر 595-665 يمكن تمرير `branch_id` جديد عند التعديل.

**الحكم:** عزل الفروع غير مثبت End-to-End، ويوجد خطر Horizontal Privilege Escalation إذا كان المستخدم يملك جلسة صحيحة دون Permission إضافية.

## 14. Double Submission وRace Conditions

يوجد استخدام لـ `FOR UPDATE` داخل `ReceiptService::allocatePayment()` في [core/Finance/ReceiptService.php:51-79](C:/xampp/htdocs/alghazali/core/Finance/ReceiptService.php:51)، وهذا يحمي تخصيص الدفعات في المسار الجديد.

لكن:

- صرف العملات لا يستخدم Idempotency Key.
- بعض المسارات القديمة تنشئ أو تحدث الحركات مباشرة.
- `FinanceContext::normalize()` يحتفظ بـ `idempotency_key`، لكن `InvoiceService` يبحث فعليًا عن مصدر العملية `source_type/source_id/category` بدل قيد فريد على المفتاح نفسه.
- لا يوجد قيد Database عام يمنع تكرار العمليات المالية المتشابهة.

**الحكم:** الحماية من Double Submission غير موحدة.

## 15. سيناريوهات التدقيق

| السيناريو | النتيجة |
|---|---|
| إنشاء عملية صحيحة | المسارات الجديدة تحتوي Transaction، لكن التغطية غير موحدة |
| إنشاء عملية بدون Permission | مؤكد كخطر في صرف العملات والفواتير القديمة |
| تعديل عملية معتمدة | الفواتير تقيد التعديل غالبًا إلى draft، لكن فحص Permission غير موحد |
| حذف عملية معتمدة | حذف السند المرحّل ممكن بصلاحية الحذف؛ الحذف الفعلي موجود |
| Reverse | موجود ويمنع التكرار بالكود، مع تجاوز accountant |
| Reverse مرتين | يمنع بالكود عبر `is_reversed` وارتباط الأصل/العكسي |
| Cancel | موجود ضمن Reverse، لكن المسارات ليست موحدة |
| إرسال نفس العملية مرتين | الحماية جزئية وليست Database-wide |
| فشل منتصف العملية | المسارات الجديدة تستخدم Transaction؛ المسارات القديمة تحتاج مراجعة منفصلة |
| عملية في فترة مغلقة | الخدمات الجديدة تمنعها؛ لا يوجد ضمان لكل Endpoint |
| تغيير سعر الصرف | ممكن عبر POST في بعض مسارات الفواتير دون Audit موحد |
| تغيير الحساب بعد الاعتماد | القيود تختلف حسب المسار ولا يوجد قفل Database موحد |
| تغيير الفرع | ممكن تمرير `branch_id` في تعديل الفاتورة دون تحقق نطاق واضح |
| الوصول إلى فرع آخر | غير مثبت End-to-End، والكود لا يفرض العزل مركزيًا |
| الوصول إلى عملية مستخدم آخر | بعض الصفحات تبحث بالـ ID فقط دون ملكية أو نطاق مستخدم |

## 16. Production Blockers المطلوبة للمعالجة

1. إضافة Authentication وPermission إلزامية إلى `ajax_post_exchange.php` و`ajax_unpost_exchange.php`.
2. توحيد جميع عمليات الفواتير عبر Service واحد مع صلاحيات Create/Edit/Post/Delete/Reverse.
3. منع حذف أي `posted` أو `approved` ماليًا؛ استخدام Reverse أو Cancel موثق بدل الحذف.
4. إضافة Transaction-level validation تمنع القيد غير المتوازن، ويفضل Constraint/Trigger أو Stored Procedure موحدة.
5. فرض `Debit = Credit` قبل Commit، وليس فقط قبل العرض.
6. توحيد مصدر الحقيقة للأرصدة وإضافة Reconciliation آلي بين `journal_lines` و`account_balances_unified`.
7. تسجيل Audit إلزامي لكل إنشاء وترحيل وتعديل وإلغاء وReverse وحذف، مع Before/After/Reason/IP/User.
8. فرض عزل الفرع داخل Backend/Service، وعدم قبول `branch_id` من العميل دون تحقق من نطاق المستخدم.
9. إضافة Idempotency key بقيد Unique للعمليات المالية الحساسة.
10. اختبار مصادق عليه بحسابات `employee`, `accountant`, `branch_manager`, `admin` قبل أي قبول إنتاجي.

## 17. الحكم النهائي

النظام يحتوي على بنية صلاحيات وخدمات مالية جيدة جزئيًا، لكن التنفيذ غير موحد بين المسارات الجديدة والقديمة. وجود صلاحيات في قاعدة البيانات لا يعني أن كل Endpoint يتحقق منها.

**الحكم:** `NO-GO`

لا يجوز اعتماد النظام Production Ready قبل إغلاق جميع البنود المصنفة Critical وHigh وإعادة تنفيذ اختبار End-to-End بجلسات مستخدمين وفروع متعددة.

---

# 18. جولة التحقق النهائي Evidence-Based

تم تنفيذ جولة تحقق إضافية بناءً على شرط عدم التخمين. النتائج أدناه تميز بين الفحص العملي، والفحص البنيوي، وما تعذر إثباته.

## 18.1 اختبارات HTTP العملية

| الاختبار | النتيجة الفعلية | الحالة |
|---|---|---|
| GET `/admin/ajax_post_exchange.php` بدون جلسة مصادق عليها | HTTP 200 مع رسالة خطأ داخلية، وليس 401/403 Authentication | STILL FAILED كتصميم حماية |
| GET `/admin/ajax_unpost_exchange.php` بدون جلسة مصادق عليها | HTTP 200 مع رسالة خطأ داخلية، وليس 401/403 Authentication | STILL FAILED كتصميم حماية |
| POST للنقطتين بدون CSRF | HTTP 403 | VERIFIED CSRF فقط، وليس Permission |
| PHP lint للنقاط الحساسة | لا توجد أخطاء Syntax في 5 ملفات | VERIFIED |

اختبار HTTP لم ينفذ كتابة مالية؛ الطلبات استخدمت ID غير صالح أو فشلت قبل الوصول إلى Commit.

## 18.2 اختبارات قاعدة البيانات العملية

| الاختبار | النتيجة الفعلية | الحالة |
|---|---|---|
| إدخال `debit=123.45, credit=0` في `journal_lines` داخل Transaction | قُبل الإدخال ثم تم Rollback | STILL FAILED / Critical |
| القيود غير المتوازنة الموجودة حاليًا | لا توجد حركة حالية غير متوازنة | VERIFIED CURRENT DATA ONLY |
| أسطر قيود سالبة | 0 | VERIFIED |
| سطر يحتوي مدينًا ودائنًا معًا | 0 | VERIFIED |
| أسطر بلا حركة مالية أصلية | 0 | VERIFIED |
| تخصيصات دفع بلا حركة مالية | 0 | VERIFIED |
| Foreign Key constraints للجداول المالية الأساسية | 0 | STILL FAILED |
| فوارق الرصيد المخزن مقابل القيود | 2 حسابات بها فروقات | STILL FAILED / Critical |
| تكرار مصدر فاتورة `source_type/source_id/category` | حالة واحدة مكررة | STILL FAILED |
| حركة مرحّلة بلا `audit_logs` | 7 من 15 | STILL FAILED |
| حركة مرحّلة بلا `financial_transaction_audit` | 15 من 15 | STILL FAILED |

### تفاصيل فوارق الأرصدة

| Balance ID | Account | Currency | Stored Current Balance | Journal Net | Difference |
|---:|---:|---:|---:|---:|---:|
| 285 | 4 | 1 | 1875.00 | -125.00 | 2000.00 |
| 291 | 169 | 1 | 3150.00 | 400.00 | 2750.00 |

هذه النتيجة تفشل المعادلة المطلوبة:

```text
Opening Balance + Debits - Credits = Current Balance
```

## 18.3 إجراءات قاعدة البيانات

يوجد الإجراء `sp_validate_journal_balance(transaction_id)`، وهو يرسل `SIGNAL` عند عدم التوازن، لكن وجود الإجراء لا يثبت استدعاءه في كل Endpoint. الإدخال المباشر إلى `journal_lines` قُبل، ولا يوجد Trigger يفرض استدعاء الإجراء.

يوجد أيضًا:

- `sp_rebuild_balances()`، وتعليقه في قاعدة البيانات يذكر `[PATCH:NO_TX]`.
- `sp_update_account_balances()`، ويستدعي إعادة البناء.
- لا توجد Triggers في المخطط الحي، رغم وجود Triggers في ملف dump المرجعي `tools/database/alghazali.sql`.

هذا يثبت وجود Schema Drift بين الـ dump والمخطط العامل.

## 18.4 اختبارات الأدوار المطلوبة

الأدوار الموجودة فعليًا: `employee`, `agent`, `accountant`, `branch_manager`, `accounts_manager`, `box_manager`, `admin`, `developer`.

تم فحص توزيع الصلاحيات في قاعدة البيانات لكل دور. لكن الاختبار العملي المصادق عليه لكل دور لم يكن قابلاً للتنفيذ لعدم توفر كلمات مرور/جلسات اختبار مخصصة. لذلك تصنيف الاختبارات العملية هو:

| Role | DB Permission Map | Authenticated UI/API Test | ID/branch tampering Test | الحالة |
|---|---|---|---|---|
| employee | VERIFIED | NOT VERIFIABLE | NOT VERIFIABLE | PARTIALLY VERIFIED |
| agent | VERIFIED | NOT VERIFIABLE | NOT VERIFIABLE | PARTIALLY VERIFIED |
| accountant | VERIFIED | NOT VERIFIABLE | NOT VERIFIABLE | PARTIALLY VERIFIED |
| branch_manager | VERIFIED | NOT VERIFIABLE | NOT VERIFIABLE | PARTIALLY VERIFIED |
| accounts_manager | VERIFIED | NOT VERIFIABLE | NOT VERIFIABLE | PARTIALLY VERIFIED |
| box_manager | VERIFIED | NOT VERIFIABLE | NOT VERIFIABLE | PARTIALLY VERIFIED |
| admin | VERIFIED | NOT VERIFIABLE | NOT VERIFIABLE | PARTIALLY VERIFIED |
| developer | VERIFIED | NOT VERIFIABLE | NOT VERIFIABLE | PARTIALLY VERIFIED |

عدم توفر جلسة اختبار لا يعتبر نجاحًا؛ لذلك لا يمكن إغلاق مخاطر Vertical أو Horizontal Privilege Escalation.

## 18.5 نتائج الاختبارات الإلزامية

| السيناريو | Evidence | الحالة |
|---|---|---|
| إنشاء بدون Permission | نقاط صرف العملات تفتقد Permission؛ لا توجد جلسة اختبار لعملية Commit | STILL FAILED / NOT FULLY VERIFIABLE |
| تعديل عملية معتمدة | بعض الخدمات تمنعها، لكن المسارات القديمة غير موحدة | PARTIALLY FIXED / STILL FAILED |
| حذف عملية معتمدة | حذف السند المرحّل موجود في `delete_voucher.php` | STILL FAILED / Critical |
| Reverse | Transaction وربط الأصل موجودان، مع bypass لدور accountant | STILL FAILED |
| Unpost | Permission موجود في `unpost_voucher.php`، لكن صرف العملات بلا Permission | STILL FAILED |
| قيد غير متوازن | الإدخال المباشر قُبل | STILL FAILED / Critical |
| Double Submission | حماية جزئية في `ReceiptService`، بلا قيد عام وصرف العملات بلا Idempotency | NOT VERIFIED / High |
| Race Condition | `FOR UPDATE` موجود في مسار تخصيص الدفع، لكن لم يختبر المتزامن لكل العمليات | NOT VERIFIABLE |
| تغيير الفرع | `invoices.php` يقبل `branch_id` من POST دون عزل مركزي | STILL FAILED |
| تغيير ID | استعلامات حساسة تستخدم ID مباشرة دون Object-Level Authorization موحد | STILL FAILED DESIGN RISK |
| فترة مغلقة | `FinanceContext` يحمي الخدمات الجديدة؛ لا يوجد ضمان لكل المسارات القديمة | PARTIALLY FIXED |
| تغيير سعر الصرف | قيمة POST تدخل في الحسابات؛ Audit/Permission موحد غير مثبت | STILL FAILED |

## 18.6 حالات الإصلاح

لم يتم تعديل الكود في هذه الجولة، لذلك لا توجد حالة `VERIFIED FIXED`.

| المشكلة | Evidence قبل الإصلاح | الإصلاح | Evidence بعد الإصلاح | الحالة |
|---|---|---|---|---|
| صرف العملات بلا Permission | السطور 8-40 و8-37 | لم ينفذ | لا يوجد | STILL FAILED |
| حذف السند المرحّل | السطور 117-142 | لم ينفذ | لا يوجد | STILL FAILED |
| قبول قيد غير متوازن | اختبار DB Rollback | لم ينفذ | لا يوجد | STILL FAILED |
| فوارق الأرصدة | Balance IDs 285 و291 | لم ينفذ | لا يوجد | STILL FAILED |
| نقص Audit للحركات المرحّلة | 7 من 15 | لم ينفذ | لا يوجد | STILL FAILED |
| عزل الفروع | قبول `branch_id` من الطلب | لم ينفذ | لا يوجد | STILL FAILED |

## 19. Production Readiness Score

الدرجات التالية تعكس Evidence المتاح، وتخصم النقاط عند وجود فشل فعلي أو عدم قابلية تحقق في مجال حساس.

```text
Security:              48%
Authorization:         38%
Financial Integrity:   31%
Database Integrity:    42%
Auditability:          40%
Branch Isolation:      35%
Performance:           80%
Testing:               46%
```

`Performance` ليست محور هذه الجولة؛ الدرجة مبنية على اختبارات سابقة محدودة وليست Load Test إنتاجي.

### Critical Issues: 6

1. صرف العملات بدون Authentication/Permission موحد.
2. Unpost صرف العملات بدون Permission.
3. حذف حركات مالية مرحّلة مباشرة.
4. قبول قيد غير متوازن على مستوى Database.
5. وجود فوارق فعلية بين الأرصدة المخزنة وحركة القيود.
6. مسارات فواتير حساسة بلا Authorization موحد وعزل فرع مركزي.

### High Issues: 8

1. سبع حركات مرحّلة بلا Audit Log مرتبط.
2. جدول `financial_transaction_audit` بلا سجلات.
3. عدم وجود Foreign Keys مالية أساسية.
4. تجاوز دور accountant لصلاحية Reverse.
5. غياب Idempotency عام وغياب حماية موحدة من Double Submission.
6. عدم توحيد فحص الفترة المالية في جميع المسارات.
7. خطر الوصول إلى فرع آخر عبر ID/branch_id.
8. وجود تكرار في مصدر فاتورة.

### Medium Issues: 3

1. بعض GET Endpoints الحساسة تعيد HTTP 200 بدل رفض واضح.
2. `has_permission()` لا يتحقق من `users.status` في كل طلب.
3. Schema Drift بين dump يحتوي Triggers والمخطط العامل بلا Triggers.

### Low Issues: 0

## 20. FINAL DECISION

```text
🔴 NO-GO
```

القرار مبني على Evidence من الملفات، قاعدة البيانات، Schema، الأدوار، الصلاحيات، الخدمات، Endpoints، الحركات المالية، Audit Logs، الأرصدة، القيود، Transaction/ROLLBACK، واختبارات HTTP وPHP lint.

لا يمكن اختيار `READY FOR PRODUCTION` أو `CONDITIONALLY READY` مع بقاء مشاكل Critical وHigh غير مغلقة، ومع بقاء اختبارات الأدوار المصادق عليها غير قابلة للتحقق.
