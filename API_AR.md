# توثيق REST API العربي

## مبادئ الاستدعاء

واجهة API متاحة تحت `/api/v1`. في الاستضافة المشتركة أو خادم لا يطبق إعادة كتابة Apache، استخدم نقطة الدخول المكافئة `api/v1/index.php?route=<المسار>`. تعيد كل الاستجابات JSON بالتنسيق الموحد التالي:

```json
{
  "success": true,
  "message": "رسالة عربية واضحة",
  "data": {}
}
```

تحتاج العمليات التي تغيّر البيانات إلى جلسة مسجلة ورمز CSRF في ترويسة `X-CSRF-Token`. يحصل العميل على الرمز عبر `GET auth/me` أو من أي استجابة مصادقة. تدعم الواجهة كذلك Bearer Token للمستهلكات البرمجية عبر `Authorization: Bearer <token>`.

| الفئة | المسار والطريقة | المصادقة | الوصف |
|---|---|---|---|
| الصحة | `GET health` | لا | فحص الخدمة وإرجاع CSRF للجلسة |
| المصادقة | `POST auth/register` | CSRF | إنشاء حساب عميل وتسجيل دخوله |
| المصادقة | `POST auth/login` | CSRF | تسجيل دخول مستخدم موجود |
| المصادقة | `POST auth/logout` | CSRF | إنهاء الجلسة الحالية |
| المصادقة | `GET auth/me` | جلسة أو Bearer | بيانات المستخدم والأدوار والصلاحيات |
| المصادقة | `POST auth/token` | جلسة وCSRF | إنشاء رمز API صالح 30 يومًا |
| البيانات العامة | `GET countries`, `GET currencies`, `GET companies`, `GET routes` | لا | بيانات مرجعية عامة |
| البيانات العامة | `GET cities?country_id=1`, `GET stations?city_id=1` | لا | المدن والمحطات النشطة |
| الرحلات | `GET trips/search?origin_city_id=1&destination_city_id=3&date=YYYY-MM-DD` | لا | بحث الرحلات المتاحة |
| الرحلات | `GET trips/{id}` | لا | تفاصيل الرحلة والمسار والمحطات والأسعار |
| المقاعد | `GET trips/{id}/seats?segment_id=2` | لا | حالة المقاعد لمقطع محدد |
| الحجوزات | `POST bookings` | مستخدم وCSRF | إنشاء حجز قيد الانتظار |
| الحجوزات | `GET bookings`, `GET bookings/{id}` | مستخدم | حجوزات ضمن نطاق المستخدم أو الشركة |
| الحجوزات | `PUT bookings/{id}/confirm` | صلاحية تأكيد وCSRF | تأكيد الحجز وإصدار التذاكر |
| الحجوزات | `PUT bookings/{id}/reject` | صلاحية رفض وCSRF | رفض الحجز وتحرير المقاعد |
| الحجوزات | `POST bookings/{id}/cancel` | المالك أو صلاحية إلغاء وCSRF | إلغاء طلب قيد الانتظار |
| التذاكر | `GET tickets`, `GET tickets/{id}` | مستخدم | التذاكر ضمن نطاقه |
| التذاكر | `GET tickets/verify/{qr_token}` | لا | التحقق من رمز تذكرة QR |
| الوكيل | `GET agent/wallet`, `GET agent/transactions`, `GET agent/commissions`, `GET agent/bookings` | دور وكيل | بيانات حساب الوكيل فقط |
| الإدارة | `POST admin/agents/{id}/wallet/credit` | إدارة وكلاء وCSRF | إضافة رصيد موثق للوكيل |
| الإدارة | `PUT admin/agents/{id}/financial-settings` | إدارة وكلاء وCSRF | تعديل الائتمان والحد الأدنى والحالة المالية |
| الإدارة التشغيلية | `GET admin/operations` | إدارة رحلات | عرض الشركات والمسارات والباصات والرحلات في نطاق الشركة |
| الإدارة التشغيلية | `POST admin/companies` | مدير رئيسي وCSRF | إنشاء شركة نقل جديدة |
| الإدارة التشغيلية | `POST admin/references/{countries|cities|stations|currencies|exchange-rates}` | مدير رئيسي وCSRF | إنشاء بيانات المواقع والعملات وأسعار الصرف |
| الإدارة التشغيلية | `POST admin/users/{id}/roles` | مدير رئيسي وCSRF | تعيين دور للمستخدم ضمن نطاق شركة اختياري |
| الإدارة التشغيلية | `POST admin/routes` | إدارة مسارات وCSRF | إنشاء مسار غير نشط أثناء الإعداد |
| الإدارة التشغيلية | `POST admin/routes/{id}/stops` | إدارة مسارات وCSRF | إضافة محطة وإعادة بناء مقاطع المسار غير النشط |
| الإدارة التشغيلية | `POST admin/segment-prices` | إدارة مسارات وCSRF | تعريف سعر عملة لمقطع مسار |
| الإدارة التشغيلية | `POST admin/buses` | إدارة باصات وCSRF | إنشاء باص وتوليد مخطط مقاعده |
| الإدارة التشغيلية | `POST admin/trips` | إدارة رحلات وCSRF | إنشاء رحلة ونسخ المقاعد والأسعار الفعالة |
| التقارير | `GET dashboard/summary`, `GET reports/overview` | تقرير أو وكيل | مؤشرات وتقارير محددة النطاق |
| الإشعارات | `GET notifications`, `PUT notifications/{id}/read` | مستخدم | قائمة الإشعارات وتعليمها مقروءة |

## مثال إنشاء حجز

```json
POST /api/v1/index.php?route=bookings
X-CSRF-Token: <token>
Content-Type: application/json

{
  "trip_id": 1,
  "segment_id": 2,
  "seats": ["1B"],
  "passengers": [
    {
      "full_name_ar": "محمد أحمد علي",
      "phone_country_code": "+967",
      "phone": "777000000",
      "passport_number": "YEM-000001",
      "birth_date": "1992-02-18",
      "birth_place": "صنعاء",
      "passport_issue_date": "2024-01-10",
      "passport_issue_place": "صنعاء"
    }
  ]
}
```

ينفذ الخادم التحقق من تداخل مقاطع المقاعد تحت Transaction و`FOR UPDATE`. لذلك لا يكفي أن يبدو المقعد متاحًا في المتصفح؛ يقرر الخادم الإتاحة النهائية عند الحفظ.

## الأخطاء المهمة

| الرمز | الحالة | المعنى |
|---|---:|---|
| `UNAUTHORIZED` | 401 | لا توجد جلسة أو رمز وصول صالح |
| `FORBIDDEN` | 403 | لا يملك الدور صلاحية المورد أو شركة الحجز |
| `SEAT_NOT_AVAILABLE` | 409 | المقعد غير صالح أو غير متاح |
| `SEAT_CONFLICT` | 409 | المقعد مستخدم في مقطع متداخل من الرحلة نفسها |
| `BOOKING_EXPIRED` | 409 | انتهت مهلة طلب الحجز قبل تأكيده |
| `CREDIT_DISABLED` | 409 | الشراء الآجل مقفل لحساب الوكيل |
| `INSUFFICIENT_BALANCE` | 409 | الرصيد والائتمان المتاحان لا يكفيان |
| `AGENT_FINANCIALLY_BLOCKED` | 403 | حساب الوكيل موقوف ماليًا |
| `VALIDATION_ERROR` | 422 | الحقول أو المعرفات أو التواريخ غير صالحة |
