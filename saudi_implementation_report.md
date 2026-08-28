# تقرير تنفيذ جاهزية منصة «رِحلة» للسوق السعودي

**تاريخ المراجعة:** 28 أغسطس 2026

## الخلاصة التنفيذية

تم تنفيذ طبقة سعودية إضافية داخل المشروع الحالي PHP/MariaDB/JavaScript، من دون إعادة بناء النظام أو حذف البيانات التاريخية. أضيف دعم **SAR** بصورة additive من دون جعله العملة الافتراضية، وأضيفت طبقة دفع قابلة لتبديل المزود، وHosted Checkout اختياري، وسجل محاولات الدفع وwebhooks idempotent، وحالات refund كاملة وجزئية، وإعدادات VAT والفوترة، ولقطات ضريبية immutable، وشاشة مدفوعات وفواتير في لوحة الإدارة.

لم يتم تفعيل الدفع الإلكتروني أو VAT أو تكامل ZATCA فعليًا؛ فالإعدادات الجديدة تبدأ معطلة، ولا يمكن تفعيل المزود قبل توفر مفتاح تشفير الخادم ومفاتيح التاجر وسر webhook. كما لم تُرسل بيانات بطاقة إلى الخادم أو الواجهة، ولم تُخزّن PAN أو CVV أو PIN.

> **حدود مهمة:** هذا تنفيذ تقني، وليس ترخيصًا من SAMA أو اعتمادًا من ZATCA أو استشارة ضريبية/قانونية. يجب مراجعة محاسب أو مستشار ضريبي سعودي ومزود دفع مرخص قبل التشغيل التجاري.

## ما تم تنفيذه

| المجال | ما تم تنفيذه | الحالة الحالية |
|---|---|---|
| العملة | إضافة SAR برمز `SAR` ورمز `ر.س` مع إبقاء YER/TST والعملة الافتراضية القديمة كما هي | جاهز بعد تطبيق migration |
| الدفع | `PaymentGatewayInterface` و`PaymentService` ومحول `MoyasarGateway` | الكود موجود، المزود معطل |
| Hosted Checkout | إنشاء فاتورة مستضافة من backend وإعادة checkout URL فقط، مع حساب المبلغ والعملة من الحجز | يتطلب app_url ومفاتيح sandbox |
| Idempotency | مفتاح UUID لمحاولة الدفع، منع النقرات المتكررة، وإعادة استخدام محاولة قائمة عند وجود رابط صالح | مختبر محليًا من ناحية البنية |
| Webhook | endpoint لميسر، التحقق من السر بـ `hash_equals`، حفظ event فريد، معالجة replay بأمان، وحفظ payload منقحًا | يحتاج secret حقيقي واختبار sandbox |
| حالات الدفع | فصل حالة payment عن booking، وتسجيل provider status وبيئة العملية ومعرف invoice/payment | جاهز بنيويًا |
| Refund | كامل/جزئي، تحقق من المتبقي، سجل pending/completed/failed، provider refund ID، fee منفصلة، وصافي الاسترداد | يتطلب عملية دفع خارجية حقيقية للاختبار الخارجي |
| Seat hold | استخدام `held_until` الموجود، ومنع إنشاء checkout لحجز منتهٍ؛ webhook المتأخر لا يحول الحجز المنتهي إلى مدفوع | متوافق مع السلوك الحالي |
| VAT | إعدادات مستقلة بلا نسبة افتراضية، وحساب snapshot في الحجز عند التفعيل | معطل افتراضيًا |
| الفوترة | `invoices` و`invoice_lines` وإصدار snapshot بعد paid verified، مع قائمة فواتير في لوحة الإدارة | داخلي فقط؛ ZATCA معطل |
| الأمن | خزنة AES-256-GCM server-side، عدم إظهار الأسرار بعد الحفظ، عدم وضع credentials في frontend/GitHub | يتطلب مفتاح تشفير الخادم |
| الواجهة | صفحة `admin/payments.php` وصفحة `admin/payment_settings.php`، وقسم VAT والفوترة داخل إعدادات الدفع | مختبر محليًا RTL |

## الجداول والـ migrations

