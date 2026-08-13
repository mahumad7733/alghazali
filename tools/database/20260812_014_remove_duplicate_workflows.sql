-- حذف النسخ المكررة بعد التحقق من عدم وجود حجوزات أو معاملات مرتبطة بها.
-- السير المحفوظة الفعالة هي: 9 للباصات، 10 للطيران، 6 للجوازات.

DELETE FROM workflow_transitions WHERE workflow_id IN (4, 5, 7, 8);
DELETE FROM workflow_steps WHERE workflow_id IN (4, 5, 7, 8);
DELETE FROM workflows WHERE id IN (4, 5, 7, 8);
