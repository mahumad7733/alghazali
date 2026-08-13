-- إعادة خياري نوع تعديل الحجز بعد تنظيف الحقول المكررة.
UPDATE workflow_fields
SET field_options = '{"options":["route","time"]}',
    field_label = 'نوع التعديل'
WHERE field_key = 'modification_type';
