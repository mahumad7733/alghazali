=======================================================
 تقرير المرحلة الثالثة - Database Safety Audit
 نظام AlGhazali ERP - إعادة هيكلة FinanceService
 التاريخ: 2026-08-06
=======================================================

⚠️  الهدف: لا يوجد أي تعديل على قاعدة البيانات في هذه المرحلة.
     الملف هو تحليل توثيقي فقط قبل أي Migrations مستقبلية.

القاعدة المطبقة في هذا التقرير:
- لا كتابة SQL تنفذي (لا CREATE / ALTER / DROP / DELETE).
- توثيق الحالة الراهنة فقط.
- أي تعديل مستقبلي يسبقه Migration كامل مع Rollback مكتوب.

=======================================================
 الفصل 1: إجمالي الجداول المالية تحت المراقبة (10 جداول أساسية)
=======================================================

 الرقم | اسم الجدول                       | نوع البيانات المحفوظة           | الحجم التقديمي (صفوف) | درجة الخطورة
-------|----------------------------------|--------------------------------|----------------------|------------
  1    | invoices                         | الفواتير والسندات (5 أنواع)    | ~370+ صفوف           | 🔴 عالية
  2    | financial_transactions           | المعاملات المالية (ترحيل)     | ~30+ صفوف            | 🔴 عالية
  3    | journal_lines                    | القيود اليومية (مدين/دائن)    | ~55+ زوج صفوف        | 🔴 عالية
  4    | payment_allocations              | تخصيص الدفعات على الفواتير    | 1 صفوف              | 🔴 عالية
  5    | unified_accounts                 | شجرة الحسابات المحاسبية       | ~70+ حسابات          | 🟠 متوسطة
  6    | account_balances_unified         | أرصدة الحسابات (متوسطة يوميا) | ~25+ صفوف            | 🔴 عالية
  7    | customers                        | العملاء + روابط حساباتهم      | ~7+ عملاء            | 🟠 متوسطة
  8    | suppliers                        | الموردين + روابط حساباتهم     | ~12+ موردين          | 🟠 متوسطة
  9    | currencies                       | العملات + أسعار الصرف الحالية | 3 عملات              | 🟡 منخفضة
 10    | fiscal_periods                   | الفترات المالية (أشهر 1-12)   | 12 صفوف              | 🟠 متوسطة
 11*   | audit_logs                       | سجل التدقيق المالي (أمان عام) | ~30+ صفوف            | 🟡 منخفضة

* تم إضافة audit_logs كجدول أمان محيطي — إضافي على الطلب الأصلي.

=======================================================
 الفصل 2: وصف تفصيلي لكل جدول + الأعمدة + المفاتيح
=======================================================

----------------------------------------------------------------
 الجدول 1: invoices  (الفواتير والسندات — الطول الكلي 41 عمود)
----------------------------------------------------------------

📋 الأعمدة الأساسية (مُصنّفة):

 فئة العمود       | الأعمدة                                             | النوع
------------------|----------------------------------------------------|----------
 المفتاح الرئيسي  | id                                                 | INT (PK)
 الأرقام والتواريخ| invoice_number(UK), invoice_date, due_date, reversed_from(FK-self)| UK/VARCHAR/INT
 الهوية والفرع    | branch_id, source_type, source_id, service_id      | INT/VARCHAR
 الأطراف الثالثة | customer_id, supplier_id, agent_id, branch_entity_id | INT
 التكاليف        | cost_center_id, currency_id, total_amount(18,2)     | INT/DECIMAL
 الخصومات والضرائب| discount(15,2), tax_amount, tax_rate(5,2), net_amount | DECIMAL
 التكلفة الشرائية | cost_amount(15,2) NOT NULL DEFAULT 0               | DECIMAL
 الدفع           | payment_type, delivery_type, amount_received(15,2)  | VARCHAR/DECIMAL
 حسابات القيد    | account_id, customer_account_id, supplier_account_id | INT
 حالات الفاتورة  | payment_status (unpaid/partial/paid/overpaid)       | VARCHAR
                  | invoice_status (draft/review/approved/posted...)    | VARCHAR
 وصف              | description TEXT                                    | TEXT
 إنشاء / تحديث    | created_by(N), created_ip(45), created_user_agent,  | INT/VARCHAR/TEXT
                  | created_at, updated_at, updated_by                  | DATETIME/INT
 الترحيل         | posted_at, posted_by, posted_ip, posted_by_name     | DATETIME/INT/VARCHAR

