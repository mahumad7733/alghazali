-- ترقية تشغيلية ومالية متوافقة: لا تنشئ جداول جديدة ولا تحذف بيانات أو علاقات قائمة.
-- تنفذ مرة واحدة على قاعدة موجودة؛ تظل القيم التاريخية كما هي، وتستخدم الحقول الجديدة للبيانات اللاحقة.

ALTER TABLE route_subroutes
  ADD COLUMN company_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER currency_id;

ALTER TABLE trip_segment_prices
  ADD COLUMN company_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER currency_id;

ALTER TABLE bookings
  ADD COLUMN company_cost_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER commission_amount,
  ADD COLUMN company_payable_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER company_cost_amount,
  ADD COLUMN platform_commission_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER company_payable_amount;

ALTER TABLE booking_segments
  ADD COLUMN company_unit_amount DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER destination_name_ar;

ALTER TABLE bookings
  ADD COLUMN agent_commission_type ENUM('percentage','fixed') NULL AFTER commission_amount,
  ADD COLUMN agent_commission_rate DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER agent_commission_type;

ALTER TABLE trips
  ADD COLUMN route_subroute_id BIGINT UNSIGNED NULL AFTER route_id,
  ADD INDEX idx_trips_route_subroute (route_subroute_id);

ALTER TABLE trips
  ADD COLUMN recurrence_group VARCHAR(64) NULL AFTER trip_number,
  ADD COLUMN recurrence_index SMALLINT UNSIGNED NULL AFTER recurrence_group,
  ADD INDEX idx_trips_recurrence_group (recurrence_group, departure_at);
