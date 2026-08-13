# 01 — SYSTEM ARCHITECTURE

**النطاق:** Production / قراءة فقط  
**التاريخ:** 14 أغسطس 2026  

النظام تطبيق PHP إداري يستقبل عمليات الوحدات التشغيلية من صفحات `admin/` وواجهات AJAX، ثم يربطها بالفواتير والحركات المالية وبنود القيود. طبقة الحسابات الموحدة هي `unified_accounts`، وجدول الرصيد التجميعي هو `account_balances_unified`، بينما السجل المحاسبي التفصيلي هو `journal_lines` المرتبط بـ `financial_transactions`.

| طبقة | المكونات الفعلية | الدور |
|---|---|---|
| العرض | `admin/*.php` وملفات AJAX | إدخال وعرض الحجوزات والفواتير والسندات |
| الخدمة | `core/Finance/*`, `includes/accounting_functions.php` | التحقق، الترحيل، التدقيق، تحديث الرصيد |
| المستندات | `invoices`, `financial_transactions`, `payment_allocations` | دورة البيع والشراء والتحصيل والصرف |
| دفتر الأستاذ | `journal_entries`, `journal_lines` | القيد التفصيلي والتوازن |
| الرصيد | `account_balances_unified` | ناتج/تجميع مع افتتاحية محتملة |
| الرقابة | `audit_logs`, صلاحيات، فترات مالية | قابلية التتبع والمنع |

تدفق البيانات العام هو: صفحة تشغيلية ← فاتورة أو سند ← ترحيل محكوم ← بنود قيد ← تحديث الرصيد ← Audit Trail. ما زال وجود أكثر من مسار لتحديث الرصيد خطراً لأن PHP يحترم `normal_balance` والفرع، بينما `sp_rebuild_balances` في SQL المستودع يستخدم `debit-credit` ولا يميز الفرع [3] [4].

## المراجع

[1]: ./ACCOUNT_BALANCE_ROOT_CAUSE_REPORT_20260814.md "تقرير السبب الجذري للأرصدة"
[2]: ./root_cause_findings.json "نتيجة الفحص القراءة فقط"
[3]: ./includes/accounting_functions.php "محرك PHP المحاسبي"
[4]: ./tools/database/alghazali.sql "مخطط وإجراءات قاعدة البيانات"
