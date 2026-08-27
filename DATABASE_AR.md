# توثيق قاعدة البيانات

## مبادئ التصميم

يستخدم المخطط `utf8mb4` وInnoDB، وتُربط كيانات الشركة بالـ `company_id` كلما كانت بياناتها تشغيلية. تُفرض العلاقات بالمفاتيح الأجنبية، وتُحفظ التواريخ بتوقيت الخادم. لا توجد أعمدة تخزن كلمات مرور صريحة؛ حقل `users.password_hash` يحفظ ناتج `password_hash()` فقط.

| النطاق | الجداول الأساسية | الغرض |
|---|---|---|
| الهوية والصلاحيات | `users`, `roles`, `permissions`, `user_roles`, `role_permissions`, `company_users`, `api_tokens`, `login_attempts` | مصادقة آمنة وRBAC وتعيين الأدوار للشركة |
| الشركات والمواقع | `companies`, `countries`, `cities`, `stations`, `currencies`, `exchange_rates` | عزل الشركات والمواقع والعملات وأسعار الصرف |
| النقل | `routes`, `route_stops`, `route_segments`, `segment_prices`, `buses`, `bus_seats`, `trips`, `trip_segment_prices`, `trip_seat_inventory` | المسارات والباصات والأسعار والرحلات والمقاعد |
| البيع | `customers`, `passengers`, `bookings`, `booking_segments`, `booking_passengers`, `booking_seats`, `tickets`, `payments` | دورة الحجز وتفاصيل المسافر والتذكرة والدفع |
| الوكلاء والمالية | `agents`, `agent_wallets`, `agent_wallet_transactions`, `agent_commissions`, `accounts`, `journal_entries`, `journal_lines` | المحافظ والائتمان والديون والعمولات والقيود المحاسبية |
| الحوكمة | `notifications`, `audit_logs` | التنبيهات الداخلية وسجل العمليات الحساسة |

## نموذج الحجز والمقعد

يتكون المسار من محطات مرتبة، وينتج عن كل زوج مرتّب من المحطات `route_segment`. عند إنشاء رحلة تُنسخ أسعار المقاطع إلى `trip_segment_prices` كي لا تتغير قيمة حجز قديم عند تعديل سعر المسار لاحقًا. يحتفظ `booking_segments` بلقطة من اسم نقطة البداية والنهاية وسعر الوحدة.

عند طلب مقعد، يبحث النظام عن حجز في الرحلة نفسها له مقطع متداخل مع الشرط التالي:

```text
existing.origin_order < requested.destination_order
AND existing.destination_order > requested.origin_order
```

إذا تحقق الشرط للمقعد نفسه وحالة الحجز `pending` أو `confirmed`، يرفض الطلب. تعمل عملية الفحص والحفظ في معاملة واحدة بقفل `FOR UPDATE`، ما يمنع حجز المقعد نفسه بالتوازي.

## العزل متعدد الشركات

يحمّل الخادم دور المستخدم و`company_id` من جدول `user_roles`. يضيف طلب الموظف أو الوكيل قيد الشركة إلى الاستعلامات، بينما يكون المدير الرئيسي فقط قادرًا على العبور بين الشركات. لا تُقبل قيمة `company_id` مرسلة من المتصفح كبديل عن هذا القيد.

## البيانات الابتدائية

ينشئ `seed.sql` شركتين ومسارًا وباصًا ورحلة قادمة وحسابات تجريبية ورصيد وكيل وحجزًا مؤكدًا. صُممت البيانات لاختبار النظام، وليست مراجعات أو شهادات مستخدمين. يجب عدم استخدامها كما هي في الإنتاج.