🔑 الفهارس (من alghazali.sql سطر 18685-18705):
  • PRIMARY KEY (id)
  • UNIQUE  invoice_number (invoice_number)
  • FK-like: fk_inv_cur (currency_id), idx_inv_customer (customer_id),
             idx_inv_supplier (supplier_id), idx_inv_agent (agent_id)
  • الفرع: idx_inv_branch, idx_invoices_branch, idx_inv_branch_status,
           idx_invoices_branch_id
  • البحث: idx_inv_date_status (invoice_date,invoice_status)
  • المصدر: idx_inv_source (source_type, source_id)
  • الحالة: idx_inv_category_status, idx_invoices_invoice_status,
            idx_invoices_payment_status
  • التراجع: reversed_from (index)
  • الخدمة: idx_invoice_service_id
  • التتبع: idx_invoices_created_at

⚠️ ملاحظة: هناك أكثر من 6 فهارس مكررة تقريباً على (branch_id) وحده.
   هذا غير ضار حالياً ولكنه قد يسبب تباطؤ INSERT/UPDATE مع زيادة حجم البيانات.

🔔 القوادح (Triggers — من alghazali.sql سطر 5336-5365):
  • trg_invoices_check_bi  BEFORE INSERT
  • trg_invoices_check_bu  BEFORE UPDATE
  الوظيفة: ضمان أن جميع الأعمدة المالية (total_amount, discount, tax_amount,
            tax_rate, cost_amount, amount_received, net_amount) >= 0.
            وملء net_amount تلقائياً إذا كان فارغاً = total - discount + tax.

⚠️ 🔴 ملاحظة خطيرة: هذه القوادح تعمل على المستوى الصفري (per-row).
   إذا حذفنا عمودًا أو أضفنا عمودًا ماليًا جديدًا — يجب تحديثها يدويًا
   لاحقًا. أي نسيتها = سقط الصحة المالية.

----------------------------------------------------------------
 الجدول 2: financial_transactions (المعاملات المالية — 28 عمود)
----------------------------------------------------------------

📋 الأعمدة الأساسية (مُصنّفة):

 فئة العمود       | الأعمدة                                                   | النوع
------------------|----------------------------------------------------------|-----------
 المفتاح الرئيسي  | id                                                       | INT (PK)
 الأرقام          | transaction_number (UK ×2), unified_transaction_id       | VARCHAR/INT
 التواريخ         | transaction_date, created_at, updated_at, posted_at,    | DATE/DATETIME
                  | cancelled_at                                              |
 الهوية والفرع    | branch_id, transaction_type (receipt/payment/invoice...),| INT/VARCHAR
                  | status (draft/posted/reversed/cancelled...)              | VARCHAR
 الكيان          | entity_type, entity_id, party_account_id,                | VARCHAR/INT
                  | cash_bank_account_id, cost_center_id                     | INT
 المبلغ والعملة  | currency_id, amount(18,2), exchange_rate(15,6)           | INT/DECIMAL
 المرجع          | reference_number, reference_type (reversal/invoice...),  | VARCHAR/INT
                  | reference_id, description (TEXT)                         | INT/TEXT
 المستخدم        | created_by (NN), updated_by, posted_by, cancelled_by     | INT
 IP / UA          | created_ip, created_user_agent, updated_ip, posted_ip,   | VARCHAR(45)/TEXT
                  | cancelled_ip, cancellation_reason (TEXT)                 | VARCHAR/TEXT
 عكس المعاملات    | reversal_transaction_id (INDEX), is_reversed,             | INT/TINYINT
                  | original_voucher_id (INDEX), reversal_voucher_id (INDEX) | INT

🔑 الفهارس (alghazali.sql سطر 18570-18593):
  • PRIMARY KEY (id)
  • UNIQUE ×2: trans_num, idx_unique_transaction_number (مكرر!).
  • العملات: fk_ft_cur (currency_id)
  • الحالة/النوع: idx_ft_status_type (status, transaction_type), idx_ft_type, idx_ft_status
  • التواريخ: idx_ft_date (transaction_date), idx_ft_created_at
  • الحسابات: idx_ft_party (party_account_id), idx_ft_cash (cash_bank_account_id)
  • الفرع: idx_ft_branch, idx_financial_transactions_branch, idx_ft_branch_status
  • المرجع: idx_ft_ref (reference_type, reference_id), idx_ft_reference_type, idx_ft_reference_id
  • العكس: idx_reversal_transaction (reversal_transaction_id), original_voucher_id, reversal_voucher_id
  • مكررة: idx_ft_transaction_number (الرقم موجود أصلًا كـ UK)

🔔 القوادح (Triggers — alghazali.sql سطر 4856-4869):
  • trg_financial_transactions_check_bi  BEFORE INSERT
  • trg_financial_transactions_check_bu  BEFORE UPDATE
  الوظيفة: ضمان amount >= 0 و exchange_rate >= 0.0001.

🔴 ملاحظة: لا توجد أية قيود FK فعلية بين (financial_transactions ↔ journal_lines)
   أو (financial_transactions ↔ unified_accounts). العلاقات منطقية فقط.
   أي حذف مباشر لصف من unified_accounts قد يترك Orphan Records في الجدولين.

