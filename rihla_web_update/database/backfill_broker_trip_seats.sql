-- إصلاح الرحلات الوسيطة القديمة التي لا تحتوي seat_count.
-- القيمة المعتمدة: 20 مقعدًا، وهي سعة الباص التجاري الوحيد الموجودة في قاعدة الاختبار.
START TRANSACTION;

SET @broker_pool_id := (
    SELECT id FROM buses
    WHERE company_id = 2 AND COALESCE(is_virtual, 0) = 1 AND status = 'active'
    ORDER BY id LIMIT 1
);

UPDATE trips
SET seat_count = 20,
    bus_type = CASE WHEN LOWER(COALESCE(bus_type, '')) IN ('vip', 'tourist', 'tourism') THEN 'VIP' ELSE 'normal' END,
    bus_id = @broker_pool_id
WHERE company_id = 2
  AND status = 'open'
  AND (seat_count IS NULL OR seat_count = 0);

DELETE inventory
FROM trip_seat_inventory inventory
INNER JOIN trips t ON t.id = inventory.trip_id
WHERE t.company_id = 2
  AND t.status = 'open'
  AND t.bus_id = @broker_pool_id
  AND t.seat_count = 20;

INSERT INTO trip_seat_inventory (trip_id, bus_seat_id, is_available)
SELECT t.id, bs.id, 1
FROM trips t
INNER JOIN bus_seats bs ON bs.bus_id = @broker_pool_id AND bs.is_active = 1 AND CAST(SUBSTRING(bs.seat_code, 2) AS UNSIGNED) <= 20
WHERE t.company_id = 2
  AND t.status = 'open'
  AND t.bus_id = @broker_pool_id
  AND t.seat_count = 20
ORDER BY t.id, bs.id;

COMMIT;
