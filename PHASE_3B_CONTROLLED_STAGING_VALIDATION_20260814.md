# PHASE 3B — CONTROLLED STAGING VALIDATION

**التاريخ:** 2026-08-14  
**النطاق:** نسخة Staging منفصلة فقط  
**Production:** لم تُنفذ عليه أي كتابة أو Migration أو Rebuild أو Recalculate.  
**القرار:** لا انتقال إلى PHASE 4 ولا إصلاحات قبل موافقة صريحة لاحقة.

> هذا التقرير تشخيصي واختباري، وليس تفويضاً لتعديل الأرصدة أو القيود.

## 1. Environment

تم استخدام اتصال Web/PHP الفعلي بقاعدة `ghazali` على MariaDB 10.11.14 لاستخراج Snapshot، ثم استُخدمت قاعدة الاختبار المنفصلة `ghazali_booking_test` كـStaging. قاعدة Production كانت عبر المستخدم `alghazali_app`، بينما لم يكن اتصال CLI المباشر متاحاً بسبب اختلاف إعداداته واعتماداته؛ لذلك تمت عملية Snapshot/Restore عبر اتصال Web/PHP الموجود في التطبيق.

| البيئة | قاعدة البيانات | الجداول | الحالة |
|---|---|---:|---|
| Production | `ghazali` | 154 | لم تُمس |
| Staging قبل Restore | `ghazali_booking_test` | 154 | تم حفظ Snapshot قبل التغيير |
| Staging بعد Restore | `ghazali_booking_test` | 154 | تمت الاستعادة بنجاح |

## 2. Backup/Restore Evidence

أُنشئ ملف Production Snapshot محلي محمي بالصلاحيات في `staging_phase3b/production_snapshot.sql` بحجم 4,906,726 بايت، ويحتوي على 154 جدولاً و13,509 صفاً. كما أُنشئ Snapshot للحالة السابقة لـStaging في `staging_phase3b/staging_before_restore.sql` بحجم 4,796,479 بايت، ويحتوي على 154 جدولاً و13,129 صفاً.

أُجريت استعادة أولى واكتُشف خلالها تحذير ENUM في `unified_accounts`، فتوقفت العملية قبل اكتمال البيانات. لم يُعتبر ذلك نجاحاً. بعد ضبط SQL mode في Staging فقط وإعادة العملية، تمت استعادة 523 عبارة SQL دون أخطاء، وتطابقت الأعداد الأساسية مع Production:

| الجدول | Production | Staging بعد Restore |
|---|---:|---:|
| `financial_transactions` | 46 | 46 |
| `invoices` | 57 | 57 |
| `journal_lines` | 90 | 90 |
| `payment_allocations` | 3 | 3 |
| `account_balances_unified` | 39 | 39 |
| `unified_accounts` | 109 | 109 |
| `currencies` | 3 | 3 |
| `branches` | 1 | 1 |

بعد انتهاء الاختبارات، أُعيدت Staging إلى Snapshot السابق دون أخطاء، وعادت الأعداد إلى 17 حركة مالية و19 فاتورة و34 بند قيد و32 صف رصيد و107 حسابات. حُذف الإجراء المؤقت وأُزيلت أدوات HTTP المؤقتة من المستودع، بينما بقيت ملفات Snapshot المحلية المحمية لخطة الرجوع.

## 3. Test Cases

| الحالة | التنفيذ | النتيجة |
|---|---|---|
| A — Reversal 433 → 456 | قراءة الأصل والعكس والبنود والأرصدة | تم التحقق |
| B — Account 5/YER | فحص Snapshot والافتتاحية والحركات التاريخية المتاحة | UNKNOWN |
| C — Account 164 | فحص YER وSAR والافتتاحية والعكس والتحويل | السبب التاريخي غير مثبت |
| D — Account 168/SAR | مقارنة الرصيد المخزن مع SP rebuild والعكس | فرق 250 مثبت كفرق Projection |
| E — Branch NULL | فحص الحركات 404 و405 و406 و407 و429 و432 | السياسة UNKNOWN |
| F — Currency | مقارنة exchange rate في الحركة والعملات والبنود والرصيد الأساسي | اختلاف مثبت |
| G — PHP vs SP | تشغيل `apply_transaction_balances` و`sp_rebuild_balances` على نفس Staging | اختلاف مثبت |
| H — Idempotency | تطبيق PHP مرة ثم مرتين داخل معاملات Rollback | فشل الاختبار، خطر Double Update |
| I — Audit | فحص خمس طبقات تدقيق و`posted_at` | تغطية غير موحدة |

## 4. Before/After Values

