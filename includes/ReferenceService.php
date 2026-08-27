<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class ReferenceService
{
    public function __construct(private Database $database, private BookingService $bookingService)
    {
        $this->ensureBrokerColumns();
    }

    private function ensureBrokerColumns(): void
    {
        $pdo = $this->database->pdo();
        if ($pdo->query("SHOW COLUMNS FROM buses LIKE 'is_virtual'")->fetchColumn() === false) { $pdo->exec('ALTER TABLE buses ADD COLUMN is_virtual TINYINT(1) NOT NULL DEFAULT 0 AFTER status'); }
        if ($pdo->query("SHOW COLUMNS FROM trips LIKE 'trip_type'")->fetchColumn() === false) { $pdo->exec("ALTER TABLE trips ADD COLUMN trip_type VARCHAR(30) NOT NULL DEFAULT 'local' AFTER trip_number"); }
        if ($pdo->query("SHOW COLUMNS FROM trips LIKE 'bus_type'")->fetchColumn() === false) { $pdo->exec("ALTER TABLE trips ADD COLUMN bus_type VARCHAR(100) NULL AFTER trip_type"); }
        if ($pdo->query("SHOW COLUMNS FROM trips LIKE 'seat_count'")->fetchColumn() === false) { $pdo->exec("ALTER TABLE trips ADD COLUMN seat_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER bus_type"); }
    }

    /** @return list<array<string, mixed>> */
    public function countries(): array
    {
        return $this->all('SELECT id, code, name_ar, phone_code FROM countries WHERE is_active = 1 ORDER BY name_ar');
    }

    /** @return list<array<string, mixed>> */
    public function currencies(): array
    {
        return $this->all('SELECT id, code, name_ar, symbol_ar, decimal_places FROM currencies WHERE is_active = 1 ORDER BY code');
    }

    /** @return list<array<string, mixed>> */
    public function cities(int $countryId): array
    {
        return $this->all('SELECT id, country_id, name_ar FROM cities WHERE country_id = :country_id AND is_active = 1 ORDER BY name_ar', ['country_id' => $countryId]);
    }

    /** @return list<array<string, mixed>> */
    public function stations(int $cityId): array
    {
        return $this->all('SELECT id, city_id, name_ar, address, latitude, longitude FROM stations WHERE city_id = :city_id AND is_active = 1 ORDER BY name_ar', ['city_id' => $cityId]);
    }

    /** @return list<array<string, mixed>> */
    public function companies(): array
    {
        $items = $this->all(
            "SELECT co.id, co.trade_name, co.legal_name, co.phone, co.email, co.latitude, co.longitude, co.logo_path, co.cover_image_path, cu.code AS currency_code, cu.symbol_ar AS currency_symbol,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.bus_type), '') ORDER BY b.bus_type SEPARATOR '|') AS bus_types,
                    (SELECT ROUND(AVG(rv.rating), 1) FROM trip_reviews rv INNER JOIN bookings rb ON rb.id = rv.booking_id WHERE rb.company_id = co.id AND rv.status = 'published') AS company_rating,
                    (SELECT COUNT(*) FROM trip_reviews rv INNER JOIN bookings rb ON rb.id = rv.booking_id WHERE rb.company_id = co.id AND rv.status = 'published') AS company_rating_count
             FROM companies co INNER JOIN currencies cu ON cu.id = co.base_currency_id
             LEFT JOIN buses b ON b.company_id = co.id AND b.status = 'active' AND COALESCE(b.is_virtual, 0) = 0
             WHERE co.status = 'active'
             GROUP BY co.id, co.trade_name, co.legal_name, co.phone, co.email, co.latitude, co.longitude, co.logo_path, co.cover_image_path, cu.code, cu.symbol_ar
             ORDER BY co.trade_name"
        );
        foreach ($items as &$item) { $item['gallery_images'] = $this->companyImages((int) $item['id']); }
        unset($item);
        return $items;
    }

    /** @return list<array<string, mixed>> */
    public function searchTrips(int $originCityId, int $destinationCityId, string $date, string $busType = ''): array
    {
        $this->bookingService->expirePendingBookings();
        $statement = $this->database->pdo()->prepare(
            "SELECT t.id, t.trip_number, t.bus_id, t.trip_type, t.seat_count, t.departure_at, t.arrival_at, t.status, r.route_type, r.journey_type, co.id AS company_id, co.trade_name AS company_name, co.latitude AS company_latitude, co.longitude AS company_longitude, co.logo_path, co.cover_image_path,
                    CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE COALESCE(b.name_ar, 'الباص غير مربوط') END AS bus_name, COALESCE(NULLIF(t.bus_type, ''), NULLIF(CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.bus_type END, ''), 'normal') AS bus_type, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.model_year END AS model_year, COALESCE(NULLIF(t.seat_count, 0), CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.seat_count END, 0) AS total_seats, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.interior_image_path END AS interior_image_path, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.exterior_image_path END AS exterior_image_path, COALESCE(b.is_virtual, 0) AS is_virtual,
                    COALESCE(subroute.origin_arrival_time, CASE WHEN COALESCE(ors.arrival_offset_minutes, 0) = 0 THEN TIME(DATE_SUB(t.departure_at, INTERVAL 30 MINUTE)) ELSE TIME(DATE_ADD(t.departure_at, INTERVAL ors.arrival_offset_minutes MINUTE)) END) AS attendance_time,
                    rs.id AS segment_id, oc.name_ar AS origin_name, dc.name_ar AS destination_name, os.name_ar AS origin_station_name, os.address AS origin_station_address, os.latitude AS origin_station_latitude, os.longitude AS origin_station_longitude, ds.name_ar AS destination_station_name, ds.address AS destination_station_address, ds.latitude AS destination_station_latitude, ds.longitude AS destination_station_longitude,
                    tsp.amount, cu.code AS currency_code, cu.symbol_ar AS currency_symbol,
                    (SELECT pcl.previous_amount FROM trip_price_change_logs pcl WHERE pcl.trip_id = t.id AND pcl.route_segment_id = rs.id AND pcl.currency_id = tsp.currency_id ORDER BY pcl.created_at DESC, pcl.id DESC LIMIT 1) AS price_previous_amount,
                    (SELECT pcl.change_type FROM trip_price_change_logs pcl WHERE pcl.trip_id = t.id AND pcl.route_segment_id = rs.id AND pcl.currency_id = tsp.currency_id ORDER BY pcl.created_at DESC, pcl.id DESC LIMIT 1) AS price_change_type,
                    GREATEST((CASE WHEN EXISTS (SELECT 1 FROM trip_seat_inventory inventory_base WHERE inventory_base.trip_id = t.id) THEN LEAST(COALESCE(NULLIF(t.seat_count, 0), b.seat_count, 0), (SELECT COUNT(*) FROM trip_seat_inventory inventory_count WHERE inventory_count.trip_id = t.id AND inventory_count.is_available = 1)) ELSE COALESCE(NULLIF(t.seat_count, 0), b.seat_count, 0) END) - (SELECT COUNT(*) FROM booking_seats booked_seat INNER JOIN bookings booked ON booked.id = booked_seat.booking_id WHERE booked.trip_id = t.id AND booked.status IN ('pending', 'confirmed')), 0) AS available_seats,
                    (SELECT ROUND(AVG(rv.rating), 1) FROM trip_reviews rv WHERE rv.trip_id = t.id AND rv.status = 'published') AS rating,
                    (SELECT COUNT(*) FROM trip_reviews rv WHERE rv.trip_id = t.id AND rv.status = 'published') AS rating_count,
                    (SELECT ROUND(AVG(rv.recommendation) * 100) FROM trip_reviews rv WHERE rv.trip_id = t.id AND rv.status = 'published') AS recommendation_percent,
                    (SELECT ROUND(AVG(rv.rating), 1) FROM trip_reviews rv INNER JOIN bookings rb ON rb.id = rv.booking_id WHERE rb.company_id = co.id AND rv.status = 'published') AS company_rating,
                    (SELECT COUNT(*) FROM trip_reviews rv INNER JOIN bookings rb ON rb.id = rv.booking_id WHERE rb.company_id = co.id AND rv.status = 'published') AS company_rating_count
             FROM trips t
             INNER JOIN routes r ON r.id = t.route_id
             INNER JOIN companies co ON co.id = t.company_id AND co.status = 'active'
             LEFT JOIN buses b ON b.id = t.bus_id AND b.status = 'active'
             INNER JOIN route_segments rs ON rs.route_id = t.route_id AND rs.is_active = 1
             INNER JOIN route_subroute_links subroute_link ON subroute_link.route_segment_id = rs.id AND subroute_link.subroute_id = t.route_subroute_id
             LEFT JOIN route_subroutes subroute ON subroute.id = subroute_link.subroute_id AND subroute.status = 'active'
             INNER JOIN route_stops ors ON ors.id = rs.origin_stop_id
             INNER JOIN route_stops drs ON drs.id = rs.destination_stop_id
             INNER JOIN stations os ON os.id = ors.station_id
             INNER JOIN cities oc ON oc.id = os.city_id
             INNER JOIN stations ds ON ds.id = drs.station_id
             INNER JOIN cities dc ON dc.id = ds.city_id
             INNER JOIN trip_segment_prices tsp ON tsp.trip_id = t.id AND tsp.route_segment_id = rs.id
             INNER JOIN currencies cu ON cu.id = tsp.currency_id
             WHERE t.status = 'open' AND t.departure_at > CURRENT_TIMESTAMP AND DATE(t.departure_at) = :travel_date AND os.city_id = :origin_city_id AND ds.city_id = :destination_city_id
               AND (:bus_type_filter = '' OR COALESCE(NULLIF(t.bus_type, ''), NULLIF(CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.bus_type END, ''), 'normal') = :bus_type_value)
             ORDER BY t.departure_at ASC, tsp.amount ASC"
        );
        $statement->execute(['travel_date' => $date, 'origin_city_id' => $originCityId, 'destination_city_id' => $destinationCityId, 'bus_type_filter' => $busType, 'bus_type_value' => $busType]);
        $items = $statement->fetchAll();
        foreach ($items as &$item) { $item['gallery_images'] = $this->companyImages((int) $item['company_id']); if (!empty($item['origin_station_address'])) { $item['origin_station_name'] .= ' — ' . $item['origin_station_address']; } if (!empty($item['destination_station_address'])) { $item['destination_station_name'] .= ' — ' . $item['destination_station_address']; } }
        unset($item);
        return $items;
    }

    /** @return list<array<string, mixed>> */
    public function upcomingTrips(int $limit = 20): array
    {
        $this->bookingService->expirePendingBookings();
        $limit = max(1, min(50, $limit));
        $items = $this->all(
            "SELECT t.id, t.trip_number, t.bus_id, t.trip_type, t.seat_count, t.departure_at, t.arrival_at, t.status, r.route_type, r.journey_type, co.id AS company_id, co.trade_name AS company_name, co.logo_path, co.cover_image_path,
                    CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE COALESCE(b.name_ar, 'الباص غير مربوط') END AS bus_name, COALESCE(NULLIF(t.bus_type, ''), NULLIF(CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.bus_type END, ''), 'normal') AS bus_type, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.model_year END AS model_year, COALESCE(NULLIF(t.seat_count, 0), CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.seat_count END, 0) AS total_seats, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.interior_image_path END AS interior_image_path, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.exterior_image_path END AS exterior_image_path, COALESCE(b.is_virtual, 0) AS is_virtual,
                    COALESCE(subroute.origin_arrival_time, CASE WHEN COALESCE(ors.arrival_offset_minutes, 0) = 0 THEN TIME(DATE_SUB(t.departure_at, INTERVAL 30 MINUTE)) ELSE TIME(DATE_ADD(t.departure_at, INTERVAL ors.arrival_offset_minutes MINUTE)) END) AS attendance_time,
                    rs.id AS segment_id, oc.name_ar AS origin_name, dc.name_ar AS destination_name, os.name_ar AS origin_station_name, os.address AS origin_station_address, os.latitude AS origin_station_latitude, os.longitude AS origin_station_longitude, ds.name_ar AS destination_station_name, ds.address AS destination_station_address, ds.latitude AS destination_station_latitude, ds.longitude AS destination_station_longitude,
                    tsp.amount, cu.code AS currency_code, cu.symbol_ar AS currency_symbol,
                    (SELECT pcl.previous_amount FROM trip_price_change_logs pcl WHERE pcl.trip_id = t.id AND pcl.route_segment_id = rs.id AND pcl.currency_id = tsp.currency_id ORDER BY pcl.created_at DESC, pcl.id DESC LIMIT 1) AS price_previous_amount,
                    (SELECT pcl.change_type FROM trip_price_change_logs pcl WHERE pcl.trip_id = t.id AND pcl.route_segment_id = rs.id AND pcl.currency_id = tsp.currency_id ORDER BY pcl.created_at DESC, pcl.id DESC LIMIT 1) AS price_change_type,
                    GREATEST((CASE WHEN EXISTS (SELECT 1 FROM trip_seat_inventory inventory_base WHERE inventory_base.trip_id = t.id) THEN LEAST(COALESCE(NULLIF(t.seat_count, 0), b.seat_count, 0), (SELECT COUNT(*) FROM trip_seat_inventory inventory_count WHERE inventory_count.trip_id = t.id AND inventory_count.is_available = 1)) ELSE COALESCE(NULLIF(t.seat_count, 0), b.seat_count, 0) END) - (SELECT COUNT(*) FROM booking_seats booked_seat INNER JOIN bookings booked ON booked.id = booked_seat.booking_id WHERE booked.trip_id = t.id AND booked.status IN ('pending', 'confirmed')), 0) AS available_seats,
                    (SELECT ROUND(AVG(rv.rating), 1) FROM trip_reviews rv WHERE rv.trip_id = t.id AND rv.status = 'published') AS rating,
                    (SELECT COUNT(*) FROM trip_reviews rv WHERE rv.trip_id = t.id AND rv.status = 'published') AS rating_count,
                    (SELECT ROUND(AVG(rv.recommendation) * 100) FROM trip_reviews rv WHERE rv.trip_id = t.id AND rv.status = 'published') AS recommendation_percent,
                    (SELECT ROUND(AVG(rv.rating), 1) FROM trip_reviews rv INNER JOIN bookings rb ON rb.id = rv.booking_id WHERE rb.company_id = co.id AND rv.status = 'published') AS company_rating,
                    (SELECT COUNT(*) FROM trip_reviews rv INNER JOIN bookings rb ON rb.id = rv.booking_id WHERE rb.company_id = co.id AND rv.status = 'published') AS company_rating_count
             FROM trips t
             INNER JOIN routes r ON r.id = t.route_id
             INNER JOIN companies co ON co.id = t.company_id AND co.status = 'active'
             LEFT JOIN buses b ON b.id = t.bus_id AND b.status = 'active'
             INNER JOIN route_segments rs ON rs.route_id = t.route_id AND rs.is_active = 1
             INNER JOIN route_subroute_links subroute_link ON subroute_link.route_segment_id = rs.id AND subroute_link.subroute_id = t.route_subroute_id
             LEFT JOIN route_subroutes subroute ON subroute.id = subroute_link.subroute_id AND subroute.status = 'active'
             INNER JOIN route_stops ors ON ors.id = rs.origin_stop_id
             INNER JOIN route_stops drs ON drs.id = rs.destination_stop_id
             INNER JOIN stations os ON os.id = ors.station_id
             INNER JOIN cities oc ON oc.id = os.city_id
             INNER JOIN stations ds ON ds.id = drs.station_id
             INNER JOIN cities dc ON dc.id = ds.city_id
             INNER JOIN trip_segment_prices tsp ON tsp.trip_id = t.id AND tsp.route_segment_id = rs.id
             INNER JOIN currencies cu ON cu.id = tsp.currency_id
             WHERE t.status = 'open' AND t.departure_at > CURRENT_TIMESTAMP
             ORDER BY t.departure_at ASC, tsp.amount ASC LIMIT {$limit}"
        );
        foreach ($items as &$item) { $item['gallery_images'] = $this->companyImages((int) $item['company_id']); if (!empty($item['origin_station_address'])) { $item['origin_station_name'] .= ' — ' . $item['origin_station_address']; } if (!empty($item['destination_station_address'])) { $item['destination_station_name'] .= ' — ' . $item['destination_station_address']; } }
        unset($item);
        return $items;
    }

    /** @return array<string, mixed> */
    public function trip(int $tripId): array
    {
        $this->bookingService->expirePendingBookings();
        $statement = $this->database->pdo()->prepare(
            'SELECT t.*, co.trade_name AS company_name, co.phone AS company_phone, co.latitude AS company_latitude, co.longitude AS company_longitude, co.logo_path, co.cover_image_path, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.name_ar END AS bus_name, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.bus_number END AS bus_number, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.plate_number END AS plate_number, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.interior_image_path END AS interior_image_path, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.exterior_image_path END AS exterior_image_path, COALESCE(b.is_virtual, 0) AS is_virtual, r.name_ar AS route_name
             FROM trips t INNER JOIN companies co ON co.id = t.company_id LEFT JOIN buses b ON b.id = t.bus_id INNER JOIN routes r ON r.id = t.route_id
             WHERE t.id = :id AND t.status = \'open\' AND t.departure_at > CURRENT_TIMESTAMP LIMIT 1'
        );
        $statement->execute(['id' => $tripId]);
        $trip = $statement->fetch();
        if (!is_array($trip)) {
            Response::error('الرحلة المطلوبة غير موجودة.', 'NOT_FOUND', 404);
        }
        $trip['stops'] = $this->all(
            'SELECT rs.id, rs.stop_order, s.name_ar AS station_name, s.address AS station_address, s.latitude, s.longitude, c.name_ar AS city_name, rs.arrival_offset_minutes, rs.departure_offset_minutes
             FROM route_stops rs INNER JOIN stations s ON s.id = rs.station_id INNER JOIN cities c ON c.id = s.city_id WHERE rs.route_id = :route_id ORDER BY rs.stop_order',
            ['route_id' => $trip['route_id']]
        );
        $trip['gallery_images'] = $this->companyImages((int) $trip['company_id']);
        $trip['segments'] = $this->all(
            'SELECT seg.id, seg.origin_order, seg.destination_order, ost.name_ar AS origin_station, ost.address AS origin_station_address, ost.latitude AS origin_station_latitude, ost.longitude AS origin_station_longitude, dst.name_ar AS destination_station, dst.address AS destination_station_address, dst.latitude AS destination_station_latitude, dst.longitude AS destination_station_longitude, tsp.amount, cu.code AS currency_code, cu.symbol_ar AS currency_symbol
             FROM route_segments seg INNER JOIN route_stops o ON o.id = seg.origin_stop_id INNER JOIN route_stops d ON d.id = seg.destination_stop_id
             INNER JOIN stations ost ON ost.id = o.station_id INNER JOIN stations dst ON dst.id = d.station_id
             INNER JOIN trip_segment_prices tsp ON tsp.trip_id = :trip_id AND tsp.route_segment_id = seg.id INNER JOIN currencies cu ON cu.id = tsp.currency_id
             WHERE seg.route_id = :route_id ORDER BY seg.origin_order, seg.destination_order',
            ['trip_id' => $tripId, 'route_id' => $trip['route_id']]
        );
        return $trip;
    }

    /** @return list<array<string, mixed>> */
    public function seats(int $tripId, int $segmentId): array
    {
        $this->bookingService->expirePendingBookings();
        return $this->bookingService->seatsForSegment($tripId, $segmentId);
    }

    /** @return list<array<string, mixed>> */
    private function companyImages(int $companyId): array
    {
        return $this->all('SELECT id, company_id, image_path, image_order FROM company_images WHERE company_id = :company_id AND status = \'active\' ORDER BY image_order', ['company_id' => $companyId]);
    }

    /** @return list<array<string, mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
