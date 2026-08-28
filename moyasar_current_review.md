# مراجعة ميسر الرسمية — 28 أغسطس 2026

## المصادر

1. https://moyasar.com/ar/ — الموقع الرسمي العربي؛ يذكر وسائل الدفع المدعومة مثل مدى والبطاقات وApple Pay وSTC Pay وSamsung Pay، لكن التفعيل الفعلي يعتمد على حساب التاجر والعقد.
2. https://docs.moyasar.com/api/authentication — API Keys، publishable key لعملية إنشاء الدفع فقط، secret key للـBackend، Basic Auth مع كلمة مرور فارغة، ورفض إرسال بيانات البطاقة إلى Backend التاجر.
3. https://docs.moyasar.com/api/idempotency — يدعم إنشاء الدفع باستخدام `given_id` بصيغة UUID v4 في جسم الطلب لمنع تكرار الدفع عند إعادة المحاولة. صفحة الفاتورة المستضافة لا توثق في نفس الموضع أن رأسًا مخصصًا باسم `X-Rihla-Idempotency-Key` هو آلية ميسر الرسمية.
4. https://docs.moyasar.com/api/invoices/01-create-invoice — إنشاء Hosted Invoice عبر `POST /invoices` بمبلغ integer بوحدة العملة الصغرى، ISO-4217 currency، description، callback_url، success_url، back_url، expired_at. حالة الفاتورة قد تكون initiated/paid/failed/refunded/canceled/on_hold/expired/voided.
5. https://docs.moyasar.com/api/other/webhooks/webhook-reference — يدعم Webhooks، ويطلب استجابة 2xx سريعة، ويعيد المحاولة خمس مرات بعد الفشل. الكائن يتضمن id/type/created_at/secret_token/live/data. الأحداث تشمل payment_paid وpayment_faild وpayment_refunded وpayment_voided وpayment_authorized وpayment_captured وpayment_verified.
6. https://docs.moyasar.com/api/payments/05-refund-payment — الاسترداد `POST /payments/:id/refund` كامل أو جزئي عبر amount اختياري بوحدة العملة الصغرى.
7. https://docs.moyasar.com/api/payments/payment-status-reference — حالات الدفع initiated/paid/failed/authorized/captured/refunded/voided/verified.

## نتائج المراجعة البرمجية

- المحول الحالي يستخدم Basic Auth بصورة متوافقة مع التوثيق، ويمنع البطاقة من المرور في Backend.
- Hosted Invoice يستخدم amount minor units وcurrency ISO وcallback/success/back/expired.
- يوجد خطأ يجب إصلاحه في إدخال payment row الجديد داخل `PaymentService::ensurePaymentRow`: عدد placeholders/ترتيب قيم INSERT لا يطابق الأعمدة ويهمل environment بصورة صحيحة.
- يوجد خطر توافق في idempotency: المحول يرسل `X-Rihla-Idempotency-Key` إلى `/invoices` بينما توثيق ميسر المتاح يثبت `given_id` لإنشاء الدفع، ولا يثبت هذا الرأس للفواتير المستضافة. الحماية المحلية تمنع النقر المكرر، لكن لا يجوز ادعاء idempotency لدى ميسر للفواتير قبل تحقق رسمي إضافي.
- يوجد خلل محتمل في webhook environment: `handleWebhook` يختار sandbox قبل live دون مطابقة الحقل `live` الوارد من ميسر؛ يجب أن يطابق `live=true` إعداد live و`live=false` إعداد sandbox، ويرفض عدم التطابق.
- حالة `payment_faild` يجب أن تُعامل كاسم حدث ميسر الموثق رغم الخطأ الإملائي في اسم الحدث الرسمي.
- وسائل مدى وApple Pay وSTC Pay وSamsung Pay ليست مفاتيح frontend عامة بحد ذاتها؛ يجب أن يحددها حساب التاجر/عقد ميسر وإعدادات النطاق والجهاز، لذلك قائمة methods في لوحة الإدارة توثيقية ما لم يتم اعتمادها من ميسر.
