-- بيانات عرض محلية قابلة لإعادة التشغيل، لا تُستخدم على الإنتاج دون مراجعة.
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

INSERT INTO countries (code, name_ar, phone_code, is_active)
VALUES ('YE', 'اليمن', '+967', 1)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), is_active = 1;
SET @country_id = LAST_INSERT_ID();

INSERT INTO currencies (code, name_ar, symbol_ar, decimal_places, is_active)
VALUES ('YER', 'الريال اليمني', 'ر.ي', 0, 1)
ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), name_ar = VALUES(name_ar), symbol_ar = VALUES(symbol_ar), is_active = 1;
SET @currency_id = LAST_INSERT_ID();

INSERT INTO cities (country_id, name_ar, is_active)
SELECT @country_id, 'صنعاء', 1 WHERE NOT EXISTS (SELECT 1 FROM cities WHERE country_id = @country_id AND name_ar = 'صنعاء');
INSERT INTO cities (country_id, name_ar, is_active)
SELECT @country_id, 'تعز', 1 WHERE NOT EXISTS (SELECT 1 FROM cities WHERE country_id = @country_id AND name_ar = 'تعز');
SET @origin_city_id = (SELECT id FROM cities WHERE country_id = @country_id AND name_ar = 'صنعاء' LIMIT 1);
SET @destination_city_id = (SELECT id FROM cities WHERE country_id = @country_id AND name_ar = 'تعز' LIMIT 1);

INSERT INTO stations (city_id, name_ar, address, is_active)
SELECT @origin_city_id, 'محطة صنعاء المركزية', 'شارع الستين، صنعاء', 1
WHERE NOT EXISTS (SELECT 1 FROM stations WHERE city_id = @origin_city_id AND name_ar = 'محطة صنعاء المركزية');
INSERT INTO stations (city_id, name_ar, address, is_active)
SELECT @destination_city_id, 'محطة تعز الرئيسية', 'شارع جمال، تعز', 1
WHERE NOT EXISTS (SELECT 1 FROM stations WHERE city_id = @destination_city_id AND name_ar = 'محطة تعز الرئيسية');
SET @origin_station_id = (SELECT id FROM stations WHERE city_id = @origin_city_id AND name_ar = 'محطة صنعاء المركزية' LIMIT 1);
SET @destination_station_id = (SELECT id FROM stations WHERE city_id = @destination_city_id AND name_ar = 'محطة تعز الرئيسية' LIMIT 1);

INSERT INTO companies (legal_name, trade_name, logo_path, cover_image_path, country_id, city_id, address, phone, email, base_currency_id, status)
SELECT 'شركة النور اليمنية للنقل والسياحة', 'شركة النور للنقل', 'uploads/companies/noor/logo.png', 'uploads/companies/noor/cover.png', @country_id, @origin_city_id, 'صنعاء، الجمهورية اليمنية', '+967 1 234 567', 'support@noor-transport.local', @currency_id, 'active'
WHERE NOT EXISTS (SELECT 1 FROM companies WHERE trade_name = 'شركة النور للنقل');
SET @company_id = (SELECT id FROM companies WHERE trade_name = 'شركة النور للنقل' ORDER BY id LIMIT 1);
UPDATE companies SET logo_path = 'uploads/companies/noor/logo.png', cover_image_path = 'uploads/companies/noor/cover.png' WHERE id = @company_id;

INSERT INTO company_images (company_id, image_path, image_order, status)
SELECT @company_id, 'uploads/companies/noor/gallery-1.png', 1, 'active'
WHERE NOT EXISTS (SELECT 1 FROM company_images WHERE company_id = @company_id AND image_order = 1);
UPDATE company_images SET image_path = 'uploads/companies/noor/gallery-1.png', status = 'active' WHERE company_id = @company_id AND image_order = 1;
INSERT INTO company_images (company_id, image_path, image_order, status)
SELECT @company_id, 'uploads/companies/noor/gallery-2.png', 2, 'active'
WHERE NOT EXISTS (SELECT 1 FROM company_images WHERE company_id = @company_id AND image_order = 2);
UPDATE company_images SET image_path = 'uploads/companies/noor/gallery-2.png', status = 'active' WHERE company_id = @company_id AND image_order = 2;

