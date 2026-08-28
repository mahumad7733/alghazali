# تقرير الفحص والتحليل الكامل لنظام «رِحلة»

**تاريخ الفحص:** 28 أغسطس 2026  
**نطاق الفحص:** النسخة المحلية من مشروع PHP/MariaDB، قاعدة البيانات المحلية المتصلة فعليًا، واجهة العميل، لوحة الإدارة، API، وملفات JavaScript.  
**نطاق التعديل:** فحص النظام وقاعدة البيانات كان read-only. أُضيفت فقط صفحة توثيق عامة باسم `developers.php` تنفيذًا للتعليمات الثانية المرفقة؛ لم تُنشأ جداول أو API جديدة لهذا المركز، ولم تُنفذ عمليات CRUD على بيانات حقيقية.

> هذا التقرير يصف ما وجدته في الكود وقاعدة البيانات، ولا يفترض وظائف غير موجودة. عندما تكون الوظيفة غير موجودة حاليًا، أذكر ذلك صراحة.

## القسم 1: ملخص المشروع

نظام رِحلة هو منصة ويب عربية لحجز رحلات الباصات. التقنية الفعلية هي **PHP 8.3 تقريبًا، MariaDB/MySQL، JavaScript عادي، Bootstrap 5 RTL، CSS مخصص**. لا يظهر في المشروع استخدام Laravel أو Symfony أو React أو Vue أو أي Framework Backend/Frontend رئيسي؛ البنية عبارة عن صفحات PHP، خدمات PHP داخل `includes/`، موزع REST-like داخل `api/v1/index.php`، وواجهة JavaScript تعتمد `fetch` وHTML templates.

النظام يعمل بنموذج وسيط/منصة: الشركات توفر بيانات الرحلات والأسعار، والمنصة تعرض الرحلات وتستقبل الحجوزات، ويمكن إنشاء رحلة بلا باص فعلي باستخدام مخزون مقاعد افتراضي داخلي. يوجد أيضًا دعم للوكلاء ومحافظهم، والتحويل البنكي اليدوي، والحسابات المالية، والتقييمات، والتذاكر، والإشعارات، وOTP، وطبقة دفع مستضافة اختيارية أضيفت حديثًا لكنها **معطلة افتراضيًا**.

القاعدة المحلية التي فُحصت اسمها الداخلي `rihla_latest_local`. أعداد الصفوف غير التعريفية الحالية هي: 2 شركة، 2 مسار رئيسي، 3 مسارات فرعية، 3 رحلات، 7 عملاء، وكيل واحد، 11 حجزًا، 8 مدفوعات، 10 تذاكر، و0 استردادات. لم تُنشأ بيانات جديدة أثناء الفحص.

## القسم 2: هيكل المشروع

| النوع | المسار | الوظيفة الفعلية |
|---|---|---|
| نقطة الموقع العامة | `index.php`, `customer.php` | تشغيل واجهة الموقع وواجهة العميل العامة |
| صفحات عامة | `about.php`, `contact.php`, `privacy.php`, `developers.php` | صفحات معلوماتية؛ `developers.php` يوثق API الفعلي فقط |
| تسجيل الدخول | `login.php`, `logout.php` | تسجيل الدخول والخروج عبر جلسة PHP وواجهة API |
| لوحة الإدارة | `admin/*.php` | أغلفة صفحات الإدارة، والمنطق التشغيلي في `app.js` وAPI |
| تطبيق/واجهة الوكيل | `agent.php` وملفات الإدارة المرتبطة | عرض وظائف الوكيل ومحفظته وحجوزاته |
| API | `api/v1/index.php` | موزع المسارات، CSRF، الجلسة، الصلاحيات، والاستجابات JSON |
| خدمات Backend | `includes/*.php` | Auth، الحجوزات، المراجع، الإدارة، الوكلاء، البنوك، المدفوعات، الفواتير وغيرها |
| قاعدة البيانات | `database/schema.sql` و`database/*_migration.sql` | المخطط المرجعي والترقيات الإضافية |
| JavaScript عام | `assets/js/app.js` | منطق الواجهة والطلبات والنوافذ والحجز والإدارة |
| JavaScript عام للموقع | `assets/js/public-template.js` | الرأس والتذييل والصفحات العامة وبطاقات الرحلات |
| JavaScript مركز المطورين | `assets/js/developer-center.js` | renderer توثيقي مستقل بلا استدعاءات API أو أسرار |
| CSS | `assets/css/app.css`, `assets/css/public-template.css` | التصميم العام والإدارة والموقع العام والوضع الليلي |
| الصور والملفات | `uploads/` | شعارات الشركات وصور الباصات وملفات المستخدمين والإيصالات |
| إعدادات الخادم | `config/config.php`, `config/database.php` | اتصال قاعدة البيانات وإعدادات التطبيق؛ لا يجب نشرها في GitHub |

الاتصال بقاعدة البيانات يتم من خلال `includes/bootstrap.php` ثم `classes/Database.php`. تستخدم الخدمات PDO والمعاملات `transaction` وprepared statements. ملف `config/config.php` الحقيقي يحتوي إعدادات حساسة ولذلك لم يُضمّن في التقرير أو الصفحة العامة.

## القسم 3: قاعدة البيانات

نتيجة التدقيق الفعلي كانت **64 جدولًا و131 علاقة Foreign Key**. الجداول الأساسية في المجال التشغيلي هي كما يلي:

