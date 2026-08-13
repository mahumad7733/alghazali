# 13 — PHASE 3B CONTROLLED FIX PLAN

**النطاق:** Production / قراءة فقط  
**التاريخ:** 14 أغسطس 2026  

لا تبدأ هذه الخطة إلا بعد اعتماد التقرير. التسلسل الإلزامي هو:

```text
Backup Production
  ↓
Restore Test / prove backup
  ↓
Clone to Staging
  ↓
Implement one controlled change
  ↓
Run migration only on Staging
  ↓
Verify accounting and permissions
  ↓
Run reconciliation engine
  ↓
Run rollback test
  ↓
Obtain explicit Production approval
  ↓
Maintenance window + monitored deployment
  ↓
Post-deployment reconciliation
```

الأولوية الأولى ليست تعديل الرصيد، بل توحيد مصدر الحقيقة وسياسة العكس. بعد ذلك تُختبر معالجة `branch_id=NULL`، وربط Audit Trail، والتخصيص اليتيم، ثم فروقات الأرصدة. يمنع إنشاء قيد تعويضي لمجرد مطابقة رقم مخزن.

شروط الانتقال غير المكتملة حالياً هي: إثبات مصدر 50,000/30,000/9,000، اعتماد سياسة العكس، تفسير الحركات الست بلا فرع، وربط 39 حركة بأثر تدقيق موحد. لذلك يبقى القرار `NO-GO FOR CONTROLLED FIXES` حتى اعتماد أدلة إضافية.

## المراجع

[1]: ./ACCOUNT_BALANCE_ROOT_CAUSE_REPORT_20260814.md "تقرير السبب الجذري للأرصدة"
[2]: ./root_cause_findings.json "نتيجة الفحص القراءة فقط"
[3]: ./includes/accounting_functions.php "محرك PHP المحاسبي"
[4]: ./tools/database/alghazali.sql "مخطط وإجراءات قاعدة البيانات"