INSERT INTO routes (company_id, code, name_ar, route_type, status)
SELECT @company_id, 'Sanaa-Taiz', 'صنعاء ← تعز', 'normal', 'active'
WHERE NOT EXISTS (SELECT 1 FROM routes WHERE company_id = @company_id AND code = 'Sanaa-Taiz');
SET @route_id = (SELECT id FROM routes WHERE company_id = @company_id AND code = 'Sanaa-Taiz' LIMIT 1);

INSERT INTO route_stops (route_id, station_id, stop_order, arrival_offset_minutes, departure_offset_minutes)
SELECT @route_id, @origin_station_id, 1, 0, 0
WHERE NOT EXISTS (SELECT 1 FROM route_stops WHERE route_id = @route_id AND stop_order = 1);
INSERT INTO route_stops (route_id, station_id, stop_order, arrival_offset_minutes, departure_offset_minutes)
SELECT @route_id, @destination_station_id, 2, 480, 480
WHERE NOT EXISTS (SELECT 1 FROM route_stops WHERE route_id = @route_id AND stop_order = 2);
SET @origin_stop_id = (SELECT id FROM route_stops WHERE route_id = @route_id AND stop_order = 1 LIMIT 1);
SET @destination_stop_id = (SELECT id FROM route_stops WHERE route_id = @route_id AND stop_order = 2 LIMIT 1);

INSERT INTO route_segments (route_id, origin_stop_id, destination_stop_id, origin_order, destination_order, is_active)
SELECT @route_id, @origin_stop_id, @destination_stop_id, 1, 2, 1
WHERE NOT EXISTS (SELECT 1 FROM route_segments WHERE route_id = @route_id AND origin_stop_id = @origin_stop_id AND destination_stop_id = @destination_stop_id);
SET @segment_id = (SELECT id FROM route_segments WHERE route_id = @route_id AND origin_stop_id = @origin_stop_id AND destination_stop_id = @destination_stop_id LIMIT 1);

INSERT INTO route_subroutes (company_id, origin_city_id, destination_city_id, currency_id, company_amount, amount, origin_arrival_time, origin_departure_time, destination_arrival_time, destination_departure_time, status)
SELECT @company_id, @origin_city_id, @destination_city_id, @currency_id, 15000.00, 20000.00, '07:30:00', '08:00:00', '16:00:00', '16:15:00', 'active'
WHERE NOT EXISTS (SELECT 1 FROM route_subroutes WHERE company_id = @company_id AND origin_city_id = @origin_city_id AND destination_city_id = @destination_city_id);
SET @subroute_id = (SELECT id FROM route_subroutes WHERE company_id = @company_id AND origin_city_id = @origin_city_id AND destination_city_id = @destination_city_id ORDER BY id LIMIT 1);

INSERT INTO route_subroute_links (route_id, subroute_id, route_segment_id, stop_order)
SELECT @route_id, @subroute_id, @segment_id, 1
WHERE NOT EXISTS (SELECT 1 FROM route_subroute_links WHERE route_id = @route_id AND subroute_id = @subroute_id);

INSERT INTO segment_prices (company_id, route_segment_id, currency_id, amount, starts_at, status)
SELECT @company_id, @segment_id, @currency_id, 20000.00, '2026-01-01 00:00:00', 'active'
WHERE NOT EXISTS (SELECT 1 FROM segment_prices WHERE company_id = @company_id AND route_segment_id = @segment_id AND currency_id = @currency_id AND status = 'active');
SET @segment_price_id = (SELECT id FROM segment_prices WHERE company_id = @company_id AND route_segment_id = @segment_id AND currency_id = @currency_id AND status = 'active' ORDER BY id LIMIT 1);

INSERT INTO buses (company_id, name_ar, bus_number, plate_number, bus_type, interior_image_path, exterior_image_path, model_year, seat_count, status)
SELECT @company_id, 'باص النور السياحي', 'NOOR-20', 'يمن-20-001', 'VIP', 'uploads/companies/noor/bus-interior.png', 'uploads/companies/noor/bus-exterior.png', 2024, 20, 'active'
WHERE NOT EXISTS (SELECT 1 FROM buses WHERE company_id = @company_id AND bus_number = 'NOOR-20');
SET @bus_id = (SELECT id FROM buses WHERE company_id = @company_id AND bus_number = 'NOOR-20' LIMIT 1);
UPDATE buses SET interior_image_path = 'uploads/companies/noor/bus-interior.png', exterior_image_path = 'uploads/companies/noor/bus-exterior.png' WHERE id = @bus_id;

