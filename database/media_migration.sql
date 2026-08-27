-- يُنفذ مرة واحدة على قاعدة قائمة قبل إضافة حقول صور الباص.
ALTER TABLE buses ADD COLUMN IF NOT EXISTS interior_image_path VARCHAR(500) NULL AFTER bus_type;
ALTER TABLE buses ADD COLUMN IF NOT EXISTS exterior_image_path VARCHAR(500) NULL AFTER interior_image_path;
