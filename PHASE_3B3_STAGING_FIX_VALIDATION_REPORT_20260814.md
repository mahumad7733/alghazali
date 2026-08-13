# PHASE 3B.3 — STAGING FIX AND VALIDATION REPORT

**التاريخ:** 2026-08-14  
**النطاق:** Staging فقط  
**Production:** Read-only بالكامل  
**القرار:** **NO-GO — STOPPED AT FIRST REAL-PATH GATE FAILURE**

> تمت الموافقة على إصلاحات Staging فقط. وبناءً على الشرط الإلزامي، أُوقفت المرحلة فور ظهور أول اختلاف في المسار الفعلي، ولم تُنفذ أي محاولة تصحيح تلقائي للبيانات أو متابعة للاختبارات اللاحقة.

## 1. بيئة التنفيذ والحدود

لا يملك مستخدم التطبيق صلاحية `CREATE DATABASE`، لكنه يملك قاعدة اختبار كاملة باسم `ghazali_booking_test` تحتوي على 154 جدولاً، ولذلك استُخدمت كبيئة Staging مستقلة عن `ghazali`. تم أولاً إنشاء Snapshot حديث من Production، ثم استُعيد إلى Staging، ثم نُسخت شجرة الكود إلى `/home/ubuntu/alghazali_staging` مع Patch خاص بها. لم يُعدّل الكود الموجود في شجرة المشروع الرئيسية ولم يُوجّه التطبيق الإنتاجي إلى Staging.

تأكد فحص الصلاحيات أن Production هي `ghazali`، وأن قواعد الاختبار المتاحة هي `ghazali_booking_test` و`ghazali_crm_test`. قاعدة `ghazali_crm_test` ليست نسخة ERP كاملة، إذ تحتوي على 31 جدولاً، لذلك لم تكن مناسبة لهذا الاختبار.

## 2. Snapshots وSHA-256

| النسخة | الجداول | الصفوف | SHA-256 |
|---|---:|---:|---|
| Production قبل التنفيذ | 154 | 13,509 | `8b2c6200338578d108b0390c09303dcd5444d7f89a492bdec6173adceedf8a25` |
| Staging قبل الاستعادة | 154 | 13,129 | `69898ccfb0f681e034a6db66a046d8dbcec30ca80f84d0d5ed1f75781afdb5c3` |
| Production بعد انتهاء Rollback | 154 | 13,509 | `4821a21817da12f65830e3826f00f95d7fddf17dc090697f9f62465c1821f5b8` |

تختلف بصمة الملف الكاملة بسبب سطر timestamp في رأس ملف Snapshot. بعد تجاهل هذا السطر، كانت البصمة المطبّعة قبل وبعد متطابقة:

```text
normalized_before = 44ce295943399cb87c10fd65a5f2f8f51da25d876d3398043bc8e5880876ddd0
normalized_after  = 44ce295943399cb87c10fd65a5f2f8f51da25d876d3398043bc8e5880876ddd0
```

وهذا يثبت أن بيانات Production لم تتغير خلال المرحلة.

## 3. الاستعادة إلى Staging

تمت استعادة Snapshot Production إلى `ghazali_booking_test` بنجاح:

| العنصر | النتيجة |
|---|---|
| أوامر الاستعادة المنفذة | 523 |
| أخطاء الاستعادة | 0 |
| `financial_transactions` | 46 |
| `invoices` | 57 |
| `journal_lines` | 90 |
| `account_balances_unified` | 39 |
| `unified_accounts` | 109 |
| `currencies` | 3 |
| `branches` | 1 |

## 4. الإصلاحات المطبقة على Staging قبل الاختبار

تم تطبيق Migration واحدة على Staging فقط باسم:

`20260814_phase3b3_staging_fix.sql`

وتضمنت نظرياً وعملياً في Staging:

| الإصلاح | الحالة قبل التوقف |
|---|---|
| `financial_balance_application_log` مع `event_key` فريد | طُبق بنجاح |
| قفل الحركة أثناء تطبيق الأثر | موجود في Patch الكود |
| منع Duplicate Application | موجود في Patch الكود |
| استخدام `normal_balance` | موجود في Patch الكود وSP Staging |
| استخدام `financial_transactions.exchange_rate` التاريخي | موجود في Patch الكود وSP Staging |
| NULL-safe branch lookup | موجود في Patch الكود وSP Staging |
| الحفاظ على `opening_balance` | موجود في SP Staging |
| Full Rebuild موحد | أُنشئ في Staging |
| توحيد Audit للتطبيق | أُضيفت حقول Application Log المطلوبة |

تم فحص بناء ملف `includes/accounting_functions.php` في نسخة Staging ونجح `php -l`، كما نجح فحص بناء مشغلات الاختبار.

## 5. الجدول المتأثر والإجراء المخزن

| الكائن | العملية | النتيجة بعد Rollback |
|---|---|---|
| `financial_balance_application_log` | CREATE TABLE في Staging | حُذف بنجاح |
| `sp_rebuild_balances` | DROP/CREATE في Staging | أُزيل الإجراء التجريبي بنجاح |
| `account_balances_unified` | كان سيُحدث عبر التطبيق/Rebuild | لم تُجرَ مصالحة نهائية ولم يبقَ تعديل بعد Rollback |
| `financial_transactions` | قراءة وقفل داخل المسار التجريبي | لم يبقَ تعديل على البيانات بعد Rollback |
| `journal_lines` | قراءة فقط في أول Gate | لم تتغير |
| Production `ghazali` | لا عملية كتابة | لم يُلمس |

## 6. أول اختبار للمسار الفعلي وسبب التوقف

تم تشغيل النسخة المعدلة من المسار الفعلي `apply_transaction_balances()`، وليس Prototype، على الحركة التالية:

