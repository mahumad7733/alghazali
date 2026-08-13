-- إبقاء سير معاملات الجوازات الأساسي رقم 6 وتعطيل النسختين المكررتين.
UPDATE workflows
SET is_active = 0, updated_at = CURRENT_TIMESTAMP
WHERE transaction_type = 'passport_transactions' AND id IN (7, 8);

-- إزالة الانتقالات المكررة في السير الأساسي، مع إبقاء أول نسخة من كل انتقال.
DELETE FROM workflow_transitions
WHERE workflow_id = 6 AND id >= 46;

-- إزالة نسخ الخطوات المكررة، مع الإبقاء على الخطوات الأصلية 37 إلى 43.
DELETE FROM workflow_steps
WHERE workflow_id = 6 AND id BETWEEN 44 AND 57;