| المجال | الجداول الحقيقية |
|---|---|
| الهوية والصلاحيات | `users`, `roles`, `permissions`, `user_roles`, `role_permissions`, `api_tokens`, `login_attempts` |
| الدول والمدن والمحطات | `countries`, `cities`, `stations` |
| الشركات | `companies`, `company_images`, `company_users`, `company_financial_settings`, `company_settlements` |
| المسارات | `routes`, `route_stops`, `route_segments`, `route_subroutes`, `route_subroute_links`, `segment_prices` |
| الباصات والمقاعد | `buses`, `bus_seats`, `trip_seat_inventory` |
| الرحلات والأسعار | `trips`, `trip_segment_prices`, `trip_price_change_logs` |
| العملاء والمسافرون | `customers`, `passengers`, `booking_passengers` |
| الوكلاء والماليات | `agents`, `agent_wallets`, `agent_wallet_transactions`, `agent_commissions` |
| الحجوزات والتذاكر | `bookings`, `booking_segments`, `booking_seats`, `tickets` |
| الدفع والفوترة | `payments`, `refunds`, `payment_gateway_settings`, `payment_attempts`, `payment_webhook_events`, `invoices`, `invoice_lines`, `tax_settings` |
| المحاسبة | `accounts`, `account_transactions`, `expenses`, `banks` |
| التشغيل والمراسلة | `notifications`, `audit_logs`, `contact_channels`, `contact_messages`, `site_settings`, `trip_display_settings` |
| OTP والأجهزة | `otp_settings`, `otp_provider_settings`, `otp_challenges`, `otp_registration_payloads`, `user_devices` |

### أهم الجداول والعلاقات

| الجدول | المفتاح الأساسي وأهم الحقول | العلاقات الأساسية |
|---|---|---|
| `countries` | `id`, `code`, `name_ar`, `phone_code`, `is_active` | الدولة أب للمدن والعملاء والشركات والوكلاء |
| `cities` | `id`, `country_id`, `name_ar`, `is_active` | `country_id → countries.id`؛ ترتبط بالمحطات والعملاء والمسارات الفرعية |
| `stations` | `id`, `city_id`, `name_ar`, `address`, `latitude`, `longitude`, `is_active` | `city_id → cities.id`؛ تستخدمها محطات المسار |
| `companies` | `id`, `legal_name`, `trade_name`, الصور، الإحداثيات، `base_currency_id`, `status` | الدولة والمدينة والعملة الأساسية؛ أب للمسارات والباصات والرحلات |
| `routes` | `id`, `company_id`, `code`, `name_ar`, `route_type`, `journey_type`, `status` | `company_id → companies.id`؛ أب للمحطات والمقاطع والرحلات |
| `route_subroutes` | `id`, `company_id`, مدينتا الانطلاق والوصول، `currency_id`, `company_amount`, `amount`, أوقات الانطلاق والوصول | المدن والعملات والشركة اختيارية للشريحة المشتركة؛ ترتبط بالمسارات عبر جدول الربط |
| `route_stops` | `id`, `route_id`, `station_id`, `stop_order`, أزمنة offset | محطة مرتبة داخل المسار الرئيسي |
| `route_segments` | `id`, `route_id`, `origin_stop_id`, `destination_stop_id`, ترتيب البداية والنهاية | مقطع قابل للحجز بين محطتين من المسار |
| `route_subroute_links` | `id`, `route_id`, `subroute_id`, `route_segment_id`, `stop_order` | يربط المسار الرئيسي بالمقطع الفرعي وبالمقطع التشغيلي |
| `segment_prices` | `id`, `company_id`, `route_segment_id`, `currency_id`, `amount`, فترة الفاعلية والحالة | سعر مرجعي للمقطع قبل نسخه إلى الرحلة |
| `buses` | `id`, `company_id`, الاسم والرقم واللوحة والنوع وعدد المقاعد والحالة و`is_virtual` | الشركة أب للباص؛ `is_virtual` يدعم وسيطًا بلا باص فعلي |
| `bus_seats` | `id`, `bus_id`, `seat_code`, الصف والعمود والنوع والحالة | مقاعد الباص الأساسية |
| `trips` | `id`, `company_id`, `route_id`, `route_subroute_id`, `bus_id`, رقم الرحلة، النوع، عدد المقاعد، التكرار، أوقات السفر، الحالة | ترتبط بالشركة والمسار والمقطع الفرعي والباص ومنشئها |
| `trip_segment_prices` | `trip_id`, `route_segment_id`, `currency_id`, `company_amount`, `amount`, `source_price_id` | لقطة سعر الرحلة والمقطع؛ تغيير السعر المرجعي لا يغير الرحلات القديمة تلقائيًا |
| `trip_seat_inventory` | `trip_id`, `bus_seat_id`, `is_available` | مخزون المقاعد الفعلي للرحلة |
| `customers` | `id`, `user_id`, `country_id`, `city_id` | ملف عميل مرتبط بمستخدم، دولة ومدينة |
| `passengers` | بيانات المسافر والوثيقة والجنس والهاتف وتواريخ الميلاد والإصدار | قد يرتبط بعميل؛ يربط بالحجز عبر `booking_passengers` |
| `agents` | `id`, `company_id`, `user_id`, الرمز، الدولة، الإحداثيات، نوع وقيمة العمولة، الحالة وإعدادات الائتمان | الوكيل مرتبط بمستخدم وشركة ومحفظة |
| `agent_wallets` | `agent_id`, `currency_id`, `balance`, `credit_limit`, `used_debt`, `minimum_balance` | محفظة الوكيل حسب العملة |
| `bookings` | رقم الحجز، الشركة والرحلة والعميل والوكيل، المصدر، العملة، subtotal، الضريبة، العمولات، تكلفة الشركة، الإجمالي، الحالات، `held_until` | أب للحجز التفصيلي والمقاعد والتذاكر والمدفوعات |
| `payments` | المبلغ والعملة والطريقة والقناة والحالة، البنك، provider IDs، fee/net، المرجع والإيصال | `booking_id → bookings.id`, `currency_id → currencies.id` |
| `refunds` | `payment_id`, العملة، المبلغ، الحالة، provider refund/payment ID، idempotency، الرسوم، الصافي، السبب، المعتمد | استرداد تابع لدفعة ولا توجد سجلات حاليًا |
| `currencies` | `id`, `code`, الاسم والرمز، المنازل العشرية، `is_default`, `is_active` | تستخدمها الشركات والأسعار والرحلات والحجوزات والحسابات |
| `exchange_rates` | العملة الأساسية والاقتباس، `rate`, فترة الفاعلية والحالة | كلا العملتين ترتبطان بـ`currencies` |
| `accounts` | الشركة والعملة، كود الحساب، الاسم، النوع، الرصيد والحالة | حساب مالي للشركة |
| `account_transactions` | الحساب والحجز، النوع، مدين/دائن، المرجع والملاحظة والمنفذ | سجل مالي تابع للحساب وقد يرتبط بالحجز |

