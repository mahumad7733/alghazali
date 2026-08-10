# تقرير تنفيذ ملف العميل الموحد

**التاريخ:** 2026-08-07  
**النظام:** AlGhazali ERP  
**نطاق الاختبار:** قاعدة الاختبار `alghazali_refactor_test` على المنفذ `3307`  
**قاعدة الإنتاج:** لم يتم تعديلها

## ملخص التنفيذ

تم تنفيذ طبقة مشتركة لملف العميل الموحد، تشمل البحث الفوري عبر AJAX، إنشاء ملف العميل عند عدم وجوده، ربط الخدمات بجدول `passports`، وتسجيل تاريخ الخدمة في `customer_service_history`.

تم اعتماد أسماء الجداول الفعلية في النظام بعد فحص المخطط:

- `bus_flight_bookings` بدلًا من جدولين منفصلين للباص والطيران.
- `family_visit_requests` بدلًا من `family_visit`.
- `postal_shipments` بدلًا من `postal_services`.
- `umrah` و`hajj` محفوظتان حاليًا كسجلات في `passports` مع نوع خدمة، ويدعمهما `umrah_details`.
- `work_visa` محفوظة ضمن `passports` و`work_visa_profiles`.

## ملفات جديدة

- `database/migrations/2026_08_07_002_customer_profile_history.sql`
- `includes/customer_profile.php`
- `admin/ajax/customer_search.php`
- `admin/customer_profile.php`
- `admin/js/customer-profile-search.js`
- `tools/customer_profile_integration_test.php`

## الملفات المعدلة

- `admin/header.php`: تحميل مكون البحث الموحد.
- `admin/bus_flight_bookings.php`: تفعيل البحث وربط `passport_id`.
- `core/bookings/BookingCreatorService.php`: إنشاء/اختيار العميل وتسجيل تاريخ حجز الباص أو الطيران داخل المعاملة.
- `admin/passport_transaction_add.php`: تفعيل واجهة البحث.
- `admin/process_passport_transaction.php`: ربط المعاملة وتسجيل التاريخ.
- `admin/family_visit.php`: تفعيل واجهة البحث.
- `admin/process_family_visit.php`: ربط الطلب وتسجيل التاريخ.
- `admin/postal_services.php`: تفعيل واجهة البحث.
- `admin/process_postal_service.php`: ربط الشحنة وتسجيل التاريخ.
- `admin/umrah.php` و`admin/hajj.php`: تفعيل واجهة البحث.
- `admin/ajax_umrah.php` و`admin/ajax_hajj.php`: تسجيل الخدمة في التاريخ الموحد.
- `admin/work_visa.php`: تفعيل واجهة البحث؛ حفظ الخدمة يمر عبر `admin/passports.php`.
- `admin/passports.php`: تسجيل خدمات الجواز والعمرة والحج وتأشيرة العمل.

## تعديلات قاعدة البيانات في الـMigration

- إنشاء `customer_service_history` مع فهرس العميل والتاريخ وفهرس الخدمة وقيد مرجعي إلى `passports.id`.
- إضافة `passport_id` بشكل اختياري إلى الجداول الفعلية:
  - `bus_flight_bookings`
  - `passport_transactions`
  - `family_visit_requests`
  - `postal_shipments`
- إضافة حقول الملف الموحد إلى `passports` عند عدم وجودها:
  - `id_type`
  - `id_number`
  - `id_issue_place`
  - `id_issue_date`
  - `mobile_number`
- جعل `passport_number` اختياريًا حتى يمكن إنشاء ملف عميل لا يحمل جوازًا بعد.
- إضافة فهارس البحث والربط المطلوبة.
- جعل الـMigration قابلًا لإعادة التشغيل مع فحص وجود الأعمدة والفهارس والقيود.

## آلية التشغيل

1. يكتب الموظف اسمًا أو جوازًا أو جوالًا أو هوية.
2. يرسل `customer-profile-search.js` طلبًا إلى `admin/ajax/customer_search.php` دون إعادة تحميل الصفحة.
3. عند اختيار عميل، تُعبأ الحقول ويضاف `passport_id` مخفيًا إلى النموذج.
4. عند عدم اختيار عميل، تستمر البيانات اليدوية، ويُنشأ ملف `passports` عند الحفظ.
5. يسجل النظام الخدمة في `customer_service_history` داخل نفس معاملة الحفظ حيث يتوفر مسار المعاملة.
6. صفحة `admin/customer_profile.php?passport_id=ID` تعرض بيانات العميل والصور وسجل الخدمات.

## الاختبارات

- PHP lint للملفات الجديدة والمعدلة: **نجاح**.
- اختبار ملف العميل الموحد: **PASS**.
- اختبار إنشاء العميل وتسجيل التاريخ داخل معاملة مع rollback: **PASS**.
- تطبيق الـMigration على `alghazali_refactor_test`: **PASS**.
- إعادة تشغيل الـMigration على قاعدة الاختبار: **PASS**.
- Architecture smoke: **PASS**.
- Facade integration: **PASS**.
- Service-operation integration: **PASS**.
- Broad accounting integration: **PASS**.
- `git diff --check`: **PASS**.

## المشاكل التي تم حلها

- عدم وجود بحث موحد في الخدمات.
- تكرار بيانات المسافر بين الخدمات.
- عدم وجود سجل مركزي لخدمات العميل.
- اختلاف أسماء الجداول الفعلية عن أسماء المتطلبات النظرية.
- عدم دعم العميل الذي لا يملك رقم جواز عند إنشاء الملف.
- منع تكرار سجل الخدمة باستخدام مفتاح فريد مركب.
- حماية البحث من SQL Injection باستخدام PDO Prepared Statements.
- حماية صفحة الملف وواجهة البحث من الوصول غير المصرح.

## القيود الحالية

- يجب تطبيق الـMigration على الإنتاج فقط بعد Backup وموافقة صريحة ونافذة صيانة.
- اختبار AJAX عبر متصفح مسجل الدخول واختبار رفع الصور الفعلي يحتاج Acceptance يدويًا على بيئة Staging.
- لا توجد Triggers؛ التسجيل يتم داخل مسارات الحفظ البرمجية حتى تبقى التغييرات قابلة للمراجعة والتراجع.
- لم يتم حذف أي بيانات أو كود قديم.
