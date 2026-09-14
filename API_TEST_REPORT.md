# تقرير اختبار API لمنصة رِحلة

## منهجية الاختبار

تم فحص مسارات API الموجودة في `api/v1/index.php` ومراجعة مسارات OTP والصلاحيات والحماية، كما تم اختبار المسار العام للصحة من الخارج. لا تُعد المسارات غير المجربة PASS.

| Method | Endpoint | Auth | الاختبار | النتيجة الفعلية | الحالة |
|---|---|---|---|---|---|
| GET | `/api/v1/index.php?route=health` | Public | طلب مباشر عبر HTTPS | مهلة اتصال من InfinityFree | HOST BLOCKED |
| GET | `/api/v1/index.php?route=cities&country_id=1` | Public | يحتاج استجابة DB | لم يُنفذ حيًا في هذه الجولة | NOT RUN |
| GET | `/api/v1/index.php?route=trips/upcoming&limit=8` | Public | يحتاج استجابة DB | لم يُنفذ حيًا في هذه الجولة | NOT RUN |
| POST | `/api/v1/index.php?route=auth/register` | CSRF/session أو OTP حسب الإعداد | بيانات ناقصة/صحيحة | يحتاج DB ومزود OTP | NOT RUN |
| POST | `/api/v1/index.php?route=auth/login` | CSRF/session أو OTP حسب الإعداد | بيانات صحيحة/خاطئة | يحتاج DB وجلسة | NOT RUN |
| POST | `/api/v1/index.php?route=otp/verify` | Challenge | رمز صحيح/خاطئ/منتهي | منطق الخدمة فُحص محليًا، E2E يحتاج DB | PARTIAL |
| POST | `/api/v1/index.php?route=otp/resend` | Challenge | إعادة إرسال وحدود المعدل | إصلاح مجموع `send_count` منشور | PARTIAL |
| GET | `/api/v1/index.php?route=site-settings` | Public | طلب مباشر | يحتاج استجابة PHP الحية | NOT RUN/HOST |
| GET | `/api/v1/index.php?route=language/context` | Public | طلب مباشر | يحتاج استجابة PHP الحية | NOT RUN/HOST |
| GET | `/api/v1/index.php?route=admin/otp-logs` | Admin permission | منع مستخدم عادي | يحتاج جلسة DB | NOT RUN |
| POST/PUT | مسارات المدن والدول | Admin permission | إنشاء/تعديل/تكرار | الكود مفحوص، E2E يحتاج DB | NOT RUN |
| POST | مسار إنشاء الحجز | Customer/Agent/Admin | حجز/مقاعد/سعر | يحتاج DB وبيانات رحلة | NOT RUN |
| POST | مسارات الدفع والـ callback | Auth/provider | دفع ناجح وفاشل | يحتاج مزود Sandbox | NOT RUN |

## نتائج أمنية ثابتة

تم فحص استخدام الاستعلامات المجهزة في خدمات OTP والحجوزات والصلاحيات، وتمت إضافة حماية الخادم ومنع تنفيذ PHP داخل مجلد الرفع. لا يُسمح بتسجيل PASS لاختبارات IDOR وRBAC وCSRF الكاملة قبل تشغيل جلسات فعلية بأدوار مختلفة.

## المطلوب لإغلاق التقرير

يجب تشغيل PHP/MySQL محليًا مع نسخة Schema اختبارية، أو إعادة استقرار InfinityFree، ثم تنفيذ كل طلبات GET/POST/PUT/DELETE مع جلسات أدوار منفصلة وتسجيل نتيجة قاعدة البيانات بعد كل عملية. كما يجب استخدام مزود دفع وOTP تجريبيين بدل بيانات الإنتاج.
