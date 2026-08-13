# PHASE 3B.2 — STAGING FIX DESIGN VALIDATION

**التاريخ:** 2026-08-14  
**البيئة:** SQLite محلية جديدة ومعزولة في `phase3b2_staging/staging.sqlite`  
**Production:** Read-only بالكامل؛ لم يُنفذ عليه أي `INSERT` أو `UPDATE` أو `DELETE` أو `ALTER` أو `DROP` أو `TRUNCATE` أو Migration أو Rebuild أو Recalculate أو استدعاء إجراء مغير للبيانات.  
**القرار النهائي:** **NO-GO — DESIGN STILL UNVALIDATED**

> نجاح الاختبار المعزول يثبت سلوك النموذج التجريبي فقط، ولا يثبت جاهزية تطبيقه على PHP/Stored Procedure أو Production.

## 1. نطاق المرحلة

تم تنفيذ PHASE 3B.2 على Staging جديدة مستقلة تماماً، باستخدام Fixture محاسبي صغير مبني على سيناريو العكس 433 → 456 وأنواع الحسابات الخمسة المطلوبة. لم يتم نسخ بيانات Production إلى هذه البيئة، ولم يتم تشغيل أي إصلاح على قاعدة `ghazali` أو على `ghazali_booking_test`.

الـPrototype يحتوي على جداول معزولة للحسابات والعملات والحركات وبنود القيود والأرصدة وسجل تطبيق الأحداث وسجل التدقيق. صُمم محرك الاختبار بحيث يطبق الحدث مرة واحدة فقط باستخدام `event_key` فريد، ويستخدم قفل المعاملة، ويقرأ `normal_balance`، ويحافظ على سعر الصرف التاريخي الملتقط في الحركة.

## 2. التغييرات النظرية المطلوبة — لم تُطبق

| المجال | التغيير النظري | الملفات/الجداول المتوقعة |
|---|---|---|
| Idempotency | إضافة سجل تطبيق فريد لكل حدث، ومنع تكرار نفس `transaction_id + operation + direction` | Migration جديدة، `accounting_functions.php`، `financial_transaction_application_log` |
| Reversal | جعل الأصل Reversed والعكس Posted، ومنع إنشاء عكس ثانٍ لنفس الأصل | `reverse_voucher.php`، `financial_transactions`، قيود فريدة |
| Formula | توحيد signed movement وفق `normal_balance` في PHP وSP | `includes/accounting_functions.php`، `sp_rebuild_balances` |
| Branch | فصل NULL/Corporate عن Branch 1، وتجميع الرصيد بالمفتاح الثلاثي | `account_balances_unified`، التقارير، خدمات الترحيل |
| Currency | حفظ السعر التاريخي للحركة/السطر وعدم استخدام السعر الحالي في Rebuild | `financial_transactions.exchange_rate` أو حقل تاريخي على `journal_lines` |
| Opening | تعريف الافتتاحية Versioned وعدم خلطها بالحركات | `account_balances_unified` أو جدول opening balances مستقل |
| Audit | تسجيل العملية قبل/بعد والاتجاه والمستخدم والطلب ومفتاح التكرار والنتيجة | جدول Application/Audit موحد ومسارات AJAX والخدمات |

لم يتم إنشاء هذه الـMigrations أو تعديل هذه الملفات؛ الجدول يصف نطاق الأثر المتوقع فقط.

## 3. نتيجة Idempotency والعمليات

تم اختبار نفس الحدث مرة، ثم تكراره، ثم تشغيله بالتوازي عبر عمليتين PHP مستقلتين على SQLite مع `WAL` و`busy_timeout`:

| الاختبار | النتيجة |
|---|---|
| تطبيق `reverse` لأول مرة | Applied مرة واحدة |
| تكرار نفس طلب العكس | Duplicate، دون أثر ثانٍ |
| `post` | Applied مرة واحدة |
| `unpost` | Applied مرة واحدة بالاتجاه -1 |
| `cancel` | Applied مرة واحدة بالاتجاه -1 |
| Retry لـ`unpost` | Duplicate، دون أثر إضافي |
| طلبان متزامنان لنفس الحدث | عملية واحدة Applied، والثانية Duplicate |
| عدد Application Logs للحدث المتزامن | 1 |
| Double Update | لم يظهر في Prototype |
| Lost Update | لم يظهر في Prototype |