## القسم 4: الشركات

إنشاء الشركة يتم من مسار الإدارة `POST ?route=admin/companies`، والتعديل من `PUT ?route=admin/companies/{id}`، وتغيير الحالة من `PUT ?route=admin/companies/{id}/status`، والحذف من `DELETE ?route=admin/companies/{id}`. التعديل يتطلب المدير الرئيسي في الكود الحالي، ويتحقق من الاسم القانوني والتجاري والدولة والمدينة والعملة الأساسية والبريد والهاتف والإحداثيات.

بيانات الشركة تحفظ في `companies`، وتفاصيل الصور في `company_images`، والشعار وصورة الغلاف في مسارات داخل `uploads/`. ترتبط الشركة بالمسارات الرئيسية عبر `routes.company_id`، وبالباصات عبر `buses.company_id`، وبالرحلات عبر `trips.company_id`، وبالوكلاء عبر `agents.company_id`.

تظهر الشركة للعامة عبر `GET ?route=companies` إذا كانت `status = active`. الاستجابة العامة تعرض الاسم التجاري والهوية والصور وبعض بيانات الاتصال والتقييم المنشور، ولا تعرض تكلفة الشركة أو أرباح المنصة. إذا كانت الشركة مرتبطة بمسارات أو باصات أو رحلات أو حجوزات أو مستخدمين تابعين، فإن الحذف ممنوع ويعاد الخطأ `DEPENDENCY_EXISTS`؛ الإيقاف هو البديل الآمن.

## القسم 5: المسارات الرئيسية

المسار الرئيسي موجود في جدول `routes` ويرتبط بشركة واحدة من خلال `company_id`. الحقول الفعلية هي الاسم، الرمز، نوع المسار `normal/tourist`، نوع الرحلة `direct/indirect`، والحالة `active/inactive`.

عند إنشاء المسار عبر `POST ?route=admin/routes` يجب اختيار شركة واسم ومسار فرعي واحد على الأقل. الكود يولد رمز المسار، يحدد نوع الرحلة تلقائيًا إلى غير مباشر عندما يكون هناك أكثر من مقطع ما لم يحدد المستخدم غير ذلك، ثم يربط المقاطع عبر `route_subroute_links`.

المسار الرئيسي يتكون تشغيليًا من محطات `route_stops` ومقاطع `route_segments`. عند إضافة محطة إلى مسار غير منشور يعيد النظام بناء المقاطع الممكنة بين المحطات المرتبة. لا يجوز تعديل محطات المسار المنشور؛ يعاد الخطأ `ROUTE_LOCKED`.

تعديل مسار لا يغير الشركة أو قائمة المقاطع إذا كانت هناك رحلات مرتبطة. إذا طُلب تغيير جوهري لمسار مرتبط برحلات، يرفض النظام العملية بـ`DEPENDENCY_EXISTS` بدل تغيير الرحلات القديمة. حذف المسار ممنوع إذا كان مرتبطًا برحلة.

## القسم 6: المسارات الفرعية

المسار الفرعي موجود في `route_subroutes`. الحقول الفعلية هي:

| الحقل | الوصف |
|---|---|
| `origin_city_id` | مدينة الانطلاق |
| `destination_city_id` | مدينة الوصول |
| `currency_id` | عملة سعر المقطع |
| `company_amount` | سعر/تكلفة الشركة الداخلية |
| `amount` | سعر البيع المرجعي |
| `origin_arrival_time` | وقت الحضور في مدينة الانطلاق |
| `origin_departure_time` | وقت المغادرة من مدينة الانطلاق |
| `destination_arrival_time` | وقت الوصول إلى الوجهة |
| `destination_departure_time` | وقت المغادرة من الوجهة إن كانت جزءًا من المسار |
| `status` | `active/inactive` |

تُضاف المسارات الفرعية عبر `POST ?route=admin/subroutes` وتُعدل عبر `PUT ?route=admin/subroutes/{id}`. يتحقق الكود من وجود مدينتين مختلفتين وعملة نشطة وسعر بيع، ولا يسمح لغير صاحب الصلاحية بإدارة سعر الشركة. المسار الفرعي قد يكون مشتركًا عندما يكون `company_id` فارغًا، وتعديل/حذف المقطع المشترك محصور بالمدير الرئيسي.

المسار الفرعي يرتبط بالمسار الرئيسي من خلال `route_subroute_links`، وبالمقطع التشغيلي من خلال `route_segment_id`. سعر المقطع المرجعي يحفظ في `segment_prices`، ثم تنسخ قيمته إلى `trip_segment_prices` عند إنشاء الرحلة. لذلك تغيير المسار الفرعي أو سعره لا يغير أسعار الرحلات القديمة تلقائيًا؛ الرحلة تحمل لقطة سعرية خاصة بها.

## القسم 7: الرحلات

إنشاء رحلة مفردة يتم عبر `POST ?route=admin/trips`. الحقول الفعلية هي المسار الرئيسي، المقطع الفرعي الاختياري، الباص الاختياري، رقم الرحلة، النوع المحلي/الدولي، فئة الباص، عدد المقاعد، وقت المغادرة والوصول، وعمولة الوكيل بنوعها `percentage/fixed` وقيمتها.

