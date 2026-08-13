# PHASE 3B.1 — PRE-FIX DESIGN & IMPACT ANALYSIS

**التاريخ:** 2026-08-14  
**النطاق:** تحليل ساكن وقراءة فقط على الكود وSnapshot/Staging  
**Production:** لا توجد موافقة على أي تغيير، ولم تُنفذ أي كتابة أو Migration أو Rebuild أو تعديل رصيد.  
**الحكم:** **DESIGN ONLY — NO PRODUCTION CHANGE AUTHORIZED**

> هذا التقرير يحدد التصميم المقترح والأثر المتوقع قبل الإصلاح. لا يُعد تفويضاً لتنفيذ أي تغيير مالي.

## 1. حدود المرحلة ومصادر الأدلة

اعتمد التحليل على `includes/accounting_functions.php`، ونقاط AJAX والإدارة التي تستدعي محرك الأرصدة، وتعريفات الإجراءات المخزنة في `tools/database/alghazali.sql`، وملفات Migration، وSnapshot Production المحفوظ خلال PHASE 3B. كما تمت مراجعة نتيجة اختبار Staging السابقة التي أثبتت سلوك `600 → 350` عند التطبيق الأول و`600 → 100` عند التطبيق الثاني.

المرفق المقدم لهذه المرحلة ينتهي عند بداية قسم Audit في السطر 202؛ لذلك يغطي هذا التقرير جميع المتطلبات الظاهرة حتى تلك النقطة، مع استكمال تحليل Audit المتاح من الكود وSnapshot والتقارير السابقة، دون افتراض متطلبات غير موجودة في الجزء المقروء.

## 2. حصر جميع استدعاءات `apply_transaction_balances()`

الدالة معرفة في `includes/accounting_functions.php:46`، وتُستدعى من المسارات التالية:

| الموضع | الاتجاه | التصنيف التشغيلي | الملاحظات |
|---|---:|---|---|
| `includes/accounting_functions.php:615` | +1 | Posting | ترحيل فاتورة شراء وإنشاء الحركة والبنود ثم تحديث الرصيد |
| `includes/accounting_functions.php:696` | +1 | Posting | مسار ترحيل مالي داخلي مشابه، محكوم بحارس Triggers |
| `includes/accounting_functions.php:1261` | +1 | Reversal | إدخال أثر الحركة العكسية |
| `includes/accounting_functions.php:1265` | -1 | Cancellation/Unpost | إزالة أثر الحركة الأصلية عند الإلغاء أو العكس |
| `includes/accounting_functions.php:1641` | +1 | Reversal | إنشاء وتطبيق القيد العكسي في مسار إلغاء وعكس العملية |
| `includes/accounting_functions.php:1645` | -1 | Cancellation | إزالة أثر الأصل قبل تغيير حالته إلى `cancelled` |
| `includes/accounting_functions.php:1856` | +1 | Manual Posting | ترحيل Voucher يدوي من حالة Draft/Approved إلى Posted |
| `includes/accounting_functions.php:2027` | +1 | Manual Posting | مسار Voucher آخر يدعم حالات Draft/Approved/Pending Approval |
| `admin/ajax/delete_voucher.php:153` | -1 | Cancellation/Delete | إزالة أثر سند قبل حذف/إلغاء السند؛ خطر مرتفع إذا لم يكن Posted أو إذا تكرر الطلب |
| `admin/ajax/reverse_voucher.php:163` | +1 | Reversal/AJAX | تطبيق الحركة العكسية الجديدة |
| `admin/ajax/reverse_voucher.php:173` | -1 | Reversal/AJAX | إزالة أثر الأصل بعد إنشاء العكس |
| `admin/ajax/unpost_voucher.php:56` | -1 | Unpost/AJAX | Posted إلى Draft مع إزالة الأثر |
| `admin/ajax_family_visit.php:79` | -1 | Cancellation/AJAX | إزالة أثر الحركة المرتبطة بالزيارة العائلية |
| `admin/ajax_post_exchange.php:51` | +1 | Posting/AJAX | ترحيل حركة صرف/تبادل |
| `admin/ajax_postal_services.php:89` | -1 | Cancellation/AJAX | إزالة أثر حركة خدمة بريدية |
| `admin/ajax_unpost_exchange.php:38` | -1 | Unpost/AJAX | إلغاء ترحيل حركة تبادل |
| `admin/ajax_work_visa.php:181` | -1 | Cancellation/AJAX | إزالة أثر Voucher تأشيرة العمل قبل إلغاء/إعادة ضبطه |
| `admin/ajax_work_visa.php:329` | -1 | Cancellation/AJAX | إزالة أثر Voucher مرتبط بفواتير التأشيرة |
| `admin/invoices.php:1145` | -1 | Cancellation/Invoice reset | إزالة الأثر مع إبقاء الدليل المحاسبي وعدم حذف السطور المرحّلة |
| `admin/invoices.php:1587` | -1 | Delete/Invoice cleanup | إزالة الأثر ثم حذف التخصيصات والسطور والحركة في مسار قديم/محدود |