| الحقل | القيمة |
|---|---|
| `transaction_id` | 456 |
| `transaction_number` | `PMT-26-00010` |
| الحالة | `posted` |
| النوع | `payment` |
| المبلغ | 250.00 |
| العملة | `currency_id=1` |
| الفرع | 1 |
| عدد بنود القيد | 2 |

تم إيقاف المرحلة فوراً بالخطأ التالي:

```text
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'code' in 'SELECT'
```

المصدر هو افتراض وجود `currencies.code` في مسار إدخال رصيد جديد داخل Patch. الـSchema الحقيقي المستعاد لا يحتوي هذا العمود؛ فحقل كود العملة في التصميم الحالي ليس بهذه التسمية. هذا اختلاف Schema حقيقي، ولذلك لم يتم تعديل الاستعلام تلقائياً ولم تُستكمل أي اختبارات.

## 7. الاختبارات التي لم تُنفذ بسبب شرط التوقف

وفق التعليمات، لم تُنفذ بعد أول فشل:

| الاختبار | الحالة |
|---|---|
| Post الكامل | لم يُستكمل |
| Unpost | لم يُنفذ بعد التوقف |
| Cancel | لم يُنفذ بعد التوقف |
| Reverse الكامل | لم يُنفذ بعد التوقف |
| Retry وDuplicate Request | لم يُنفذا بعد التوقف |
| Double-click | لم يُنفذ بعد التوقف |
| Concurrent Requests | لم يُنفذ بعد التوقف |
| Duplicate Reversal وReversal of Reversal | لم يُنفذا بعد التوقف |
| Multiple Currencies | لم يُنفذ بعد التوقف |
| Multiple Branches وNULL | لم يُنفذا بعد التوقف |
| Non-zero Opening Balance | لم يُنفذ بعد التوقف |
| Full Rebuild = Incremental PHP = Stored Procedure = Expected Ledger | لم يُعتمد؛ Gate فشل قبل المقارنة |
| المصالحة الشاملة للحسابات | لم تُستكمل بعد التوقف |

## 8. الحسابات الحرجة

لم يتم إنشاء أي قيد تعويضي أو تعديل يدوي للحسابات التالية:

| الحساب | العملة | السياسة أثناء المرحلة |
|---:|---|---|
| 5 | YER | لم يُلمس؛ ما زال فرق 50,000 غير مثبت تاريخياً |
| 164 | YER | لم يُلمس؛ ما زال فرق 30,000 غير محسوم كـProjection policy |
| 164 | SAR | لم يُلمس؛ ما زال فرق 9,000 بحاجة إلى مصالحة كاملة |
| 168 | SAR | لم يُلمس؛ ما زال فرق 250 مرتبطاً باختلاف العكس/Projection السابق |

## 9. Rollback وإزالة آثار Staging

بعد توقف Gate، نُفذت الإجراءات التالية على Staging فقط:

1. أُعيدت `ghazali_booking_test` إلى Snapshot ما قبل المرحلة عبر 519 أمراً، دون أخطاء.
2. أزيل جدول `financial_balance_application_log`.
3. أزيل إجراء `sp_rebuild_balances` التجريبي.
4. عاد عدد جداول Staging إلى 154.
5. لم تُنفذ أي اختبارات إضافية بعد الفشل.

النسخة البرمجية المعدلة بقيت في مجلد Staging المحلي خارج شجرة المشروع الرئيسية لأغراض المراجعة، ولم تُدفع إلى GitHub ولم تُفعّل على التطبيق الإنتاجي.

## 10. الملفات وMigrations

### ملفات Staging المعدلة أو المنشأة

| الملف | الحالة |
|---|---|
| `/home/ubuntu/alghazali_staging/includes/accounting_functions.php` | Patch تجريبي فقط، خارج Production وخارج Git الرئيسي |
| `/home/ubuntu/alghazali_staging/database/migrations/20260814_phase3b3_staging_fix.sql` | Migration Staging فقط |
| أدوات التحكم والاختبار تحت `tools/database/phase3b3_*` | مؤقتة للتنفيذ ثم أزيلت من المسار العام بعد Rollback |

### Migration المنفذة

تم تنفيذ `20260814_phase3b3_staging_fix.sql` على `ghazali_booking_test` فقط. لم تُنفذ أي Migration على `ghazali`.

## 11. القرار

# NO-GO — STOPPED AT FIRST REAL-PATH GATE FAILURE

السبب المباشر هو عدم توافق Patch مع Schema الحقيقي: الاستعلام يعتمد على `currencies.code` غير الموجود. وبما أن المستخدم اشترط التوقف عند أول اختلاف، لم يتم تصحيح الاستعلام تلقائياً ولم تُنفذ بقية الاختبارات.

لا يُسمح بالانتقال إلى Production أو اعتبار النظام جاهزاً. الخطوة التالية تحتاج موافقة جديدة بعد مراجعة سبب Schema mismatch وتحديد اسم/مصدر كود العملة الصحيح في المخطط الفعلي، ثم إنشاء Staging جديدة من Snapshot وإعادة الاختبار من البداية.

## References

[1]: ./PHASE_3B1_PRE_FIX_DESIGN_IMPACT_ANALYSIS_20260814.md "Pre-fix design and impact analysis"

[2]: ./PHASE_3B2_STAGING_FIX_DESIGN_VALIDATION_20260814.md "Prototype Staging validation"

[3]: ./includes/accounting_functions.php "Production source accounting function; not modified in this phase"

[4]: ./tools/database/alghazali.sql "Repository SQL definitions"

[5]: ./PHASE3B3_BASELINE_NOTES.md "Internal Staging baseline and execution notes"