هذه النتيجة تثبت أن **النموذج المقترح** يمكنه منع التكرار عند وجود Application Log فريد وقفل معاملة. لكنها لا تثبت أن الكود الحالي في Production يحقق ذلك؛ بل إن اختبار PHASE 3B السابق أثبت أن `apply_transaction_balances()` الحالي كان يكرر الأثر عند استدعائه مرتين.

## 4. نتيجة Reversal 433 → 456

تم استخدام أصل 433 بحالة `reversed` وعكس 456 بحالة `posted`.

| الشرط | النتيجة |
|---|---|
| Original = Reversed | ناجح |
| Reversal = Posted | ناجح |
| Original journal lines لا تتغير | ناجح؛ المقارنة قبل/بعد متطابقة |
| Reversal journal lines عكس تام | ناجح؛ Debit وCredit تبدلا لكل حساب |
| تكرار طلب العكس | Duplicate ولا ينشئ أثراً ثانياً |
| Full Rebuild = Incremental = Expected | ناجح داخل Fixture المعزول لكل المفاتيح المفحوصة |

أظهر اختبار التطابق الثلاثي في Fixture أن النتائج تساوت لكل مفاتيح `Account + Currency + Branch` الموجودة في الاختبار. هذا لا يعالج فرق الحساب 168 في Production، لأن ذلك الفرق ناتج عن اختلاف الكود الفعلي الحالي عن Prototype المقترح.

## 5. اختبار الصيغة المحاسبية

تم اختبار الأنواع الخمسة:

| نوع الحساب | Normal Balance | PHP Prototype | SP Prototype | Expected Ledger | الحالة |
|---|---|---:|---:|---:|---|
| Asset | Debit | 100 | 100 | 100 | Match |
| Expense | Debit | 100 | 100 | 100 | Match |
| Liability | Credit | -100 | -100 | -100 | Match |
| Equity | Credit | -100 | -100 | -100 | Match |
| Revenue | Credit | -100 | -100 | -100 | Match |

الصيغ المختبرة هي:

```text
debit-normal  => debit - credit
credit-normal => credit - debit
```

النتيجة تثبت الصيغة على الحسابات التجريبية فقط. لم يتم تعديل `sp_rebuild_balances` أو PHP Production، ولذلك لا تزال المقارنة الفعلية بين الكود الحالي والإجراء الحالي غير معتمدة.

## 6. اختبار الفروع

تم اختبار ثلاثة نطاقات:

| المفتاح | القيمة الابتدائية في Fixture | النتيجة |
|---|---:|---|
| Branch 1 | 600 | بقي مستقلاً |
| Branch 2 | 75 | بقي مستقلاً |
| `branch_id=NULL` | 50 | بقي مستقلاً |

لم يتحول NULL إلى Branch 1 أو Branch 2. هذا يحقق السياسة المقترحة في Prototype، لكنه لا يثبت أن جميع مسارات PHP وStored Procedure Production تطبقها؛ فالتقرير السابق أثبت أن بعض مسارات PHP تستخدم fallback إلى 1 وأن SP لا يجمع حسب الفرع.

## 7. اختبار العملة وسعر الصرف التاريخي

أنشئت حركة بمبلغ 100 وسعر تاريخي 140، فأصبح Base Amount المتوقع 14,000. بعد تغيير سعر جدول العملات الحالي إلى 200، بقي السعر التاريخي المستخدم في الاختبار 140، وبقيت نتيجة إعادة البناء التاريخية 14,000. كما صُمم العكس ليستخدم السعر التاريخي نفسه لا سعر اليوم.

| العنصر | نتيجة الاختبار |
|---|---:|
| Currency amount | 100 |
| Historical rate | 140 |
| Current rate بعد التغيير | 200 |
| Base amount المتوقع | 14,000 |
| Rebuild يعتمد السعر التاريخي | نعم في Prototype |
| Reversal يستخدم نفس السعر | نعم في Prototype |