عند اختيار المسار يتحقق النظام من ملكية المسار للشركة ومن صلاحية المسار الفرعي. إذا لم يُحدد باص، ينشئ النظام أو يعيد استخدام مزود مقاعد افتراضيًا داخليًا بعلامة `is_virtual = 1`. هذا لا يعني وجود باص حقيقي؛ هو مخزون مقاعد تقني لوسيط لا يملك أسطولًا.

بعد إنشاء الرحلة ينسخ النظام المقاعد إلى `trip_seat_inventory`، وينسخ الأسعار النشطة من `segment_prices` إلى `trip_segment_prices`. وقت الحضور والمغادرة التشغيليان يؤخذان من المسار الفرعي أو من offsets الخاصة بمحطات المسار، وليس من سعر المتصفح.

الرحلة المتكررة تستخدم `POST ?route=admin/trips/recurring/preview` للمعاينة ثم `POST ?route=admin/trips/recurring` للإنشاء. المدخلات هي `start_date`, `end_date`, `weekdays` من 1 إلى 7، المسار والمقطع، عدد المقاعد، النوع، فئة الباص، بادئة رقم الرحلة وعمولة الوكيل. ينشئ النظام occurrence لكل يوم يطابق أيام الأسبوع داخل الفترة، بحد أقصى 366 يومًا، ويستخدم أوقات المسار الفرعي. مثال الفترة 01/09/2026–30/09/2026 مع السبت والإثنين والأربعاء ينشئ رحلة مستقلة لكل تاريخ مطابق، مع `recurrence_group` مشترك و`recurrence_index` متسلسل.

تعديل الرحلة يتم عبر `PUT ?route=admin/trips/{id}`، تغيير حالتها عبر `PUT ?route=admin/trips/{id}/status`، والحذف عبر `DELETE ?route=admin/trips/{id}` مع قيود التبعيات. توجد معاينة وتطبيق لتعديل أسعار الرحلات بصورة فردية أو جماعية، مع سجل `trip_price_change_logs` عند تسجيل التغيير.

## القسم 8: المقاعد

عدد المقاعد يحدد في `buses.seat_count` أو `trips.seat_count`. عند إنشاء باص فعلي ينشئ النظام سجلات `bus_seats` بأكواد مثل 1A و1B، وعند إنشاء رحلة بلا باص يستخدم مخزونًا افتراضيًا بأكواد داخلية تبدأ بـV. الواجهة تعرض أرقام المقاعد بصورة عربية للمستخدم.

لكل رحلة سجل في `trip_seat_inventory` يربط الرحلة بالمقعد ويحدد `is_available`. القراءة العامة للمقاعد هي `GET ?route=trips/{id}/seats&segment_id=ID`، وتستدعي `BookingService::seatsForSegment` بعد إتمام انتهاء الحجوزات المؤقتة المنتهية.

عند الحجز يقفل النظام الرحلة والمقاعد داخل transaction، ويتحقق من أن المقعد متاح وأنه لا يوجد حجز مؤكد أو معلق يتداخل مع مقطع الرحلة. لذلك يوجد منع برمجي للحجز المكرر والتعارض بين مقطعين متداخلين. عند رفض أو إلغاء الحجز يحرر النظام المقاعد وفق مسار الإغلاق الحالي. إذا انتهت مهلة `held_until` يحرر النظام الحجز المعلق عند طلبات القراءة/التشغيل التي تستدعي `expirePendingBookings`.

## القسم 9: الحجوزات

يبدأ العميل بالبحث عبر `GET ?route=trips/search`، أو يرى الرحلات القادمة عبر `GET ?route=trips/upcoming`. يختار رحلة ومقطعًا ثم يطلب المقاعد. إنشاء الحجز الفعلي هو `POST ?route=bookings` ويتطلب مستخدمًا مصادقًا وCSRF صالحًا.

`BookingService::create` يستقبل `trip_id`, `segment_id`, مصفوفة `seats`, ومصفوفة `passengers`. لا يقبل السعر من العميل كمصدر للحقيقة؛ يجلب سعر المقطع من `trip_segment_prices`، ويحسب subtotal، ضريبة الإعدادات إن كانت مفعلة، الإجمالي، تكلفة الشركة، عمولة الوكيل، وربح المنصة في Backend.

يُحفظ الحجز في `bookings`، والمقطع في `booking_segments`، وبيانات المسافرين في `passengers` ثم `booking_passengers`، والمقاعد في `booking_seats`، ودفعة أولية في `payments`. الحجز يبدأ غالبًا بحالة `pending` وpayment status `pending/unpaid` مع مهلة مقعد.

القنوات الحالية هي `agent`, `company`, `bank_transfer`, و`gateway` فقط إذا كان مزود Moyasar مفعّلًا. التحويل البنكي يتطلب بنكًا نشطًا يطابق عملة الرحلة ورقم عملية. حجز الوكيل يستخدم محفظته وفق إعدادات الوكيل ولا يمرر بيانات بطاقة.

تأكيد الحجز الإداري هو `PUT ?route=bookings/{id}/confirm`، واستلام الدفع هو `PUT ?route=bookings/{id}/payment`، والرفض هو `PUT ?route=bookings/{id}/reject` مع سبب. إلغاء الحجز هو `POST ?route=bookings/{id}/cancel` مع سبب. تفاصيل الحجز هي `GET ?route=bookings/{id}`، والتذاكر تُصدر بعد التأكيد حسب الحالة ومسار الخدمة.

حالة الحجز منفصلة عن حالة الدفع. الحالات الحالية للحجز هي `pending`, `confirmed`, `cancelled`, `rejected`, `completed`, `expired`، وحالات الدفع الأساسية هي `unpaid`, `pending`, `paid`, `refunded`، مع حالات إضافية في طبقة المدفوعات الجديدة للاحتفاظ بحالة المزود دون كسر القيم القديمة.

