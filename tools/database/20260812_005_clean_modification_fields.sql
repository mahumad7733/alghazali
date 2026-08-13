-- تنظيف حقول تعديل الحجز: المسار فقط، بدون وقت مغادرة مطلوب ضمن حقول سير العمل.
UPDATE workflow_steps
SET show_fields = REPLACE(REPLACE(show_fields, 'requested_departure_time,', ''), ',requested_departure_time', '')
WHERE show_fields LIKE '%requested_departure_time%';

DELETE m FROM workflow_field_group_mappings m
JOIN workflow_fields f ON f.id = m.field_id
WHERE f.field_key = 'requested_departure_time';

DELETE FROM workflow_fields WHERE field_key = 'requested_departure_time';

-- ضمان أن نوع التعديل المتاح في الحقول هو تعديل المسار فقط.
UPDATE workflow_fields
SET field_options = '{"options":["route"]}', field_label = 'نوع التعديل (تعديل المسار فقط)'
WHERE field_key = 'modification_type';
