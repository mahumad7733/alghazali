-- توسعة المسارات الرئيسية فقط: لا تعدّل جدول route_subroutes ولا بياناته.
ALTER TABLE routes
  ADD COLUMN route_type ENUM('normal','tourist') NOT NULL DEFAULT 'normal' AFTER name_ar;
