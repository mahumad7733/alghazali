# خطة تنفيذ متطلبات السوق السعودي — رِحلة

**الحالة:** مسودة تنفيذية داخلية قبل التعديل، بتاريخ 28 أغسطس 2026. هذه الخطة لا تغيّر قاعدة البيانات أو بيانات الإنتاج.

## القرار الفني العام

سيبقى المشروع PHP/MariaDB/JavaScript عاديًا وواجهة Bootstrap RTL. ستضاف طبقة دفع مستقلة عن إعدادات عرض الرحلات، مع إبقاء القنوات اليدوية القديمة متوافقة. سيكون محول **Moyasar** هو المحول الأول الاختياري بسبب وضوح توثيقه الرسمي للفاتورة المستضافة، idempotency، webhooks، والاسترداد، لكن سيبقى معطلًا حتى إدخال بيانات تاجر حقيقية واكتمال تفعيل الحساب. سيظل التصميم قابلًا لإضافة HyperPay أو PayTabs لاحقًا من خلال الواجهة نفسها.

لن يتعامل التطبيق مع PAN أو CVV أو PIN، ولن تعتمد النتيجة على Return URL وحده. حالة الدفع النهائية ستأتي من تحقق الخادم وحدث webhook موثق، مع منع التكرار وإبقاء كل عملية دفع مرتبطة بالحجز والعملة والمبلغ الملتقط من قاعدة البيانات.

## مصفوفة الفجوات والأولوية

| المتطلب | الموجود حاليًا | الفجوة/الخطر | التنفيذ الآمن المقترح | الأولوية |
|---|---|---|---|---|
| SAR وmulti-currency | `currencies` و`exchange_rates` موجودان، والعملة الافتراضية الحالية ليست SAR | SAR غير موجودة؛ تغيير الافتراضي قد يغيّر معنى الأسعار التاريخية | Migration additive لإضافة SAR إن لم تكن موجودة، بلا جعلها افتراضية وبلا تحويل تاريخي؛ التحقق من currency_id في كل تقرير | P0 |
| المصدر الخلفي للمبلغ | إنشاء الحجز يحسب السعر من DB، والحجز يخزن currency_id وexchange_rate_used | طبقة الدفع الخارجي غير موجودة | PaymentService يعيد حساب المبلغ/العملة من booking، ويرفض مبلغ العميل غير المطابق | P0 |
| حالات الدفع | `payments.status` enum قديم: pending/completed/failed/refunded | لا توجد processing/cancelled/expired/partially_refunded/provider states، وتعديل enum in-place خطر | أعمدة additive نصية للحالة الداخلية وحالة المزود، مع إبقاء status القديم كمرآة توافقية إلى أن يثبت التشغيل | P0 |
| payment attempts | جدول payments واحد لكل حجز عمليًا | لا يوجد provider payment/invoice ID، attempt number، idempotency أو response snapshot | جدول `payment_attempts` منفصل بمفتاح idempotency فريد، provider IDs، amount minor، raw response مقنّع، timestamps | P0 |
| إعدادات الدفع | مفاتيح الدفع اليدوي مخلوطة في `trip_display_settings` | لا توجد صفحة أو تخزين سرّي مستقل | `payment_gateway_settings` بإعدادات عامة غير سرية وحقول سرية مشفرة server-side؛ عرض الأسرار مقنّع فقط | P0 |
| Hosted Checkout | غير موجود | إضافة card form داخل رِحلة قد تعرض بيانات البطاقة للمشروع | إنشاء Moyasar invoice مستضافة وإرجاع checkout URL فقط؛ callback/webhook على الخادم | P0 |
| provider adapter | غير موجود | ربط مباشر سيصعّب تبديل المزود | `PaymentGatewayInterface`، `MoyasarGateway` اختياري، و`ManualPaymentGateway` كجسر للقنوات القديمة | P0 |
| webhook | غير موجود | Return URL قابل للتلاعب أو التأخر؛ replay قد يكرر التأكيد | endpoint مستقل، حفظ raw event، unique(provider,event_id)، تحقق secret/توقيع حسب إعداد المزود، معالجة idempotent | P0 |
| idempotency | غير موجود | duplicate click/refresh قد ينشئ محاولات أو مطالبات مكررة | مفتاح UUID/طلب محفوظ ب unique key، وإعادة نفس نتيجة attempt عند التكرار | P0 |
| seat hold | `held_until` ومعالجة انتهاء موجودة | late webhook بعد انتهاء الحجز يحتاج رفضًا آمنًا | فحص booking status/held_until داخل transaction عند تأكيد webhook؛ تحويل المحاولة المنتهية إلى expired وعدم إصدار تذكرة | P0 |
| status polling/return | غير موجود | العميل لا يعرف الحالة بعد العودة | return route للعرض فقط يستعلم backend؛ webhook هو authority؛ status API لا يثق بمعلمات المتصفح | P1 |
| refunds | `refunds` minimal ولا يوجد تنفيذ provider | لا يوجد partial/full guard أو provider refund ID | RefundService يتحقق من captured/refunded/remaining، يسجل طلبًا pending، يستدعي adapter إن كان مفعّلًا، ولا يغيّر paid إلا بعد verified response/event | P0 |
| رسوم البوابة والتوزيع | عمولات الوكيل/الشركة موجودة، رسوم gateway غير موجودة | خلط gateway fee مع platform/company/agent amounts | أعمدة snapshot للرسوم والـnet، وتقارير منفصلة؛ لا تعدّل wallet للوكيل عند دفع gateway | P1 |
| فواتير/VAT | لا توجد invoices/tax snapshots | لا يجوز افتراض نسبة أو ادعاء ZATCA | جداول invoice وinvoice_lines مع snapshots immutable وإعدادات tax قابلة للتكوين؛ integration ZATCA disabled حتى تحديد الأهلية/onboarding | P0 |
| فاتورة B2C/B2B | لا يوجد نوع فاتورة أو رقم ضريبي | نقص بيانات قانونية | حقول نوع الفاتورة وبيانات المورد/العميل الاختيارية، مع منع إصدار tax claim قبل مراجعة مختص | P1 |
| لوحة المدفوعات | لا توجد صفحة مستقلة | لا توجد رؤية للمحاولات والـwebhooks والاستردادات | `admin/payments.php` وAPI للبحث/الفلاتر/التفاصيل، مع RBAC وإخفاء الأسرار وPAN | P1 |
| إعدادات مستقلة | TripDisplaySettings يحوي legacy manual flags | تداخل مسؤوليات | صفحة `admin/payment_settings.php` أو section مستقل، مع بقاء legacy flags للعرض فقط/التوافق | P1 |
| RBAC/audit | RBAC وAuditLogger موجودان | الصلاحيات الجديدة غير مضافة | permissions additive: view/manage payment settings, view payments, refund payments, view invoices؛ تسجيل كل تغيير | P0 |
| الأمن | جلسة HttpOnly وCSRF وredaction موجودة | لا توجد حماية provider/webhook/retention policy | عدم تسجيل secrets/card data، HTTPS production prerequisite، rate limits للـinitiate/refund، constant-time secret compare، hash للـpayload | P0 |
| timezone السعودية | config الافتراضي Asia/Aden | تغيير timezone قد يؤثر على بيانات/تقارير حالية | إضافة setting/config قابل للضبط، وعدم تغيير القيمة الحالية تلقائيًا؛ توحيد عرض التقارير بعد قرار صريح | P1 |
| OTP/+966 | OTP normalize يدعم default country configurable، غير مفعّل فعليًا | إعداد مزود حقيقي غير متوفر | لا تفعيل ولا إرسال؛ توثيق متطلبات مزود SMS/WhatsApp، مع اختبار mock محلي فقط | P2 |
| التقارير والعملات | توجد تقارير مالية متعددة العملات | احتمال الجمع بلا تحويل | كل aggregate يضم currency_code أو يحوّل بسعر snapshot موثق، ومنع مقارنة SAR/YER بلا conversion | P0 |
| InfinityFree | PHP/MariaDB وcURL موجودان على الأرجح | لا worker دائم ولا secret manager خارجي مؤكد | webhook سريع 2xx، معالجة قصيرة/transactional، expiry on request/cron host إن توفر؛ لا polling Manus | P1 |
| اختبار production | لا توجد merchant credentials أو sandbox keys | لا يمكن إثبات sandbox/provider flow | اختبارات adapter contract/mock محليًا؛ اختبار sandbox الحقيقي بعد تزويد مفاتيح اختبار، دون إنشاء بيانات إنتاج تجريبية | P0 |