----------------------------------------------------------------
 الجدول 3: journal_lines (قيود اليومية — 9 أعمدة فقط)
----------------------------------------------------------------

📋 الأعمدة:  id (PK), branch_id, financial_transaction_id (NN), account_id (NN),
             cost_center_id, debit(15,2), credit(15,2), currency_id (NN),
             description (TEXT).

🔑 الفهارس (alghazali.sql سطر 18715-18729):
  • PRIMARY KEY (id)
  • FK-like: fk_jl_ft (financial_transaction_id), fk_jl_acc (account_id)
  • النسخ المكررة: idx_jl_ft_id, idx_journal_lines_branch, idx_jl_branch,
                   idx_jl_account, idx_jl_financial_transaction_id,
                   idx_jl_account_id, idx_jl_currency_id
  • مركبة: idx_jl_cost_center, idx_jl_account_currency (account_id, currency_id)

🔔 القوادح (alghazali.sql سطر 5451-5484 — الأهم صرامةً):
  • trg_journal_lines_check_bi  BEFORE INSERT
  • trg_journal_lines_check_bu  BEFORE UPDATE
  الوظيفة:
     IF  debit < 0  → SIGNAL SQLSTATE 45000 → خطأ قاتل يرفض الاستعلام
     IF credit < 0  → SIGNAL SQLSTATE 45000 → خطأ قاتل يرفض الاستعلام
     وتعويض NULL بالصفر.
  ⚠️ أي Refactoring يحاول إدراج قيم سلبية (حتى عن طريق الخطأ) في دفتر الأستاذ
     سيُرفض على مستوى قاعدة البيانات — مما يعطي طبقة حماية إضافية فوق كود PHP.

----------------------------------------------------------------
 الجدول 4: payment_allocations (تخصيص الدفعات — 5 أعمدة فقط)
----------------------------------------------------------------

📋 الأعمدة:  id (PK), financial_transaction_id (NN), invoice_id (NN),
             allocated_amount(15,2) (NN), created_at (DATETIME).

🔑 الفهارس (alghazali.sql سطر 18827-18837):
  • PRIMARY KEY (id)
  • UNIQUE  uq_payment_allocations_voucher_invoice (financial_transaction_id, invoice_id)
      → يمنع تخصيص نفس الدفعة على نفس الفاتورة مرتين — مهم جداً
  • FK-like: fk_pa_ft, idx_pa_ft, idx_pa_financial_transaction_id
  • FK-like: fk_pa_inv, idx_pa_inv, idx_pa_invoice_id

⚠️ لا توجد FK فعلية — فقط Indexes تُحاكي FK.
   على الرغم من أن الحقل (financial_transaction_id, invoice_id) موجود كـ Unique Key،
   أي حذف مباشر لـ invoice أو financial_transaction (غير المُخطط له)
   سيترك صفاً يتيمة هنا — لذلك هو الجدول الأكثر حساسية للـ Migrations السفلية.

----------------------------------------------------------------
 الجدول 5: unified_accounts (شجرة الحسابات المحاسبية — 18 عمود)
----------------------------------------------------------------

📋 الأعمدة الرئيسية:
  id, account_code (UK), account_name_ar,
  account_type (ENUM: asset/liability/equity/revenue/expense/box/bank/agent/branch/receivable/payable),
  account_sub_type (VARCHAR 30),
  owner_type (ENUM: system/agent/branch/employee/customer/supplier/other),
  normal_balance (ENUM: debit/credit NN),
  credit_limit_base, debit_limit_base,
  parent_id (FK ذاتي للشجرة), branch_id,
  is_active, account_status, created_at,
  بنكية: bank_account_number, bank_branch_address, bank_branch_number,
  deleted_at (soft delete).

🔑 الفهارس (alghazali.sql سطر 19027-19037):
  • PRIMARY KEY (id)
  • UNIQUE  account_code (account_code)
  • الشجرة: parent_id, idx_ua_parent, idx_ua_parent_status (parent_id, account_status)
  • الفرع: fk_acc_branch (branch_id)
  • النوع: idx_ua_code, idx_ua_type

⚠️ ملاحظة: ENUM (account_type) صارم جداً ولا يمكن إضافة نوع جديد بدون ALTER.
   أي خدمة مالية جديدة مستقبلية تحتاج نوع حساب جديد = Migration لا بد منها.

----------------------------------------------------------------
 الجدول 6: account_balances_unified (أرصدة الحسابات — 14 عمود)
----------------------------------------------------------------

📋 الأعمدة:
  id, account_id (NN), branch_id, currency_id (NN), currency_code,
  opening_balance, current_balance, current_balance_base,
  credit_limit, debit_limit, is_frozen,
  last_updated (ON UPDATE current_timestamp),
  cost_center_id,
  opening_balance_base.

