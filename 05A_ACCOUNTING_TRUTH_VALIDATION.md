# 05A — ACCOUNTING TRUTH MODEL VALIDATION

**المرحلة:** PHASE 3A.1  
**النطاق:** Production — قراءة فقط  
**قاعدة البيانات المفحوصة:** `ghazali` عبر Web/PHP، MariaDB 10.11.14  
**القيود:** لم تُنفذ أي أوامر `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `DROP`, `TRUNCATE`, Migration, Rebuild أو Recalculate على Production.

## A. Source of Truth

الأدلة الحالية تثبت أن **`journal_lines` المرتبطة بحركات `financial_transactions` المرحّلة هي مصدر الحقيقة التفصيلي للحركة المحاسبية**. `financial_transactions` يملك حالة السند والمرجع والمبلغ والعملة، لكنه لا يكفي وحده لإعادة بناء طرفي القيد. `audit_logs` و`financial_transaction_audit` و`financial_transaction_logs` طبقات تتبع وليست بديلاً عن القيد.

أما `account_balances_unified` فالأدلة لا تثبته مصدراً مستقلاً للحقيقة. تعريف `sp_rebuild_balances` في SQL المستودع يعيد تحديثه من بنود القيود المرحّلة مع الاحتفاظ بـ`opening_balance`، كما أن PHP يطبّق عليه زيادات تفاضلية عبر `ON DUPLICATE KEY UPDATE`. لذلك يُعامل في هذه المرحلة كـ **Projection/Cache مع مكوّن افتتاحي محتمل**، وليس كسجل أصل مستقل.

| المصدر | ما يثبته | درجة الإثبات |
|---|---|---|
| `journal_lines` | الحساب المدين/الدائن، العملة، الفرع، الحركة التفصيلية | مثبت |
| `financial_transactions` | نوع المستند، الحالة، المرجع، التاريخ، المستخدم، المبلغ | مثبت |
| `account_balances_unified` | قيمة مجمعة مخزنة وقد تتضمن افتتاحية | مشتق/غير مستقل |
| `audit_logs` | أثر الإجراء أو المستند | مثبت جزئياً |
| `financial_transaction_audit` و`financial_transaction_logs` و`module_audit_log` | طبقات تدقيق إضافية | موجودة، تحتاج ربطاً موحداً |

## B. Journal Lifecycle

المسار الأساسي في `includes/accounting_functions.php` ينشئ `financial_transactions` بحالة `draft`، ثم ينشئ بنود `journal_lines`، ثم يستدعي `validate_journal_balance()`، ثم يحول الحالة إلى `posted` ويضع `posted_at=NOW()`، ثم يطبق تحديث الرصيد إذا لم توجد Triggers متخصصة للأرصدة [1].

| المسار | المصدر/المستند | المدين | الدائن | العملة/الصرف | الفرع | الحالة/الوقت | التدقيق |
|---|---|---|---|---|---|---|---|
| فاتورة بيع نقد/تحويل | `php_post_invoice`، `includes/accounting_functions.php:573-616` | صندوق/بنك للمقبوض، أو العميل للباقي | الإيراد | `invoice.currency_id`؛ الرصيد الأساسي يستخدم `currencies.exchange_rate` | `invoice.branch_id` | يبدأ Draft ثم Posted، `posted_at=NOW()` | المسار المالي/`log_audit` حسب caller |
| فاتورة بيع آجل | `php_post_invoice:597-608` | حساب العميل | الإيراد | عملة الفاتورة | فرع الفاتورة | Draft ثم Posted | تدقيق الترحيل |
| فاتورة شراء | `php_post_invoice:672-697` | حساب التكلفة | المورد | عملة الفاتورة | فرع الفاتورة | Draft ثم Posted | تدقيق الترحيل |
| سند قبض/صرف | `sp_post_receipt_voucher` و`sp_post_payment_voucher` | حسب نوع السند | الحساب المقابل | `currency_id` و`exchange_rate` من الحركة | `branch_id` | الإجراء يرحّل ويدعو تحديث الرصيد | يكتب `audit_logs` وفق SQL المستودع |
| صرف/قبض AJAX | `admin/ajax_post_exchange.php` | من بنود القيد الموجودة | من بنود القيد الموجودة | من الحركة | من الحركة | يتحقق من Draft ثم يضع Posted و`posted_at` | `log_audit` بعد الترحيل |
| عكس سند | `admin/ajax/reverse_voucher.php:118-165` | يبدل Debit/Credit لكل سطر أصلي | العكس التام | ينسخ `currency_id` و`exchange_rate` | ينسخ `branch_id` | ينشئ Draft، ثم Posted للعكس ويضع الأصل Reversed | `log_audit` في نهاية المسار |

## C. Balance Lifecycle

يوجد مساران فعليان لتغيير `account_balances_unified`:

1. **PHP incremental path:** `apply_transaction_balances(PDO, financialTransactionId, direction)` يقرأ فرع الحركة، ويستخدم fallback إلى الفرع 1 إذا كان `branch_id` فارغاً، ثم يجمع بنود القيد حسب الحساب والعملة، ويطبق `normal_balance`, ثم يضرب الحركة في `currencies.exchange_rate`، ويحدث الرصيد عبر `ON DUPLICATE KEY UPDATE` [1].
2. **Stored procedure rebuild path:** `sp_rebuild_balances()` ينشئ جدولاً مؤقتاً، ويجمع كل `journal_lines` المرتبطة بحركات `status='posted'`، ثم يحسب `SUM(debit-credit)` حسب الحساب والعملة فقط، ويضيف الحركة إلى `opening_balance`، دون تجميع أو ربط حسب `branch_id` [2].

الصفحات `admin/branches.php`, `customers.php`, `employees.php`, `financial_accounts.php`, `manage_banks.php`, `manage_boxes.php`, `manage_currency_balances.php`, `manage_expenses.php`, و`suppliers.php` تنشئ صفوف أرصدة أولية أو حدوداً حسابية. هذه ليست حركات محاسبية بحد ذاتها، لكنها تثبت أن الجدول يُستخدم أيضاً كحاوية افتتاحية/إعدادات. لا يوجد Trigger من الستة المفحوصة يكتب مباشرة إلى `account_balances_unified`؛ Triggers الحالية تتحقق من قيم `financial_transactions`, `invoices`, و`journal_lines`.

## D. Reversal Policy

السياسة الحالية المثبتة من الكود هي: إذا كان السند الأصلي `posted` وكان الإلغاء المباشر غير مسموح، ينشئ النظام سنداً عكسياً جديداً بحالة Draft، ينسخ `branch_id`, `currency_id`, `exchange_rate`, `party_account_id`, `cash_bank_account_id`, ثم ينسخ كل `journal_lines` مع تبديل `debit` و`credit`. بعد التحقق من التوازن، يضع السند العكسي `posted` ويطبق عليه `apply_transaction_balances(..., 1)`, ثم يضع الأصل `reversed` و`is_reversed=1` [3].

بالتالي، من منظور مسار PHP الحالي:

| السؤال | النتيجة المثبتة |
|---|---|
| هل الأصل Reversed يدخل كحركة Posted في إعادة احتساب الإجراء؟ | لا؛ `sp_rebuild_balances` يشترط `ft.status='posted'`، والأصل يصبح `reversed`. |
| هل العكس Posted يدخل؟ | نعم؛ العكس يظل `posted` وتدخل بنوده في إعادة الاحتساب. |
| هل الأصل والعكس موجودان معاً؟ | نعم؛ الأصل موجود بحالة `reversed` والعكس Posted. |
| هل الرصيد يعتمد الأصل والعكس معاً؟ | في PHP incremental path، الأصل يُنقص عند الإلغاء المباشر أو لا يُطبق مجدداً، والعكس يُطبق. في Stored Procedure path، الأصل Reversed مستبعد والعكس يدخل. |
| هل السياسة متطابقة في كل المسارات؟ | غير مثبتة بالكامل؛ توجد مسارات إلغاء/حذف/Unpost متعددة تستدعي direction `-1` وقد تختلف عن الإجراء التجميعي. |

الحركتان 405 و407 تثبتان أن السند العكسي يمكن أن يكون `posted` مع `posted_at=NULL` في البيانات الحالية، وهي فجوة Auditability وليست سبباً يسمح بتعديل مباشر.

## E. Opening Balance Policy

يوجد `opening_balance` و`opening_balance_base` في `account_balances_unified`. تعريف `sp_ensure_opening_balance` في SQL المستودع ينشئ الصف أو يحدّث الافتتاحية ثم يستدعي `sp_rebuild_balances`. هذا يثبت أن التصميم **يسمح** برصيد افتتاحي فعلي، لكنه لا يثبت أن كل قيمة موجودة حالياً افتتاحية موثقة.

في صفوف الفروقات الستة عشر، كل القيم المرصودة لـ`opening_balance` و`opening_balance_base` كانت صفراً. كما لم يظهر جدول مستقل للرصيد الافتتاحي من فحص أسماء أعمدة القاعدة. لذلك تصنيف السياسة الحالية هو: **Actual Opening Balance capability exists, but the observed differences are not explained by non-zero opening balances**.

## F. Branch Policy

الكود لا يطبق سياسة واحدة. `apply_transaction_balances()` يستخدم `financial_transactions.branch_id`، لكنه يضع الفرع 1 عند غياب القيمة. في المقابل، `sp_rebuild_balances()` يجمع حسب الحساب والعملة ولا يستخدم `branch_id` في جدول الحركة المؤقت أو في الربط النهائي. كما توجد حركات مالية فعلية بـ`branch_id=NULL`، منها 404 و405 و406 و407 و429 و432.

لا يوجد دليل كافٍ لتقرير أن `NULL` يعني الفرع الرئيسي أو الفرع العام أو الحركة المركزية. وجود فرع واحد في قاعدة البيانات لا يكفي للحكم. لذلك الحالة الصحيحة هي **UNKNOWN / requires explicit branch policy**، ولم يتم تحديث أي `branch_id`.

## G. Currency Policy

بنود القيد تحمل `currency_id` و`journal_lines`، والحركة تحمل `currency_id` و`exchange_rate`. PHP يستخدم `MAX(c.exchange_rate)` من جدول العملات عند تحديث الرصيد الأساسي، بينما `sp_rebuild_balances` يستخدم `currencies.exchange_rate`، وليس بالضرورة قيمة `financial_transactions.exchange_rate`. هذا اختلاف مثبت في مصدر سعر الصرف.

في الحساب 164/SAR، الرصيد الأصلي المخزن 8,100 مقابل `current_balance_base=1,134,000`، ما يثبت أن التحويل الأساسي محفوظ بقيمة مختلفة عن الرقم الأصلي، لكن الفحص الحالي لا يثبت أن هذا وحده يفسر فرق 9,000. يلزم اختبار قاعدة العملة في Staging قبل اعتماد الصيغة.

## H. Balance Formula

المعادلة المثبتة للإجراء المخزن هي:

```text
sp_rebuild_balances = opening_balance + SUM(posted journal debit - credit)
```

أما معادلة PHP فهي:

```text
PHP delta =
  debit - credit للحسابات normal_balance=debit
  credit - debit للحسابات normal_balance=credit

