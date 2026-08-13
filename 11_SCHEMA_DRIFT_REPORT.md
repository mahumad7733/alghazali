# 11 — SCHEMA DRIFT REPORT

**النطاق:** Production / قراءة فقط  
**التاريخ:** 14 أغسطس 2026  

مقارنة المخطط والقراءة الفعلية أظهرت أن القاعدة الفعلية تقريباً 154 جدولاً و4 Views و6 Triggers و34 routines، بينما توجد تعريفات SQL وpatches متعددة في المستودع. هذا يخلق احتمال Schema Drift إذا طُبق ملف SQL كامل على قاعدة قائمة.

| مجال | الملاحظة | الخطورة |
|---|---|---|
| Procedures | `SHOW CREATE` أعاد NULL لبعض الإجراءات لمستخدم Web/PHP | High: صلاحية/تعريف غير قابل للتحقق |
| Balance table | مفاتيح فريدة متعددة، وحقول افتتاحية/base | Medium/High |
| Triggers | 6 تحقق من قيم الحركات والفواتير والقيود، لا تحدث الرصيد مباشرة | Medium |
| SQL patches | أكثر من ملف patch وتعريفات إجراءات | High |
| CLI vs Web | CLI يشير إلى قاعدة/منفذ مختلفين | Critical للتشخيص |

لا يُسمح بأي Migration قبل أخذ Schema Snapshot ومقارنته بـ information_schema في Staging وProduction.

## المراجع

[1]: ./ACCOUNT_BALANCE_ROOT_CAUSE_REPORT_20260814.md "تقرير السبب الجذري للأرصدة"
[2]: ./root_cause_findings.json "نتيجة الفحص القراءة فقط"
[3]: ./includes/accounting_functions.php "محرك PHP المحاسبي"
[4]: ./tools/database/alghazali.sql "مخطط وإجراءات قاعدة البيانات"
