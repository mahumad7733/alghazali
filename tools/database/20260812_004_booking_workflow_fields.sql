-- حقول سير عمل الحجوزات الجديدة وربطها بالحالات المناسبة
SET @wf_booking := (SELECT id FROM workflows WHERE transaction_type = 'booking' ORDER BY id DESC LIMIT 1);
SET @wf_bus_flight := (SELECT id FROM workflows WHERE transaction_type = 'bus_flight_bookings' ORDER BY id DESC LIMIT 1);

INSERT INTO workflow_fields (field_key, field_label, field_type, field_options, placeholder, is_required, is_active, sort_order)
SELECT x.field_key, x.field_label, x.field_type, x.field_options, x.placeholder, x.is_required, 1, x.sort_order
FROM (
    SELECT 'modification_type' AS field_key, 'نوع التعديل' AS field_label, 'select' AS field_type, '{"options":["route","time"]}' AS field_options, 'اختر نوع التعديل' AS placeholder, 1 AS is_required, 210 AS sort_order
    UNION ALL SELECT 'requested_from_city_id', 'مدينة المغادرة المطلوبة', 'number', NULL, 'رقم المدينة الجديدة', 0, 211
    UNION ALL SELECT 'requested_to_city_id', 'مدينة الوصول المطلوبة', 'number', NULL, 'رقم المدينة الجديدة', 0, 212
    UNION ALL SELECT 'requested_departure_time', 'وقت المغادرة المطلوب', 'text', NULL, 'مثال: 14:30', 0, 213
    UNION ALL SELECT 'requested_mod_date', 'تاريخ المغادرة المطلوب', 'date', NULL, NULL, 0, 214
    UNION ALL SELECT 'mod_reason', 'سبب التعديل', 'textarea', NULL, 'اكتب سبب التعديل', 0, 215
    UNION ALL SELECT 'charge_penalty', 'إضافة غرامة على التعديل', 'checkbox', NULL, NULL, 0, 216
    UNION ALL SELECT 'modification_penalty_percent', 'نسبة غرامة التعديل %', 'number', NULL, '0 - 100', 0, 217
    UNION ALL SELECT 'modification_penalty_amount', 'مبلغ غرامة التعديل', 'number', NULL, NULL, 0, 218
    UNION ALL SELECT 'discount_percent', 'نسبة الخصم/الغرامة %', 'number', NULL, '0 - 100', 0, 219
    UNION ALL SELECT 'discount_amount', 'المبلغ المخصوم', 'number', NULL, NULL, 0, 220
    UNION ALL SELECT 'net_amount', 'المبلغ الصافي بعد الخصم', 'number', NULL, NULL, 0, 221
    UNION ALL SELECT 'cancel_reason', 'سبب الإلغاء', 'textarea', NULL, 'اكتب سبب الإلغاء', 0, 222
    UNION ALL SELECT 'cancel_datetime', 'تاريخ ووقت الإلغاء', 'datetime', NULL, NULL, 0, 223
    UNION ALL SELECT 'ticket_number', 'رقم التذكرة', 'text', NULL, NULL, 0, 224
    UNION ALL SELECT 'confirm_datetime', 'تاريخ ووقت التأكيد', 'datetime', NULL, NULL, 0, 225
) AS x
WHERE NOT EXISTS (SELECT 1 FROM workflow_fields f WHERE f.field_key = x.field_key);

-- تعيين الحقول لحالات سير عمل الحجوزات الحديث والقديم.
UPDATE workflow_steps ws JOIN workflows w ON w.id = ws.workflow_id
SET ws.show_fields = CASE
    WHEN ws.step_key IN ('request_edit') OR ws.step_name LIKE '%طلب تعديل%' OR ws.step_name LIKE '%تم تعديل%' THEN 'modification_type,requested_from_city_id,requested_to_city_id,requested_departure_time,requested_mod_date,mod_reason,charge_penalty,modification_penalty_percent,modification_penalty_amount'
    WHEN ws.step_key = 'cancel' OR ws.step_name LIKE '%إلغاء%' OR ws.step_name LIKE '%ملغي%' THEN 'cancel_reason,cancel_datetime,discount_percent,discount_amount,net_amount'
    WHEN ws.step_key = 'confirm' OR ws.step_name LIKE '%تأكيد%' OR ws.step_name LIKE '%مؤكد%' THEN 'ticket_number,confirm_datetime'
    ELSE ws.show_fields
END
WHERE w.transaction_type IN ('booking', 'bus_flight_bookings');

-- ضمان بقاء الحقول الجديدة قابلة للعرض حتى عند عدم وجود step_key في السجلات القديمة.
UPDATE workflow_steps ws JOIN workflows w ON w.id = ws.workflow_id
SET ws.show_fields = 'modification_type,requested_from_city_id,requested_to_city_id,requested_departure_time,requested_mod_date,mod_reason,charge_penalty,modification_penalty_percent,modification_penalty_amount'
WHERE w.transaction_type IN ('booking', 'bus_flight_bookings') AND (ws.step_name LIKE '%تعديل%' OR ws.step_name LIKE '%طلب تعديل%');

-- ربط الحقول الجديدة بمجموعة حجوزات الباص والطيران في شاشة إدارة الحقول.
INSERT INTO workflow_field_group_mappings (field_id, group_id)
SELECT f.id, g.id
FROM workflow_fields f
JOIN workflow_field_groups g ON g.group_key = 'booking'
WHERE f.field_key IN (
    'modification_type','requested_from_city_id','requested_to_city_id','requested_departure_time',
    'requested_mod_date','mod_reason','charge_penalty','modification_penalty_percent',
    'modification_penalty_amount','discount_percent','discount_amount','net_amount',
    'cancel_reason','cancel_datetime','ticket_number','confirm_datetime'
)
AND NOT EXISTS (
    SELECT 1 FROM workflow_field_group_mappings m
    WHERE m.field_id = f.id AND m.group_id = g.id
);