🔑 الفهارس (alghazali.sql سطر 18324-18333):
  • PRIMARY KEY (id)
  • UNIQUE  uq_account_currency_global (account_id, currency_id)
  • UNIQUE  uq_account_branch_currency_cc (account_id, branch_id, currency_id, cost_center_id)
  • UNIQUE  unique_account_branch_currency (account_id, branch_id, currency_id)
  • FK-like: fk_bal_cur (currency_id)
  • مكررة: idx_ab_account_currency, idx_abu_account, idx_abu_currency,
           idx_account_balances_branch, idx_abu_branch

⚠️ الـ Unique Keys الثلاثة هنا يبدو أنها مراحل تطورية لنفس الفكرة.
   الخطر: لو حدث INSERT ب (account_id + currency_id + branch_id = NULL)
   ثم إعادة INSERT بنفس البيانات — في بعض الحالات قد لا يطبق الـ UK بشكل صحيح
   لأن الـ NULL != NULL في MySQL. جدول الأرصدة هو أكثر الجداول تعرضًا للخطأ.

----------------------------------------------------------------
 الجدول 7: customers (العملاء — 14 عمود + 4 أعمدة إضافية تمت إضافتها مؤخرًا)
----------------------------------------------------------------

📋 الأعمدة (من alghazali.sql سطر 4482-4498):
  id, account_id (INT NULL → روابط الحسابات المالية),
  full_name (NN), phone, whatsapp, nationality, start_date (DATE), address,
  branch_id, status (ENUM active/inactive), notes, created_at, deleted_at,
  updated_at, default_credit_limit (18,2 NN DEFAULT 0).

⚠️ 📝 إضافي: ملف _test_cash_fix.php (السطور 9-17) يضيف أثناء التطوير 5 أعمدة إضافية
   لا تظهر في alghazali.sql → customer_code, created_by, customer_status,
   is_default_cash, و Key (customer_code) Unique + Key (is_default_cash).
   هذه الأعمدة مطبقة بيئيًا لكنها غير موجودة في Dump الرسمي — وهي
   أول مرشح لـ Migration موحد لاحقًا.

🔑 الفهارس:
  PRIMARY KEY (id), branch_id, idx_cust_phone, idx_cust_account.

----------------------------------------------------------------
 الجدول 8: suppliers (الموردين — 10 أعمدة)
----------------------------------------------------------------

📋 الأعمدة:
  id, account_id (INT NULL), supplier_name (NN), supplier_phone,
  supplier_email, link, address,
  status (ENUM active/inactive/closed),
  created_at, updated_at (ON UPDATE), deleted_at.

🔑 الفهارس:
  PRIMARY KEY (id), idx_supp_account (account_id).

----------------------------------------------------------------
 الجدول 9: currencies (العملات — 11 عمود)
----------------------------------------------------------------

📋 الأعمدة:
  id, currency_name (NN), currency_symbol (NN), is_default,
  created_at, currency_code, exchange_rate (10,4),
  exchange_rate_sell (15,6), exchange_rate_buy (15,6),
  is_active (NN default 1),
  last_updated.

🔑 الفهارس:
  PRIMARY KEY (id), idx_exchange_rate, idx_is_default,
  idx_curr_code, idx_curr_default (مكرر الثاني من is_default).

----------------------------------------------------------------
 الجدول 10: fiscal_periods (الفترات المالية — 8 أعمدة)
----------------------------------------------------------------

📋 الأعمدة:
  id, period_name (NN), start_date (NN), end_date (NN),
  is_closed (TINYINT default 0), closed_by, closed_at, created_at.

🔑 الفهارس:
  PRIMARY KEY (id) فقط — لا يوجد فهرس على (is_closed) أو (start/end_date)
  رغم أن FinanceService يبحث بهما باستمرار!

⚠️ نقطة ضعف: استعلام assertFiscalPeriodOpen
  (WHERE ? BETWEEN start_date AND end_date AND is_closed=0) لا يمتلك فهرسًا.
  للانتاجية الكبيرة مستقبلاً = Migration إضافة INDEX ضروري.

----------------------------------------------------------------
 الجدول 11 (أمان محيطي): audit_logs (سجل التدقيق — 11 عمود)
----------------------------------------------------------------

📋 الأعمدة:
  id, user_id (NN), action (VARCHAR 100 NN), table_name (VARCHAR 50 NN),
  record_id, old_values (TEXT), new_values (TEXT),
  ip_address, created_at (NN), user_ip, user_agent (TEXT).

🔑 الفهارس:
  PRIMARY KEY (id) فقط.

=======================================================
 الفصل 3: العلاقات المنطقية + (غياب Foreign Keys الفعلية)
=======================================================