## ترتيب التنفيذ

يبدأ التنفيذ بالـmigration additive القابلة لإعادة التشغيل، ثم service/adapter/config/security، ثم API للإنشاء والحالة والعودة والـwebhook والاسترداد، ثم لوحة الإدارة والصلاحيات، ثم tax/invoice snapshots. لن يتم تغيير default currency أو تفعيل Moyasar أو ZATCA من الكود وحده.

## عناصر متوقفة خارج الكود

يحتاج التفعيل الحقيقي إلى اختيار مزود نهائي وعقد merchant/onboarding، مفاتيح sandbox ثم live، webhook secret، domain/callback URLs، وسائل الدفع المفعلة للمحفظة، إعداد Apple Pay إن استُخدم، بيانات الكيان النظامية والرقم الضريبي، وتحديد أهلية/مرحلة الفوترة الإلكترونية. لا يجوز اختلاق أي من هذه القيم أو وضعها في GitHub أو JavaScript.

## معيار عدم فقد البيانات

كل migration تستخدم فحص وجود الجدول/العمود/الفهرس قبل الإضافة حيث يدعم MariaDB ذلك، ولا تحذف أعمدة أو صفوفًا أو تعيد ترقيم الحجوزات والمدفوعات. قبل التطبيق على الاستضافة يجب أخذ نسخة احتياطية يدوية ومراجعة SHA-256 للملفات، ثم اختبار migration على نسخة من قاعدة محلية مماثلة.