## القسم 10: العملاء

العميل هو مستخدم في `users` له دور `customer` وسجل تابع في `customers`. تسجيل العميل يتم عبر `POST ?route=auth/register`، وتسجيل الدخول عبر `POST ?route=auth/login`. توجد طبقة OTP اختيارية، لكنها لا ترسل رسائل حقيقية ما لم تجهز إعدادات مزود فعلي.

بيانات العميل الأساسية هي المستخدم والبلد والمدينة. بيانات المسافر التفصيلية تحفظ في `passengers` وتشمل الاسم العربي والجنس ورقم الهاتف والوثيقة وتواريخ ومواقع الميلاد والإصدار. يمكن للعميل حفظ ملف مسافر سابق عبر `auth/me/customer-profile` لإعادة استخدامه.

يرى العميل الرحلات العامة فقط، ثم المقاعد، ثم ينشئ الحجز بعد المصادقة. يرى حجوزاته وتذاكره وإشعاراته ضمن نطاق `customer_id` الخاص به. لا يسمح API العام بعرض تكلفة الشركة أو عمولة الوكيل أو أرباح الإدارة.

## القسم 11: الوكلاء

الوكيل هو مستخدم مرتبط بسجل `agents`، والشركة، والدولة، ونوع وقيمة العمولة، والحالة. `agent_code` اختياري في المخطط الحالي. حالات الوكيل هي `active`, `financially_blocked`, `suspended`.

الرصيد يحفظ في `agent_wallets` حسب العملة، مع `balance`, `credit_limit`, `used_debt`, و`minimum_balance`. الحركات تحفظ في `agent_wallet_transactions` بنوع الحركة والمبالغ قبل/بعد والسبب والمنفذ. عمولات الوكيل تحفظ في `agent_commissions` بحالة `pending/payable/paid/cancelled`.

الوكلاء يملكون مسارات API مثل `agent/wallet`, `agent/transactions`, `agent/commissions`, و`agent/bookings`. عند إنشاء حجز بصفة وكيل يحسب النظام العمولة من الربح الإجمالي، ولا يسمح أن تتجاوز الربح، ويوقف الحجز إذا كان الوكيل موقوفًا ماليًا أو غير نشط. توجد صلاحيات وإعدادات ائتمان، لكن بعض وظائف التسويات النهائية لا توجد لها سجلات فعلية حاليًا؛ `company_settlements` عددها 0.

## القسم 12: العملات

العملات تحفظ في `currencies`، وهي متعددة العملات فعلًا. الحقول هي الرمز ISO، الاسم، الرمز العربي، المنازل العشرية، `is_default`, و`is_active`. عدد العملات الحالي 3، والعملات الافتراضية الحالية يجب ألا تتعدد؛ العملة التاريخية الافتراضية لا ينبغي تغييرها دون قرار ترحيل.

السعر يرتبط بالعملة في `route_subroutes`, `segment_prices`, `trip_segment_prices`, `bookings`, `payments`, `accounts`, و`agent_wallets`. التحويل يحفظ في `exchange_rates` عبر عملة أساس وعملة اقتباس ومعدل وفترة فاعلية. لا يجوز جمع مبالغ YER وSAR وUSD في تقرير واحد دون تحويل موثق؛ النظام الحالي يحمل العملة مع القيمة ولا يحولها تلقائيًا في كل التقارير.

SAR أضيفت بصورة additive في migration السعودية، لكنها ليست العملة الافتراضية تلقائيًا. USD يمكن إضافتها أو تفعيلها من مرجع العملات إن وجدت في القاعدة، لكن لا يجوز افتراض توفرها من الواجهة وحدها. القيمة التاريخية في الحجز لا تتغير عند تغيير سعر الصرف لاحقًا لأن الحجز يحمل `exchange_rate_used` عند الحاجة.

## القسم 13: لوحة التحكم

| الصفحة | الملف | الوظيفة الفعلية |
|---|---|---|
| لوحة التحكم | `admin/admin.php` | ملخص التشغيل والإحصاءات والإشعارات |
| الحجوزات | `admin/bookings.php` | قائمة وتفاصيل وتأكيد ورفض وإلغاء واستلام دفع وحجز إداري |
| الدول والمدن | `admin/countries.php`, `admin/cities.php` | إدارة الدول والمدن والحالة |
| الشركات | `admin/companies.php` | إضافة وتعديل وإيقاف وحذف الشركة والصور والموقع |
| المسارات الرئيسية | `admin/main_routes.php` | تركيب المسار من المقاطع والمحطات والحالة |
| المسارات الفرعية | `admin/sub_routes.php` | المدن والأسعار والأوقات والحالة |
| المحطات | `admin/stations.php` | بيانات المحطة والإحداثيات والبحث بالخريطة |
| الباصات | `admin/buses.php` | الباصات والمقاعد والصور والحالة |
| الرحلات | `admin/trips.php` | الرحلات الفردية والمتكررة والأسعار والحجوزات المرتبطة |
| الوكلاء | `admin/agents.php` | إضافة وتعديل وحالة الوكيل والرمز |
| العملاء | `admin/customers.php` | إدارة حسابات العملاء |
| المستخدمون | `admin/users.php` | المستخدمون والأدوار |
| الصلاحيات | `admin/permissions.php` | الأدوار والصلاحيات الحالية |
| الإعدادات | `admin/settings.php` | إعدادات الموقع والرحلات والاتصال والبنوك وغيرها |
| المدفوعات | `admin/payments.php` | سجل الدفع والفواتير الجديدة والقنوات |
| إعدادات الدفع | `admin/payment_settings.php` | إعدادات المزود وVAT والفوترة، مع إخفاء الأسرار |
| التقارير | `admin/reports.php` | تقارير تشغيلية ومالية حسب الصلاحيات |
| المالية | `admin/financial.php`, `admin/company-finance.php` | الحسابات والحركات وملخص الشركة |
| التذاكر | `admin/tickets.php` | حالة التذكرة والدفع والمقعد والجنس والطباعة |
| الإشعارات | `admin/notifications.php` | قراءة وإدارة التنبيهات |
| OTP | `admin/otp_settings.php` | قنوات ومزودو OTP وإعداداتها واختبارها |

