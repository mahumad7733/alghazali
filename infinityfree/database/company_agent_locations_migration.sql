-- ترقية مواقع شركات النقل والوكلاء: استخدمها مرة واحدة على قاعدة البيانات الحالية.
ALTER TABLE companies
  ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,7) NULL AFTER address,
  ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) NULL AFTER latitude;

ALTER TABLE agents
  ADD COLUMN IF NOT EXISTS latitude DECIMAL(10,7) NULL AFTER country_id,
  ADD COLUMN IF NOT EXISTS longitude DECIMAL(10,7) NULL AFTER latitude;