كما توجد استدعاءات `sp_rebuild_balances()` في `run_rebuild.php` وأدوات الاختبار والإصلاح، واستدعاءات `sp_update_account_balances()` في `tools/database/prepare_finance_test.php` وعمليات التحقق. لم يظهر من الحصر مسار Background Job مالي مستقل يستدعي الدالة مباشرة؛ الأدوات CLI/الإدارية تعتبر Manual/Test وليس مساراً تشغيلياً للمستخدم النهائي.

## 3. هل يمكن أن يصل نفس `financial_transaction_id` أكثر من مرة؟

نعم. توجد أربعة مسارات مؤكدة للتكرار المحتمل:

| مصدر التكرار | النتيجة المحتملة |
|---|---|
| إعادة إرسال طلب AJAX أو النقر المزدوج | تنفيذ نفس `+1` أو `-1` مرة ثانية قبل أن تمنع الحالة الطلب |
| Retry من المتصفح أو Reverse Proxy بعد مهلة اتصال | قد يكون الطلب الأول نجح بينما لم تصل استجابته؛ إعادة الطلب تضاعف الأثر |
| انتقال داخلي بين Service وEndpoint | يمكن أن يطبق Service التحديث ثم يطبقه Endpoint أو مسار الفاتورة مرة أخرى إذا لم يكن هناك قفل موحد |
| انتقال الحالة ثم استدعاء مسار آخر | Unpost/Cancel/Delete أو Reverse قد يستقبل نفس الحركة من أكثر من صفحة أو AJAX |

حارس `balances_triggers_enabled()` يمنع استدعاء PHP عندما تكون Triggers فعالة، لكنه لا يسجل أن الحركة طُبقت ولا يضمن Idempotency عندما تكون Triggers معطلة. كما أن `idempotency_key` في Migration يحمي إنشاء الحركة/الفاتورة، لكنه لا يحمي تطبيق الرصيد للدالة الموجودة. لذلك فالفشل المثبت في اختبار `600 → 350 → 100` قابل للتفسير بنيوياً، ولا ينبغي الاعتماد على حالة الواجهة وحدها لمنعه.

## 4. مقارنة نماذج محرك الأرصدة

| المعيار | Incremental | Full Rebuild | Hybrid |
|---|---|---|---|
| Accounting correctness | يعتمد على كل حدث وعدم تكراره؛ معرض لتراكم الخطأ | قوي إذا كان Ledger كاملاً والحالات محددة | قوي إذا كان Rebuild مرجعاً وIncremental guarded |
| Idempotency | ضعيف افتراضياً | طبيعي؛ التجميع يعيد نفس الناتج | قوي بعد تسجيل event/application key |
| Reversal | يحتاج إزالة دقيقة للأصل وإضافة العكس | يعتمد على Posted/Reversed policy واضحة | نفس منطق Rebuild مع تحديث سريع للعكس |
| Concurrency | يحتاج row locks وversioning | يحتاج نافذة أو Snapshot متسق | Locks للأحداث، وRebuild مجدول/مراقب |
| Performance | ممتاز للعمليات الصغيرة | أثقل مع نمو البيانات | أفضل توازن للإنتاج |
| Recovery | صعب إذا فُقد حدث أو تكرر | سهل نسبياً بإعادة البناء | سريع يومياً مع Rebuild تحقق دوري |
| Auditability | جيدة فقط مع event log كامل | ممتازة إذا كان Ledger immutable | ممتازة مع سجل تطبيق مستقل |
| Multi-currency | حساس لمصدر سعر الصرف | يعيد الحساب من السعر التاريخي المخزن | يجمع السرعة وإعادة التحقق |
| Multi-branch | يحتاج مفتاح فرع في كل عملية | يجب أن يجمع حسب الفرع | يدعم الفرع مع Rebuild تحقق |
| Opening balances | يحتاج معالجة افتتاحية دقيقة | يضيف افتتاحية مع تعريف رسمي | الأفضل إذا كانت الافتتاحية Versioned |