تم تفقد جميع الـ ALTER TABLE من سطر 18320 لنهاية الملف.
النتيجة المُهمة: لا يوجد أي ALTER TABLE ... ADD FOREIGN KEY يربط بين
الجداول العشرة المالية باستثناء الـ 3 علاقات التالية (تُعرف فقط من
الـ CREATE TABLE خارج هذه الدائرة):

 العلاقة الفعلية الوحيدة (المكتشفة سابقًا في Dependency Mapping):
    • invoices.reversed_from  →  invoices.id   (self-referencing FK)
    • bus_flight_bookings.invoice_id → invoices.id   (على الجدول السياحي)
    • passports.supplier_id  → suppliers.id

🔴🔴🔴 الأهم: عدم وجود FK فعلية بين الجداول المالية:
 الجدول الابن                      | الحقل                    | الجدول الأب المنطقي
----------------------------------|--------------------------|-------------------------
 financial_transactions.currency_id    | —                    | currencies.id      [لا FK]
 financial_transactions.party_account_id| —                    | unified_accounts.id [لا FK]
 financial_transactions.cash_bank_account_id | —               | unified_accounts.id [لا FK]
 journal_lines.financial_transaction_id | —                      | financial_transactions.id [لا FK]
 journal_lines.account_id              | —                      | unified_accounts.id [لا FK]
 journal_lines.currency_id             | —                      | currencies.id [لا FK]
 payment_allocations.financial_transaction_id | —               | financial_transactions.id [لا FK]
 payment_allocations.invoice_id         | —                      | invoices.id  [لا FK]
 invoices.currency_id                   | —                      | currencies.id [لا FK]
 invoices.customer_id                   | —                      | customers.id [لا FK]
 invoices.supplier_id                   | —                      | suppliers.id [لا FK]
 invoices.account_id / *_account_id     | —                      | unified_accounts.id [لا FK]
 account_balances_unified.account_id    | —                      | unified_accounts.id [لا FK]
 customers.account_id                   | —                      | unified_accounts.id [لا FK]
 suppliers.account_id                   | —                      | unified_accounts.id [لا FK]
 unified_accounts.parent_id             | —                      | unified_accounts.id [لا FK, لكن index موجود]
 unified_accounts.branch_id             | —                      | branches.id [لا FK]

الاستنتاج:
   ⚠️  سلامة التكامل المرجعي (Referential Integrity) لا تُحفظ بواسطة InnoDB
   على الإطلاق — بل تُحفظ بالكامل داخل كود PHP فقط!
   أي:
     • حذف صف من unified_accounts قد يُترك آلاف الصفوف يتيمة عبر 6 جداول.
     • تغيير نوع الحقل (مثلاً account_id من INT → BIGINT) مسموح به برمجياً
       وسيكسر جميع الجداول الأخرى دون أي تحذير.
   = التوصية الأمنية في هذا التقرير:
     ⛔️ ممنوع حذف أي صف أو تغيير نوع أي حقل ضمن الدائرة المالية
        طوال فترة إعادة الهيكلة (المراحل 4-12).

=======================================================
 الفصل 4: الجداول المستخدمة من الـ Stored Procedures (غير المتوفرة في alghazali.sql)
=======================================================

تم التوثيق من قِبل الطلب (Q2): الـ Stored Procedures الحالية هي ملك
النظام الإنتاجي و ممنوع تعديلها / إعادة كتابتها / تغيير Parameters /
تغيير نتائجها — فقط توثيقها هنا.

تم استخراج أسمائها من استدعاءات `CALL <اسم>` داخل كود PHP
(FinanceService + accounting_functions.php):

 الاسم                              | عدد البارمترات | موقع الاستدعاء الأساسي
------------------------------------|---------------|-----------------------------------------
 1. sp_create_invoice               | 17 + 1 OUT    | accounting_functions.php السطر 304
 2. sp_post_invoice                 | 2 IN          | accounting_functions.php السطر 2047
 3. sp_create_receipt_voucher       | 12 + 2 OUT    | FinanceService السطر 501
 4. sp_create_payment_voucher       | 12 + 2 OUT    | FinanceService السطر 582
 5. sp_ensure_opening_balance       | 7 IN          | FinanceService السطور 943, 1016
 6. sp_create_expense_voucher       | 13 + 2 OUT    | FinanceService السطر 1159
 7. sp_post_expense_voucher         | 2 IN          | accounting_functions.php السطر 1947
 8. sp_process_expense_approval     | 5 IN          | FinanceService السطر 1212

⚠️ الـ Dump الرسمي لـ alghazali.sql لا يحتوي صياغة CREATE PROCEDURE لهذه
   الإجراءات على الإطلاق — مما يعني أنها منشأة يدوياً على قواعد البيانات
   (أو عبر ملفات منفصلة غير مراقبة بـ Git).
   = هذا يُعد من أعظم المخاطر الأمنية: أي تغيير غير موثق لـ SP على الإنتاج
     لن يظهر في Git أو الـ Dump.