الملف الأساسي للتطبيق على قواعد قائمة هو:

`database/saudi_payment_foundation_migration.sql`

وهو migration قابل لإعادة التشغيل ويضيف فقط: SAR، أعمدة tax snapshot للحجوزات، أعمدة provider/environment للمدفوعات، metadata للاستردادات، `payment_gateway_settings`، `payment_attempts`، `payment_webhook_events`، `tax_settings`، `invoices`، و`invoice_lines`.

تم تحديث `database/schema.sql` أيضًا لتثبيت البنية في قواعد جديدة. قبل تطبيق migration على الاستضافة، يجب أخذ نسخة احتياطية خارج المستودع ثم تشغيل الملف على قاعدة الإنتاج في نافذة صيانة مناسبة. لا تُشغّل dump أو credentials داخل GitHub.

## واجهات API المضافة

| المسار | الوظيفة | الحماية |
|---|---|---|
| `POST api/v1/index.php?route=payments/checkout/{booking_id}` | إنشاء Hosted Checkout لحجز pending صالح | جلسة مستخدم، صلاحية الحجز، CSRF |
| `GET api/v1/index.php?route=payments/attempts/{attempt_id}` | قراءة حالة محاولة دفع | جلسة وملكية الحجز |
| `GET api/v1/index.php?route=payments/return&key={uuid}` | عرض حالة العودة للعميل فقط | جلسة وملكية الحجز؛ ليس مصدر الحقيقة |
| `POST api/v1/index.php?route=payments/webhook/moyasar` | استقبال ومعالجة webhook | سر webhook، event uniqueness، transaction |
| `POST api/v1/index.php?route=admin/payments/{payment_id}/refund` | refund كامل/جزئي | CSRF وصلاحية إدارية، provider ID |
| `GET api/v1/index.php?route=admin/payments` | سجل المدفوعات | صلاحية إدارية |
| `GET api/v1/index.php?route=admin/invoices` | سجل الفواتير | صلاحية إدارية |
| `GET/PUT api/v1/index.php?route=admin/payment-settings` | قراءة/حفظ إعدادات المزود | صلاحيات الإدارة، الأسرار server-side |
| `GET/PUT api/v1/index.php?route=admin/tax-settings` | قراءة/حفظ VAT والفوترة | صلاحيات الإدارة، ZATCA لا يقبل التفعيل |

## الاختبارات المنفذة

تم تشغيل PHP lint على ملفات المشروع، وفحص JavaScript syntax، واختبار health وcities وtrips/upcoming وpayment-options محليًا. كما أُعيد تشغيل migration مرتين على قاعدة الاختبار؛ نجحت الإعادة ولم تتضاعف SAR أو السجل الافتراضي.

أُجري اختبار webhook محلي بسر تجريبي فقط: قُبل السر الصحيح ورُفض السر الخاطئ. وأُجري اختبار transactional لـVAT بنسبة 15% على قيمة 20,000؛ أعاد snapshot ضريبة 3,000 وإجمالي 23,000، ثم تم rollback كامل. كما أُنشئت فاتورة snapshot داخل transaction ثم أُلغي كل الأثر.

ظل baseline قاعدة الاختبار: **11 حجزًا، 8 مدفوعات، 0 refund**. لم يُنشأ حجز أو دفع أو refund أو فاتورة دائمة لأغراض الاختبار.

## النشر

تم رفع الملفات الآمنة فقط عبر FTP، من دون `config/config.php` أو `.env` أو مفاتيح أو نسخ قواعد بيانات. تم رفع 15 ملفًا بلا إخفاقات مُبلغ عنها. بعد ذلك تم تسجيل commitين في GitHub:

- `855c4b6` — طبقة الدفع وVAT وwebhook والفواتير.
- `5e947be` — توثيق فحص النشر الخارجي.