### النموذج المختار: Hybrid Controlled Ledger Projection

النموذج المقترح الوحيد هو **Hybrid**. مصدر الحقيقة هو `journal_lines` التابعة لحركات مالية غير قابلة للتعديل بعد الترحيل. التحديث السريع مسموح فقط عبر Event/Application Log فريد لكل `financial_transaction_id + operation + version`، مع تحقق من الحالة وقفل الصف. ويظل `Full Rebuild` هو مرجع المصالحة الدوري، ويجب أن ينتج نفس القيمة التي أنتجها التحديث التفاضلي. إذا اختلفا، يتوقف الترحيل ويُفتح استثناء Audit بدلاً من تصحيح صامت.

## 5. الصيغة المحاسبية الرسمية المقترحة

التعريفات الأساسية هي أن **Debit** زيادة في الأصول والمصروفات، ونقص في الالتزامات وحقوق الملكية والإيرادات؛ و**Credit** هو العكس. أما **Normal Balance** فهو الجانب الذي تزيد به طبيعة الحساب عادة.

| نوع الحساب | Normal Balance | الزيادة الطبيعية | Signed Balance المقترح |
|---|---|---|---|
| Asset | Debit | Debit | `debit - credit` |
| Expense | Debit | Debit | `debit - credit` |
| Liability | Credit | Credit | `credit - debit` |
| Equity | Credit | Credit | `credit - debit` |
| Revenue | Credit | Credit | `credit - debit` |

الصيغة الرسمية الموحدة التي يجب أن يستخدمها PHP وStored Procedure معاً هي:

```text
signed_movement =
    CASE WHEN normal_balance = 'debit'
         THEN debit - credit
         ELSE credit - debit
    END

current_balance = opening_balance + SUM(signed_movement)
current_balance_base = opening_balance_base + SUM(signed_movement * historical_exchange_rate)
```

هذه الصيغة ليست تفويضاً بتطبيقها الآن؛ هي مواصفة تصميم تحتاج اختباراً شاملاً على دليل الحسابات قبل اعتماد Migration. ولا يجوز استخدام `debit-credit` لكل الحسابات من دون قراءة `normal_balance`، كما لا يجوز افتراض أن كل حساب يحمل قيمة صحيحة في الحقل الحالي قبل فحص Chart of Accounts.

## 6. سياسة العكس الرسمية

السياسة المقترحة غير القابلة للتأويل هي:

```text
Original Posted  → Reversed
Reversal         → Posted
```

في `financial_transactions` يحتفظ الأصل بمعرفه وحالته `reversed`، ويرتبط بـ`reversal_voucher_id`. تُنشأ حركة جديدة للعكس بحالة `posted`، وتحمل `original_voucher_id` للأصل. لا يجوز حذف الأصل أو تعديل مبلغ أو عملة أو فرع حركة Posted.

في `journal_lines` تبقى سطور الأصل كما هي كدليل غير قابل للتعديل، وتُنشأ سطور جديدة للعكس بتبديل Debit وCredit مع الاحتفاظ بالعملة والفرع وسعر الصرف التاريخي نفسه. الأصل Reversed لا يدخل Full Rebuild، والعكس Posted يدخل مرة واحدة.

في `account_balances_unified` يجب أن تُحسب النتيجة من الحالة النهائية للـLedger: الأصل Reversed مستبعد، والعكس Posted داخل. ويجب أن يساوي Full Rebuild نتيجة Incremental Update. لا يسمح بتطبيق `-1` على الأصل ثم `+1` للعكس إلا مع سجل تطبيق فريد يثبت أن كل عملية حدثت مرة واحدة؛ وإلا تصبح إعادة الطلب خطرة.

## 7. Currency Policy