التوصية لاحقًا (خارج المرحلة الحالية):
   • إضافة Dump لجميع الـ Stored Procedures / Triggers / Views دوريًا
     ضمن مجلد tools/database/ (سطحي فقط — لا يطبق الآن).

=======================================================
 الفصل 5: القوادح (Triggers) الموجودة + الجداول التي تحميها
=======================================================

 تم العثور على 6 Triggers فقط (الكل ضمن Dumps):

 Trigger Name                       | الجدول                  | الحدث | مستوى الصرامة
------------------------------------|-------------------------|-------|----------------
 trg_invoices_check_bi              | invoices                | BI    | تنعيم القيم (GREATEST)
 trg_invoices_check_bu              | invoices                | BU    | تنعيم القيم
 trg_financial_transactions_check_bi| financial_transactions  | BI    | تنعيم القيم
 trg_financial_transactions_check_bu| financial_transactions  | BU    | تنعيم القيم
 trg_journal_lines_check_bi         | journal_lines           | BI    | صارم (SIGNAL SQLSTATE 45000)
 trg_journal_lines_check_bu         | journal_lines           | BU    | صارم (SIGNAL SQLSTATE 45000)

🚫 غياب Total Row Count Triggers: لا يوجد أي Trigger يقوم بتحديث
   current_balance في account_balances_unified عند إدراج قيد جديد.
   العملية كلها منفذة يدوياً عبر كود PHP (أو عبر SPs).

🚫 غياب Trigger لـ payment_status: لا يوجد Trigger يقوم بتحديث
   invoices.payment_status عند إدراج/تعديل صف في payment_allocations.
   يعتمد كلياً على الدالة php_recalculate_invoice_payment().

=======================================================
 الفصل 6: مخاطر تعديل قاعدة البيانات (Risk Matrix)
=======================================================

 درجة المخاطرة | الوصف                                                            | الإجراء الموصى به
---------------|------------------------------------------------------------------|--------------------------------------------
 🔴🔴 عالية جداً | تغيير نوع العمود (INT↔BIGINT / CHAR↔VARCHAR) لأي حقل ضمن       | ⛔️ ممنوع طوال دورة إعادة الهيكلة.
               | الحقول التالية: (id, account_id, customer_id, supplier_id,     | إذا اضطررنا: تطبيق Migration
               | financial_transaction_id, invoice_id, currency_id).            |   بخطوة واحدة + تشغيل SQL Verifier.
 🔴🔴 عالية جداً | حذف أو إعادة تسمية أي جدول مالي أو أي عمود مستخدم.            | ⛔️ ممنوع مطلقاً.
 🔴 عالية      | تعديل صياغة أحد الـ 6 SPs أو تغيير باراميتراتها.               | ⛔️ ممنوع حسب طلبك (Q2).
 🔴 عالية      | تعديل صياغة أحد الـ 6 Triggers.                                | 🚧 مسموح فقط عبر Migration رسمي.
 🔴 عالية      | إضافة عمود NOT NULL DEFAULT missing (أو بدون default) إلى جدول  | Migration + تحديث جميع الدوال INSERT
               | مزدحم حاليًا مثل invoices/journal_lines.                      |   في accounting_functions.php أولاً.
 🟠 متوسطة     | إضافة FK فعلية (ALTER TABLE ADD FOREIGN KEY) بين الجداول.       | ⚠️ يتطلب فحص Orphan Records قبلها +
               |                                                                  |   SQL Cleanup Stage + Rollback ممكن.
 🟠 متوسطة     | إضافة أعمدة جديدة (Nullable فقط) مثل: posted_user_name_ar أو    | 🟢 آمن نسبيًا عبر Migration فقط.
               | allocation_reference_id.                                       |
 🟠 متوسطة     | إضافة Indexes جديدة للأعمدة المذكورة تحت (نقاط الضعف):          | 🟢 آمن 100%. CREATE INDEX ONLINE تقريبًا
               |   • fiscal_periods(start_date,end_date,is_closed)               |   لا يحتاج Rollback عاجلاً.
               |   • audit_logs(table_name,record_id,created_at)                 |
               |   • invoices: إزالة الفهارس المكررة على branch_id                |   → أمان فقط: فحص EXPLAIN قبل الحذف.
 🟡 منخفضة     | زيادة دقة DECIMAL مثل (18,2) إلى (20,4).                        | Migration صحيح عبر MODIFY COLUMN.
 🟡 منخفضة     | إضافة View بسيطة للتقارير (فقط SELECT).                         | آمن 100% و لا يحتاج تغيير كود.
 🟡 منخفضة     | تمييز الـ ENUM بإضافة قيمة جديدة في نهاية القائمة (لأن آخرها    | آمن عبر MODIFY COLUMN.
               |   ليس له ترتيب).                                                 |

=======================================================
 الفصل 7: الجداول التي يُمنع تعديلها مباشرة أثناء Refactoring
=======================================================

