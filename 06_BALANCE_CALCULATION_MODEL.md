# 06 — BALANCE CALCULATION MODEL

**النطاق:** Production / قراءة فقط  
**التاريخ:** 14 أغسطس 2026  

يوجد نموذجان فعليان. في PHP تستخدم `apply_transaction_balances()` طبيعة الحساب: `debit-credit` للأصول والمصروفات و`credit-debit` للخصوم والإيرادات، مع فرع وفallback. في SQL المستودع، `sp_rebuild_balances` يجمع `debit-credit` لجميع الحسابات ولا يربط الفرع أثناء التجميع.

| البعد | PHP | `sp_rebuild_balances` |
|---|---|---|
| normal_balance | نعم | لا |
| branch_id | نعم/fallback | لا |
| status posted | عبر مسار الحركة | نعم |
| opening balance | يحافظ على الصف | يحافظ عليه |
| reversal | يعتمد على الحركة والمسار | يعتمد على حالة posted فقط |
| base currency | حسب مسار PHP | `currencies.exchange_rate` |

هذا **تعارض منطق محاسبي حرج** وليس إصلاحاً منفذاً. يجب اختبار نموذج واحد على نسخة Staging قبل اختيار معادلة الإنتاج.

## المراجع

[1]: ./ACCOUNT_BALANCE_ROOT_CAUSE_REPORT_20260814.md "تقرير السبب الجذري للأرصدة"
[2]: ./root_cause_findings.json "نتيجة الفحص القراءة فقط"
[3]: ./includes/accounting_functions.php "محرك PHP المحاسبي"
[4]: ./tools/database/alghazali.sql "مخطط وإجراءات قاعدة البيانات"