رابط المستودع: [mahumad7733/alghazali](https://github.com/mahumad7733/alghazali)

فحص الاستضافة بعد الرفع حلّ اسم النطاق، لكن HTTP وHTTPS لم يعيدا استجابة ضمن المهلة من بيئة الاختبار. لذلك لا أعتبر live smoke test ناجحًا، ولا أدّعي أن النسخة المنشورة تعمل حتى يُعاد فحص الاستضافة من Chrome أو من شبكة المستخدم.

## ما يلزم من صاحب المنصة قبل التفعيل

يلزم اختيار المزود النهائي وتوقيع العقد وإتمام KYC/AML ومتطلبات التاجر خارج الكود. يلزم أيضًا تزويد إعداد الخادم بمفتاح تشفير عشوائي قوي، وإنشاء حساب sandbox أولًا، ثم إدخال secret API وwebhook secret عبر صفحة الإدارة أو config server-side الآمن. لا تُرسل هذه القيم في المحادثة ولا تُحفظ في GitHub.

قبل live يلزم تحديد البيئة، العملة المقبولة، methods المفعلة فعليًا، أهلية مدى وApple Pay، النطاق العام HTTPS، callback/webhook URL، بيانات الشركة القانونية والضريبية، وسياسة refund. Apple Pay ومدى لا تصبحان متاحتين بمجرد وجود حقل في الواجهة؛ يلزم تفعيلهما لدى المزود والتاجر والجهاز أو النطاق حسب المتطلبات التجارية والتقنية.

## ملاحظات رسمية

تنظر قواعد SAMA إلى الربط والدعم التقني كخدمة مساندة، ولا ينبغي للتطبيق أن يتصرف كـPSP أو يتعاقد مباشرة مع التجار أو ينفذ KYC/AML أو التسوية النهائية نيابة عن مزود الدفع [1]. وتوضح وثائق Moyasar أن Hosted Invoice يعيد صفحة دفع مستضافة، وأن مبالغ API تُرسل بأصغر وحدة للعملة، كما توثق idempotency وrefund الكامل أو الجزئي [2] [3] [4] [5]. كما توثق Moyasar webhooks بمعرف حدث وسر وإعادات محاولة؛ لذلك بُنيت معالجة event idempotent، لكن اختبار sandbox الحقيقي يحتاج بيانات التاجر [6].

تعرّف ZATCA الفواتير الإلكترونية وتفرق بين الفاتورة الضريبية والفاتورة المبسطة، وتربط الإلزام بالأهلية الضريبية ومراحل التطبيق [7]. كما توفر صفحة القوانين وصفحة المواصفات الرسمية روابط اللوائح والقواعد والمعايير الفنية [8] [9]. لذلك حُفظت snapshot داخلية فقط، وبقي تكامل ZATCA مغلقًا حتى تحديد أهلية المكلف والمرحلة والشهادة والبيانات القانونية ومراجعة المستشار.

## الملفات الرئيسية

الملفات الأهم هي `database/saudi_payment_foundation_migration.sql`، `includes/PaymentGatewayInterface.php`، `includes/MoyasarGateway.php`، `includes/PaymentService.php`، `includes/SecretVault.php`، `includes/InvoiceService.php`، `includes/BookingService.php`، `api/v1/index.php`، `admin/payments.php`، `admin/payment_settings.php`، و`config/config.example.php`.

## المراجع

[1]: https://rulebook.sama.gov.sa/en/rules-dealing-e-commerce-payment-service-and-support-providers "SAMA — Rules for Dealing with E-Commerce Payment Service and Support Providers"
[2]: https://docs.moyasar.com/api/invoices/01-create-invoice "Moyasar — Create Invoice"
[3]: https://docs.moyasar.com/api/idempotency "Moyasar — Idempotency"
[4]: https://docs.moyasar.com/api/payments/01-create-payment "Moyasar — Create Payment"
[5]: https://docs.moyasar.com/api/payments/05-refund-payment "Moyasar — Refund Payment"
[6]: https://docs.moyasar.com/api/other/webhooks/webhook-reference "Moyasar — Webhook Reference"
[7]: https://zatca.gov.sa/en/E-Invoicing/Introduction/Pages/What-is-e-invoicing.aspx "ZATCA — What is E-invoicing?"
[8]: https://zatca.gov.sa/en/E-Invoicing/Introduction/LawsAndRegulations/Pages/default.aspx "ZATCA — Laws and Regulations"
[9]: https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Pages/E-Invoice-specifications.aspx "ZATCA — E-Invoice Specifications"
