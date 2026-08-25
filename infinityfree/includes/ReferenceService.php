<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class ReferenceService
{
    public function __construct(private Database $database, private BookingService $bookingService)
    {
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
        return $this->all('SELECT id, city_id, name_ar, address FROM stations WHERE city_id = :city_id AND is_active = 1 ORDER BY name_ar', ['city_id' => $cityId]);
    }

    /** @return list<array<string, mixed>> */
    public function companies(): array
    {
        $items = $this->all(
            'SELECT co.id, co.trade_name, co.legal_name, co.phone, co.email, co.latitude, co.longitude, co.logo_path, co.cover_image_path, cu.code AS currency_code, cu.symbol_ar AS currency_symbol,
                    GROUP_CONCAT(DISTINCT NULLIF(TRIM(b.bus_type), \'\') ORDER BY b.bus_type SEPARATOR \'|\') AS bus_types
             FROM companies co INNER JOIN currencies cu ON cu.id = co.base_currency_id
             LEFT JOIN buses b ON b.company_id = co.id AND b.status = \'active\'
             WHERE co.status = \'active\'
             GROUP BY co.id, co.trade_name, co.legal_name, co.phone, co.email, co.latitude, co.longitude, co.logo_path, co.cover_image_path, cu.code, cu.symbol_ar
             ORDER BY co.trade_name'
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
            'SELECT t.id, t.trip_number, t.departure_at, t.arrival_at, t.status, co.id AS company_id, co.trade_name AS company_name, co.latitude AS company_latitude, co.longitude AS company_longitude, co.logo_path, co.cover_image_path,
                    b.name_ar AS bus_name, b.bus_type, b.model_year, b.seat_count AS total_seats, b.interior_image_path, b.exterior_image_path,
                    subroute.origin_arrival_time AS attendance_time,
                    rs.id AS segment_id, os.name_ar AS origin_name, ds.name_ar AS destination_name,
                    tsp.amount, cu.code AS currency_code, cu.symbol_ar AS currency_symbol,
                    (SELECT COUNT(*) FROM trip_seat_inventory ti WHERE ti.trip_id = t.id AND ti.is_available = 1) AS available_seats
             FROM trips t
             INNER JOIN companies co ON co.id = t.company_id AND co.status = \'active\'
             INNER JOIN buses b ON b.id = t.bus_id
             INNER JOIN route_segments rs ON rs.route_id = t.route_id AND rs.is_active = 1
             LEFT JOIN route_subroute_links subroute_link ON subroute_link.route_segment_id = rs.id
             LEFT JOIN route_subroutes subroute ON subroute.id = subroute_link.subroute_id AND subroute.status = \'active\'
             INNER JOIN route_stops ors ON ors.id = rs.origin_stop_id
             INNER JOIN route_stops drs ON drs.id = rs.destination_stop_id
             INNER JOIN stations os ON os.id = ors.station_id
             INNER JOIN stations ds ON ds.id = drs.station_id
             INNER JOIN trip_segment_prices tsp ON tsp.trip_id = t.id AND tsp.route_segment_id = rs.id
             INNER JOIN currencies cu ON cu.id = tsp.currency_id
             WHERE t.status = \'open\' AND DATE(t.departure_at) = :travel_date AND os.city_id = :origin_city_id AND ds.city_id = :destination_city_id
               AND (:bus_type_filter = \'\' OR b.bus_type = :bus_type_value)
             ORDER BY t.departure_at ASC, tsp.amount ASC'
        );
        $statement->execute(['travel_date' => $date, 'origin_city_id' => $originCityId, 'destination_city_id' => $destinationCityId, 'bus_type_filter' => $busType, 'bus_type_value' => $busType]);
        $items = $statement->fetchAll();
        foreach ($items as &$item) { $item['gallery_images'] = $this->companyImages((int) $item['company_id']); }
        unset($item);
        return $items;
    }

    /** @return array<string, mixed> */
    public function trip(int $tripId): array
    {
        $this->bookingService->expirePendingBookings();
        $statement = $this->database->pdo()->prepare(
            'SELECT t.*, co.trade_name AS company_name, co.phone AS company_phone, co.latitude AS company_latitude, co.longitude AS company_longitude, co.logo_path, co.cover_image_path, b.name_ar AS bus_name, b.bus_number, b.plate_number, b.interior_image_path, b.exterior_image_path, r.name_ar AS route_name
             FROM trips t INNER JOIN companies co ON co.id = t.company_id INNER JOIN buses b ON b.id = t.bus_id INNER JOIN routes r ON r.id = t.route_id
             WHERE t.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $tripId]);
        $trip = $statement->fetch();
        if (!is_array($trip)) {
            Response::error('الرحلة المطلوبة غير موجودة.', 'NOT_FOUND', 404);
        }
        $trip['stops'] = $this->all(
            'SELECT rs.id, rs.stop_order, s.name_ar AS station_name, c.name_ar AS city_name, rs.arrival_offset_minutes, rs.departure_offset_minutes
             FROM route_stops rs INNER JOIN stations s ON s.id = rs.station_id INNER JOIN cities c ON c.id = s.city_id WHERE rs.route_id = :route_id ORDER BY rs.stop_order',
            ['route_id' => $trip['route_id']]
        );
        $trip['gallery_images'] = $this->companyImages((int) $trip['company_id']);
        $trip['segments'] = $this->all(
            'SELECT seg.id, seg.origin_order, seg.destination_order, ost.name_ar AS origin_station, dst.name_ar AS destination_station, tsp.amount, cu.code AS currency_code, cu.symbol_ar AS currency_symbol
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
