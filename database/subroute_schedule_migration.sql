-- ترقية المسارات الفرعية إلى مقاطع مشتركة مع أوقات الوصول والمغادرة للمدن.
ALTER TABLE route_subroutes
  MODIFY COLUMN company_id BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS origin_arrival_time TIME NULL AFTER amount,
  ADD COLUMN IF NOT EXISTS origin_departure_time TIME NULL AFTER origin_arrival_time,
  ADD COLUMN IF NOT EXISTS destination_arrival_time TIME NULL AFTER origin_departure_time,
  ADD COLUMN IF NOT EXISTS destination_departure_time TIME NULL AFTER destination_arrival_time;