new balance = old current_balance + PHP delta × direction
new base balance = old current_balance_base + PHP delta × exchange_rate
```

لا يمكن اعتماد معادلة إنتاج واحدة بعد، لأن المعادلتين لا تتطابقان في `normal_balance`, `branch_id`, ومصدر `exchange_rate`. `account_balances_unified` يجب أن يبقى Projection/Cache إلى أن تُحسم هذه الاختلافات.

## I. PHP vs Stored Procedure Differences

| البعد | PHP `apply_transaction_balances()` | `sp_rebuild_balances()` | التقييم |
|---|---|---|---|
| إشارة Debit/Credit | يحترم `normal_balance` | `debit-credit` للجميع | اختلاف محاسبي حرج |
| `normal_balance` | نعم | لا | اختلاف مثبت |
| الفرع | يستخدم الحركة، fallback 1 | لا يميز الفرع | Contamination risk |
| العملة | `jl.currency_id` | `jl.currency_id` | متفق جزئياً |
| سعر الصرف | `currencies.exchange_rate` في PHP الحالي | `currencies.exchange_rate` | الإجراء متفق جزئياً، لكن الحركة تحمل rate مستقل |
| الرصيد الافتتاحي | لا يغير الافتتاحية عند الزيادة | يضيف الحركة إلى الافتتاحية | متفق في الهدف، مختلف في التنفيذ |
| Reversal | `direction=-1` لمسارات الإلغاء و`+1` للعكس | يستبعد الأصل Reversed ويدخل العكس Posted | يحتاج سياسة موحدة |
| الحالة | caller يغير Draft إلى Posted قبل التطبيق | الإجراء يشترط Posted في التجميع | متفق مبدئياً |
| تاريخ الحركة | بعض المسارات تستخدم transaction date | التجميع لا يفرض تاريخاً داخل فترة | فجوة رقابية |
| وقت الترحيل | PHP يضعه في المسارات الحديثة | بعض البيانات 405/407 Posted و`posted_at=NULL` | Audit gap |
| Idempotency | `ON DUPLICATE KEY` يزيد الرصيد؛ يحتاج حارس caller | rebuild تجميعي أكثر قابلية للإعادة | PHP retry risk |
| Transaction boundary | يلتزم بمعاملة caller | الإجراءات تستخدم transaction/commit حسب المسار | يحتاج توحيد |
| Audit | callers/services تسجل، مع 39 حركة بلا أثر مباشر مطابق | الإجراءات تكتب `audit_logs` في مساراتها | coverage غير متساوٍ |

استدعاءات PHP المرصودة تشمل الفواتير، العكس، الإلغاء، Unpost، التبادل، خدمات العائلة والبريد وتأشيرات العمل، بالإضافة إلى `invoices.php`. استدعاءات SQL المرصودة تشمل `sp_post_invoice`, `sp_post_receipt_voucher`, `sp_post_payment_voucher`, `sp_unpost_invoice`, `sp_ensure_opening_balance`, و`sp_update_account_balances`.

## J. Evidence for Each Conclusion

| النتيجة | الدليل |
|---|---|
| `journal_lines` مصدر تفصيلي | `includes/accounting_functions.php:578-608`, وارتباطها بـ`financial_transactions` |
| PHP يطبق normal_balance | `includes/accounting_functions.php:60-75` |
| PHP يستخدم الفرع fallback 1 | `includes/accounting_functions.php:54-57` |
| PHP يحدث الرصيد تفاضلياً | `includes/accounting_functions.php:83-118` |
| الإجراء يعيد البناء من Posted | `tools/database/alghazali.sql:1896-1931` |
| الإجراء لا يميز الفرع | تجميع `tmp_rb_movements` حسب account/currency فقط |
| العكس يبدل Debit/Credit | `admin/ajax/reverse_voucher.php:143-155` |
| العكس Posted والأصل Reversed | `admin/ajax/reverse_voucher.php:158-195` |
| وجود Audit في مسار العكس | `admin/ajax/reverse_voucher.php:209-215` |
| 16 صفاً مختلفاً | `root_cause_findings.json` و`ACCOUNT_BALANCE_ROOT_CAUSE_REPORT_20260814.md` |
| الحساب 168 فرق 250 | الحركة 433 الأصلية والحركة 456 العكسية في نتائج PHASE 3A |
| الحساب 5/YER فرق 50,000 | لا افتتاحية غير صفرية ولا حركة حالية تفسر 50,000 في نتائج القراءة |
| عدم وجود مصدر تاريخي داخل المستودع | البحث في SQL/backup/archive/migration/restore/scripts لم يجد مبلغاً مستقلاً؛ ظهرت المبالغ داخل التقارير نفسها فقط |

## K. Unknowns

لا يوجد دليل كافٍ حتى الآن على مصدر 50,000 YER للحساب 5، أو 30,000 YER للحساب 164، أو 9,000 SAR للحساب 164. كما لم تُحسم دلالة `branch_id=NULL`، ولم تثبت طبيعة كل سجل من سجلات الاختبار، ولم يُثبت أن كل 39 حركة بلا `audit_logs` مباشر بلا أثر في جداول التدقيق الأخرى.

كذلك، صلاحية Web/PHP لم تسمح بإظهار جسم بعض الإجراءات عبر `SHOW CREATE PROCEDURE`، لذلك استُخدم تعريف SQL الموجود في المستودع كدليل تنفيذ، مع تصنيفه كدليل مخطط لا كإثبات أن نسخة Production مطابقة حرفياً.

## L. Required Evidence

لتحويل حالات `UNKNOWN` إلى أسباب مؤكدة، يلزم جمع الأدلة التالية دون تعديل Production:

| السؤال | الدليل المطلوب |
|---|---|
| 50,000 YER | نسخة قاعدة زمنية قبل 2026-07-24، backup/archived ledger، أو log إعادة بناء يذكر الحساب 5/العملة 3 |
| 30,000 YER | أرشيف الحساب 164/YER أو سجل افتتاح/ترحيل سابق |
| 9,000 SAR | سجل حركة أو أرشيف الحساب 164/SAR مع exchange rate وbase amount |
| الحساب 168/250 | اختبار Staging يعيد سيناريو 433/456 مع مقارنة الأصل والعكس قبل وبعد |
| `branch_id=NULL` | إعداد فروع، وثائق سياسة، ومستندات أصلية للحركات 404–407 و429 و432 |
| Audit gaps | ربط موحد بين `audit_logs`, `financial_transaction_audit`, `financial_transaction_logs`, `module_audit_log` |
| Stored procedures | `SHOW CREATE` من حساب قراءة مخول أو Schema dump مطابق لنسخة Production |

## M. Production Risks

| الخطر | الشدة | السبب |
|---|---|---|
| مصدر حقيقة غير موحد | Critical | PHP والإجراء يحسبان الرصيد بصيغ مختلفة |
| Retry/double balance update | High | PHP يزيد الرصيد عبر `ON DUPLICATE KEY` ويعتمد على حارس caller |
| فرع ملوث/مفقود | High | PHP fallback إلى 1 والإجراء يتجاهل الفرع |
| سياسة عكس غير موحدة | High | مسارات العكس والإلغاء وUnpost متعددة |
| سجل تدقيق غير مكتمل | High | 39 حركة بلا أثر مباشر مطابق و`posted_at=NULL` لعكسين |
| تاريخ أرصدة غير مفسر | Critical | فروقات 50,000 و30,000 و9,000 |
| Schema/procedure drift | High | SQL المستودع لا يضمن مطابقة نسخة Production دون snapshot |

## القرار النهائي

# TRUTH MODEL PARTIALLY CONFIRMED

تم إثبات أن القيود المرحّلة هي مصدر الحقيقة التفصيلي، وأن `account_balances_unified` Projection/Cache مع افتتاحية محتملة، كما تم إثبات مسار العكس ومسارات التحديث الأساسية. لكن لم يتم إثبات معادلة إنتاج موحدة، أو مصدر الفروقات التاريخية، أو سياسة الفرع والعملة النهائية. لذلك لا يبدأ أي إصلاح أو Rebuild أو Migration.

## المراجع

[1]: ./includes/accounting_functions.php "مسارات PHP لإنشاء القيود وتحديث الأرصدة"

[2]: ./tools/database/alghazali.sql "تعريفات الإجراءات المخزنة وإعادة بناء الأرصدة"

[3]: ./admin/ajax/reverse_voucher.php "مسار عكس السند وتسجيل التدقيق"

[4]: ./root_cause_findings.json "نتائج PHASE 3A القراءة فقط"