المصدر الرسمي المقترح عند إنشاء الحركة هو **سعر صرف تاريخي يُلتقط في نفس لحظة الترحيل ويُخزن على الحركة/القيد**. `currencies.exchange_rate` هو جدول مرجعي حالي أو آخر سعر، ولا يجوز استخدامه لإعادة بناء حركة قديمة إذا تغير بعد أشهر. `journal_lines.currency_id` يحدد عملة المبلغ المدين/الدائن، بينما `financial_transactions.exchange_rate` يجب أن يصبح السعر التاريخي الملزم للحركة. إذا كان هناك سعر مختلف لكل سطر، فيجب تخزينه على مستوى السطر؛ ولا يُسمح بمزج المصدرين بصمت.

| السؤال | السياسة المقترحة |
|---|---|
| مصدر السعر عند الإنشاء | مصدر معتمد يُقرأ مرة واحدة ويُحفظ تاريخياً |
| تاريخي أم حالي | تاريخي لكل حركة مرحّلة |
| تغييره بعد الترحيل | ممنوع؛ التصحيح عبر عكس وإعادة إصدار |
| Base amount | `foreign_amount × historical_exchange_rate` مع اتجاه العملة المعتمد |
| Reversal | يستخدم نفس السعر التاريخي للأصل، لا سعر اليوم |
| Rebuild بعد أشهر | يقرأ السعر المخزن في الحركة/السطر، ولا يعيد قراءة السعر الحالي |

الدليل الحالي يثبت وجود تعارض: `financial_transactions.exchange_rate=1.000000` بينما `currencies.exchange_rate=140.0000` لـSAR في Snapshot. لذلك لا يجوز اعتماد أي مصدر نهائياً قبل معالجة تاريخ الأسعار وتحديد ما إذا كان `1` يمثل سعر النظام الأساسي أو قيمة افتراضية خاطئة.

## 8. Branch Policy

التصميم المقترح هو أن `branch_id=NULL` لا يعني تلقائياً Branch 1. يجب تعريفه صراحة كـ**Corporate/General** إذا كانت الحركة مركزية، أو رفض الترحيل إذا كانت الخدمة تتطلب فرعاً.

| الحالة | السياسة |
|---|---|
| حركة مركزية حقيقية | `branch_id=NULL` مسموح مع كود Corporate واضح في التقارير |
| خدمة فرعية أو صندوق فرع | `branch_id` إلزامي، وNULL يرفض قبل الترحيل |
| التقارير | تعرض Corporate/General في مجموعة مستقلة، لا تدمجه في Branch 1 |
| Full Rebuild | يجمع حسب `(account_id, currency_id, branch_id)` ويحافظ على NULL ككيان مستقل |
| Backfill | ممنوع قبل اعتماد تعريف كل حركة NULL ومصدرها |

هذا التصميم يمنع تحويل الحركات الست ذات `branch_id=NULL` إلى الفرع 1 دون دليل.

## 9. Account 5 / YER — 50,000

لا يُقترح أي قيد أو تعديل. الأدلة المفقودة التي يجب طلبها، إن كانت متاحة لدى مسؤول الخادم، هي:

| المصدر | ما الذي يمكن أن يثبته | الحالة الحالية |
|---|---|---|
| MariaDB binary logs | INSERT/UPDATE/DELETE التاريخي ووقت التغيير | يحتاج صلاحية DBA واحتفاظاً زمنياً مناسباً |
| General log | SQL الوارد أثناء تفعيله | لا دليل أنه كان مفعلاً |
| Application logs | مسار المستخدم والطلب والـexception | موجودة جزئياً، تحتاج مطابقة زمنية |
| Web server logs | endpoint/IP/وقت الطلب | يحتاج وصول الخادم |
| `audit_logs` | تغييرات موثقة من التطبيق | لا يثبت وحده مصدراً غير مسجل |
| Filesystem backups | Snapshot سابق للقيمة | لم يظهر Backup تاريخي مؤكد داخل المستودع |
| Old database dumps | Ledger قبل الفرق | لم يظهر Dump تاريخي مستقل |
| Git history | SQL/fixtures أو كود يولد المبلغ | لا يثبت وحده كتابة Production |
| Archived databases | مقارنة أرصدة سابقة | غير متاحة في البيئة الحالية |

النتيجة الحالية تبقى **UNKNOWN**. لا يجوز استنتاج أن 50,000 هو افتتاحية أو حركة محذوفة أو قيد يدوي دون سجل خارجي يثبت ذلك.