### 4.1 الحساب 168/SAR

قبل تشغيل `sp_rebuild_balances` في Staging، كان الصف:

| القيمة | قبل |
|---|---:|
| `current_balance` | 600 |
| `current_balance_base` | 84,000 |
| `opening_balance` | 0 |
| `branch_id` | 1 |

بعد تشغيل الإجراء المخزن في Staging، أصبح:

| القيمة | بعد SP rebuild |
|---|---:|
| `current_balance` | 850 |
| `current_balance_base` | 119,000 |
| `opening_balance` | 0 |
| `branch_id` | 1 |

الفرق 250 ليس تخميناً: يطابق أثر العكس Posted للحساب 168 في الحركة 456، بينما الأصل 433 أصبح Reversed. بعد الاختبار أُعيدت Staging إلى حالتها السابقة.

### 4.2 الحساب 5/YER

| القيمة | Production/قبل الاختبار |
|---|---:|
| `current_balance` المخزن | 44,500 |
| `opening_balance` | 0 |
| الحركة المحسوبة سابقاً | -5,500 |
| الفرق | 50,000 |

لم يظهر Backup أو Archive أو Ledger سابق أو Rebuild log مستقل يثبت مصدر 50,000. النتيجة تبقى UNKNOWN ولا يوجد قيد تعويضي.

### 4.3 الحساب 164

| العملة | المخزن | الافتتاحية | الحركة الحالية/المعادلة السابقة | الفرق |
|---|---:|---:|---:|---:|
| YER | 35,500 | 0 | 5,500 | 30,000 |
| SAR | 8,100 | 0 | -900 | 9,000 |

توجد قيمة `current_balance_base=1,134,000` للحساب 164/SAR، بينما الرصيد الأصلي 8,100، وهذا يثبت اختلافاً في التحويل الأساسي، لكنه لا يثبت وحده أصل فرق 9,000.

## 5. Truth Model Results

تم تأكيد أن `journal_lines` المرتبطة بالحركات المرحّلة هي مصدر الحقيقة التفصيلي. وتم تأكيد أن `account_balances_unified` Projection/Cache قابل لإعادة البناء، لكنه لا يتصرف كنسخة موحدة من الحقيقة بسبب اختلاف مساري PHP والإجراء المخزن.

تم تشغيل `sp_rebuild_balances` فعلياً داخل Staging. لذلك أصبحت نتيجة المقارنة أقوى من مجرد قراءة SQL: الإجراء حسب الحركات المرحّلة بصيغة `debit-credit`، ووصل بالحساب 168/SAR إلى 850 بدلاً من 600.

## 6. PHP vs Stored Procedure Comparison

| البعد | PHP `apply_transaction_balances` | `sp_rebuild_balances` | نتيجة الاختبار |
|---|---|---|---|
| الصيغة | يحترم `normal_balance` | `debit-credit` للجميع | اختلاف جوهري |
| الفرع | يستخدم `financial_transactions.branch_id` مع fallback إلى 1 | لا يجمع حسب الفرع | اختلاف جوهري |
| العملة | يجمع حسب الحساب والعملة | يجمع حسب الحساب والعملة | متفق جزئياً |
| سعر الصرف | يستخدم سعر `currencies.exchange_rate` | يستخدم سعر `currencies.exchange_rate` | لا يثبت أن `financial_transactions.exchange_rate` هو المصدر |
| العكس | يطبق direction `-1` أو يدخل العكس بـ`+1` حسب المسار | يستبعد الأصل غير Posted ويدخل العكس Posted | متفق في سيناريو 433/456 |
| الافتتاحية | يحافظ عليها في الزيادة التفاضلية | يضيفها إلى نتيجة إعادة البناء | متفق في الهدف، مختلف في دورة التنفيذ |
| Idempotency | يزيد الرصيد كل مرة | إعادة بناء تجميعية | PHP يفشل عند التكرار بدون حارس |

في فحص الصيغة على زوج 433/456 معاً، كانت المحصلة الصافية `0.00` لكلا الصيغتين لأن القيد الأصلي والعكس يلغي أحدهما الآخر. هذا لا يلغي الفرق الذي يظهر عند حساب الحالة الحالية Posted فقط؛ فـSP rebuild أعطى 850 للحساب 168/SAR.

## 7. Reversal Results

الحركة 433 (`F0001`) حالتها `reversed`، و`is_reversed=1`، و`reversal_voucher_id=456`. الحركة 456 (`PMT-26-00010`) حالتها `posted`، و`original_voucher_id=433`. كل حركة تحتوي سطرين متوازنين:

