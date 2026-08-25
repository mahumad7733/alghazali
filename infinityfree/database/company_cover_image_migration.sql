-- نفّذ هذه الترقية مرة واحدة على قاعدة البيانات الحالية قبل استعمال صورة الشركة التعريفية.
ALTER TABLE companies ADD COLUMN cover_image_path VARCHAR(500) NULL AFTER logo_path;
