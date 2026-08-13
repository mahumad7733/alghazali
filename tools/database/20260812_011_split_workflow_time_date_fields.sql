-- إضافة نوع مستقل للوقت في حقول سير العمل، مع إبقاء التاريخ حقلاً date مستقلاً.
ALTER TABLE workflow_fields
MODIFY COLUMN field_type ENUM('text','number','date','time','datetime','textarea','select','checkbox','file') NOT NULL DEFAULT 'text';

UPDATE workflow_fields
SET field_type = 'time', field_label = 'وقت المغادرة المطلوب'
WHERE field_key = 'requested_departure_time';

UPDATE workflow_fields
SET field_type = 'date', field_label = 'تاريخ المغادرة المطلوب'
WHERE field_key = 'requested_mod_date';
