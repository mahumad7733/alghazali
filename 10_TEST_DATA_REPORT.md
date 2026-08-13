# 10 — TEST DATA CANDIDATES

**النطاق:** Production / قراءة فقط  
**التاريخ:** 14 أغسطس 2026  

تم الاحتفاظ بكل البيانات. المؤشرات السابقة وجدت 27 فاتورة و36 حركة مالية تطابق مؤشرات اختبار/Debug أو نمط أرقام العينة؛ لا يكفي رقم `INV-26-*` وحده لتأكيد أنها اختبار.

| السجل/النمط | النوع | الحالة | الأثر | الثقة |
|---|---|---|---|---|
| `B0001`, transaction 459 | Receipt | Draft | بلا journal lines، لا أثر مرحّل | Likely Test |
| `F0001`, transaction 433 | Invoice/customer | Reversed | له journal وعكس 456 | Unknown / Operational possible |
| `PMT-26-00010`, transaction 456 | Reversal | Posted | يؤثر في الحساب 168 | Operational/Test unknown |
| `INV-26-*` و`PUR-26-*` | Invoice/financial | متفاوتة | بعضها مرحّل | Unknown |
| `RCT-26-00035/36` و`PMT-26-00008/09` | Receipt/Reversal | Reversed/Posted | يؤثر في الحسابين 5 و164 | Operational/Test unknown |

لا يُصنف أي سجل كـ Confirmed Test إلا بعد ربطه بالمستخدم والوصف والطلب أو بيئة الاختبار.

## المراجع

[1]: ./ACCOUNT_BALANCE_ROOT_CAUSE_REPORT_20260814.md "تقرير السبب الجذري للأرصدة"
[2]: ./root_cause_findings.json "نتيجة الفحص القراءة فقط"
[3]: ./includes/accounting_functions.php "محرك PHP المحاسبي"
[4]: ./tools/database/alghazali.sql "مخطط وإجراءات قاعدة البيانات"