معظم الصفحات الإدارية أغلفة؛ التوجيه الفعلي يتم عبر API و`assets/js/app.js`. الصلاحيات تُفحص في `Auth::requirePermissions`، والمدير ذو دور `super_admin` يتجاوز فحص الصلاحية الدقيقة، بينما المستخدمون الآخرون يحتاجون أكواد الصلاحيات المربوطة بأدوارهم.

## القسم 14: سير العمل الكامل

```text
الدولة
  ↓
المدينة
  ↓
المحطة
  ↓
الشركة
  ↓
المسار الرئيسي routes
  ↓
محطات المسار route_stops
  ↓
المقاطع route_segments + route_subroutes
  ↓
أسعار المقاطع segment_prices
  ↓
الرحلة trips
  ↓
لقطات الأسعار trip_segment_prices + مخزون المقاعد trip_seat_inventory
  ↓
البحث العام
  ↓
اختيار المقطع والمقعد
  ↓
العميل أو الوكيل المصادق
  ↓
الحجز bookings + المسافرون + المقاعد + الدفعة
  ↓
التأكيد/الدفع/التذكرة/الإشعار/التقييم
```

**إنشاء الشركة:** المدير يدخل بيانات الشركة القانونية والتجارية والدولة والمدينة والعملة الأساسية والاتصال والصور.  
**إنشاء المسار:** يختار الشركة واسم المسار ونوعه، ثم يربط مقاطع فرعية ومحطات.  
**إنشاء الرحلة:** يختار المسار والمقطع، ويحدد الأوقات وعدد المقاعد والعمولة والباص الاختياري؛ تنسخ الأسعار والمقاعد إلى الرحلة.  
**بحث العميل:** يحدد مدينتي الانطلاق والوصول والتاريخ ونوع الباص، ويقرأ الرحلات التي حالتها open وتاريخها مستقبلي.  
**الحجز:** يختار المقعد والمقطع وبيانات المسافرين وطريقة الدفع؛ Backend يقفل المقاعد ويعيد حساب المبلغ.  
**ما بعد الحجز:** الإدارة تؤكد أو ترفض أو تستلم الدفع. عند التأكيد تصدر التذاكر وفق الخدمة، وعند الإلغاء يحرر النظام المقاعد ويسجل السبب ويصدر الإشعارات المرتبطة.

## القسم 15: المشاكل الموجودة والملاحظات التشغيلية

لم أجرِ عمليات إضافة أو تعديل أو حذف تجريبية على بيانات حقيقية. نتائج القراءة والتشغيل الآمن هي:

| الصفحة/الملف | الملاحظة | التأثير |
|---|---|---|
| الاستضافة العامة `rihla.kesug.com` | DNS يحل، لكن HTTP وHTTPS لم يعيدا استجابة ضمن مهلة الفحص من بيئة الاختبار | لا يمكن اعتبار النشر الخارجي مؤكدًا من داخل البيئة؛ يلزم فحص حساب InfinityFree وسجلات الخطأ |
| `trips/upcoming` محليًا | أعاد نجاحًا لكن 0 رحلة قادمة في لحظة الفحص بسبب شرط `departure_at > CURRENT_TIMESTAMP` والحالة open | لا تظهر رحلات في الصفحة العامة إذا كانت تواريخها منتهية؛ هذا سلوك فلترة وليس بالضرورة خطأ |
| المسار المحلي الخاطئ `/rihla_app/developers.php` | أعاد 404 لأن DocumentRoot المحلي هو جذر المشروع | الرابط الصحيح المحلي هو `/developers.php` |
| لوحة API للشركة | لا توجد لوحة فعلية لإدارة مفاتيح الشركة والصلاحيات وسجل الطلبات | مركز المطورين يوضح أنها غير متوفرة حاليًا ولا ينشئها تلقائيًا |
| Webhooks العامة | لا توجد Webhooks لشركات النقل لإنشاء/تحديث الرحلات أو الحجوزات | التكامل الخارجي لا يستقبل إشعارات تشغيل عامة حاليًا |
| Rate Limit العام | لم يُرصد حد عام لكل API؛ الموجود المؤكد هو حماية تسجيل الدخول: 5 محاولات فاشلة خلال 15 دقيقة | لا يجوز إعلان رقم Rate Limit عام غير موجود |
| حسابات البنوك المحلية | الجدول موجود، لكن عدد الحسابات الحالية في الفحص 1 على مستوى القاعدة، وقد لا يطابق كل عملة أو شركة | التحويل البنكي لا يظهر كحساب صالح إلا عند مطابقة العملة والحالة |
| الفواتير/ZATCA | الفواتير الداخلية snapshot موجودة، لكن تكامل ZATCA غير موجود/معطل | لا يجوز إعلان اعتماد أو امتثال ZATCA من إضافة الجداول فقط |
| API token | يوجد Bearer token عام مرتبط بمستخدم، وليس نظام مفاتيح شركة بصلاحيات scopes مستقلة | ربط الشركات يحتاج اعتمادًا وتصميم صلاحيات مخصصًا مستقبلًا |
| OTP | البنية والإعدادات موجودة، لكن الإرسال الحقيقي غير مفعل دون مزود وبياناته | لا توجد رسائل حقيقية من الاختبار |
| الوضع الليلي | الصفحة العامة ومركز المطورين ورثا الوضع الليلي الموجود؛ تم فحص المركز بصريًا في الحالة الداكنة | لا مشكلة ظاهرة في القراءة، ويلزم اختبار إضافي على أجهزة فعلية عند اعتماد التصميم |

