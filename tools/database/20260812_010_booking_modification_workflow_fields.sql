-- إعادة تعريف حقول طلب تعديل الحجز داخل سير العمل.
INSERT INTO workflow_fields (field_key, field_label, field_type, field_options, is_required, is_active, sort_order)
SELECT 'requested_departure_time', 'وقت المغادرة المطلوب', 'text', NULL, 0, 1, 31
WHERE NOT EXISTS (SELECT 1 FROM workflow_fields WHERE field_key = 'requested_departure_time');

-- ربط الحقل الجديد بمجموعات الحجز القديمة والجديدة.
INSERT INTO workflow_field_group_mappings (field_id, group_id, sort_order, is_visible)
SELECT f.id, g.id, f.sort_order, 1
FROM workflow_fields f
JOIN workflow_field_groups g ON g.group_key IN ('booking', 'bus_booking', 'flight_booking')
WHERE f.field_key = 'requested_departure_time'
  AND NOT EXISTS (
      SELECT 1 FROM workflow_field_group_mappings m
      WHERE m.field_id = f.id AND m.group_id = g.id
  );

-- الحقول التابعة لطلب التعديل تظهر في إعداد الخطوة، بينما واجهة الحجز تعرضها بشكل شرطي.
UPDATE workflow_steps
SET show_fields = 'modification_type,requested_from_city_id,requested_to_city_id,requested_departure_time,requested_mod_date,mod_reason,charge_penalty,modification_penalty_percent,modification_penalty_amount'
WHERE workflow_id IN (5, 9, 10) AND step_name = 'طلب تعديل';