## 10. Account 164 — تحليل منفصل

### 10.1 فرق 30,000 YER

يوجد دليل مباشر في Snapshot: الحركة `427` برقم `INV-26-00003`، مرتبطة بالفاتورة `SI-000009` رقم 368، ومبلغها 30,000 بعملة YER. كما توجد سطور القيد `855` للحساب 164 مديناً 30,000 و`856` لحساب الإيراد 39 دائناً 30,000، وكلاهما مرتبط بالحركة 427.

إذن مصدر مبلغ 30,000 **قابل للتتبع إلى حركة محاسبية محددة**، وليس مبلغاً مجهول المصدر. لكن الفرق بين الرصيد المخزن 35,500 والحركة الحالية المحسوبة سابقاً 5,500 لا يثبت أن الحركة 427 مفقودة؛ بل يثبت أن مسار Projection أو نطاق الحالات أو إعادة البناء لا يعرضها بنفس السياسة. الافتتاحية صفر، ولا يظهر عكس للحركة 427، ولا توجد إشارة إلى سعر صرف يفسر فرقاً بالقيمة نفسها. لا يجوز تعديل الرصيد قبل فحص حالة 427 في Production ومطابقة آخر Projection.

### 10.2 فرق 9,000 SAR

يوجد دليل جمعي مباشر في Snapshot للحساب 164 بعملة SAR:

| الحركة | المبلغ المدين |
|---:|---:|
| 408 | 900 |
| 410 | 4,750 |
| 423 | 900 |
| 425 | 900 |
| 430 | 900 |
| 457 | 650 |
| **المجموع** | **9,000** |

الرصيد المخزن 8,100 لا يساوي هذا المجموع، بينما الافتتاحية صفر. لا يظهر تفسير من سعر الصرف لأن الفرق نفسه 9,000 بعملة SAR، ولا يوجد دليل عكسي جامع لهذه الحركات. النتيجة: **مصدر 9,000 قابل للتتبع إلى ست حركات Ledger، لكن سبب عدم ظهوره في Projection هو State/Scope/Balance-policy mismatch وليس مصدراً مجهولاً**. لا يتم تعديل الرصيد.

## 11. Account 168 / 250 SAR — الإثبات الرياضي

وفق نتيجة Staging السابقة:

```text
Stored = 600 SAR
SP Rebuild = 850 SAR
Difference = 850 - 600 = 250 SAR
```

الـ250 يطابق الحركة الأصلية 433 والعكس 456:

| الحركة | الحالة | حساب 168 |
|---|---|---:|
| 433 | Reversed | Credit 250 |
| 456 | Posted | Debit 250 |

في Full Rebuild، الأصل 433 يُستبعد لأنه Reversed، والعكس 456 يدخل لأنه Posted؛ لذلك يظهر أثر 250 في نتيجة الإجراء مقارنة بالرصيد المخزن. أما PHP الحالي، فعند استدعاء `apply_transaction_balances(433, +1)` على نفس معرف الأصل، يعالج أثر سطور الأصل تفاضلياً رغم أن الحركة Reversed، فانتقل الاختبار من 600 إلى 350. عند تكرار الاستدعاء انتقل من 350 إلى 100، أي إن كل استدعاء إضافي أضاف أثراً قدره -250 مرة أخرى.

بالتالي، سبب الفرق ليس خطأً حسابياً في جمع 433/456 معاً، بل اختلاف في **ما إذا كان التطبيق يعالج الأصل حسب سطوره أم حسب حالته النهائية**. يجب أن يقرأ Incremental Engine الحالة الحالية، ويعالج العكس كحدث مستقل مرة واحدة، وإلا فلن يتطابق مع Full Rebuild.

## 12. Audit Design Analysis

المعمارية الحالية تحتوي على حارس Triggers، وجداول `audit_logs` و`financial_transaction_audit` و`financial_transaction_logs`، لكن Snapshot السابق أظهر 1,531 في `audit_logs` وصفر في جدولي التدقيق المالي المنظمين. كما ظهر 12 سنداً Posted بلا `posted_at` أو `posted_by` في اختبار Staging المستعاد.