INSERT INTO bus_seats (bus_id, seat_code, seat_row, column_code, seat_type, is_active)
SELECT @bus_id, seat_code, seat_row, column_code, 'regular', 1
FROM (
  SELECT 'A1' seat_code, 1 seat_row, 'A' column_code UNION ALL SELECT 'B1',1,'B' UNION ALL SELECT 'C1',1,'C' UNION ALL SELECT 'D1',1,'D'
  UNION ALL SELECT 'A2',2,'A' UNION ALL SELECT 'B2',2,'B' UNION ALL SELECT 'C2',2,'C' UNION ALL SELECT 'D2',2,'D'
  UNION ALL SELECT 'A3',3,'A' UNION ALL SELECT 'B3',3,'B' UNION ALL SELECT 'C3',3,'C' UNION ALL SELECT 'D3',3,'D'
  UNION ALL SELECT 'A4',4,'A' UNION ALL SELECT 'B4',4,'B' UNION ALL SELECT 'C4',4,'C' UNION ALL SELECT 'D4',4,'D'
  UNION ALL SELECT 'A5',5,'A' UNION ALL SELECT 'B5',5,'B' UNION ALL SELECT 'C5',5,'C' UNION ALL SELECT 'D5',5,'D'
) seats
WHERE NOT EXISTS (SELECT 1 FROM bus_seats existing WHERE existing.bus_id = @bus_id AND existing.seat_code = seats.seat_code);

SET @departure_at = TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '08:00:00');
SET @arrival_at = TIMESTAMP(DATE_ADD(CURDATE(), INTERVAL 1 DAY), '16:00:00');
INSERT INTO trips (company_id, route_id, route_subroute_id, bus_id, trip_number, departure_at, arrival_at, booking_open_at, booking_close_at, status)
SELECT @company_id, @route_id, @subroute_id, @bus_id, 'NO-1001', @departure_at, @arrival_at, NOW(), DATE_SUB(@departure_at, INTERVAL 30 MINUTE), 'open'
WHERE NOT EXISTS (SELECT 1 FROM trips WHERE company_id = @company_id AND trip_number = 'NO-1001' AND DATE(departure_at) = DATE(@departure_at));
SET @trip_id = (SELECT id FROM trips WHERE company_id = @company_id AND trip_number = 'NO-1001' AND DATE(departure_at) = DATE(@departure_at) LIMIT 1);

INSERT INTO trip_segment_prices (trip_id, route_segment_id, currency_id, company_amount, amount, source_price_id)
SELECT @trip_id, @segment_id, @currency_id, 15000.00, 20000.00, @segment_price_id
WHERE NOT EXISTS (SELECT 1 FROM trip_segment_prices WHERE trip_id = @trip_id AND route_segment_id = @segment_id AND currency_id = @currency_id);
INSERT INTO trip_seat_inventory (trip_id, bus_seat_id, is_available)
SELECT @trip_id, id, 1 FROM bus_seats
WHERE bus_id = @bus_id AND is_active = 1
  AND NOT EXISTS (SELECT 1 FROM trip_seat_inventory existing WHERE existing.trip_id = @trip_id AND existing.bus_seat_id = bus_seats.id);

INSERT INTO contact_channels (type, title_ar, value, description_ar, icon, sort_order, status)
VALUES
('phone', 'الهاتف', '+967 1 234 567', 'الدعم والحجوزات من 08:00 إلى 20:00', 'bi-telephone', 1, 'active'),
('whatsapp', 'واتساب', '+967 777 123 456', 'رسائل سريعة لخدمة العملاء', 'bi-whatsapp', 2, 'active'),
('email', 'البريد الإلكتروني', 'support@rihla.local', 'نستقبل استفساراتكم وطلبات الدعم', 'bi-envelope', 3, 'active'),
('location', 'موقع المكتب', 'صنعاء، الجمهورية اليمنية', 'مكتب خدمة العملاء الرئيسي', 'bi-geo-alt', 4, 'inactive'),
('hours', 'ساعات العمل', 'يوميًا 08:00 — 20:00', 'عدا العطلات الرسمية', 'bi-clock', 5, 'active')
ON DUPLICATE KEY UPDATE value = VALUES(value), description_ar = VALUES(description_ar), status = VALUES(status);
COMMIT;
SET FOREIGN_KEY_CHECKS = 1;

SELECT @company_id AS company_id, @route_id AS route_id, @bus_id AS bus_id, @trip_id AS trip_id, DATE(@departure_at) AS travel_date;
