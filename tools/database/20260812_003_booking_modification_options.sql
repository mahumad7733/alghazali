-- خيارات تعديل الحجز: وقت المغادرة
ALTER TABLE bus_flight_bookings
    ADD COLUMN IF NOT EXISTS departure_time TIME NULL AFTER departure_date;
