# PHASE 3B.4 — ACCOUNT_TYPE RESTORE ROOT-CAUSE REPORT

**النطاق:** تحقيق قراءة فقط  
**Production:** `ghazali` — لم تُنفذ عليه أي كتابة  
**Staging:** `ghazali_booking_test`  
**القرار:** **NO-GO — لا إصلاح قبل موافقة مستقلة**

## 1. Root Cause

السبب الجذري هو **عدم اتساق بيانات تاريخية مع عقد Schema لعمود ENUM**، وليس اختلافاً بين تعريف Production وتعريف Staging.

عمود `unified_accounts.account_type` معرف كالتالي في Production وStaging وSnapshot:

```sql
ENUM('asset','liability','equity','revenue','expense','box','bank','agent','branch','receivable','payable')
```

لكن Snapshot يحتوي على القيمة الفارغة `''` في هذا العمود. القيمة الفارغة ليست ضمن أعضاء ENUM المسموحين. عند استعادة Snapshot باستخدام جلسة تتعامل مع `Data truncated` كتحذير/استثناء، يفشل `INSERT` عند ذلك الصف.

## 2. Evidence

| المصدر | الدليل |
|---|---|
| Production `ghazali` | `account_type` هو ENUM أعلاه، ويوجد صف واحد بقيمة `''` |
| Snapshot Production | يحتوي نفس الصف والقيمة الفارغة داخل `INSERT INTO unified_accounts` |
| Staging | يملك نفس ENUM، ويوجد فيه نفس الصف عند الحالة المستقرة |
| صف المشكلة | `id=167`, `account_code=11201005`, الاسم `فارس عبدالله احمد احمد البروي`, `owner_type=other`, `normal_balance=debit` |
| الخطأ | `SQLSTATE[01000] 1265 Data truncated for column 'account_type' at row 7` |

السجل الخام في Snapshot هو:

```sql
('167','11201005','فارس عبدالله  احمد احمد البروي','',NULL,'other','debit','0.00','0.00','10','1','1','active','2026-07-26 03:33:04',NULL,NULL,NULL,NULL)
```

والصف التالي هو الحساب 168، لذلك يمكن تحديد الصف المسبب بدقة داخل INSERT الخاص بـ`unified_accounts`.

## 3. Exact Schema/Data Mismatch

| العنصر | القيمة الفعلية |
|---|---|
| الجدول | `unified_accounts` |
| العمود | `account_type` |
| نوع العمود | ENUM إنجليزي محدود |
| القيمة الموجودة في Snapshot | `''` — empty string |
| القيمة المسموح بها | واحدة من 11 قيمة: `asset`, `liability`, `equity`, `revenue`, `expense`, `box`, `bank`, `agent`, `branch`, `receivable`, `payable` |
| الصف المتأثر | `id=167`, `account_code=11201005` |
| عدد القيم الفارغة في Production | 1 |

## 4. سبب نشوء القيمة تاريخياً

يوجد مسار برمجي يثبت أن التطبيق كان يكتب تسميات عربية لا تنتمي إلى ENUM الإنجليزي. ففي `admin/customers.php`، السطر 71، يستخدم مسار إنشاء حساب العميل قيمة ثابتة هي `'عميل'` داخل `account_type`. وفي `admin/agents.php`، السطر 59، يستخدم مسار الوكيل قيمة ثابتة هي `'وكيل'`.

هذا يفسر كيف يمكن أن تنتج قيمة غير صالحة تاريخياً: عند الكتابة تحت SQL mode متساهل، يمكن لمارياDB أن تحول قيمة ENUM غير المسموح بها إلى empty string مع تحذير. الحساب 167 يقع تحت شجرة العملاء `11201` ويحمل كوداً من نمط `11201005`، ولذلك يتطابق مع مسار إنشاء العميل. هذا استدلال قوي من الكود والبيانات، وليس تعديلاً أو تصحيحاً للصف.

كما أن `normalize_account_type()` في `admin/invoice_details.php` يعيد القيمة الفارغة كما هي عند إدخال فارغ، ولا يفرض تحققاً من أعضاء ENUM؛ وهذا يؤكد أن طبقة العرض لم تكن حاجزاً كافياً، لكنه ليس سبب Restore المباشر.

## 5. هل Production الحالية متوافقة مع بياناتها؟

**هي متوافقة تشغيليةً مع تاريخها الحالي، لكنها غير متوافقة تعاقدياً مع Schema تحت Strict Validation.** Production نفسها تستخدم نفس ENUM وتحتوي القيمة الفارغة؛ وهذا يعني أن الصف دخل أو بقي في فترة/جلسة سمحت بتسجيل قيمة ENUM غير صالحة مع تحذير. لذلك لا يمكن اعتبار Production سليمة محاسبياً أو Schema-wise لمجرد أن الصف موجود فيها.

## 6. هل المشكلة من Snapshot أم Staging أم Restore؟

المشكلة مركبة من ثلاث حقائق، لكن السبب المباشر واضح:

