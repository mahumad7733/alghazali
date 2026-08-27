-- مخزون مقاعد داخلي للوسيط: لا يظهر في شاشة الباصات ولا يعتبر باصًا تجاريًا.
ALTER TABLE buses ADD COLUMN is_virtual TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE trips ADD COLUMN bus_type VARCHAR(100) NULL AFTER trip_type;

-- بعد تشغيل الكود، ينشئ النظام تلقائيًا مزود مقاعد داخليًا لكل شركة عند إنشاء رحلة بلا باص.
-- يتم ربط المقاعد بالرحلة في trip_seat_inventory فقط، وتبقى bus_id مخفية على مستوى الواجهة.