| الحركة | الحساب 5 | الحساب 168 |
|---|---|---|
| 433 الأصل | Debit 250 | Credit 250 |
| 456 العكس | Credit 250 | Debit 250 |

تم إثبات أن الأصل Reversed لا يدخل في تجميع SP الذي يشترط `status='posted'`، بينما العكس Posted يدخل. ومع ذلك، الرصيد المخزن 600 لا يساوي ناتج SP 850، ما يثبت أن Projection الحالي لم يُبنَ وفق نفس الحالة أو لم يُعد بناؤه بعد آخر أثر.

## 8. Account 5 Root Cause

تم فحص Snapshot Production، Snapshot Staging، ملفات SQL، migrations، backup/archive/restore/scripts، والتقارير السابقة. لم يظهر دليل تاريخي مستقل يثبت مصدر 50,000 YER. الافتتاحية صفر، ولا توجد حركة حالية بقيمة 50,000 تفسر الفرق. التصنيف: **UNKNOWN**. لا يوجد اقتراح لقيد تعويضي.

## 9. Account 164 Root Cause

بالنسبة إلى YER، الافتتاحية صفر والرصيد المخزن 35,500 مقابل حركة حالية 5,500، لذلك يظل فرق 30,000 غير مثبت المصدر تاريخياً. بالنسبة إلى SAR، الرصيد المخزن 8,100 مقابل حركة حالية سالبة 900، والرصيد الأساسي 1,134,000، لذلك يظل فرق 9,000 غير مثبت المصدر. لم تظهر حركة عكسية أو افتتاحية أو سجل أرشيف مستقل يثبت أصل أي من المبلغين.

## 10. Account 168 Root Cause

الفرق 250 مرتبط فعلياً بالزوج 433/456. السبب الأقرب المثبت هو **اختلاف Projection/Balance Update Logic بعد العكس**، وليس وجود قيد مفقود جديد. الأصل Reversed، العكس Posted، وSP rebuild يحسب 850، بينما القيمة المخزنة 600. لا يجوز تصحيح الفرق مباشرة قبل اعتماد سياسة موحدة وإثبات تاريخ آخر تحديث للرصيد.

## 11. Branch Policy Result

الحركات الست 404 و405 و406 و407 و429 و432 تحمل `branch_id=NULL`. بعضها `reversed` وبعضها `posted`، وتواريخها تمتد من 2026-07-24 إلى 2026-07-26. لم توجد وثيقة أو إعداد أو مرجع مستندي يثبت أن NULL يعني الفرع 1 أو الفرع العام أو الحركة المركزية. كما أن PHP يضع fallback إلى الفرع 1، بينما SP لا يجمع حسب الفرع. النتيجة: **UNKNOWN — لا يجوز تحويل NULL إلى 1 تلقائياً**.

## 12. Currency Policy Result

في الحركة 433/456، `financial_transactions.exchange_rate=1.000000`، بينما `currencies.exchange_rate=140.0000` للعملة SAR، و`journal_lines.currency_id=1`. الرصيد الأساسي يتأثر بسعر جدول العملات في مسار PHP وSP، لا بقيمة الحركة وحدها. لذلك لا يمكن اعتماد `financial_transactions.exchange_rate` أو `currencies.exchange_rate` منفرداً كمصدر حقيقة قبل تحديد سياسة تاريخية لسعر الصرف. النتيجة: **Currency source partially confirmed, historical exchange policy UNKNOWN**.

## 13. Idempotency Result

اختبار PHP نفذ داخل معاملات منفصلة ثم Rollback. للحساب 168/SAR:

| عدد مرات التطبيق | قبل | بعد داخل المعاملة |
|---:|---:|---:|
| مرة واحدة | 600 | 350 |
| مرتان | 600 | 100 |

الفرق الإضافي 250 عند التشغيل الثاني يثبت أن `apply_transaction_balances()` ليس Idempotent وحده؛ إعادة تنفيذ نفس الحركة تضاعف الأثر. لم تصل هذه الكتابة إلى Staging بعد Rollback، ولم تصل مطلقاً إلى Production.

## 14. Audit Result

| الطبقة | عدد السجلات في Snapshot |
|---|---:|
| `audit_logs` | 1,531 |
| `financial_transaction_audit` | 0 |
| `financial_transaction_logs` | 0 |
| `module_audit_log` | 3 |

يوجد 12 سنداً `posted` بلا `posted_at` أو `posted_by` في Staging المستعادة. غياب السجل من `audit_logs` لا يثبت غياب التدقيق بالكامل، لكن وجود صفر في جدولي `financial_transaction_audit` و`financial_transaction_logs` يثبت أن هاتين الطبقتين لا توفران تغطية للنسخة المفحوصة. يلزم ربط موحد عبر كل الطبقات قبل اعتماد Audit Trail كاملاً.