لا يجوز اعتبار هذا اعتماداً لسياسة Production، لأن الكود الحالي يستخدم مصادر مختلفة بين `financial_transactions.exchange_rate` و`currencies.exchange_rate`، وهو اختلاف يجب حسمه قبل أي إصلاح.

## 8. اختبار Opening Balance

تم اختبار افتتاحية غير صفرية مقدارها 1,000 وحركة مرحّلة مقدارها 300 على حساب Asset ذي Debit-normal:

```text
opening + posted movement = 1,000 + 300 = 1,300
```

كانت نتيجة Incremental وExpected Ledger تساوي 1,300. كما أن Full Rebuild في Fixture يعتمد الافتتاحية نفسها ويعطي القيمة ذاتها. لم يتم تغيير أي Opening Balance في Production.

## 9. اختبار التزامن

شغلت عمليتان مستقلتان نفس `transaction_id/event` في الوقت نفسه. فازت عملية واحدة بإدخال Application Log، وقرأت العملية الثانية المفتاح الفريد وأعيدت لها نتيجة Duplicate. لم ينتج عن ذلك سجل تطبيق مزدوج أو أثر مزدوج.

هذا النجاح مشروط بوجود قيد فريد وقفل معاملة في التصميم النهائي. إذا بقيت الدالة الحالية تعمل كتحديث تفاضلي بلا Application Log فريد، فسيظل خطر Double Update قائماً.

## 10. اختبار Audit

سجل Prototype الحقول المطلوبة لكل العمليات المختبرة:

`transaction_id`, `operation`, `before_status`, `after_status`, `direction`, `user_id`, `request_id`, `idempotency_key`, `created_at`, و`result`.

تم إنشاء 6 سجلات Audit، وجميع الحقول المطلوبة كانت موجودة في كل السجلات. شملت العمليات العكس، التكرار، Post، Unpost، Cancel، وRetry. هذه نتيجة Prototype فقط؛ Snapshot Production السابق أظهر فجوات فعلية في جدولي `financial_transaction_audit` و`financial_transaction_logs`، لذلك لا يمكن اعتماد Audit Production قبل تطبيق تصميم موحد واختباره على نسخة حقيقية معزولة.

## 11. شرط التطابق الثلاثي

الشرط الإلزامي هو:

```text
Full Rebuild Result
=
Incremental Result
=
Expected Ledger Result
```

لكل مفتاح:

```text
Account + Currency + Branch
```

في الـPrototype المعزول، تحقق الشرط لكل المفاتيح التجريبية بعد سيناريو العكس 433 → 456. لكن شرط الانتقال العام لا يتحقق بعد؛ لأن الاختبار لم يشغّل الكود الحالي في `includes/accounting_functions.php` وStored Procedure Production على نفس مجموعة بيانات إصلاحية، ولأن النتيجة السابقة على Production/Staging أثبتت اختلافاً بينهما في الحساب 168 وعدم Idempotency في الدالة الحالية.

وفق التعليمات، أي اختلاف في البيئة الفعلية يوقف المرحلة ولا يسمح بإصلاح تلقائي. لذلك لا يُعتبر نجاح Prototype دليلاً كافياً.

## 12. Before/After في Staging المعزولة

| الحالة | Before | After Prototype |
|---|---:|---:|
| Original lines 433 | ثابتة | ثابتة دون تغيير |
| Reversal application | غير مطبق | مطبق مرة واحدة فقط |
| Duplicate reversal | غير مطبق | مرفوض كـDuplicate |
| Concurrent application log | 0 | 1 فقط |
| Opening balance test | 1,000 | 1,300 |
| Historical FX base | 14,000 بالسعر 140 | بقي 14,000 بعد تغيير السعر الحالي إلى 200 |
| Branch NULL | مستقل | بقي مستقلاً عن Branch 1 و2 |

هذه التغييرات تمت داخل ملف SQLite المحلي المعزول فقط، ثم حُفظت نتائجه محلياً لأغراض التوثيق. لا توجد Before/After على Production.

## 13. Unknowns والفروقات المتبقية