هذه الجداول يجب التعامل معها كـ READ ONLY — أي تغيير يسبقه Migration رسمي:

 رمز الحظر | اسم الجدول                    | السبب
-----------|-------------------------------|----------------------------------------------------
 🚫 BLOCK  | unified_accounts              | شجرة الحسابات هي قلب النظام. هنا فقط: إضافة صفوف.
 🚫 BLOCK  | account_balances_unified      | أي تغيير هنا يعطل جميع تقارير الأرصدة.
 🚫 BLOCK  | journal_lines                 | القيود محفوظة قانونياً لـ 5+ سنوات. فقط INSERT.
 🚫 BLOCK  | financial_transactions        | المعاملات مسجلة. فقط UPDATE الحالات + INSERT.
 🚫 BLOCK  | payment_allocations           | مرتبطة بتوازن الفواتير. فقط INSERT + DELETE محدود.
 🚫 BLOCK  | currencies                    | أسعار الصرف مرتبطة بالسجلات السابقة.
 🟡 CAUTION| invoices                      | مسموح UPDATE الحالات فقط.
 🟡 CAUTION| customers / suppliers         | مسموح بتحديث account_id (عبر PartyAccountService).
 🟢 OK     | audit_logs                    | فقط INSERT طبيعي.
 🟢 OK     | fiscal_periods                | فقط UPDATE (is_closed).

=======================================================
 الفصل 8: خطة Migrations المستقبلية المقترحة (الوصف فقط — لا SQL)
=======================================================

هذه هي التعديلات المتوقع أن تكون ضرورية خلال المراحل 4-12 أو بعدها.
كل تعديل يُصاغ لاحقًا كـ Migration مستقل مع Rollback.

------------------------------------------------
 Migration #1 (الأولوية الأعلى): توحيد أعمدة customers الإضافية
------------------------------------------------

— اسم الملف المقترح:  database/migrations/2026_08_06_001_add_customer_extra_fields.sql
— السبب: الأعمدة customer_code, created_by, customer_status, is_default_cash مطبقة بيئيًا
         عبر _test_cash_fix.php لكنها غير موجودة في Dump الرسمي (عدم تطابق بيئات).
— طريقة التنفيذ:
   1. فحص وجود الأعمدة + الفهارس قبل ADD (IGNORE DUPLICATE style).
   2. ADD COLUMN للـ 4 أعمدة الجديدة كلها NULLable أو DEFAULT صحيح.
   3. ADD UNIQUE KEY + ADD KEY للـ customer_code + is_default_cash (إذ لم تكن موجودة).
   4. إنشاء عميل افتراضي واحد مع is_default_cash = 1 لكل فرع أو فرع عام NULL.
— Rollback:
   DROP INDEX + DROP COLUMN في نفس الترتيب العكسي.
— الاعتماديات: PartyAccountService.php (يستخدم is_default_cash).

------------------------------------------------
 Migration #2 (الأولوية عالية): إضافة FKs فعلية لـ payment_allocations
------------------------------------------------

— اسم الملف المقترح:  database/migrations/2026_08_06_002_add_fks_payment_allocations.sql
— السبب: الجدول هو الأكثر عرضة للـ Orphans لأنه لا FK حاليًا مع وجود Unique Index.
— طريقة التنفيذ:
   1. مرحلة تنظيف: SELECT orphaned records (invoice_id غير موجود) وإنشاء تقرير تدقيق.
   2. إصلاح الصفوف اليتيمة يدويًا قبل ALTER (تقسيم عمل خارجي).
   3. ALTER TABLE payment_allocations
        ADD CONSTRAINT fk_pa_invoice  FOREIGN KEY (invoice_id) REFERENCES invoices(id)
            ON DELETE RESTRICT,
        ADD CONSTRAINT fk_pa_ft FOREIGN KEY (financial_transaction_id)
            REFERENCES financial_transactions(id) ON DELETE CASCADE;
— Rollback:
   ALTER TABLE payment_allocations DROP FOREIGN KEY + DROP INDEX إضافي إذا تم إنشاؤه.
— التحذير: يتطلب تشغيل قبل الميغريشن:
   SELECT COUNT(*) FROM payment_allocations WHERE invoice_id NOT IN (SELECT id FROM invoices).
— المخاطرة: إذا كان هناك صفوف يتيمة → سيفشل ALTER. هذا هو المقصود — لنا اكتشاف المشاكل.

------------------------------------------------
 Migration #3 (الأولوية متوسطة): إضافة Indexes ضائعة على fiscal_periods + audit_logs
------------------------------------------------

— اسم الملف المقترح:  database/migrations/2026_08_06_003_add_missing_indexes.sql
— السبب: استعلام assertFiscalPeriodOpen يعمل بدون فهرس. audit_logs كبير بسرعة.
— طريقة التنفيذ:
   1. ALTER TABLE fiscal_periods
        ADD INDEX idx_fp_open_range (start_date, end_date, is_closed);
   2. ALTER TABLE audit_logs
        ADD INDEX idx_al_table_rec (table_name, record_id, created_at);