التصميم المطلوب قبل الإصلاح هو سجل واحد موحد لكل حدث مالي، يتضمن `transaction_id`, `operation`, `before_status`, `after_status`, `direction`, `user_id`, `request_id`, `idempotency_key`, `created_at`, ونتيجة التطبيق. لا يكفي تسجيل تغيير الحالة فقط؛ يجب تسجيل تطبيق الأثر على Projection، ورفض التكرار، والعكس، وUnpost، وسبب الفشل. لا يتم Backfill أو إنشاء سجلات مصطنعة قبل اعتماد سياسة الاحتفاظ ومصدر البيانات.

## 13. Impact Analysis Before Fix

| التغيير المقترح لاحقاً | الجداول المتأثرة | الخطر | شرط التنفيذ |
|---|---|---|---|
| Guarded Incremental Engine | `account_balances_unified` وسجل التطبيق | مضاعفة/نقص الأرصدة | اختبار تزامن وRollback |
| توحيد Formula | PHP وSP وReports | تغيير أرصدة كثيرة | مصالحة قبل/بعد لكل حساب وعملة وفرع |
| تثبيت Historical FX | `financial_transactions`/`journal_lines` | تغيير Base balances | اعتماد سعر تاريخي وعدم تعديل Posted |
| تعريف Branch NULL | الحركات والتقارير | نقل أرصدة بين الفروع | Mapping معتمد لكل حركة |
| توحيد Reversal | `financial_transactions`/`journal_lines` | عكس مزدوج | قفل فريد للأصل والعكس |
| Audit Consolidation | جداول التدقيق | فقدان أثر تاريخي أو تكراره | Snapshot وRetention وTest |

## 14. التصميم التنفيذي المقترح للمرحلة التالية، دون تنفيذه الآن

أولاً، يُنشأ Event/Application Ledger فريد ويُمنع تطبيق الحركة إذا كان نفس الحدث قد طُبق سابقاً. ثانياً، تُوحّد القراءة حول الحالة النهائية للحركة: Posted يدخل، Reversed الأصل لا يدخل والعكس Posted يدخل، وDraft/Cancelled لا يدخلان. ثالثاً، يُنفذ Rebuild في Staging على نسخة مستقلة مع تقرير فروقات قبل/بعد، ثم يُقارن مع Incremental على مجموعة حالات محددة. رابعاً، لا يُستخدم أي قيد تعويضي للحسابين 5 أو 164 قبل اكتمال الأدلة التاريخية. خامساً، لا يُسمح بأي تغيير Production قبل Backup قابل للاستعادة وموافقة جديدة.

## 15. القرار النهائي

# NO-GO FOR FIXES / NO PRODUCTION CHANGE AUTHORIZED

تم تحقيق أهداف PHASE 3B.1 التصميمية والقراءة فقط. تم حصر الاستدعاءات، إثبات إمكانية تكرار نفس الحركة، اختيار نموذج Hybrid، تعريف الصيغة الرسمية المقترحة، وتصميم سياسات العكس والعملات والفروع، وتحليل الحسابين 5 و164 والحساب 168.

لا توجد موافقة على PHASE 4، ولا يُنفذ أي `UPDATE` أو `INSERT` أو `DELETE` أو `ALTER` أو `CALL sp_rebuild_balances()` على Production. الخطوة التالية الممكنة فقط هي مراجعة هذا التصميم ومنح موافقة صريحة جديدة على **Staging Fix Design Validation** إن أراد المستخدم المتابعة.

## References

[1]: ./includes/accounting_functions.php "PHP accounting functions and apply_transaction_balances paths"

[2]: ./admin/ajax/reverse_voucher.php "AJAX reversal path"

[3]: ./admin/ajax/unpost_voucher.php "AJAX unpost path"

[4]: ./tools/database/alghazali.sql "Stored procedure definitions and source SQL snapshot"

[5]: ./database/migrations/2026_08_11_005_idempotency.sql "Idempotency key migration"

[6]: ./database/migrations/2026_08_11_009_structured_financial_audit.sql "Structured financial audit migration"

[7]: ./database/migrations/2026_08_11_010_immutable_posted_transactions.sql "Immutable posted transaction migration"

[8]: ./PHASE_3B_CONTROLLED_STAGING_VALIDATION_20260814.md "Controlled Staging validation evidence"

[9]: ./staging_phase3b/production_snapshot.sql "Local protected Production snapshot used for read-only evidence"

[10]: ./staging_phase3b/staging_before_restore.sql "Local protected Staging rollback snapshot"
