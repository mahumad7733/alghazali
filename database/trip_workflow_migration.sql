-- دورة تشغيل الرحلة: مجدولة -> مفتوحة -> قيد الصعود -> مكتملة.
-- الحالات الملغاة والمنتهية حالات نهائية، ولا تُحذف البيانات التاريخية.
ALTER TABLE trips
  MODIFY COLUMN status ENUM('scheduled','open','boarding','completed','cancelled','expired') NOT NULL DEFAULT 'scheduled';
