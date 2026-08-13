-- إضافة وقت المغادرة الحالي كحقل مستقل في إدارة سير العمل.
INSERT INTO workflow_fields (field_key, field_label, field_type, field_options, is_required, is_active, sort_order)
SELECT 'current_departure_time', 'وقت المغادرة الحالي', 'time', NULL, 0, 1, 30
WHERE NOT EXISTS (SELECT 1 FROM workflow_fields WHERE field_key = 'current_departure_time');

INSERT INTO workflow_field_group_mappings (field_id, group_id, sort_order, is_visible)
SELECT f.id, g.id, f.sort_order, 1
FROM workflow_fields f
JOIN workflow_field_groups g ON g.group_key IN ('booking', 'bus_booking', 'flight_booking')
WHERE f.field_key = 'current_departure_time'
  AND NOT EXISTS (
      SELECT 1 FROM workflow_field_group_mappings m
      WHERE m.field_id = f.id AND m.group_id = g.id
  );

UPDATE workflow_steps
SET show_fields = CASE
    WHEN show_fields IS NULL OR show_fields = '' THEN 'current_departure_time,modification_type,requested_from_city_id,requested_to_city_id,requested_departure_time,requested_mod_date,mod_reason,charge_penalty,modification_penalty_percent,modification_penalty_amount'
    WHEN FIND_IN_SET('current_departure_time', show_fields) = 0 THEN CONCAT('current_departure_time,', show_fields)
    ELSE show_fields
END
WHERE workflow_id IN (5, 9, 10) AND step_name = 'طلب تعديل';