## 15. Remaining UNKNOWNs

تبقى UNKNOWNs التالية: مصدر 50,000 YER للحساب 5، مصدر 30,000 YER للحساب 164/YER، مصدر 9,000 SAR للحساب 164/SAR، المعنى الرسمي لـ`branch_id=NULL`، مصدر سعر الصرف التاريخي، سبب `posted_at=NULL` لبعض السندات، ومطابقة نسخة إجراءات Production حرفياً مع SQL المستودع.

## 16. Production Risk Assessment

| الخطر | المستوى | دليل Staging |
|---|---|---|
| تكرار تطبيق الرصيد | Critical | 600 → 350 → 100 عند تطبيق PHP مرتين |
| Projection غير متزامن بعد العكس | Critical | الحساب 168: 600 مخزن مقابل 850 بعد SP |
| اختلاف PHP/SP | Critical | اختلاف normal_balance والفرع ومسار التجميع |
| فروقات تاريخية غير مفسرة | Critical | 50,000 و30,000 و9,000 |
| Audit gaps | High | 12 posted بلا وقت ترحيل، وجدولا تدقيق ماليان صفرياً |
| فرع NULL غير محسوم | High | ست حركات بلا سياسة موثقة |

## 17. Exact Recommended Fixes

لا تُنفذ هذه التوصيات في PHASE 3B. قبل أي إصلاح، يجب اعتمادها في PHASE 4 بعد موافقة صريحة:

1. اختيار مصدر حقيقة واحد، والأفضل `journal_lines` المرحّلة مع تعريف واضح للرصيد الافتتاحي.
2. توحيد معادلة الرصيد حول `normal_balance` أو اعتماد `debit-credit` مع إعادة تعريف طبيعة كل حساب، ثم توحيد PHP وStored Procedure.
3. جعل تحديث الرصيد Idempotent عبر سجل تطبيق لكل `financial_transaction_id` أو عبر إعادة بناء تجميعي، ومنع إعادة الزيادة التفاضلية عند إعادة المحاولة.
4. اعتماد سياسة مكتوبة لـ`branch_id=NULL` قبل أي Backfill.
5. اعتماد مصدر سعر صرف تاريخي واحد وتخزينه على الحركة أو القيد وفق سياسة معتمدة.
6. توحيد Audit Trail وربط كل ترحيل وعكس وإلغاء وUnpost بالمستخدم والوقت والسبب وIP.
7. عدم إنشاء قيود تعويضية للحسابات 5 و164 قبل ظهور دليل تاريخي مستقل.

## 18. Rollback Plan

خطة الرجوع التي تم اختبارها فعلياً هي استعادة `staging_before_restore.sql` إلى `ghazali_booking_test` مع تعطيل المفاتيح الأجنبية داخل Staging فقط، ثم حذف الإجراء المؤقت. تمت الاستعادة بنجاح وعادت الأعداد الأساسية إلى حالة ما قبل الاختبار. لا توجد خطة تنفيذ أو Rollback مطلوبة على Production لأن Production لم يُعدل.

قبل أي PHASE 4، يجب أخذ Snapshot جديد، حفظ checksum خارجي، اختبار Restore في قاعدة جديدة أو مساحة معزولة، وتسجيل موافقة واضحة على كل Migration أو Rebuild.

## 19. Final GO/NO-GO for PHASE 4

# NO-GO FOR PHASE 4

الاختبارات نجحت في إثبات وجود مخاطر وسلوكيات قابلة لإعادة الإنتاج، لكنها لم تثبت سبباً تاريخياً نهائياً للفروقات ولم تعتمد سياسة موحدة للحقيقة المحاسبية. لا يبدأ أي إصلاح أو Migration أو Rebuild أو Recalculate في Production قبل مراجعة هذا التقرير ومنح موافقة صريحة جديدة.

## References

[1]: ./05A_ACCOUNTING_TRUTH_VALIDATION.md "PHASE 3A.1 — Accounting Truth Model Validation"

[2]: ./ACCOUNT_BALANCE_ROOT_CAUSE_REPORT_20260814.md "PHASE 3A — Account Balance Root Cause Report"

[3]: ./tools/database/alghazali.sql "Stored procedure definitions used for controlled Staging validation"

[4]: ./includes/accounting_functions.php "PHP balance application and posting paths"

[5]: ./admin/ajax/reverse_voucher.php "Reversal endpoint and audit path"
