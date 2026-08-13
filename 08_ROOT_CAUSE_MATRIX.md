# 08 — ROOT CAUSE MATRIX

**النطاق:** Production / قراءة فقط  
**التاريخ:** 14 أغسطس 2026  

| الفرق | التصنيف | الدليل الحالي | الدليل المطلوب للإثبات | الإجراء الموصى به لاحقاً |
|---|---|---|---|---|
| 50,000 YER للحساب 5 | UNKNOWN | افتتاحية 0، لا حركة مدينة حالية بقيمة 50,000 | Backup/archived ledger/rebuild log | فحص Staging لا تعديل |
| 30,000 YER للحساب 164 | HISTORICAL/DATA | المخزن 35,500 والحركة 5,500 | أرشيف أو سجل فتح | توثيق مصدر |
| 9,000 SAR للحساب 164 | HISTORICAL/DATA | المخزن 8,100 والحركة -900 | أرشيف وتحويل عملة | توحيد السياسة بعد اختبار |
| 250 SAR للحساب 168 | REVERSAL POLICY | الفرق يطابق عكس F0001 | سياسة مكتوبة واختبار أصل/عكس | اختبار عكسي في Staging |
| الحسابات 34/37/39/46/48/49/165/166/169 | HISTORICAL/DATA | رصيد غير صفري بلا حركة حالية/افتتاحية | أرشيف تاريخي | مصالحة حسابية |
| PHP vs procedure | ACCOUNTING LOGIC BUG | normal_balance/branch مقابل debit-credit/no branch | اختبار نتائج موحد | توحيد محرك واحد |
| branch NULL | UNKNOWN/DATA | 6 حركات بلا فرع | سياسة الفرع العام/مستندات الأصل | اعتماد سياسة لا تحديث مباشر |


## المراجع

[1]: ./ACCOUNT_BALANCE_ROOT_CAUSE_REPORT_20260814.md "تقرير السبب الجذري للأرصدة"
[2]: ./root_cause_findings.json "نتيجة الفحص القراءة فقط"
[3]: ./includes/accounting_functions.php "محرك PHP المحاسبي"
[4]: ./tools/database/alghazali.sql "مخطط وإجراءات قاعدة البيانات"
