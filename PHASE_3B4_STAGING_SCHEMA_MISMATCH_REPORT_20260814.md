# PHASE 3B.4 — STAGING SCHEMA MISMATCH VALIDATION

## القرار

**NO-GO — STOPPED DURING FRESH STAGING RESTORE.** لم يتم لمس Production، ولم يتم تنفيذ أي إصلاح أو Migration أو قراءة Schema لـ`currencies` بعد ظهور خطأ Restore.

## العملية

تم استخدام Snapshot Production الأصلي `production_before_phase3b3_original.sql` لاستعادة قاعدة `ghazali_booking_test` كـStaging جديدة. نفذت الأداة 522 عبارة، ثم توقفت عند أول خطأ وفق تعليمات المرحلة:

```text
SQLSTATE[01000]: Warning: 1265 Data truncated for column 'account_type' at row 7
```

الخطأ ظهر أثناء Restore قبل خطوة قراءة `SHOW COLUMNS FROM currencies`. لذلك لم يتم تحديد حقل كود العملة، ولم يتم تعديل Patch، ولم تُشغّل Real-Path Gate للحركة 456، ولم تُنفذ أي عملية مالية أو Rebuild أو Recalculate.

## الحالة

| المجال | النتيجة |
|---|---|
| Production writes | صفر |
| Production migrations | صفر |
| Staging restore | متوقف عند تحذير Data truncated |
| Schema currencies | لم يُفحص بعد التوقف |
| Patch | لم يُعدّل |
| transaction 456 | لم يُختبر |
| الاختبارات اللاحقة | لم تُنفذ |

## سبب التوقف

تعليمات المستخدم تنص على التوقف عند ظهور أي اختلاف جديد أثناء التنفيذ وعدم محاولة تصحيحه تلقائياً. خطأ `account_type` اختلاف في بيانات/Schema الاستعادة، وليس من الآمن تجاوزه أو تغيير SQL mode أو تعديل Schema دون تحليل وموافقة منفصلة. لذلك توقفت المرحلة قبل أي إصلاح.

## Production

لم تُنفذ أي أوامر كتابة على `ghazali`. لا يوجد دليل على تغيير Production في هذه المرحلة.

## الخطوة المطلوبة قبل الاستمرار

يجب تحليل توافق `account_type` بين Snapshot وSchema Staging، وتحديد ما إذا كان سبب التحذير موجوداً في المصدر أو الهدف، ثم طلب موافقة مستقلة إذا احتاج الحل إلى تعديل Schema أو سياسة Restore. بعد ذلك فقط يمكن إنشاء Staging جديدة وإعادة المحاولة.

## Rollback Evidence

بعد توقف Restore، أُعيدت Staging إلى Snapshot السابق بواسطة أداة Rollback مستقلة. نُفذت 521 عبارة، ولم تظهر أي أخطاء، وعاد عدد الجداول إلى 154. أزيلت كائنات الاختبار المؤقتة أثناء Rollback. Production بقيت Read-only ولم تُنفذ عليها أي عبارة كتابة.

يجب عدم اعتبار هذه المرحلة نجاحاً للإصلاح؛ فالـRestore نفسه لم يكتمل من Snapshot Production بسبب اختلاف `account_type`، ولذلك لم يبدأ فحص `currencies` ولم تُنفذ Gate الحركة 456.
