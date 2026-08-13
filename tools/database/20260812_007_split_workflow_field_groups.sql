-- فصل مجموعات حقول سير العمل الخاصة بالباصات والطيران.
INSERT INTO workflow_field_groups (group_name, group_key, description, is_active)
SELECT 'حجوزات الباصات', 'bus_booking', 'حقول سير عمل حجوزات الباصات', 1
WHERE NOT EXISTS (SELECT 1 FROM workflow_field_groups WHERE group_key = 'bus_booking');

INSERT INTO workflow_field_groups (group_name, group_key, description, is_active)
SELECT 'حجوزات الطيران', 'flight_booking', 'حقول سير عمل حجوزات الطيران', 1
WHERE NOT EXISTS (SELECT 1 FROM workflow_field_groups WHERE group_key = 'flight_booking');

INSERT INTO workflow_field_group_mappings (field_id, group_id, sort_order, is_visible)
SELECT m.field_id, g.id, m.sort_order, m.is_visible
FROM workflow_field_group_mappings m
JOIN workflow_field_groups old_g ON old_g.id = m.group_id AND old_g.group_key = 'booking'
JOIN workflow_field_groups g ON g.group_key IN ('bus_booking', 'flight_booking')
WHERE NOT EXISTS (
    SELECT 1 FROM workflow_field_group_mappings existing
    WHERE existing.field_id = m.field_id AND existing.group_id = g.id
);