تبقى الفروقات والـUnknowns التالية:

| البند | الحالة |
|---|---|
| الحساب 5/YER وفرق 50,000 | UNKNOWN؛ لا يوجد قيد تعويضي |
| الحساب 164/YER وفرق 30,000 | قابل للتتبع إلى الحركة 427، لكن سبب Projection غير محسوم نهائياً |
| الحساب 164/SAR وفرق 9,000 | قابل للتتبع إلى ست حركات، لكن سبب عدم تطابق Projection يحتاج تحققاً فعلياً |
| الحساب 168/SAR وفرق 250 | مثبت في Staging السابقة كاختلاف Projection بعد العكس، ولم يُصلح |
| سياسة `branch_id=NULL` في Production | غير مطبقة موحداً بعد |
| مصدر سعر الصرف التاريخي في Production | غير موحد بعد |
| تطابق PHP الحالي مع SP الحالي | غير متحقق؛ PHASE 3B أثبت اختلافاً |
| Audit Production الكامل | غير متحقق؛ توجد فجوات موثقة |

## 14. مخاطر التنفيذ

أعلى المخاطر هي تطبيق Prototype على Production قبل إضافة Application Log فريد، أو تشغيل Rebuild قبل اعتماد الصيغة ومصدر سعر الصرف، أو تحويل NULL إلى Branch 1 دون mapping، أو إنشاء قيود تعويضية للمبالغ غير المثبتة، أو اعتبار نجاح العكس التجريبي دليلاً على سلامة كل مسارات AJAX والـRetry.

## 15. خطة Rollback

لأن هذه المرحلة استخدمت SQLite جديدة، فإن Rollback الآمن هو حذف مجلد `phase3b2_staging` أو الاحتفاظ به كأثر اختبار. لم يحدث أي تغيير على `ghazali` أو `ghazali_booking_test`. قبل أي مرحلة لاحقة يجب أخذ Backup مستقل، حفظ checksum، اختبار Restore في قاعدة جديدة، ثم إجراء الاختبار على نسخة لا تتصل بمستخدمي Production.

لا توجد خطة Rollback مطلوبة لـProduction لأن Production لم يُلمس.

## 16. الحكم النهائي

# NO-GO — DESIGN STILL UNVALIDATED

نجح Prototype المعزول في إثبات أن التصميم المقترح يستطيع تحقيق Idempotency، منع Double Update، حفظ العكس، توحيد صيغة الحساب، فصل NULL عن الفروع، تثبيت السعر التاريخي، واحترام الافتتاحية. كما تحقق التطابق الثلاثي داخل Fixture الاختبار.

لكن ذلك لا يكفي للانتقال إلى PHASE 4، لأن التنفيذ الفعلي الحالي في PHP وStored Procedure لم يُعدّل ولم يُختبر بعد على نسخة Staging حقيقية من مخطط النظام، ولأن الفروقات Production السابقة ما زالت قائمة وغير محسومة. بناءً على شرط المستخدم، القرار الإلزامي هو:

**NO-GO — DESIGN STILL UNVALIDATED**

لا تبدأ PHASE 4، ولا تنفذ أي Migration أو Fix أو Rebuild أو Recalculate على Production قبل مراجعة هذا التقرير ومنح موافقة صريحة جديدة.

## References

[1]: ./PHASE_3B1_PRE_FIX_DESIGN_IMPACT_ANALYSIS_20260814.md "PHASE 3B.1 pre-fix design and impact analysis"

[2]: ./PHASE_3B_CONTROLLED_STAGING_VALIDATION_20260814.md "PHASE 3B controlled staging validation"

[3]: ./includes/accounting_functions.php "Current PHP accounting paths"

[4]: ./tools/database/alghazali.sql "Stored procedure and source SQL definitions"

[5]: ./database/migrations/2026_08_11_005_idempotency.sql "Existing idempotency migration"

[6]: ./database/migrations/2026_08_11_009_structured_financial_audit.sql "Existing structured audit migration"

[7]: ./database/migrations/2026_08_11_010_immutable_posted_transactions.sql "Existing posted transaction immutability migration"
