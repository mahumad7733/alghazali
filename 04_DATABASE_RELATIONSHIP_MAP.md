# 04 — DATABASE RELATIONSHIP MAP

**النطاق:** Production / قراءة فقط  
**التاريخ:** 14 أغسطس 2026  

الكيانات المركزية والعلاقات المحاسبية هي:

```text
operational modules
  -> invoices (source_type/source_id, customer/supplier, branch, currency)
  -> financial_transactions (reference, party/cash accounts, status)
  -> payment_allocations (financial_transaction_id -> invoice_id)
  -> journal_entries / journal_lines (financial_transaction_id, account_id, currency_id)
  -> account_balances_unified (account_id, branch_id, currency_id)
  -> audit_logs (table_name, record_id, action)
```

| العلاقة | مفتاح الربط | ملاحظة رقابية |
|---|---|---|
| الحركة ← بنود القيد | `journal_lines.financial_transaction_id` | لا توجد orphan journal lines في الفحص السابق |
| الدفع ← الفاتورة | `payment_allocations` | تخصيص يتيم واحد يحتاج مراجعة |
| الحركة ← الحساب | `party_account_id`, `cash_bank_account_id` | يحدد الطرف والنقدية |
| الرصيد ← الحساب/العملة/الفرع | unique account/branch/currency | لا توجد مفاتيح رصيد مكررة |
| التدقيق ← المستند | `table_name`, `record_id` | 39 حركات بلا أثر مباشر مطابق في الفحص السابق |

المخطط الفعلي يضم تقريباً 154 جدولاً و4 Views و6 Triggers و34 Routine بحسب نتائج الاكتشاف. تعريفات الإجراءات في SQL المستودع لا تعني أن كل جسم إجراء متاح لمستخدم Web/PHP؛ `SHOW CREATE PROCEDURE` أعاد `NULL` لبعض الإجراءات بسبب صلاحيات/تعريف الخدمة، ولذلك يجب منح صلاحية قراءة محددة في Staging قبل اعتماد خريطة روتينات كاملة.

## المراجع

[1]: ./ACCOUNT_BALANCE_ROOT_CAUSE_REPORT_20260814.md "تقرير السبب الجذري للأرصدة"
[2]: ./root_cause_findings.json "نتيجة الفحص القراءة فقط"
[3]: ./includes/accounting_functions.php "محرك PHP المحاسبي"
[4]: ./tools/database/alghazali.sql "مخطط وإجراءات قاعدة البيانات"