| الطبقة | التقييم |
|---|---|
| Snapshot | ليس تالفاً؛ هو يعكس Production، لكنه يحمل بيانات مخالفة لعقد ENUM |
| Staging Schema | يطابق Production في تعريف `account_type`، فلا يوجد اختلاف Schema يبرر تغيير النوع |
| Restore method | يمرر القيمة الفارغة إلى ENUM تحت سلوك صارم، فتتحول المخالفة التاريخية إلى `1265 Data truncated` وتفشل عملية الاستعادة |

إذن **لا يوجد دليل على أن إضافة عمود أو تغيير نوع Schema هو الحل**. ولا يوجد دليل على أن Snapshot فقد حقلاً؛ القيمة الفارغة موجودة صراحة في البيانات.

## 7. Migrations والكود

لم يظهر في migrations المفحوصة أي تغيير يضيف empty string كقيمة صحيحة أو يوسّع ENUM. Migration `2026_08_11_007_financial_foreign_keys.sql` وMigration `2026_08_11_008_financial_fk_completion.sql` تستخدمان قيماً صالحة مثل `asset` و`liability` عند إدخال حسابات تاريخية، ولا تفسران الصف 167.

المصدر البرمجي الأوضح هو كتابة `'عميل'` و`'وكيل'` في عمود ENUM إنجليزي. لذلك المشكلة الأساسية هي **Application-to-Schema contract drift تاريخي**، مع ظهورها أثناء Restore بسبب سلوك الجلسة الصارم.

## 8. Impact

يفشل Restore الكامل عند الصف 167، وقد يترك Staging في حالة استعادة جزئية إذا لم تكن العملية ذرية أو لم تُستعد من Snapshot سابق. كما أن أي مسار إنشاء عميل أو وكيل يستخدم القيم العربية نفسها قد يعيد إنتاج المشكلة في المستقبل. تغيير ENUM أو تحويل القيمة تلقائياً قد يغيّر معنى الحساب المحاسبي، ولذلك لا يجوز فعله تلقائياً.

## 9. Safe Options

| الخيار | التقييم |
|---|---|
| إصلاح الكود ليكتب قيمة ENUM الصحيحة مثل `asset` مع إبقاء نوع الحساب التشغيلي في `account_sub_type` أو علاقة العميل/الوكيل | الخيار المقترح، لكنه يحتاج Patch واختبارات Staging جديدة |
| إنشاء نسخة Sanitized من Snapshot تستبدل `''` بقيمة معتمدة بعد اعتماد تصنيف الحساب 167 | آمن فقط على نسخة منفصلة، ويحتاج قراراً محاسبياً لتحديد القيمة؛ لا يغيّر Production |
| تغيير ENUM ليشمل `''` أو `'عميل'` أو `'وكيل'` | غير مقترح؛ يوسع عقد المحاسبة ويخفي خطأ التطبيق |
| تغيير SQL mode لتجاوز التحذير | مرفوض حسب التعليمات، ولا يعالج فساد عقد البيانات |
| تعديل الصف 167 في Production | ممنوع حالياً ويحتاج موافقة وخطة محاسبية مستقلة |

## 10. الخيار المقترح

لا تغيير على Production ولا على Schema. بعد موافقة مستقلة، يُنشأ Snapshot Sanitized منفصل أو Fixture Staging يحتوي تصنيفاً معتمداً للحساب 167، ويُصلح الكود ليكتب أعضاء ENUM الإنجليزية فقط، مع حفظ نوع العلاقة في الحقول المخصصة أو جدول الربط. بعد ذلك تعاد عملية Restore وGate الحركة 456 من البداية.

## 11. ما يحتاج موافقة جديدة

يحتاج أي من الإجراءات التالية موافقة صريحة جديدة: تعديل الصف 167 أو أي حساب تاريخي، Sanitization للـSnapshot، تعديل `account_type` أو أي Schema، تغيير الكود في المسار الإنتاجي، أو تغيير سياسة تصنيف العملاء والوكلاء بين `account_type` و`account_sub_type`.

## 12. هل يمكن إعادة Restore بأمان الآن؟

**لا، ليس بنفس Snapshot وطريقة Restore الحالية.** يمكن إعادة Restore بأمان فقط بعد اختيار أحد مسارين معتمدين: إعداد Snapshot Sanitized منفصل مع توثيق كامل للقيمة البديلة، أو إنشاء Staging Fixture/Restore transform واضح ومراجع. لا يجوز تجاوز التحذير بتغيير SQL mode، ولا يجوز الانتقال إلى `currencies` أو transaction 456 قبل اعتماد هذا القرار.

تم تنفيذ Rollback لـStaging بعد التحقيق دون أخطاء، ولم تُنفذ أي كتابة على Production.

## References

[1]: ./admin/customers.php "Customer account creation path"

[2]: ./admin/agents.php "Agent account creation path"

[3]: ./admin/invoice_details.php "Account type normalization helper"

[4]: ./database/migrations/2026_08_11_007_financial_foreign_keys.sql "Financial foreign-key migration"

[5]: ./database/migrations/2026_08_11_008_financial_fk_completion.sql "Financial foreign-key completion migration"