لم تظهر أثناء معاينة `developers.php` أخطاء PHP أو JavaScript؛ الصفحة تُعرض RTL، وتستخدم Bootstrap والأصول العامة الحالية، وتعرض عنوان Base URL من الموقع الحالي.

## القسم 16: العلاقات بين الجداول

```text
countries
  └── cities
        └── stations
              └── route_stops
                    └── route_segments

companies
  ├── routes
  │     ├── route_stops
  │     ├── route_segments
  │     ├── route_subroute_links ── route_subroutes ── cities/currencies
  │     └── trips
  ├── buses ── bus_seats
  ├── agents ── agent_wallets ── agent_wallet_transactions
  ├── company_images
  └── accounts ── account_transactions

routes
  └── trips
        ├── trip_segment_prices ── currencies
        ├── trip_seat_inventory ── bus_seats
        └── bookings
              ├── booking_segments ── route_segments
              ├── booking_passengers ── passengers ── customers ── users
              ├── booking_seats ── bus_seats
              ├── payments ── refunds
              ├── invoices ── invoice_lines
              └── tickets

users
  ├── user_roles ── roles ── role_permissions ── permissions
  ├── customers
  ├── agents
  ├── api_tokens
  └── audit_logs/notifications
```

الجدول الوسيط الأهم هو `route_subroute_links` لأنه يربط المسار الرئيسي بالمقاطع الفرعية وبالمقطع الناتج من ترتيب المحطات. وفي الحجز توجد جداول وسيطة منفصلة للمسافرين والمقاعد والمقاطع حتى يمكن تطبيق تعارض المقاعد على جزء الرحلة لا على الرحلة كاملة فقط.

## القسم 17: جدول الحقول النهائي

| القسم | الحقل | الجدول | نوع البيانات | إجباري؟ | العلاقة |
|---|---|---|---|---|---|
| الشركة | الاسم التجاري | `companies.trade_name` | `varchar(180)` | نعم | — |
| الشركة | العملة الأساسية | `companies.base_currency_id` | `bigint unsigned` | نعم | `currencies.id` |
| المسار الرئيسي | الشركة | `routes.company_id` | `bigint unsigned` | نعم | `companies.id` |
| المسار الرئيسي | الاسم | `routes.name_ar` | `varchar(220)` | نعم | — |
| المسار الرئيسي | النوع | `routes.route_type` | `enum(normal,tourist)` | نعم | — |
| المسار الرئيسي | نوع الرحلة | `routes.journey_type` | `enum(direct,indirect)` | نعم | — |
| محطة المسار | المحطة | `route_stops.station_id` | `bigint unsigned` | نعم | `stations.id` |
| محطة المسار | الترتيب | `route_stops.stop_order` | `smallint unsigned` | نعم | — |
| المسار الفرعي | مدينة الانطلاق | `route_subroutes.origin_city_id` | `bigint unsigned` | نعم | `cities.id` |
| المسار الفرعي | مدينة الوصول | `route_subroutes.destination_city_id` | `bigint unsigned` | نعم | `cities.id` |
| المسار الفرعي | العملة | `route_subroutes.currency_id` | `bigint unsigned` | نعم | `currencies.id` |
| المسار الفرعي | سعر الشركة | `route_subroutes.company_amount` | `decimal(14,2)` | نعم | قيمة داخلية |
| المسار الفرعي | سعر البيع | `route_subroutes.amount` | `decimal(14,2)` | نعم | قيمة العرض المرجعية |
| المسار الفرعي | وقت الحضور | `route_subroutes.origin_arrival_time` | `time` | لا | — |
| المسار الفرعي | وقت المغادرة | `route_subroutes.origin_departure_time` | `time` | لا | — |
| السعر | السعر الفعلي للمقطع | `segment_prices.amount` | `decimal(14,2)` | نعم | `route_segments.id`, `currencies.id` |
| الرحلة | الشركة | `trips.company_id` | `bigint unsigned` | نعم | `companies.id` |
| الرحلة | المسار الرئيسي | `trips.route_id` | `bigint unsigned` | نعم | `routes.id` |
| الرحلة | المقطع الفرعي | `trips.route_subroute_id` | `bigint unsigned` | لا | `route_subroutes.id` |
| الرحلة | عدد المقاعد | `trips.seat_count` | `smallint unsigned` | نعم | — |
| الرحلة | المغادرة | `trips.departure_at` | `datetime` | نعم | — |
| الرحلة | الوصول | `trips.arrival_at` | `datetime` | نعم | — |
| الرحلة | عمولة الوكيل | `trips.agent_commission_value` | `decimal(12,4)` | لا | مع `agent_commission_type` |
| الحجز | رقم الحجز | `bookings.booking_number` | `varchar(32)` | نعم | unique |
| الحجز | الرحلة | `bookings.trip_id` | `bigint unsigned` | نعم | `trips.id` |
| الحجز | العميل | `bookings.customer_id` | `bigint unsigned` | لا | `customers.id` |
| الحجز | الوكيل | `bookings.agent_id` | `bigint unsigned` | لا | `agents.id` |
| الحجز | العملة | `bookings.currency_id` | `bigint unsigned` | نعم | `currencies.id` |
| الحجز | الإجمالي | `bookings.total_amount` | `decimal(14,2)` | نعم | يعاد حسابه Backend |
| الحجز | مهلة المقعد | `bookings.held_until` | `datetime` | نعم | — |
| العميل | مستخدم الحساب | `customers.user_id` | `bigint unsigned` | نعم | `users.id` unique |
| الوكيل | مستخدم الحساب | `agents.user_id` | `bigint unsigned` | نعم | `users.id` unique |
| الوكيل | الرصيد | `agent_wallets.balance` | `decimal(14,2)` | نعم | حسب `currency_id` |
| الدفع | الحجز | `payments.booking_id` | `bigint unsigned` | نعم | `bookings.id` |
| الدفع | الحالة | `payments.status` | enum | نعم | pending/completed/failed/refunded |
| الدفع | قناة الدفع | `payments.payment_channel` | `varchar(32)` | لا | agent/company/bank_transfer/gateway |
| الاسترداد | الدفعة | `refunds.payment_id` | `bigint unsigned` | نعم | `payments.id` |
| العملة | الرمز | `currencies.code` | `char(3)` | نعم | unique |
| الصرف | المعدل | `exchange_rates.rate` | `decimal(18,8)` | نعم | عملتا الأساس والاقتباس |