— Rollback:
   DROP INDEX.
— ملاحظة: هذا هو Migration الأكثر أمانًا ويمكن تطبيقه مبكرًا حتى قبل المرحلة 4.

------------------------------------------------
 Migration #4 (الأولوية متوسطة): إزالة الفهارس المكررة على branch_id
------------------------------------------------

— اسم الملف المقترح:  database/migrations/2026_08_06_004_remove_duplicate_indexes.sql
— السبب: 6+ فهارس مكررة تبطئ INSERT/UPDATE دون داعٍ (فواتير + معاملات + أرصدة).
— طريقة التنفيذ:
   1. تشغيل EXPLAIN لـ 10+ استعلامات شائعة قبل الحذف = لقطة أساسية.
   2. DROP INDEX لكل فهرس مكرر مثبت (مثل idx_invoices_branch_id الذي نسخة منه موجودة باسم آخر).
   3. إعادة تشغيل EXPLAIN.
— Rollback:
   إعادة CREATE INDEX لكل فهرس حُذف.
— الشرط: يتم تنفيذه فقط بعد فحص أدائي فعليًا على قاعدة بيانات حجمها حقيقي.

------------------------------------------------
 Migration #5 (الأولوية منخفضة — المستقبل البعيد): FKs لباقي الجداول
------------------------------------------------

— اسم الملف المقترح:  database/migrations/YYYY_MM_DD_XXX_add_core_financial_fks.sql
— يشمل:
   • journal_lines → financial_transactions.id (CASCADE على الحذف؟ لا RESTRICT أفضل).
   • journal_lines → unified_accounts.id (RESTRICT).
   • financial_transactions → unified_accounts.id (RESTRICT عبر party_account_id, cash_bank_account_id).
   • invoices → customers.id / suppliers.id / currencies.id (SET NULL أفضل لـ customer/supplier).
   • account_balances_unified → unified_accounts.id (RESTRICT).
   • customers.account_id → unified_accounts.id (SET NULL).
   • suppliers.account_id → unified_accounts.id (SET NULL).
— الشرط الأساسي: يتم تنفيذه فقط بعد تشغيل سكربت Orphan Detector كامل
  ودمج التقارير مع مدير النظام.

=======================================================
 الفصل 9: مخطط تنفيذ أي Migration (القواعد العامة للمرحلة المستقبلية)
=======================================================

☑️  قبل أي Migration يتم تنفيذ الخطوات التالية — بالترتيب:
  1. 🔒 أخذ Backup كامل لقاعدة البيانات (mysqldump أو الأداة الرسمية).
  2. ✍️ كتابة SQL الميغريشن + Rollback في ملفين منفصلين بترقيم متسلسل.
  3. 🧪 اختبار على قاعدة بيانات TEST = نسخة طبق الأصل من الإنتاج.
  4. 📝 تسجيل النتائج في REFACTORING_ISSUES.md إن انحرف سلوك أي استعلام.
  5. ⚡ تطبيق على الإنتاج خلال نافذة صيانة (ساعة الذروة المنخفضة).
  6. 🧾 تنفيذ SELECT تحقق بعد الـ Migration للتأكد من صحة البيانات.
  7. 💾 Commit لملف الـ Migration + Rollback إلى Git.

=======================================================
 الفصل 10: ملخص توصيات المرحلة الثالثة (قبل الانتقال للمرحلة 4)
=======================================================

| نـ. | التوصية                                                                   | درجة الأهمية |
|-----|---------------------------------------------------------------------------|--------------|
|  1  | ممنوع الحذف أو إعادة التسمية أو تغيير نوع أي عمود مالي خلال Refactoring. | ضروري القطعي |
|  2  | تطبيق Migration #3 (الفهارس الضائعة لـ fiscal_periods + audit_logs) مبكراً| مهم جدًا    |
|  3  | تطبيق Migration #1 (أعمدة customers الإضافية) لاحقًا بعد التأكيد.         | مهم          |
|  4  | تأجيل أي FK (Migrations #2 و #5) إلى بعد المرحلة العاشرة على الأقل.       | مُؤجَّل آمنًا|
|  5  | سكربت Orphan Detector دوري قبل أي FK Migration.                         | مهم جدًا     |
|  6  | الـ 6 SPs ممنوعة تمامًا — لا لمس.                                        | قراره منك (Q2)|
|  7  | الـ 6 Triggers: أي إضافة عمود مالي جديد في invoices أو financial_transactions يجب مرافقة Trigger Edit | ضروري |

=======================================================
 نهاية المرحلة الثالثة: Database Safety
 الحالة → جاهز للموافقة قبل الانتقال إلى المرحلة الرابعة (Architecture).
=======================================================