## القسم 18: الملاحظات والمخاطر

أول خطر تشغيلي هو أن نجاح رفع الملفات لا يثبت أن خدمة الويب على الاستضافة تعمل؛ الفحص الخارجي الحالي انتهى بمهلة HTTP وHTTPS. يلزم فحص حالة حساب InfinityFree وسجلات أخطاء PHP وDocumentRoot وإصدار PHP قبل اعتبار النسخة الحية جاهزة.

ثانيًا، لا يجوز تغيير العملة الافتراضية أو تحويل أرقام YER/TST التاريخية إلى SAR تلقائيًا. كل تقرير مالي يجب أن يحدد العملة أو يستخدم سعر صرف مؤرخًا بوضوح. يجب فصل سعر الشركة الداخلي، عمولة الوكيل، ربح المنصة، ورسوم مزود الدفع عن بعضها.

ثالثًا، صفحة مركز المطورين تعرض ما يدعمه API فعليًا، لكنها لا تمنح شركة صلاحية تلقائية ولا تنشئ API Key مخصصًا للشركة. Bearer token الحالي تابع لمستخدم ويُخزن hash مع تاريخ انتهاء، ويجب ألا يوضع في تطبيق Android أو JavaScript مكشوف.

رابعًا، عمليات الحجز والدفع والتذاكر تحمل بيانات حساسة. يجب استخدام HTTPS، عدم استقبال PAN/CVV/PIN، عدم مشاركة رموز Bearer أو CSRF، وعدم وضع `config.php` أو أسرار webhook أو API keys في GitHub أو الواجهة.

خامسًا، Webhook الموجود حاليًا خاص بمزود الدفع الاختياري وليس Webhook عامًا للرحلات. يجب ألا يعتمد العميل على Return URL وحده لتأكيد الدفع؛ مصدر الحقيقة هو تحقق Backend وWebhook الموثوق. ولا يجوز اختبار refund حقيقي أو تفعيل الدفع الإلكتروني قبل توفر حساب sandbox ومفاتيح تاجر صحيحة.

سادسًا، VAT والفواتير الداخلية لا تعني الامتثال لـZATCA. أهلية المكلف والمرحلة والشهادة والبيانات القانونية والتكامل الفني أمور خارج الكود وتحتاج مراجعة محاسب أو مستشار سعودي. هذا التقرير تقني وليس استشارة ضريبية أو قانونية.

### الملفات الناتجة

- `rihla_full_system_audit_2026-08-28.md` — هذا التقرير الكامل.
- `assets/js/developer-center.js` — صفحة التوثيق التفاعلية، بلا Backend جديد.
- `developers.php` — رابط الصفحة العامة.
- `assets/css/public-template.css` — تنسيق scoped لمركز المطورين.
- `current_browser_findings.md` — نتائج المعاينة المحلية واختبار الاستضافة.
- `rihla_public_api_audit.py` — اختبار GET محلي read-only.

### نتيجة مركز المطورين

الرابط العام المحلي الفعلي هو:

`http://127.0.0.1:8082/developers.php`

وعلى النطاق الخارجي، بعد عودة الاستضافة للعمل:

`https://rihla.kesug.com/developers.php`

الصفحة تحتوي على المقدمة، سير العمل، المصادقة الفعلية، Base URL الديناميكي، الرحلات والشركات والمسارات والمقاطع والمقاعد والحجز والإلغاء، حدود Webhooks، أمثلة JavaScript/PHP/Python/Dart، رموز الأخطاء المرصودة، الأمان، البيانات المحجوبة، ووضع لوحة API على أنه غير متوفر حاليًا.

## مصادر الفحص الداخلية

تم الاعتماد على الكود الفعلي، خصوصًا: `api/v1/index.php`, `includes/Auth.php`, `includes/ReferenceService.php`, `includes/BookingService.php`, `includes/AdminService.php`, `classes/Database.php`, `database/schema.sql`, ونتيجة `information_schema` المحلية التي سجلت 64 جدولًا و131 Foreign Key. لم تُستخدم بيانات PII أو أسرار الاتصال في التقرير.


## ملحق نتائج الاختبار النهائي

في فحص GET المحلي read-only بتاريخ 28 أغسطس 2026 أعاد `health` HTTP 200، والدول 4 عناصر، والعملات 3 عناصر، والمدن للدولة 1 عدد 5، والمحطات للمدينة 1 عدد 1، والشركات 2، والمسارات 2. أعاد `trips/upcoming` HTTP 200 مع 0 رحلة في لحظة الفحص بسبب شرط التاريخ والحالة؛ لا يعني ذلك فشل API.

اختبار البحث دون المعلمات المطلوبة أعاد HTTP 422 و`VALIDATION_ERROR`. طلب `bookings` و`admin/operations` دون جلسة أعاد HTTP 401 و`UNAUTHORIZED`. طلب المقاعد دون `segment_id` أعاد HTTP 422 و`VALIDATION_ERROR`. لم تُنشأ أو تُعدل أي بيانات في هذه الاختبارات.

نجح `php -l` للـ`customer.php` و`developers.php`، ونجح `node --check` لكل من `assets/js/public-template.js` و`assets/js/developer-center.js`. جرى طلب HTML النهائي محليًا، واحتوى على العنوان العربي ومحدد `developer-center` وملف JavaScript المستقل.
