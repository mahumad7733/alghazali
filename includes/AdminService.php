<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class AdminService
{
    private AuditLogger $audit;
    private NotificationService $notifications;

    public function __construct(private Database $database)
    {
        $this->ensureLocationColumns();
        $this->ensureMainRouteColumns();
        $this->ensureStationOwnershipColumns();
        $this->ensureOperationalFinanceColumns();
        $this->ensureBrokerTripColumns();
        $this->ensureCurrencyDefaultColumn();
        $this->ensureCurrencyDefaultValue();
        $this->ensureAgentCodeColumn();
        $this->ensureTripPriceChangeLogTable();
        $this->ensureAdminRbacFoundation();
        $this->audit = new AuditLogger($database);
        $this->notifications = new NotificationService($database);
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function operations(array $actor): array
    {
        $companyId = $this->companyScope($actor, null);
        $params = $companyId === null ? [] : ['company_id' => $companyId];
        $companyWhere = $companyId === null ? '1=1' : 'co.id = :company_id';
        $entityWhere = $companyId === null ? '1=1' : 'entity.company_id = :company_id';
        $routeWhere = $companyId === null ? '1=1' : 'r.company_id = :company_id';
        $stationWhere = $companyId === null ? '1=1' : '(s.company_id IS NULL OR s.company_id = :company_id OR a.company_id = :company_id)';
        $subrouteWhere = $companyId === null ? '1=1' : '(sr.company_id IS NULL OR sr.company_id = :company_id)';
        $canViewInternalPrices = $this->canManageFinancialAmounts($actor);
        $subrouteCompanyAmount = $canViewInternalPrices ? 'sr.company_amount' : 'NULL AS company_amount';
        $tripCompanyAmount = $canViewInternalPrices ? 'COALESCE((SELECT tsp_fin.company_amount FROM trip_segment_prices tsp_fin INNER JOIN route_subroute_links price_link_fin ON price_link_fin.route_segment_id = tsp_fin.route_segment_id WHERE tsp_fin.trip_id = entity.id AND price_link_fin.route_id = entity.route_id AND price_link_fin.subroute_id = entity.route_subroute_id ORDER BY price_link_fin.stop_order LIMIT 1), selected_subroute.company_amount)' : 'NULL';
        return [
            'cities' => $this->all('SELECT ci.id, ci.country_id, ci.name_ar, ci.is_active, co.name_ar AS country_name FROM cities ci INNER JOIN countries co ON co.id = ci.country_id ORDER BY co.name_ar, ci.name_ar', []),
            'companies' => $this->all("SELECT co.id, co.trade_name, co.legal_name, co.country_id, co.city_id, co.address, co.base_currency_id, co.phone, co.email, co.latitude, co.longitude, co.logo_path, co.cover_image_path, co.status, ci.name_ar AS city_name, cu.code AS currency_code FROM companies co LEFT JOIN cities ci ON ci.id = co.city_id INNER JOIN currencies cu ON cu.id = co.base_currency_id WHERE {$companyWhere} ORDER BY co.trade_name", $params),
            'company_images' => $this->all("SELECT image.id, image.company_id, image.image_path, image.image_order, image.status, co.trade_name AS company_name FROM company_images image INNER JOIN companies co ON co.id = image.company_id WHERE {$companyWhere} ORDER BY image.company_id, image.image_order", $params),
            'routes' => $this->all("SELECT entity.id, entity.company_id, entity.code, entity.name_ar, entity.route_type, entity.journey_type, entity.status, entity.created_at, co.trade_name AS company_name, (SELECT COUNT(*) FROM route_subroute_links link WHERE link.route_id = entity.id) AS subroute_count FROM routes entity INNER JOIN companies co ON co.id = entity.company_id WHERE {$entityWhere} ORDER BY entity.created_at DESC", $params),
            'subroutes' => $this->all("SELECT sr.id, sr.company_id, sr.origin_city_id, sr.destination_city_id, sr.currency_id, {$subrouteCompanyAmount}, sr.amount, sr.status, sr.origin_arrival_time, sr.origin_departure_time, sr.destination_arrival_time, sr.destination_departure_time, COALESCE(co.trade_name, 'مشترك') AS company_name, origin_city.name_ar AS origin_city_name, destination_city.name_ar AS destination_city_name, cu.code AS currency_code, cu.symbol_ar AS currency_symbol, (SELECT COUNT(*) FROM route_subroute_links linked WHERE linked.subroute_id = sr.id) AS linked_route_count FROM route_subroutes sr LEFT JOIN companies co ON co.id = sr.company_id INNER JOIN cities origin_city ON origin_city.id = sr.origin_city_id INNER JOIN cities destination_city ON destination_city.id = sr.destination_city_id INNER JOIN currencies cu ON cu.id = sr.currency_id WHERE {$subrouteWhere} ORDER BY COALESCE(co.trade_name, 'مشترك'), origin_city.name_ar, destination_city.name_ar", $params),
            'route_subroute_links' => $this->all("SELECT link.route_id, link.subroute_id, link.stop_order, r.code AS route_code, r.name_ar AS route_name, origin_city.name_ar AS origin_city_name, destination_city.name_ar AS destination_city_name, sr.amount, sr.origin_arrival_time, sr.origin_departure_time, sr.destination_arrival_time, sr.destination_departure_time, cu.code AS currency_code FROM route_subroute_links link INNER JOIN routes r ON r.id = link.route_id INNER JOIN route_subroutes sr ON sr.id = link.subroute_id INNER JOIN cities origin_city ON origin_city.id = sr.origin_city_id INNER JOIN cities destination_city ON destination_city.id = sr.destination_city_id INNER JOIN currencies cu ON cu.id = sr.currency_id WHERE {$routeWhere} ORDER BY r.name_ar, link.stop_order", $params),
            'route_stops' => $this->all("SELECT rs.id, rs.route_id, rs.station_id, r.name_ar AS route_name, s.name_ar AS station_name, c.id AS city_id, c.name_ar AS city_name, rs.stop_order, rs.arrival_offset_minutes, rs.departure_offset_minutes FROM route_stops rs INNER JOIN routes r ON r.id = rs.route_id INNER JOIN stations s ON s.id = rs.station_id INNER JOIN cities c ON c.id = s.city_id WHERE {$routeWhere} ORDER BY r.name_ar, rs.stop_order", $params),
            'stations' => $this->all("SELECT s.id, s.city_id, s.name_ar, s.address, s.latitude, s.longitude, s.station_type, s.company_id, s.agent_id, c.name_ar AS city_name, co.trade_name AS company_name, au.full_name AS agent_name FROM stations s INNER JOIN cities c ON c.id = s.city_id LEFT JOIN companies co ON co.id = s.company_id LEFT JOIN agents a ON a.id = s.agent_id LEFT JOIN users au ON au.id = a.user_id WHERE s.is_active = 1 AND {$stationWhere} ORDER BY c.name_ar, s.name_ar", $params),
            'agents' => $this->all($companyId === null ? "SELECT a.id, a.company_id, u.full_name AS full_name, co.trade_name AS company_name FROM agents a INNER JOIN users u ON u.id = a.user_id INNER JOIN companies co ON co.id = a.company_id WHERE a.status = 'active' ORDER BY u.full_name" : "SELECT a.id, a.company_id, u.full_name AS full_name, co.trade_name AS company_name FROM agents a INNER JOIN users u ON u.id = a.user_id INNER JOIN companies co ON co.id = a.company_id WHERE a.status = 'active' AND a.company_id = :company_id ORDER BY u.full_name", $params),
            'route_segments' => $this->all("SELECT seg.id, seg.route_id, r.name_ar AS route_name, origin_station.name_ar AS origin_station, destination_station.name_ar AS destination_station, seg.origin_order, seg.destination_order FROM route_segments seg INNER JOIN routes r ON r.id = seg.route_id INNER JOIN route_stops origin_stop ON origin_stop.id = seg.origin_stop_id INNER JOIN route_stops destination_stop ON destination_stop.id = seg.destination_stop_id INNER JOIN stations origin_station ON origin_station.id = origin_stop.station_id INNER JOIN stations destination_station ON destination_station.id = destination_stop.station_id WHERE {$routeWhere} ORDER BY r.name_ar, seg.origin_order, seg.destination_order", $params),
            'buses' => $this->all("SELECT entity.id, entity.company_id, entity.name_ar, entity.bus_number, entity.plate_number, entity.bus_type, entity.interior_image_path, entity.exterior_image_path, entity.seat_count, entity.status, co.trade_name AS company_name FROM buses entity INNER JOIN companies co ON co.id = entity.company_id WHERE {$entityWhere} AND COALESCE(entity.is_virtual, 0) = 0 ORDER BY entity.created_at DESC", $params),
            'trips' => $this->all("SELECT entity.id, entity.company_id, entity.route_id, entity.route_subroute_id, entity.bus_id, entity.trip_number, entity.trip_type, entity.agent_commission_type, entity.agent_commission_value, entity.seat_count, COALESCE(NULLIF(entity.seat_count, 0), b.seat_count, 0) AS total_seats, (SELECT COUNT(*) FROM booking_seats booked_seat INNER JOIN bookings booked ON booked.id = booked_seat.booking_id WHERE booked.trip_id = entity.id AND booked.status NOT IN ('cancelled', 'rejected', 'expired')) AS booked_seats, CASE WHEN EXISTS (SELECT 1 FROM trip_seat_inventory inv WHERE inv.trip_id = entity.id) THEN GREATEST(LEAST(COALESCE(NULLIF(entity.seat_count, 0), b.seat_count, 0), (SELECT COUNT(*) FROM trip_seat_inventory inv2 WHERE inv2.trip_id = entity.id AND inv2.is_available = 1)) - (SELECT COUNT(*) FROM booking_seats booked_seat2 INNER JOIN bookings booked2 ON booked2.id = booked_seat2.booking_id WHERE booked2.trip_id = entity.id AND booked2.status NOT IN ('cancelled', 'rejected', 'expired')), 0) ELSE GREATEST(COALESCE(NULLIF(entity.seat_count, 0), b.seat_count, 0) - (SELECT COUNT(*) FROM booking_seats booked_seat3 INNER JOIN bookings booked3 ON booked3.id = booked_seat3.booking_id WHERE booked3.trip_id = entity.id AND booked3.status NOT IN ('cancelled', 'rejected', 'expired')), 0) END AS available_seats, entity.departure_at, entity.arrival_at, selected_subroute.origin_arrival_time AS attendance_time, selected_subroute.origin_departure_time AS route_departure_time, selected_subroute.destination_arrival_time AS route_arrival_time, entity.status, r.name_ar AS route_name, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN 'مقاعد وسيط' ELSE COALESCE(b.name_ar, 'غير مربوط') END AS bus_name, CASE WHEN COALESCE(NULLIF(entity.bus_type, ''), '') <> '' THEN entity.bus_type ELSE CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.bus_type END END AS bus_type, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.interior_image_path END AS interior_image_path, CASE WHEN COALESCE(b.is_virtual, 0) = 1 THEN NULL ELSE b.exterior_image_path END AS exterior_image_path, co.trade_name AS company_name, co.logo_path, co.cover_image_path, origin_city.name_ar AS subroute_origin_city_name, destination_city.name_ar AS subroute_destination_city_name, COALESCE((SELECT tsp.amount FROM trip_segment_prices tsp INNER JOIN route_subroute_links price_link ON price_link.route_segment_id = tsp.route_segment_id WHERE tsp.trip_id = entity.id AND price_link.route_id = entity.route_id AND price_link.subroute_id = entity.route_subroute_id ORDER BY price_link.stop_order LIMIT 1), selected_subroute.amount) AS sale_amount, $tripCompanyAmount AS company_amount, COALESCE((SELECT price_currency.code FROM trip_segment_prices tsp2 INNER JOIN currencies price_currency ON price_currency.id = tsp2.currency_id INNER JOIN route_subroute_links price_link2 ON price_link2.route_segment_id = tsp2.route_segment_id WHERE tsp2.trip_id = entity.id AND price_link2.route_id = entity.route_id AND price_link2.subroute_id = entity.route_subroute_id ORDER BY price_link2.stop_order LIMIT 1), trip_currency.code) AS currency_code, COALESCE((SELECT price_currency.symbol_ar FROM trip_segment_prices tsp3 INNER JOIN currencies price_currency ON price_currency.id = tsp3.currency_id INNER JOIN route_subroute_links price_link3 ON price_link3.route_segment_id = tsp3.route_segment_id WHERE tsp3.trip_id = entity.id AND price_link3.route_id = entity.route_id AND price_link3.subroute_id = entity.route_subroute_id ORDER BY price_link3.stop_order LIMIT 1), trip_currency.symbol_ar) AS currency_symbol FROM trips entity INNER JOIN routes r ON r.id = entity.route_id LEFT JOIN buses b ON b.id = entity.bus_id INNER JOIN companies co ON co.id = entity.company_id LEFT JOIN route_subroutes selected_subroute ON selected_subroute.id = entity.route_subroute_id LEFT JOIN currencies trip_currency ON trip_currency.id = selected_subroute.currency_id LEFT JOIN cities origin_city ON origin_city.id = selected_subroute.origin_city_id LEFT JOIN cities destination_city ON destination_city.id = selected_subroute.destination_city_id WHERE {$entityWhere} ORDER BY entity.departure_at DESC", $params),
        ];
    }

    /** @return array<string, mixed> */
    public function legacyCreateRoute(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $companyId = $this->companyScope($actor, $input['company_id'] ?? null);
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', (string) ($input['code'] ?? '')));
        $nameInput = trim((string) ($input['name_ar'] ?? ''));
        $name = $nameInput === '' ? '' : Security::cleanText($nameInput, 220);
        $subrouteIds = is_array($input['subroute_ids'] ?? null) ? $input['subroute_ids'] : [];
        $subrouteIds = array_values(array_unique(array_filter(array_map(static fn (mixed $id): int => (int) $id, $subrouteIds), static fn (int $id): bool => $id > 0)));
        $originStationIds = is_array($input['origin_station_ids'] ?? null) ? $input['origin_station_ids'] : [];
        if (mb_strlen($code) < 3) {
            Response::error('رمز المسار مطلوب.', 'VALIDATION_ERROR', 422);
        }
        if ($subrouteIds === []) {
            if (mb_strlen($name) < 3) { Response::error('اسم المسار العربي مطلوب.', 'VALIDATION_ERROR', 422); }
            $statement = $this->database->pdo()->prepare('INSERT INTO routes (company_id, code, name_ar, status, created_by) VALUES (:company_id, :code, :name_ar, \'inactive\', :created_by)');
            $statement->execute(['company_id' => $companyId, 'code' => $code, 'name_ar' => $name, 'created_by' => $actor['id']]);
            $id = (int) $this->database->pdo()->lastInsertId();
            $this->audit->log((int) $actor['id'], $companyId, 'route_created', 'route', $id, null, ['code' => $code, 'name_ar' => $name]);
            return ['id' => $id, 'company_id' => $companyId, 'code' => $code, 'name_ar' => $name, 'status' => 'inactive'];
        }
        return $this->database->transaction(function (PDO $pdo) use ($actor, $companyId, $code, $name, $subrouteIds, $originStationIds): array {
            $items = [];
            foreach ($subrouteIds as $id) {
                $item = $this->one($pdo, 'SELECT sr.id, sr.company_id, sr.origin_city_id, sr.destination_city_id, sr.currency_id, sr.amount, sr.status, sr.origin_arrival_time, sr.origin_departure_time, sr.destination_arrival_time, sr.destination_departure_time, origin_city.name_ar AS origin_city_name, destination_city.name_ar AS destination_city_name FROM route_subroutes sr INNER JOIN cities origin_city ON origin_city.id = sr.origin_city_id INNER JOIN cities destination_city ON destination_city.id = sr.destination_city_id WHERE sr.id = :id FOR UPDATE', ['id' => $id]);
                if ($item === null || (((int) ($item['company_id'] ?? 0)) !== 0 && (int) $item['company_id'] !== (int) $companyId) || $item['status'] !== 'active') { Response::error('أحد المسارات الفرعية غير متاح لهذه الشركة.', 'VALIDATION_ERROR', 422); }
                $items[] = $item;
            }
            $branchDestinationIds = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['destination_city_id'], $items)));
            $branchOrigins = array_map(static fn (array $item): int => (int) $item['origin_city_id'], $items);
            $isCommonDestinationBranch = count($items) > 1
                && count($branchDestinationIds) === 1
                && count(array_unique($branchOrigins)) === count($branchOrigins);
            $ordered = $isCommonDestinationBranch ? $items : $this->orderSubroutes($items);
            $currencyIds = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['currency_id'], $ordered)));
            if (count($currencyIds) !== 1) { Response::error('يجب أن تستخدم المسارات الفرعية المختارة العملة نفسها لبناء سعر المقاطع المجمعة.', 'VALIDATION_ERROR', 422); }
            $routeCurrencyId = $currencyIds[0];
            $routeName = mb_strlen($name) >= 3 ? $name : $ordered[0]['origin_city_name'] . ' ← ' . implode(' ← ', array_column($ordered, 'destination_city_name'));
            $routeInsert = $pdo->prepare('INSERT INTO routes (company_id, code, name_ar, status, created_by) VALUES (:company_id, :code, :name_ar, \'active\', :created_by)');
            $routeInsert->execute(['company_id' => $companyId, 'code' => $code, 'name_ar' => $routeName, 'created_by' => $actor['id']]);
            $routeId = (int) $pdo->lastInsertId();
            if ($isCommonDestinationBranch) {
                return $this->createCommonDestinationBranchRoute($pdo, $actor, $companyId, $routeId, $code, $routeName, $ordered, $routeCurrencyId, $originStationIds);
            }
            $cityIds = [(int) $ordered[0]['origin_city_id']];
            foreach ($ordered as $item) { $cityIds[] = (int) $item['destination_city_id']; }
            $routeStops = [];
            $baseDepartureMinutes = $this->timeToMinutes($ordered[0]['origin_departure_time'] ?? null);
            $stationInsert = $pdo->prepare('INSERT INTO stations (city_id, name_ar, is_active) VALUES (:city_id, :name_ar, 1)');
            $stopInsert = $pdo->prepare('INSERT INTO route_stops (route_id, station_id, stop_order, arrival_offset_minutes, departure_offset_minutes) VALUES (:route_id, :station_id, :stop_order, :arrival_offset_minutes, :departure_offset_minutes)');
            foreach ($cityIds as $index => $cityId) {
                $requestedStationId = filter_var($originStationIds[(string) $cityId] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $station = $requestedStationId !== false
                    ? $this->one($pdo, 'SELECT s.id FROM stations s LEFT JOIN agents a ON a.id = s.agent_id WHERE s.id = :station_id AND s.city_id = :city_id AND s.is_active = 1 AND (s.company_id IS NULL OR s.company_id = :company_id OR a.company_id = :company_id)', ['station_id' => $requestedStationId, 'city_id' => $cityId, 'company_id' => $companyId])
                    : $this->one($pdo, 'SELECT s.id FROM stations s WHERE s.city_id = :city_id AND s.is_active = 1 ORDER BY s.id LIMIT 1', ['city_id' => $cityId]);
                if ($requestedStationId !== false && $station === null) { Response::error('محطة الانطلاق المختارة لا تتبع المدينة المحددة أو غير نشطة.', 'VALIDATION_ERROR', 422); }
                if ($station === null) {
                    $city = $this->one($pdo, 'SELECT name_ar FROM cities WHERE id = :id', ['id' => $cityId]);
                    $stationInsert->execute(['city_id' => $cityId, 'name_ar' => 'محطة ' . (string) $city['name_ar']]);
                    $station = ['id' => (int) $pdo->lastInsertId()];
                }
                $arrivalTime = $index === 0 ? ($ordered[0]['origin_arrival_time'] ?? null) : ($ordered[$index - 1]['destination_arrival_time'] ?? null);
                $departureTime = $index === count($cityIds) - 1 ? ($ordered[$index - 1]['destination_departure_time'] ?? null) : ($ordered[$index]['origin_departure_time'] ?? null);
                $stopInsert->execute(['route_id' => $routeId, 'station_id' => $station['id'], 'stop_order' => $index + 1, 'arrival_offset_minutes' => $index === 0 ? 0 : $this->timeOffset($arrivalTime, $baseDepartureMinutes), 'departure_offset_minutes' => $index === 0 ? 0 : $this->timeOffset($departureTime, $baseDepartureMinutes)]);
                $routeStops[] = (int) $pdo->lastInsertId();
            }
            $segmentInsert = $pdo->prepare('INSERT INTO route_segments (route_id, origin_stop_id, destination_stop_id, origin_order, destination_order) VALUES (:route_id, :origin_stop_id, :destination_stop_id, :origin_order, :destination_order)');
            $segmentIds = [];
            foreach ($routeStops as $originIndex => $originStopId) {
                foreach ($routeStops as $destinationIndex => $destinationStopId) {
                    if ($destinationIndex <= $originIndex) { continue; }
                    $segmentInsert->execute(['route_id' => $routeId, 'origin_stop_id' => $originStopId, 'destination_stop_id' => $destinationStopId, 'origin_order' => $originIndex + 1, 'destination_order' => $destinationIndex + 1]);
                    $segmentIds[$originIndex . ':' . $destinationIndex] = (int) $pdo->lastInsertId();
                }
            }
            $linkInsert = $pdo->prepare('INSERT INTO route_subroute_links (route_id, subroute_id, route_segment_id, stop_order) VALUES (:route_id, :subroute_id, :route_segment_id, :stop_order)');
            $priceInsert = $pdo->prepare('INSERT INTO segment_prices (company_id, route_segment_id, currency_id, amount, starts_at, status, created_by) VALUES (:company_id, :route_segment_id, :currency_id, :amount, NOW(), \'active\', :created_by)');
            foreach ($ordered as $index => $item) {
                $segmentId = $segmentIds[$index . ':' . ($index + 1)];
                $linkInsert->execute(['route_id' => $routeId, 'subroute_id' => $item['id'], 'route_segment_id' => $segmentId, 'stop_order' => $index + 1]);
            }
            foreach ($routeStops as $originIndex => $originStopId) {
                $combinedAmount = 0.0;
                for ($destinationIndex = $originIndex + 1; $destinationIndex < count($routeStops); $destinationIndex++) {
                    $combinedAmount += (float) $ordered[$destinationIndex - 1]['amount'];
                    $priceInsert->execute(['company_id' => $companyId, 'route_segment_id' => $segmentIds[$originIndex . ':' . $destinationIndex], 'currency_id' => $routeCurrencyId, 'amount' => $combinedAmount, 'created_by' => $actor['id']]);
                }
            }
            $this->audit->log((int) $actor['id'], $companyId, 'route_composed_from_subroutes', 'route', $routeId, null, ['code' => $code, 'subroute_ids' => $subrouteIds, 'currency_id' => $routeCurrencyId]);
            return ['id' => $routeId, 'company_id' => $companyId, 'code' => $code, 'name_ar' => $routeName, 'status' => 'active'];
        });
    }

    /** @return array<string, mixed> */
    public function createRoute(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $name = Security::cleanText($input['name_ar'] ?? null, 220);
        $subrouteIds = $this->mainRouteSubrouteIds($input);
        $routeType = $this->mainRouteType($input['route_type'] ?? 'normal');
        $journeyType = $this->mainJourneyType($input['journey_type'] ?? (count($subrouteIds) > 1 ? 'indirect' : 'direct'));
        $this->validateJourneyType($journeyType, count($subrouteIds));
        $status = $this->mainRouteStatus($input['status'] ?? 'active');
        if (mb_strlen($name) < 3) { Response::error('اسم المسار الرئيسي مطلوب.', 'VALIDATION_ERROR', 422); }
        if ($subrouteIds === []) { Response::error('اختر مسارًا فرعيًا واحدًا على الأقل من المسارات الموجودة.', 'VALIDATION_ERROR', 422); }
        $pdo = $this->database->pdo();
        $lock = (int) $pdo->query("SELECT GET_LOCK('bus_main_route_code_sequence', 10)")->fetchColumn();
        if ($lock !== 1) { Response::error('تعذر حجز مولد رمز المسار، حاول مرة أخرى.', 'TEMPORARY_ERROR', 503); }
        try {
            $code = $this->nextMainRouteCode($pdo);
            $created = $this->legacyCreateRoute($actor, [
                'company_id' => $input['company_id'] ?? null,
                'code' => $code,
                'name_ar' => $name,
                'subroute_ids' => $subrouteIds,
                'origin_station_ids' => $input['origin_station_ids'] ?? [],
            ]);
            $pdo->prepare('UPDATE routes SET route_type = :route_type, journey_type = :journey_type, status = :status WHERE id = :id')->execute(['route_type' => $routeType, 'journey_type' => $journeyType, 'status' => $status, 'id' => $created['id']]);
            $created['route_type'] = $routeType;
            $created['journey_type'] = $journeyType;
            $created['status'] = $status;
            $this->audit->log((int) $actor['id'], (int) $created['company_id'], 'main_route_created', 'route', (int) $created['id'], null, ['code' => $code, 'route_type' => $routeType, 'status' => $status, 'subroute_ids' => $subrouteIds]);
            return $created;
        } finally {
            $pdo->query("SELECT RELEASE_LOCK('bus_main_route_code_sequence')");
        }
    }

    /** @return array<string, mixed> */
    public function updateRoute(array $actor, int $routeId, array $input): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $pdo = $this->database->pdo();
        $route = $this->one($pdo, 'SELECT id, company_id, code, name_ar, route_type, journey_type, status FROM routes WHERE id = :id', ['id' => $routeId]);
        if ($route === null) { Response::error('المسار الرئيسي غير موجود.', 'NOT_FOUND', 404); }
        $this->companyScope($actor, $route['company_id']);
        $companyId = $this->companyScope($actor, $input['company_id'] ?? $route['company_id']);
        $name = Security::cleanText($input['name_ar'] ?? null, 220);
        $subrouteIds = $this->mainRouteSubrouteIds($input);
        $routeType = $this->mainRouteType($input['route_type'] ?? 'normal');
        $journeyType = $this->mainJourneyType($input['journey_type'] ?? ($route['journey_type'] ?? (count($subrouteIds) > 1 ? 'indirect' : 'direct')));
        $this->validateJourneyType($journeyType, count($subrouteIds));
        $status = $this->mainRouteStatus($input['status'] ?? 'active');
        if (mb_strlen($name) < 3) { Response::error('اسم المسار الرئيسي مطلوب.', 'VALIDATION_ERROR', 422); }
        if ($subrouteIds === []) { Response::error('اختر مسارًا فرعيًا واحدًا على الأقل من المسارات الموجودة.', 'VALIDATION_ERROR', 422); }
        $currentIds = array_map(static fn (array $item): int => (int) $item['subroute_id'], $this->allPdo($pdo, 'SELECT subroute_id FROM route_subroute_links WHERE route_id = :route_id ORDER BY stop_order', ['route_id' => $routeId]));
        sort($currentIds); $requestedIds = $subrouteIds; sort($requestedIds);
        $requiresRebuild = $currentIds !== $requestedIds || (int) $companyId !== (int) $route['company_id'];
        $tripCount = (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM trips WHERE route_id = :id', ['id' => $routeId])['total'];
        if ($requiresRebuild && $tripCount > 0) { Response::error('لا يمكن تغيير الشركة أو المسارات الفرعية لمسار مرتبط برحلات. أوقفه وأنشئ مسارًا جديدًا عند الحاجة.', 'DEPENDENCY_EXISTS', 409); }
        if (!$requiresRebuild) {
            $pdo->prepare('UPDATE routes SET name_ar = :name_ar, route_type = :route_type, journey_type = :journey_type, status = :status WHERE id = :id')->execute(['name_ar' => $name, 'route_type' => $routeType, 'journey_type' => $journeyType, 'status' => $status, 'id' => $routeId]);
            $this->audit->log((int) $actor['id'], (int) $route['company_id'], 'main_route_updated', 'route', $routeId, $route, ['name_ar' => $name, 'route_type' => $routeType, 'status' => $status]);
            return ['id' => $routeId, 'company_id' => (int) $route['company_id'], 'code' => $route['code'], 'name_ar' => $name, 'route_type' => $routeType, 'journey_type' => $journeyType, 'status' => $status];
        }
        $pdo->prepare('DELETE FROM routes WHERE id = :id')->execute(['id' => $routeId]);
        $rebuilt = $this->legacyCreateRoute($actor, ['company_id' => $companyId, 'code' => $route['code'], 'name_ar' => $name, 'subroute_ids' => $subrouteIds, 'origin_station_ids' => $input['origin_station_ids'] ?? []]);
        $pdo->prepare('UPDATE routes SET route_type = :route_type, journey_type = :journey_type, status = :status WHERE id = :id')->execute(['route_type' => $routeType, 'journey_type' => $journeyType, 'status' => $status, 'id' => $rebuilt['id']]);
        $rebuilt['route_type'] = $routeType;
        $rebuilt['journey_type'] = $journeyType;
        $rebuilt['status'] = $status;
        $this->audit->log((int) $actor['id'], (int) $companyId, 'main_route_rebuilt', 'route', (int) $rebuilt['id'], $route, ['preserved_code' => $route['code'], 'subroute_ids' => $subrouteIds]);
        return $rebuilt;
    }

    /** @return array<string, mixed> */
    public function createCountry(array $actor, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) { Response::error('إدارة الدول متاحة للمدير الرئيسي فقط.', 'FORBIDDEN', 403); }
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = Security::cleanText($input['name_ar'] ?? null, 120);
        $phoneCode = Security::cleanText($input['phone_code'] ?? null, 10);
        $status = ($input['status'] ?? 'active') === 'inactive' ? 0 : 1;
        if (!preg_match('/^[A-Z]{2}$/', $code) || mb_strlen($name) < 2) { Response::error('أدخل رمز دولة من حرفين واسم الدولة.', 'VALIDATION_ERROR', 422); }
        $pdo = $this->database->pdo();
        if ($this->one($pdo, 'SELECT id FROM countries WHERE code = :code OR name_ar = :name LIMIT 1', ['code' => $code, 'name' => $name]) !== null) { Response::error('رمز الدولة أو اسمها مسجل مسبقًا.', 'DUPLICATE_COUNTRY', 409); }
        $statement = $pdo->prepare('INSERT INTO countries (code, name_ar, phone_code, is_active) VALUES (:code, :name_ar, :phone_code, :is_active)');
        $statement->execute(['code' => $code, 'name_ar' => $name, 'phone_code' => $phoneCode ?: null, 'is_active' => $status]);
        $id = (int) $pdo->lastInsertId();
        $this->audit->log((int) $actor['id'], null, 'country_created', 'country', $id, null, ['code' => $code, 'status' => $status ? 'active' : 'inactive']);
        return ['id' => $id, 'code' => $code, 'name_ar' => $name, 'phone_code' => $phoneCode, 'status' => $status ? 'active' : 'inactive'];
    }

    /** @return array<string, mixed> */
    public function updateCountryStatus(array $actor, int $countryId, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) { Response::error('إدارة الدول متاحة للمدير الرئيسي فقط.', 'FORBIDDEN', 403); }
        $status = ($input['status'] ?? '') === 'active' ? 1 : (($input['status'] ?? '') === 'inactive' ? 0 : -1);
        if ($status < 0) { Response::error('حالة الدولة غير صالحة.', 'VALIDATION_ERROR', 422); }
        $statement = $this->database->pdo()->prepare('UPDATE countries SET is_active = :is_active WHERE id = :id');
        $statement->execute(['is_active' => $status, 'id' => $countryId]);
        if ($statement->rowCount() === 0 && $this->one($this->database->pdo(), 'SELECT id FROM countries WHERE id = :id', ['id' => $countryId]) === null) { Response::error('الدولة غير موجودة.', 'NOT_FOUND', 404); }
        $this->audit->log((int) $actor['id'], null, 'country_status_updated', 'country', $countryId, null, ['status' => $status ? 'active' : 'inactive']);
        return ['id' => $countryId, 'status' => $status ? 'active' : 'inactive'];
    }

    /** @return array<string, mixed> */
    public function createCity(array $actor, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_countries', $actor['permissions'] ?? [], true)) { Response::error('لا تملك صلاحية إدارة المدن.', 'FORBIDDEN', 403); }
        $countryId = filter_var($input['country_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $name = Security::cleanText($input['name_ar'] ?? null, 140);
        $status = ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        if ($countryId === false || mb_strlen($name) < 2 || $this->one($this->database->pdo(), 'SELECT id FROM countries WHERE id = :id AND is_active = 1', ['id' => $countryId]) === null) { Response::error('بيانات المدينة أو الدولة غير صالحة.', 'VALIDATION_ERROR', 422); }
        if ($this->one($this->database->pdo(), 'SELECT id FROM cities WHERE country_id = :country_id AND name_ar = :name_ar LIMIT 1', ['country_id' => $countryId, 'name_ar' => $name]) !== null) { Response::error('هذه المدينة موجودة مسبقًا في الدولة المختارة.', 'DUPLICATE_CITY', 409); }
        $statement = $this->database->pdo()->prepare('INSERT INTO cities (country_id, name_ar, is_active) VALUES (:country_id, :name_ar, :is_active)');
        $statement->execute(['country_id' => $countryId, 'name_ar' => $name, 'is_active' => $status === 'active' ? 1 : 0]);
        $id = (int) $this->database->pdo()->lastInsertId();
        $this->audit->log((int) $actor['id'], null, 'city_created', 'city', $id, null, ['status' => $status]);
        return ['id' => $id, 'country_id' => $countryId, 'name_ar' => $name, 'status' => $status];
    }

    /** @return array<string, mixed> */
    public function updateCityStatus(array $actor, int $cityId, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_countries', $actor['permissions'] ?? [], true)) { Response::error('لا تملك صلاحية إدارة المدن.', 'FORBIDDEN', 403); }
        $status = ($input['status'] ?? '') === 'active' ? 'active' : (($input['status'] ?? '') === 'inactive' ? 'inactive' : '');
        if ($status === '') { Response::error('حالة المدينة غير صالحة.', 'VALIDATION_ERROR', 422); }
        $statement = $this->database->pdo()->prepare('UPDATE cities SET is_active = :is_active WHERE id = :id');
        $statement->execute(['is_active' => $status === 'active' ? 1 : 0, 'id' => $cityId]);
        if ($statement->rowCount() === 0 && $this->one($this->database->pdo(), 'SELECT id FROM cities WHERE id = :id', ['id' => $cityId]) === null) { Response::error('المدينة غير موجودة.', 'NOT_FOUND', 404); }
        $this->audit->log((int) $actor['id'], null, 'city_status_updated', 'city', $cityId, null, ['status' => $status]);
        return ['id' => $cityId, 'status' => $status];
    }

    /** @return array<string, mixed> */
    public function updateCity(array $actor, int $cityId, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_countries', $actor['permissions'] ?? [], true)) { Response::error('لا تملك صلاحية إدارة المدن.', 'FORBIDDEN', 403); }
        $name = Security::cleanText($input['name_ar'] ?? null, 140);
        if (mb_strlen($name) < 2) { Response::error('اسم المدينة غير صالح.', 'VALIDATION_ERROR', 422); }
        $statement = $this->database->pdo()->prepare('UPDATE cities SET name_ar = :name_ar WHERE id = :id');
        $statement->execute(['name_ar' => $name, 'id' => $cityId]);
        if ($statement->rowCount() === 0 && $this->one($this->database->pdo(), 'SELECT id FROM cities WHERE id = :id', ['id' => $cityId]) === null) { Response::error('المدينة غير موجودة.', 'NOT_FOUND', 404); }
        $this->audit->log((int) $actor['id'], null, 'city_updated', 'city', $cityId, null, ['name_ar' => $name]);
        return ['id' => $cityId, 'name_ar' => $name];
    }

    /** @return array<string, mixed> */
    public function deleteCity(array $actor, int $cityId): array
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_countries', $actor['permissions'] ?? [], true)) { Response::error('لا تملك صلاحية إدارة المدن.', 'FORBIDDEN', 403); }
        $pdo = $this->database->pdo();
        $city = $this->one($pdo, 'SELECT id, name_ar FROM cities WHERE id = :id', ['id' => $cityId]);
        if ($city === null) { Response::error('المدينة غير موجودة.', 'NOT_FOUND', 404); }
        $counts = [
            (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM companies WHERE city_id = :id', ['id' => $cityId])['total'],
            (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM stations WHERE city_id = :id', ['id' => $cityId])['total'],
            (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM route_subroutes WHERE origin_city_id = :id OR destination_city_id = :id', ['id' => $cityId])['total'],
            (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM customers WHERE city_id = :id', ['id' => $cityId])['total'],
        ];
        if (array_sum($counts) > 0) { Response::error('لا يمكن حذف مدينة مستخدمة في شركة أو محطة أو مسار أو بيانات عميل. أوقفها بدلًا من الحذف.', 'DEPENDENCY_EXISTS', 409); }
        $this->audit->log((int) $actor['id'], null, 'city_deleted', 'city', $cityId, $city, []);
        $pdo->prepare('DELETE FROM cities WHERE id = :id')->execute(['id' => $cityId]);
        return ['id' => $cityId, 'deleted' => true];
    }

    /** @return array<string, mixed> */
    public function createSubroute(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $companyId = in_array('super_admin', $actor['roles'], true) ? null : $this->companyScope($actor, null);
        $originId = filter_var($input['origin_city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $destinationId = filter_var($input['destination_city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $currencyId = filter_var($input['currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.01]]);
        $companyAmount = $this->canManageFinancialAmounts($actor) ? filter_var($input['company_amount'] ?? 0, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]) : 0.0;
        $status = ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $times = $this->subrouteTimes($input);
        if ($originId === false || $destinationId === false || $originId === $destinationId || $currencyId === false || $amount === false || $companyAmount === false) { Response::error('اختر مدينتين مختلفتين مع العملة وسعري الشركة والبيع.', 'VALIDATION_ERROR', 422); }
        $pdo = $this->database->pdo();
        $cities = $this->allPdo($pdo, 'SELECT id, name_ar FROM cities WHERE id IN (:origin_id, :destination_id) AND is_active = 1', ['origin_id' => $originId, 'destination_id' => $destinationId]);
        if (count($cities) !== 2 || $this->one($pdo, 'SELECT id FROM currencies WHERE id = :id AND is_active = 1', ['id' => $currencyId]) === null) { Response::error('المدينة أو العملة المختارة غير نشطة.', 'VALIDATION_ERROR', 422); }
        $statement = $pdo->prepare('INSERT INTO route_subroutes (company_id, origin_city_id, destination_city_id, currency_id, company_amount, amount, origin_arrival_time, origin_departure_time, destination_arrival_time, destination_departure_time, status, created_by) VALUES (:company_id, :origin_city_id, :destination_city_id, :currency_id, :company_amount, :amount, :origin_arrival_time, :origin_departure_time, :destination_arrival_time, :destination_departure_time, :status, :created_by)');
        $statement->execute(['company_id' => $companyId, 'origin_city_id' => $originId, 'destination_city_id' => $destinationId, 'currency_id' => $currencyId, 'company_amount' => $companyAmount, 'amount' => $amount, 'origin_arrival_time' => $times['origin_arrival_time'], 'origin_departure_time' => $times['origin_departure_time'], 'destination_arrival_time' => $times['destination_arrival_time'], 'destination_departure_time' => $times['destination_departure_time'], 'status' => $status, 'created_by' => $actor['id']]);
        $id = (int) $pdo->lastInsertId();
        $this->audit->log((int) $actor['id'], $companyId, 'subroute_created', 'route_subroute', $id, null, ['origin_city_id' => $originId, 'destination_city_id' => $destinationId, 'company_amount' => $companyAmount, 'amount' => $amount, 'currency_id' => $currencyId, 'times' => $times]);
        return ['id' => $id, 'company_id' => $companyId, 'origin_city_id' => $originId, 'destination_city_id' => $destinationId, 'currency_id' => $currencyId, 'company_amount' => $companyAmount, 'amount' => $amount, 'status' => $status] + $times;
    }

    /** @return array<string, mixed> */
    public function updateCompany(array $actor, int $companyId, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) { Response::error('تعديل الشركة متاح للمدير الرئيسي فقط.', 'FORBIDDEN', 403); }
        $company = $this->one($this->database->pdo(), 'SELECT id FROM companies WHERE id = :id', ['id' => $companyId]);
        if ($company === null) { Response::error('الشركة غير موجودة.', 'NOT_FOUND', 404); }
        $legal = Security::cleanText($input['legal_name'] ?? null, 220);
        $trade = Security::cleanText($input['trade_name'] ?? null, 180);
        $countryId = filter_var($input['country_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cityId = filter_var($input['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $currencyId = filter_var($input['base_currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (mb_strlen($legal) < 3 || mb_strlen($trade) < 3 || $countryId === false || $cityId === false || $currencyId === false) { Response::error('بيانات الشركة الأساسية غير صالحة.', 'VALIDATION_ERROR', 422); }
        $phone = trim((string) ($input['phone'] ?? '')) !== '' ? Security::cleanText($input['phone'], 40) : null;
        $address = trim((string) ($input['address'] ?? '')) !== '' ? Security::cleanText($input['address'], 500) : null;
        $email = trim((string) ($input['email'] ?? '')) !== '' ? filter_var($input['email'], FILTER_VALIDATE_EMAIL) : null;
        if (trim((string) ($input['email'] ?? '')) !== '' && $email === false) { Response::error('البريد الإلكتروني غير صالح.', 'VALIDATION_ERROR', 422); }
        $coordinates = $this->coordinates($input);
        $statement = $this->database->pdo()->prepare('UPDATE companies SET legal_name = :legal_name, trade_name = :trade_name, country_id = :country_id, city_id = :city_id, address = :address, base_currency_id = :base_currency_id, phone = :phone, email = :email, latitude = :latitude, longitude = :longitude WHERE id = :id');
        $statement->execute(['legal_name' => $legal, 'trade_name' => $trade, 'country_id' => $countryId, 'city_id' => $cityId, 'base_currency_id' => $currencyId, 'address' => $address, 'phone' => $phone, 'email' => $email ?: null, 'latitude' => $coordinates['latitude'], 'longitude' => $coordinates['longitude'], 'id' => $companyId]);
        $this->audit->log((int) $actor['id'], $companyId, 'company_updated', 'company', $companyId, null, ['trade_name' => $trade]);
        return ['id' => $companyId, 'legal_name' => $legal, 'trade_name' => $trade];
    }

    /** @return array<string, mixed> */
    public function updateCompanyStatus(array $actor, int $companyId, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) { Response::error('تعديل حالة الشركة متاح للمدير الرئيسي فقط.', 'FORBIDDEN', 403); }
        $status = in_array($input['status'] ?? null, ['active', 'suspended'], true) ? (string) $input['status'] : '';
        if ($status === '') { Response::error('حالة الشركة غير صالحة.', 'VALIDATION_ERROR', 422); }
        $statement = $this->database->pdo()->prepare('UPDATE companies SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $companyId]);
        if ($statement->rowCount() === 0 && $this->one($this->database->pdo(), 'SELECT id FROM companies WHERE id = :id', ['id' => $companyId]) === null) { Response::error('الشركة غير موجودة.', 'NOT_FOUND', 404); }
        $this->audit->log((int) $actor['id'], $companyId, 'company_status_updated', 'company', $companyId, null, ['status' => $status]);
        return ['id' => $companyId, 'status' => $status];
    }

    /** @return array<string, mixed> */
    public function deleteCompany(array $actor, int $companyId): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) { Response::error('حذف الشركة متاح للمدير الرئيسي فقط.', 'FORBIDDEN', 403); }
        $pdo = $this->database->pdo();
        $company = $this->one($pdo, 'SELECT id, trade_name FROM companies WHERE id = :id', ['id' => $companyId]);
        if ($company === null) { Response::error('الشركة غير موجودة.', 'NOT_FOUND', 404); }
        $counts = [
            'routes' => (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM routes WHERE company_id = :id', ['id' => $companyId])['total'],
            'buses' => (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM buses WHERE company_id = :id', ['id' => $companyId])['total'],
            'trips' => (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM trips WHERE company_id = :id', ['id' => $companyId])['total'],
            'bookings' => (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM bookings WHERE company_id = :id', ['id' => $companyId])['total'],
            'company_users' => (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM company_users WHERE company_id = :id', ['id' => $companyId])['total'],
        ];
        if (array_sum($counts) > 0) { Response::error('لا يمكن حذف الشركة لوجود بيانات تشغيلية أو حجوزات مرتبطة بها. أوقفها بدلًا من الحذف.', 'DEPENDENCY_EXISTS', 409); }
        try {
            $pdo->beginTransaction();
            $this->audit->log((int) $actor['id'], $companyId, 'company_deleted', 'company', $companyId, $company, []);
            $pdo->prepare('DELETE FROM companies WHERE id = :id')->execute(['id' => $companyId]);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $error;
        }
        return ['id' => $companyId, 'deleted' => true];
    }

    /** @return array<string, mixed> */
    public function updateSubroute(array $actor, int $subrouteId, array $input): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $pdo = $this->database->pdo();
        $existing = $this->one($pdo, 'SELECT id, company_id FROM route_subroutes WHERE id = :id', ['id' => $subrouteId]);
        if ($existing === null) { Response::error('المسار الفرعي غير موجود.', 'NOT_FOUND', 404); }
        if ($existing['company_id'] === null && !in_array('super_admin', $actor['roles'], true)) { Response::error('تعديل المقطع المشترك متاح للمدير الرئيسي فقط.', 'FORBIDDEN', 403); }
        if ($existing['company_id'] !== null) { $this->companyScope($actor, $existing['company_id']); }
        $originId = filter_var($input['origin_city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $destinationId = filter_var($input['destination_city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $currencyId = filter_var($input['currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.01]]);
        $status = in_array($input['status'] ?? null, ['active', 'inactive'], true) ? (string) $input['status'] : 'active';
        $companyAmount = $this->canManageFinancialAmounts($actor)
            ? filter_var($input['company_amount'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]])
            : (float) (($this->one($pdo, 'SELECT company_amount FROM route_subroutes WHERE id = :id', ['id' => $subrouteId])['company_amount'] ?? 0));
        if ($originId === false || $destinationId === false || $originId === $destinationId || $currencyId === false || $amount === false || $companyAmount === false) { Response::error('اختر مدينتين مختلفتين مع العملة وسعري الشركة والبيع.', 'VALIDATION_ERROR', 422); }
        $times = $this->subrouteTimes($input);
        $statement = $pdo->prepare('UPDATE route_subroutes SET origin_city_id = :origin_city_id, destination_city_id = :destination_city_id, currency_id = :currency_id, company_amount = :company_amount, amount = :amount, origin_arrival_time = :origin_arrival_time, origin_departure_time = :origin_departure_time, destination_arrival_time = :destination_arrival_time, destination_departure_time = :destination_departure_time, status = :status WHERE id = :id');
        $statement->execute(['origin_city_id' => $originId, 'destination_city_id' => $destinationId, 'currency_id' => $currencyId, 'company_amount' => $companyAmount, 'amount' => $amount, 'origin_arrival_time' => $times['origin_arrival_time'], 'origin_departure_time' => $times['origin_departure_time'], 'destination_arrival_time' => $times['destination_arrival_time'], 'destination_departure_time' => $times['destination_departure_time'], 'status' => $status, 'id' => $subrouteId]);
        $this->audit->log((int) $actor['id'], $existing['company_id'] === null ? null : (int) $existing['company_id'], 'subroute_updated', 'route_subroute', $subrouteId, null, ['company_amount' => $companyAmount, 'amount' => $amount, 'status' => $status, 'times' => $times]);
        return ['id' => $subrouteId, 'company_amount' => $companyAmount, 'amount' => $amount, 'status' => $status] + $times;
    }

    /** @return array<string, mixed> */
    public function updateSubrouteStatus(array $actor, int $subrouteId, array $input): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $status = in_array($input['status'] ?? null, ['active', 'inactive'], true) ? (string) $input['status'] : '';
        if ($status === '') { Response::error('حالة المسار الفرعي غير صالحة.', 'VALIDATION_ERROR', 422); }
        $existing = $this->one($this->database->pdo(), 'SELECT company_id FROM route_subroutes WHERE id = :id', ['id' => $subrouteId]);
        if ($existing === null) { Response::error('المسار الفرعي غير موجود.', 'NOT_FOUND', 404); }
        if ($existing['company_id'] === null && !in_array('super_admin', $actor['roles'], true)) { Response::error('تعديل المقطع المشترك متاح للمدير الرئيسي فقط.', 'FORBIDDEN', 403); }
        if ($existing['company_id'] !== null) { $this->companyScope($actor, $existing['company_id']); }
        $this->database->pdo()->prepare('UPDATE route_subroutes SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $subrouteId]);
        $this->audit->log((int) $actor['id'], $existing['company_id'] === null ? null : (int) $existing['company_id'], 'subroute_status_updated', 'route_subroute', $subrouteId, null, ['status' => $status]);
        return ['id' => $subrouteId, 'status' => $status];
    }

    /** @return array<string, mixed> */
    public function deleteSubroute(array $actor, int $subrouteId): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $pdo = $this->database->pdo();
        $existing = $this->one($pdo, 'SELECT id, company_id FROM route_subroutes WHERE id = :id', ['id' => $subrouteId]);
        if ($existing === null) { Response::error('المسار الفرعي غير موجود.', 'NOT_FOUND', 404); }
        if ($existing['company_id'] === null && !in_array('super_admin', $actor['roles'], true)) { Response::error('حذف المقطع المشترك متاح للمدير الرئيسي فقط.', 'FORBIDDEN', 403); }
        if ($existing['company_id'] !== null) { $this->companyScope($actor, $existing['company_id']); }
        if ((int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM route_subroute_links WHERE subroute_id = :id', ['id' => $subrouteId])['total'] > 0) { Response::error('لا يمكن حذف مقطع مرتبط بمسار رئيسي. أوقفه بدلًا من الحذف.', 'DEPENDENCY_EXISTS', 409); }
        $pdo->prepare('DELETE FROM route_subroutes WHERE id = :id')->execute(['id' => $subrouteId]);
        $this->audit->log((int) $actor['id'], $existing['company_id'] === null ? null : (int) $existing['company_id'], 'subroute_deleted', 'route_subroute', $subrouteId, $existing, []);
        return ['id' => $subrouteId, 'deleted' => true];
    }

    /** @return array<string, mixed> */
    public function legacyUpdateRoute(array $actor, int $routeId, array $input): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $pdo = $this->database->pdo();
        $route = $this->one($pdo, 'SELECT id, company_id FROM routes WHERE id = :id', ['id' => $routeId]);
        if ($route === null) { Response::error('المسار الرئيسي غير موجود.', 'NOT_FOUND', 404); }
        $companyId = $this->companyScope($actor, $route['company_id']);
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9-]/', '', (string) ($input['code'] ?? '')));
        $name = Security::cleanText($input['name_ar'] ?? null, 220);
        if (mb_strlen($code) < 3 || mb_strlen($name) < 3) { Response::error('رمز واسم المسار مطلوبان.', 'VALIDATION_ERROR', 422); }
        $pdo->prepare('UPDATE routes SET code = :code, name_ar = :name_ar WHERE id = :id')->execute(['code' => $code, 'name_ar' => $name, 'id' => $routeId]);
        $this->audit->log((int) $actor['id'], (int) $companyId, 'route_updated', 'route', $routeId, null, ['code' => $code, 'name_ar' => $name]);
        return ['id' => $routeId, 'code' => $code, 'name_ar' => $name];
    }

    /** @return array<string, mixed> */
    public function updateRouteStatus(array $actor, int $routeId, array $input): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $status = in_array($input['status'] ?? null, ['active', 'inactive'], true) ? (string) $input['status'] : '';
        if ($status === '') { Response::error('حالة المسار الرئيسي غير صالحة.', 'VALIDATION_ERROR', 422); }
        $route = $this->one($this->database->pdo(), 'SELECT company_id FROM routes WHERE id = :id', ['id' => $routeId]);
        if ($route === null) { Response::error('المسار الرئيسي غير موجود.', 'NOT_FOUND', 404); }
        $companyId = $this->companyScope($actor, $route['company_id']);
        $this->database->pdo()->prepare('UPDATE routes SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $routeId]);
        $this->audit->log((int) $actor['id'], (int) $companyId, 'route_status_updated', 'route', $routeId, null, ['status' => $status]);
        return ['id' => $routeId, 'status' => $status];
    }

    /** @return array<string, mixed> */
    public function deleteRoute(array $actor, int $routeId): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $pdo = $this->database->pdo();
        $route = $this->one($pdo, 'SELECT id, company_id, code FROM routes WHERE id = :id', ['id' => $routeId]);
        if ($route === null) { Response::error('المسار الرئيسي غير موجود.', 'NOT_FOUND', 404); }
        $companyId = $this->companyScope($actor, $route['company_id']);
        if ((int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM trips WHERE route_id = :id', ['id' => $routeId])['total'] > 0) { Response::error('لا يمكن حذف مسار مرتبط برحلة. أوقفه بدلًا من الحذف.', 'DEPENDENCY_EXISTS', 409); }
        $pdo->prepare('DELETE FROM routes WHERE id = :id')->execute(['id' => $routeId]);
        $this->audit->log((int) $actor['id'], (int) $companyId, 'route_deleted', 'route', $routeId, $route, []);
        return ['id' => $routeId, 'deleted' => true];
    }

    /** @return array<string, mixed> */
    public function createBus(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_buses');
        $companyId = $this->companyScope($actor, $input['company_id'] ?? null);
        $name = Security::cleanText($input['name_ar'] ?? null, 160);
        $busNumber = strtoupper(Security::cleanText($input['bus_number'] ?? null, 64));
        $plateNumber = strtoupper(Security::cleanText($input['plate_number'] ?? null, 64));
        $seatCount = filter_var($input['seat_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $type = Security::cleanText($input['bus_type'] ?? 'standard', 80);
        if (mb_strlen($name) < 2 || mb_strlen($busNumber) < 2 || $seatCount === false) {
            Response::error('بيانات الباص وعدد المقاعد غير صالحة.', 'VALIDATION_ERROR', 422);
        }
        $pdo = $this->database->pdo();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('INSERT INTO buses (company_id, name_ar, bus_number, plate_number, bus_type, seat_count, status) VALUES (:company_id, :name_ar, :bus_number, :plate_number, :bus_type, :seat_count, \'active\')');
            $statement->execute(['company_id' => $companyId, 'name_ar' => $name, 'bus_number' => $busNumber, 'plate_number' => $plateNumber, 'bus_type' => $type, 'seat_count' => $seatCount]);
            $id = (int) $pdo->lastInsertId();
            $seatStatement = $pdo->prepare('INSERT INTO bus_seats (bus_id, seat_code, seat_row, column_code) VALUES (:bus_id, :seat_code, :seat_row, :column_code)');
            $columns = ['A', 'B', 'C', 'D'];
            for ($i = 1; $i <= $seatCount; $i++) {
                $row = (int) ceil($i / 4); $column = $columns[($i - 1) % 4];
                $seatStatement->execute(['bus_id' => $id, 'seat_code' => $row . $column, 'seat_row' => $row, 'column_code' => $column]);
            }
            $pdo->commit();
            $this->audit->log((int) $actor['id'], $companyId, 'bus_created', 'bus', $id, null, ['bus_number' => $busNumber, 'seat_count' => $seatCount]);
            return ['id' => $id, 'company_id' => $companyId, 'name_ar' => $name, 'bus_number' => $busNumber, 'seat_count' => $seatCount];
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function updateBus(array $actor, int $busId, array $input): array
    {
        $this->assertPermission($actor, 'manage_buses');
        $pdo = $this->database->pdo();
        $bus = $this->one($pdo, 'SELECT id, company_id FROM buses WHERE id = :id', ['id' => $busId]);
        if ($bus === null) { Response::error('الباص غير موجود.', 'NOT_FOUND', 404); }
        $companyId = $this->companyScope($actor, $bus['company_id']);
        $name = Security::cleanText($input['name_ar'] ?? null, 160);
        $busNumber = strtoupper(Security::cleanText($input['bus_number'] ?? null, 64));
        $plate = strtoupper(Security::cleanText($input['plate_number'] ?? null, 64));
        $type = Security::cleanText($input['bus_type'] ?? 'standard', 80);
        if (mb_strlen($name) < 2 || mb_strlen($busNumber) < 2 || mb_strlen($plate) < 2) { Response::error('بيانات الباص غير صالحة.', 'VALIDATION_ERROR', 422); }
        $pdo->prepare('UPDATE buses SET name_ar = :name_ar, bus_number = :bus_number, plate_number = :plate_number, bus_type = :bus_type WHERE id = :id')->execute(['name_ar' => $name, 'bus_number' => $busNumber, 'plate_number' => $plate, 'bus_type' => $type, 'id' => $busId]);
        $this->audit->log((int) $actor['id'], (int) $companyId, 'bus_updated', 'bus', $busId, null, ['bus_number' => $busNumber]);
        return ['id' => $busId, 'name_ar' => $name, 'bus_number' => $busNumber, 'plate_number' => $plate, 'bus_type' => $type];
    }

    /** @return array<string, mixed> */
    public function updateBusStatus(array $actor, int $busId, array $input): array
    {
        $this->assertPermission($actor, 'manage_buses');
        $status = in_array($input['status'] ?? null, ['active', 'maintenance', 'inactive'], true) ? (string) $input['status'] : '';
        if ($status === '') { Response::error('حالة الباص غير صالحة.', 'VALIDATION_ERROR', 422); }
        $bus = $this->one($this->database->pdo(), 'SELECT company_id FROM buses WHERE id = :id', ['id' => $busId]);
        if ($bus === null) { Response::error('الباص غير موجود.', 'NOT_FOUND', 404); }
        $companyId = $this->companyScope($actor, $bus['company_id']);
        $this->database->pdo()->prepare('UPDATE buses SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $busId]);
        $this->audit->log((int) $actor['id'], (int) $companyId, 'bus_status_updated', 'bus', $busId, null, ['status' => $status]);
        return ['id' => $busId, 'status' => $status];
    }

    /** @return array<string, mixed> */
    public function deleteBus(array $actor, int $busId): array
    {
        $this->assertPermission($actor, 'manage_buses');
        $pdo = $this->database->pdo();
        $bus = $this->one($pdo, 'SELECT id, company_id, bus_number FROM buses WHERE id = :id', ['id' => $busId]);
        if ($bus === null) { Response::error('الباص غير موجود.', 'NOT_FOUND', 404); }
        $companyId = $this->companyScope($actor, $bus['company_id']);
        if ((int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM trips WHERE bus_id = :id', ['id' => $busId])['total'] > 0) { Response::error('لا يمكن حذف باص مرتبط برحلة. أوقفه بدلًا من الحذف.', 'DEPENDENCY_EXISTS', 409); }
        $this->audit->log((int) $actor['id'], (int) $companyId, 'bus_deleted', 'bus', $busId, $bus, []);
        $pdo->prepare('DELETE FROM buses WHERE id = :id')->execute(['id' => $busId]);
        return ['id' => $busId, 'deleted' => true];
    }

    /** @return array<string, mixed> */
    public function addRouteStop(array $actor, int $routeId, array $input): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $stationId = filter_var($input['station_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $order = filter_var($input['stop_order'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($stationId === false || $order === false) { Response::error('المحطة وترتيبها مطلوبان.', 'VALIDATION_ERROR', 422); }
        return $this->database->transaction(function (PDO $pdo) use ($actor, $routeId, $stationId, $order, $input): array {
            $route = $this->one($pdo, 'SELECT * FROM routes WHERE id = :id FOR UPDATE', ['id' => $routeId]);
            if ($route === null) { Response::error('المسار غير موجود.', 'NOT_FOUND', 404); }
            $this->companyScope($actor, $route['company_id']);
            if ($route['status'] !== 'inactive') { Response::error('لا يمكن تعديل محطات مسار منشور.', 'ROUTE_LOCKED', 409); }
            $station = $this->one($pdo, 'SELECT id FROM stations WHERE id = :id AND is_active = 1', ['id' => $stationId]);
            if ($station === null) { Response::error('المحطة غير متاحة.', 'NOT_FOUND', 404); }
            $pdo->prepare('INSERT INTO route_stops (route_id, station_id, stop_order, arrival_offset_minutes, departure_offset_minutes) VALUES (:route_id, :station_id, :stop_order, :arrival, :departure)')->execute(['route_id' => $routeId, 'station_id' => $stationId, 'stop_order' => $order, 'arrival' => (int) ($input['arrival_offset_minutes'] ?? 0), 'departure' => (int) ($input['departure_offset_minutes'] ?? 0)]);
            $pdo->prepare('DELETE FROM route_segments WHERE route_id = :route_id')->execute(['route_id' => $routeId]);
            $stops = $this->allPdo($pdo, 'SELECT id, stop_order FROM route_stops WHERE route_id = :route_id ORDER BY stop_order', ['route_id' => $routeId]);
            $segment = $pdo->prepare('INSERT INTO route_segments (route_id, origin_stop_id, destination_stop_id, origin_order, destination_order) VALUES (:route_id, :origin_stop_id, :destination_stop_id, :origin_order, :destination_order)');
            foreach ($stops as $origin) {
                foreach ($stops as $destination) {
                    if ((int) $destination['stop_order'] <= (int) $origin['stop_order']) { continue; }
                    $segment->execute(['route_id' => $routeId, 'origin_stop_id' => $origin['id'], 'destination_stop_id' => $destination['id'], 'origin_order' => $origin['stop_order'], 'destination_order' => $destination['stop_order']]);
                }
            }
            $this->audit->log((int) $actor['id'], (int) $route['company_id'], 'route_stop_added', 'route', $routeId, null, ['station_id' => $stationId, 'stop_order' => $order]);
            return ['route_id' => $routeId, 'station_id' => $stationId, 'stop_order' => $order];
        });
    }

    /** @return array<string, mixed> */
    public function createSegmentPrice(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_routes');
        $segmentId = filter_var($input['route_segment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $currencyId = filter_var($input['currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $amount = filter_var($input['amount'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.01]]);
        if ($segmentId === false || $currencyId === false || $amount === false) { Response::error('المقطع والعملة والسعر مطلوبون.', 'VALIDATION_ERROR', 422); }
        $pdo = $this->database->pdo();
        $segment = $this->one($pdo, 'SELECT rs.id, r.company_id FROM route_segments rs INNER JOIN routes r ON r.id = rs.route_id WHERE rs.id = :id', ['id' => $segmentId]);
        if ($segment === null) { Response::error('مقطع المسار غير موجود.', 'NOT_FOUND', 404); }
        $this->companyScope($actor, $segment['company_id']);
        $statement = $pdo->prepare('INSERT INTO segment_prices (company_id, route_segment_id, currency_id, amount, starts_at, status, created_by) VALUES (:company_id, :route_segment_id, :currency_id, :amount, :starts_at, \'active\', :created_by)');
        $statement->execute(['company_id' => $segment['company_id'], 'route_segment_id' => $segmentId, 'currency_id' => $currencyId, 'amount' => $amount, 'starts_at' => $input['starts_at'] ?? date('Y-m-d H:i:s'), 'created_by' => $actor['id']]);
        $id = (int) $pdo->lastInsertId();
        $this->audit->log((int) $actor['id'], (int) $segment['company_id'], 'segment_price_created', 'segment_price', $id, null, ['amount' => $amount, 'currency_id' => $currencyId]);
        return ['id' => $id, 'route_segment_id' => $segmentId, 'currency_id' => $currencyId, 'amount' => $amount];
    }

    /** Return an internal seat provider for broker-created trips without a physical bus. */
    private function ensureVirtualSeatProvider(PDO $pdo, int $companyId, int $seatCount): int
    {
        $provider = $this->one($pdo, 'SELECT id, seat_count FROM buses WHERE company_id = :company_id AND is_virtual = 1 LIMIT 1 FOR UPDATE', ['company_id' => $companyId]);
        if ($provider === null) {
            $statement = $pdo->prepare('INSERT INTO buses (company_id, name_ar, bus_number, plate_number, bus_type, seat_count, status, is_virtual) VALUES (:company_id, :name_ar, :bus_number, :plate_number, \'broker_seat_pool\', :seat_count, \'active\', 1)');
            $statement->execute(['company_id' => $companyId, 'name_ar' => 'مخزون مقاعد الوسيط', 'bus_number' => 'BROKER-' . $companyId, 'plate_number' => 'BROKER-' . $companyId, 'seat_count' => $seatCount]);
            $providerId = (int) $pdo->lastInsertId();
            $provider = ['id' => $providerId, 'seat_count' => $seatCount];
        } else {
            $providerId = (int) $provider['id'];
            if ((int) $provider['seat_count'] < $seatCount) {
                $pdo->prepare('UPDATE buses SET seat_count = :seat_count WHERE id = :id')->execute(['seat_count' => $seatCount, 'id' => $providerId]);
            }
        }
        $existing = (int) ($this->one($pdo, 'SELECT COUNT(*) AS total FROM bus_seats WHERE bus_id = :bus_id', ['bus_id' => $providerId])['total'] ?? 0);
        if ($existing < $seatCount) {
            $insert = $pdo->prepare('INSERT INTO bus_seats (bus_id, seat_code, seat_row, column_code, seat_type, is_active) VALUES (:bus_id, :seat_code, :seat_row, :column_code, \'regular\', 1)');
            $columns = ['A', 'B', 'C', 'D'];
            for ($number = $existing + 1; $number <= $seatCount; $number++) {
                $insert->execute(['bus_id' => $providerId, 'seat_code' => 'V' . str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'seat_row' => (int) ceil($number / 4), 'column_code' => $columns[($number - 1) % 4]]);
            }
        }
        return $providerId;
    }

    /** @return array<string, mixed> */
    public function createTrip(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_trips');
        $routeId = filter_var($input['route_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $routeSubrouteRaw = $input['route_subroute_id'] ?? null;
        $routeSubrouteId = $routeSubrouteRaw === null || $routeSubrouteRaw === '' ? null : filter_var($routeSubrouteRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $busRaw = $input['bus_id'] ?? null;
        $busId = $busRaw === null || $busRaw === '' ? null : filter_var($busRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $departure = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($input['departure_at'] ?? ''));
        $arrival = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($input['arrival_at'] ?? ''));
        $tripNumber = strtoupper(Security::cleanText($input['trip_number'] ?? null, 64));
        $tripType = in_array($input['trip_type'] ?? 'local', ['local', 'international'], true) ? (string) $input['trip_type'] : 'local';
        $agentCommissionType = in_array($input['agent_commission_type'] ?? null, ['percentage', 'fixed'], true) ? (string) $input['agent_commission_type'] : null;
        $agentCommissionValue = trim((string) ($input['agent_commission_value'] ?? '')) === '' ? null : filter_var($input['agent_commission_value'], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0, 'max_range' => 999999999]]);
        if ($agentCommissionValue === false || ($agentCommissionValue !== null && $agentCommissionType === null) || ($agentCommissionType === 'percentage' && $agentCommissionValue !== null && $agentCommissionValue > 100)) { Response::error('قيمة عمولة الوكيل غير صالحة؛ النسبة يجب أن تكون بين 0 و100.', 'VALIDATION_ERROR', 422); }
        $busTypeRaw = strtolower(trim((string) ($input['bus_type'] ?? 'normal')));
        $busType = in_array($busTypeRaw, ['vip', 'tourist', 'tourism'], true) ? 'VIP' : 'normal';
        $seatCount = filter_var($input['seat_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if ($routeId === false || $routeSubrouteId === false || ($busId !== null && $busId === false) || $departure === false || $arrival === false || $arrival <= $departure || mb_strlen($tripNumber) < 3 || $seatCount === false) { Response::error('بيانات الرحلة غير صالحة. تحقق من المواعيد ورقم الرحلة وعدد المقاعد.', 'VALIDATION_ERROR', 422); }
        return $this->database->transaction(function (PDO $pdo) use ($actor, $routeId, $routeSubrouteId, $busId, $departure, $arrival, $tripNumber, $tripType, $seatCount, $busType, $agentCommissionType, $agentCommissionValue): array {
            $route = $this->one($pdo, 'SELECT * FROM routes WHERE id = :id FOR UPDATE', ['id' => $routeId]);
            $bus = $this->one($pdo, 'SELECT * FROM buses WHERE id = :id FOR UPDATE', ['id' => $busId]);
            if ($route === null || ($busId !== null && ($bus === null || (int) $route['company_id'] !== (int) $bus['company_id']))) { Response::error('المسار والباص غير متوافقين.', 'VALIDATION_ERROR', 422); }
            $companyId = $this->companyScope($actor, $route['company_id']);
            if ($routeSubrouteId !== null && $this->one($pdo, 'SELECT sr.id FROM route_subroute_links link INNER JOIN route_subroutes sr ON sr.id = link.subroute_id WHERE link.route_id = :route_id AND sr.id = :subroute_id AND sr.status = \'active\'', ['route_id' => $routeId, 'subroute_id' => $routeSubrouteId]) === null) { Response::error('المسار الفرعي المختار لا يتبع المسار الرئيسي أو غير نشط.', 'VALIDATION_ERROR', 422); }
            if ($busId === null) { $busId = $this->ensureVirtualSeatProvider($pdo, (int) $companyId, (int) $seatCount); }
            $statement = $pdo->prepare('INSERT INTO trips (company_id, route_id, route_subroute_id, bus_id, trip_number, trip_type, bus_type, agent_commission_type, agent_commission_value, seat_count, departure_at, arrival_at, booking_open_at, booking_close_at, status, created_by) VALUES (:company_id, :route_id, :route_subroute_id, :bus_id, :trip_number, :trip_type, :bus_type, :agent_commission_type, :agent_commission_value, :seat_count, :departure_at, :arrival_at, NOW(), DATE_SUB(:departure_at_close, INTERVAL 30 MINUTE), \'open\', :created_by)');
            $statement->execute(['company_id' => $companyId, 'route_id' => $routeId, 'route_subroute_id' => $routeSubrouteId, 'bus_id' => $busId, 'trip_number' => $tripNumber, 'trip_type' => $tripType, 'bus_type' => $busType, 'agent_commission_type' => $agentCommissionType, 'agent_commission_value' => $agentCommissionValue, 'seat_count' => $seatCount, 'departure_at' => $departure->format('Y-m-d H:i:s'), 'arrival_at' => $arrival->format('Y-m-d H:i:s'), 'departure_at_close' => $departure->format('Y-m-d H:i:s'), 'created_by' => $actor['id']]);
            $tripId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO trip_seat_inventory (trip_id, bus_seat_id, is_available) SELECT :trip_id, id, 1 FROM bus_seats WHERE bus_id = :bus_id AND is_active = 1 LIMIT ' . (int) $seatCount)->execute(['trip_id' => $tripId, 'bus_id' => $busId]);
            $pdo->prepare('INSERT INTO trip_segment_prices (trip_id, route_segment_id, currency_id, company_amount, amount, source_price_id) SELECT :trip_id, sp.route_segment_id, sp.currency_id, COALESCE(subroute.company_amount, 0), sp.amount, sp.id FROM segment_prices sp INNER JOIN route_segments rs ON rs.id = sp.route_segment_id LEFT JOIN route_subroute_links link ON link.route_segment_id = sp.route_segment_id LEFT JOIN route_subroutes subroute ON subroute.id = link.subroute_id WHERE sp.company_id = :company_id AND rs.route_id = :route_id AND sp.status = \'active\'')->execute(['trip_id' => $tripId, 'company_id' => $companyId, 'route_id' => $routeId]);
            $this->audit->log((int) $actor['id'], $companyId, 'trip_created', 'trip', $tripId, null, ['trip_number' => $tripNumber, 'route_subroute_id' => $routeSubrouteId]);
            return ['id' => $tripId, 'trip_number' => $tripNumber, 'trip_type' => $tripType, 'seat_count' => $seatCount, 'route_subroute_id' => $routeSubrouteId, 'status' => 'open'];
        });
    }

    /** @return array<string, mixed> */
    public function previewRecurringTrips(array $actor, array $input): array
    {
        $template = $this->recurringTripTemplate($actor, $input);
        return ['count' => count($template['occurrences']), 'occurrences' => $template['occurrences'], 'trip_number_prefix' => $template['trip_number_prefix']];
    }

    /** @return array<string, mixed> */
    public function createRecurringTrips(array $actor, array $input): array
    {
        $template = $this->recurringTripTemplate($actor, $input);
        return $this->database->transaction(function (PDO $pdo) use ($actor, $template): array {
            $route = $this->one($pdo, 'SELECT id, company_id FROM routes WHERE id = :id FOR UPDATE', ['id' => $template['route_id']]);
            $bus = $this->one($pdo, 'SELECT id, company_id FROM buses WHERE id = :id FOR UPDATE', ['id' => $template['bus_id']]);
            if ($route === null || ($template['bus_id'] !== null && ($bus === null || (int) $route['company_id'] !== (int) $bus['company_id']))) { Response::error('المسار والباص غير متوافقين.', 'VALIDATION_ERROR', 422); }
            $companyId = $this->companyScope($actor, $route['company_id']);
            $busId = $template['bus_id'];
            if ($busId === null) { $busId = $this->ensureVirtualSeatProvider($pdo, (int) $companyId, (int) $template['seat_count']); }
            $segment = $this->one($pdo, 'SELECT sr.id FROM route_subroute_links link INNER JOIN route_subroutes sr ON sr.id = link.subroute_id WHERE link.route_id = :route_id AND sr.id = :subroute_id AND sr.status = \'active\'', ['route_id' => $template['route_id'], 'subroute_id' => $template['route_subroute_id']]);
            if ($segment === null) { Response::error('المسار الفرعي المختار لا يتبع المسار الرئيسي أو غير نشط.', 'VALIDATION_ERROR', 422); }
            foreach ($template['occurrences'] as $occurrence) {
                if ($template['bus_id'] !== null && $this->one($pdo, 'SELECT id FROM trips WHERE bus_id = :bus_id AND departure_at = :departure_at AND status <> \'cancelled\' LIMIT 1 FOR UPDATE', ['bus_id' => $template['bus_id'], 'departure_at' => $occurrence['departure_at']]) !== null) {
                    Response::error('يوجد تعارض زمني على الباص في أحد تواريخ التكرار؛ لم يتم إنشاء أي رحلة.', 'TRIP_CONFLICT', 409);
                }
            }
            $group = 'RG-' . strtoupper(bin2hex(random_bytes(6)));
            $nextSequence = 1;
            foreach ($this->allPdo($pdo, 'SELECT trip_number FROM trips WHERE trip_number LIKE :prefix ORDER BY id DESC', ['prefix' => $template['trip_number_prefix'] . '-%']) as $existingTrip) {
                if (preg_match('/-(\d+)$/', (string) $existingTrip['trip_number'], $match) === 1) { $nextSequence = max($nextSequence, (int) $match[1] + 1); }
            }
            $created = [];
            foreach ($template['occurrences'] as $index => $occurrence) {
                $tripNumber = $template['trip_number_prefix'] . '-' . str_pad((string) $nextSequence++, 6, '0', STR_PAD_LEFT);
                $statement = $pdo->prepare('INSERT INTO trips (company_id, route_id, route_subroute_id, bus_id, trip_number, trip_type, bus_type, agent_commission_type, agent_commission_value, seat_count, recurrence_group, recurrence_index, departure_at, arrival_at, booking_open_at, booking_close_at, status, created_by) VALUES (:company_id, :route_id, :route_subroute_id, :bus_id, :trip_number, :trip_type, :bus_type, :agent_commission_type, :agent_commission_value, :seat_count, :recurrence_group, :recurrence_index, :departure_at, :arrival_at, NOW(), DATE_SUB(:departure_at_close, INTERVAL 30 MINUTE), \'open\', :created_by)');
                $statement->execute(['company_id' => $companyId, 'route_id' => $template['route_id'], 'route_subroute_id' => $template['route_subroute_id'], 'bus_id' => $busId, 'trip_number' => $tripNumber, 'trip_type' => $template['trip_type'], 'bus_type' => $template['bus_type'], 'agent_commission_type' => $template['agent_commission_type'], 'agent_commission_value' => $template['agent_commission_value'], 'seat_count' => $template['seat_count'], 'recurrence_group' => $group, 'recurrence_index' => $index + 1, 'departure_at' => $occurrence['departure_at'], 'arrival_at' => $occurrence['arrival_at'], 'departure_at_close' => $occurrence['departure_at'], 'created_by' => $actor['id']]);
                $tripId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO trip_seat_inventory (trip_id, bus_seat_id, is_available) SELECT :trip_id, id, 1 FROM bus_seats WHERE bus_id = :bus_id AND is_active = 1 LIMIT ' . (int) $template['seat_count'])->execute(['trip_id' => $tripId, 'bus_id' => $busId]);
                $pdo->prepare('INSERT INTO trip_segment_prices (trip_id, route_segment_id, currency_id, company_amount, amount, source_price_id) SELECT :trip_id, sp.route_segment_id, sp.currency_id, COALESCE(subroute.company_amount, 0), sp.amount, sp.id FROM segment_prices sp INNER JOIN route_segments rs ON rs.id = sp.route_segment_id LEFT JOIN route_subroute_links link ON link.route_segment_id = sp.route_segment_id LEFT JOIN route_subroutes subroute ON subroute.id = link.subroute_id WHERE sp.company_id = :company_id AND rs.route_id = :route_id AND sp.status = \'active\'')->execute(['trip_id' => $tripId, 'company_id' => $companyId, 'route_id' => $template['route_id']]);
                $created[] = ['id' => $tripId, 'trip_number' => $tripNumber, 'departure_at' => $occurrence['departure_at']];
            }
            $this->audit->log((int) $actor['id'], $companyId, 'recurring_trips_created', 'trip_recurrence', null, null, ['recurrence_group' => $group, 'count' => count($created), 'route_id' => $template['route_id'], 'route_subroute_id' => $template['route_subroute_id'], 'bus_id' => $template['bus_id']]);
            return ['recurrence_group' => $group, 'count' => count($created), 'trips' => $created];
        });
    }

    /** @return array{route_id:int,route_subroute_id:int,bus_id:int,trip_number_prefix:string,occurrences:list<array{date:string,departure_at:string,arrival_at:string}>} */
    private function recurringTripTemplate(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_trips');
        $routeId = filter_var($input['route_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $subrouteId = filter_var($input['route_subroute_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $busRaw = $input['bus_id'] ?? null;
        $busId = $busRaw === null || $busRaw === '' ? null : filter_var($busRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $start = 
            \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($input['start_date'] ?? ''));
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($input['end_date'] ?? ''));
        $weekdaysRaw = is_array($input['weekdays'] ?? null) ? $input['weekdays'] : [];
        $weekdays = array_values(array_unique(array_filter(array_map(static fn (mixed $day): int => filter_var($day, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 7]]) ?: 0, $weekdaysRaw))));
        $prefix = strtoupper(preg_replace('/[^A-Z0-9-]/', '', (string) ($input['trip_number_prefix'] ?? '')) ?? '');
        $tripType = in_array($input['trip_type'] ?? 'local', ['local', 'international'], true) ? (string) $input['trip_type'] : 'local';
        $agentCommissionType = in_array($input['agent_commission_type'] ?? null, ['percentage', 'fixed'], true) ? (string) $input['agent_commission_type'] : null;
        $agentCommissionValue = trim((string) ($input['agent_commission_value'] ?? '')) === '' ? null : filter_var($input['agent_commission_value'], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0, 'max_range' => 999999999]]);
        if ($agentCommissionValue === false || ($agentCommissionValue !== null && $agentCommissionType === null) || ($agentCommissionType === 'percentage' && $agentCommissionValue !== null && $agentCommissionValue > 100)) { Response::error('قيمة عمولة الوكيل غير صالحة؛ النسبة يجب أن تكون بين 0 و100.', 'VALIDATION_ERROR', 422); }
        $busTypeRaw = strtolower(trim((string) ($input['bus_type'] ?? 'normal')));
        $busType = in_array($busTypeRaw, ['vip', 'tourist', 'tourism'], true) ? 'VIP' : 'normal';
        $seatCount = filter_var($input['seat_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        if ($routeId === false || $subrouteId === false || ($busId !== null && $busId === false) || $seatCount === false || $start === false || $end === false || $end < $start || $end > $start->modify('+366 days') || $weekdays === [] || mb_strlen($prefix) < 2) { Response::error('بيانات تكرار الرحلات غير صالحة. تحقق من التواريخ والأيام وعدد المقاعد.', 'VALIDATION_ERROR', 422); }
        $pdo = $this->database->pdo();
        $route = $this->one($pdo, 'SELECT company_id FROM routes WHERE id = :id', ['id' => $routeId]);
        $subroute = $this->one($pdo, 'SELECT sr.origin_departure_time, sr.destination_arrival_time FROM route_subroute_links link INNER JOIN route_subroutes sr ON sr.id = link.subroute_id WHERE link.route_id = :route_id AND sr.id = :subroute_id AND sr.status = \'active\'', ['route_id' => $routeId, 'subroute_id' => $subrouteId]);
        if ($route === null || $subroute === null) { Response::error('المسار الرئيسي أو الفرعي المختار غير صالح.', 'VALIDATION_ERROR', 422); }
        $this->companyScope($actor, $route['company_id']);
        if (empty($subroute['origin_departure_time']) || empty($subroute['destination_arrival_time'])) { Response::error('أكمل وقت المغادرة ووقت الحضور في المسار الفرعي قبل إنشاء التكرار.', 'VALIDATION_ERROR', 422); }
        $occurrences = [];
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            if (!in_array((int) $date->format('N'), $weekdays, true)) { continue; }
            $departure = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . $subroute['origin_departure_time']);
            $arrival = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date->format('Y-m-d') . ' ' . $subroute['destination_arrival_time']);
            if ($departure === false || $arrival === false) { Response::error('أوقات المسار الفرعي غير صالحة.', 'VALIDATION_ERROR', 422); }
            if ($arrival <= $departure) { $arrival = $arrival->modify('+1 day'); }
            $occurrences[] = ['date' => $date->format('Y-m-d'), 'departure_at' => $departure->format('Y-m-d H:i:s'), 'arrival_at' => $arrival->format('Y-m-d H:i:s')];
        }
        if ($occurrences === []) { Response::error('لا توجد تواريخ مطابقة لأيام التكرار المختارة ضمن الفترة.', 'VALIDATION_ERROR', 422); }
        return ['route_id' => $routeId, 'route_subroute_id' => $subrouteId, 'bus_id' => $busId, 'trip_number_prefix' => $prefix, 'trip_type' => $tripType, 'bus_type' => $busType, 'agent_commission_type' => $agentCommissionType, 'agent_commission_value' => $agentCommissionValue, 'seat_count' => $seatCount, 'occurrences' => $occurrences];
    }

    /** @return array<string, mixed> */
    public function previewTripPriceUpdate(array $actor, array $input): array
    {
        $filter = $this->tripPriceFilter($actor, $input);
        $rows = $this->tripPriceRows($this->database->pdo(), $filter['company_id'], $filter['from'], $filter['to']);
        $trips = [];
        foreach ($rows as $row) {
            $tripId = (int) $row['trip_id'];
            if (!isset($trips[$tripId])) {
                $trips[$tripId] = [
                    'id' => $tripId,
                    'trip_number' => (string) $row['trip_number'],
                    'company_id' => (int) $row['company_id'],
                    'company_name' => (string) $row['company_name'],
                    'route_id' => (int) $row['route_id'],
                    'route_name' => (string) $row['route_name'],
                    'trip_type' => (string) $row['trip_type'],
                    'departure_at' => (string) $row['departure_at'],
                    'segments' => [],
                ];
            }
            $trips[$tripId]['segments'][] = [
                'route_segment_id' => (int) $row['route_segment_id'],
                'currency_id' => (int) $row['currency_id'],
                'currency_code' => (string) $row['currency_code'],
                'currency_symbol' => (string) ($row['currency_symbol'] ?? ''),
                'previous_amount' => (float) $row['amount'],
                'origin_city_name' => (string) $row['origin_city_name'],
                'destination_city_name' => (string) $row['destination_city_name'],
                'origin_order' => (int) $row['origin_order'],
                'destination_order' => (int) $row['destination_order'],
            ];
        }
        return ['company_id' => $filter['company_id'], 'from' => $filter['from'], 'to' => $filter['to'], 'trips' => array_values($trips)];
    }

    /** @return array<string, mixed> */
    public function applyTripPriceUpdate(array $actor, array $input): array
    {
        $filter = $this->tripPriceFilter($actor, $input);
        $updates = is_array($input['updates'] ?? null) ? $input['updates'] : [];
        if ($updates === [] || count($updates) > 500) { Response::error('أدخل أسعار رحلة واحدة على الأقل وبحد أقصى 500 تحديث.', 'VALIDATION_ERROR', 422); }
        $rows = $this->tripPriceRows($this->database->pdo(), $filter['company_id'], $filter['from'], $filter['to']);
        $allowed = [];
        foreach ($rows as $row) {
            $allowed[(int) $row['trip_id']][(int) $row['route_segment_id'] . ':' . (int) $row['currency_id']] = true;
        }
        return $this->database->transaction(function (PDO $pdo) use ($actor, $filter, $updates, $allowed): array {
            $changed = 0;
            $changedTrips = [];
            $select = $pdo->prepare('SELECT id, amount FROM trip_segment_prices WHERE trip_id = :trip_id AND route_segment_id = :route_segment_id AND currency_id = :currency_id FOR UPDATE');
            $update = $pdo->prepare('UPDATE trip_segment_prices SET amount = :amount WHERE id = :id');
            foreach ($updates as $tripUpdate) {
                $tripId = filter_var($tripUpdate['trip_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $prices = is_array($tripUpdate['prices'] ?? null) ? $tripUpdate['prices'] : [];
                if ($tripId === false || $prices === []) { Response::error('بيانات تعديل الأسعار غير مكتملة.', 'VALIDATION_ERROR', 422); }
                if (!isset($allowed[(int) $tripId])) { Response::error('إحدى الرحلات لا تطابق الشركة أو نطاق التاريخ المحدد.', 'VALIDATION_ERROR', 422); }
                foreach ($prices as $price) {
                    $segmentId = filter_var($price['route_segment_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $currencyId = filter_var($price['currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $amount = filter_var($price['amount'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.01, 'max_range' => 999999999]]);
                    if ($segmentId === false || $currencyId === false || $amount === false || !isset($allowed[(int) $tripId][(int) $segmentId . ':' . (int) $currencyId])) { Response::error('يوجد سعر غير صالح أو غير مرتبط بالرحلة المحددة.', 'VALIDATION_ERROR', 422); }
                    $select->execute(['trip_id' => $tripId, 'route_segment_id' => $segmentId, 'currency_id' => $currencyId]);
                    $existing = $select->fetch();
                    if (!$existing) { Response::error('سعر مقطع الرحلة غير موجود.', 'NOT_FOUND', 404); }
                    $previous = (float) $existing['amount'];
                    $nextAmount = (float) $amount;
                    $update->execute(['amount' => $amount, 'id' => $existing['id']]);
                    if (abs($previous - $nextAmount) > 0.000001) {
                        $changeType = $nextAmount > $previous ? 'increase' : 'decrease';
                        $log = $pdo->prepare('INSERT INTO trip_price_change_logs (trip_id, route_segment_id, currency_id, previous_amount, current_amount, change_type, changed_by) VALUES (:trip_id, :route_segment_id, :currency_id, :previous_amount, :current_amount, :change_type, :changed_by)');
                        $log->execute(['trip_id' => $tripId, 'route_segment_id' => $segmentId, 'currency_id' => $currencyId, 'previous_amount' => $previous, 'current_amount' => $nextAmount, 'change_type' => $changeType, 'changed_by' => $actor['id']]);
                        $changed++;
                    }
                    $changedTrips[(int) $tripId] = true;
                }
            }
            foreach (array_keys($changedTrips) as $tripId) { $this->audit->log((int) $actor['id'], (int) $filter['company_id'], 'trip_prices_bulk_updated', 'trip', (int) $tripId, null, ['from' => $filter['from'], 'to' => $filter['to']]); }
            return ['changed_prices' => $changed, 'affected_trips' => count($changedTrips), 'from' => $filter['from'], 'to' => $filter['to']];
        });
    }

    /** @return array{company_id:int,from:string,to:string} */
    private function tripPriceFilter(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_trips');
        if (!$this->canManageFinancialAmounts($actor)) { Response::error('لا تملك صلاحية تعديل أسعار الرحلات.', 'FORBIDDEN', 403); }
        $companyId = filter_var($input['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($input['from'] ?? ''));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($input['to'] ?? ''));
        if ($companyId === false || $from === false || $to === false || $to < $from) { Response::error('اختر الشركة وأدخل نطاق تاريخ صحيحًا.', 'VALIDATION_ERROR', 422); }
        $this->companyScope($actor, $companyId);
        return ['company_id' => (int) $companyId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')];
    }

    /** @return list<array<string, mixed>> */
    private function tripPriceRows(PDO $pdo, int $companyId, string $from, string $to): array
    {
        return $this->allPdo($pdo, 'SELECT t.id AS trip_id, t.company_id, t.trip_number, t.route_id, t.trip_type, t.departure_at, co.trade_name AS company_name, r.name_ar AS route_name, tsp.route_segment_id, tsp.currency_id, tsp.amount, cur.code AS currency_code, cur.symbol_ar AS currency_symbol, rs.origin_order, rs.destination_order, origin_city.name_ar AS origin_city_name, destination_city.name_ar AS destination_city_name FROM trips t INNER JOIN companies co ON co.id = t.company_id INNER JOIN routes r ON r.id = t.route_id INNER JOIN trip_segment_prices tsp ON tsp.trip_id = t.id INNER JOIN currencies cur ON cur.id = tsp.currency_id INNER JOIN route_segments rs ON rs.id = tsp.route_segment_id INNER JOIN route_stops origin_stop ON origin_stop.id = rs.origin_stop_id INNER JOIN route_stops destination_stop ON destination_stop.id = rs.destination_stop_id INNER JOIN stations origin_station ON origin_station.id = origin_stop.station_id INNER JOIN stations destination_station ON destination_station.id = destination_stop.station_id INNER JOIN cities origin_city ON origin_city.id = origin_station.city_id INNER JOIN cities destination_city ON destination_city.id = destination_station.city_id WHERE t.company_id = :company_id AND t.departure_at >= :from_date AND t.departure_at < DATE_ADD(:to_date, INTERVAL 1 DAY) AND t.status <> \'cancelled\' ORDER BY t.departure_at, t.id, rs.origin_order, rs.destination_order', ['company_id' => $companyId, 'from_date' => $from, 'to_date' => $to]);
    }

    /** @return array<string, mixed> */
    public function previewBulkTripUpdate(array $actor, array $input): array
    {
        $prepared = $this->bulkTripUpdatePayload($actor, $input);
        return ['count' => count($prepared['trips']), 'booked_count' => $prepared['booked_count'], 'action_label' => $prepared['action_label'], 'trips' => array_map(static fn (array $trip): array => ['id' => $trip['id'], 'trip_number' => $trip['trip_number'], 'departure_at' => $trip['departure_at']], $prepared['trips'])];
    }

    /** @return array<string, mixed> */
    public function applyBulkTripUpdate(array $actor, array $input): array
    {
        $prepared = $this->bulkTripUpdatePayload($actor, $input);
        return $this->database->transaction(function (PDO $pdo) use ($actor, $prepared): array {
            $tripIds = array_column($prepared['trips'], 'id');
            $lockedTrips = $this->tripsForBulkUpdate($pdo, $tripIds, true);
            if (count($lockedTrips) !== count($tripIds)) { Response::error('تعذر العثور على جميع الرحلات المحددة.', 'NOT_FOUND', 404); }
            $bookedCount = array_sum(array_map(static fn (array $trip): int => (int) $trip['booking_count'], $lockedTrips));
            if (in_array($prepared['action'], ['reschedule', 'bus'], true) && $bookedCount > 0) { Response::error('لا يمكن تعديل الموعد أو الباص جماعيًا عند وجود حجوزات على أي رحلة محددة.', 'DEPENDENCY_EXISTS', 409); }
            foreach ($lockedTrips as $trip) { $this->companyScope($actor, $trip['company_id']); }
            if ($prepared['action'] === 'status') {
                $pdo->prepare('UPDATE trips SET status = ? WHERE id IN (' . implode(',', array_fill(0, count($tripIds), '?')) . ')')->execute(array_merge([$prepared['status']], $tripIds));
            } elseif ($prepared['action'] === 'reschedule') {
                $shift = $prepared['shift_minutes'];
                $statement = $pdo->prepare('UPDATE trips SET departure_at = DATE_ADD(departure_at, INTERVAL :shift MINUTE), arrival_at = DATE_ADD(arrival_at, INTERVAL :shift MINUTE), booking_close_at = DATE_ADD(booking_close_at, INTERVAL :shift MINUTE) WHERE id = :id');
                foreach ($lockedTrips as $trip) { $statement->execute(['shift' => $shift, 'id' => $trip['id']]); }
            } else {
                $bus = $this->one($pdo, 'SELECT id, company_id FROM buses WHERE id = :id AND status = \'active\' FOR UPDATE', ['id' => $prepared['bus_id']]);
                if ($bus === null) { Response::error('الباص البديل غير موجود أو غير نشط.', 'NOT_FOUND', 404); }
                foreach ($lockedTrips as $trip) {
                    if ((int) $trip['company_id'] !== (int) $bus['company_id']) { Response::error('يجب أن يكون الباص البديل تابعًا للشركة نفسها لكل رحلة محددة.', 'VALIDATION_ERROR', 422); }
                    $pdo->prepare('UPDATE trips SET bus_id = :bus_id WHERE id = :id')->execute(['bus_id' => $bus['id'], 'id' => $trip['id']]);
                    $pdo->prepare('DELETE FROM trip_seat_inventory WHERE trip_id = :trip_id')->execute(['trip_id' => $trip['id']]);
                    $pdo->prepare('INSERT INTO trip_seat_inventory (trip_id, bus_seat_id, is_available) SELECT :trip_id, id, 1 FROM bus_seats WHERE bus_id = :bus_id AND is_active = 1')->execute(['trip_id' => $trip['id'], 'bus_id' => $bus['id']]);
                }
            }
            foreach ($lockedTrips as $trip) { $this->audit->log((int) $actor['id'], (int) $trip['company_id'], 'trips_bulk_updated', 'trip', (int) $trip['id'], null, ['action' => $prepared['action'], 'status' => $prepared['status'], 'shift_minutes' => $prepared['shift_minutes'], 'bus_id' => $prepared['bus_id'], 'selection_count' => count($lockedTrips)]); }
            return ['count' => count($lockedTrips), 'action_label' => $prepared['action_label']];
        });
    }

    /** @return array{action:string,action_label:string,status:?string,shift_minutes:?int,bus_id:?int,trips:list<array<string,mixed>>,booked_count:int} */
    private function bulkTripUpdatePayload(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_trips');
        $rawIds = is_array($input['trip_ids'] ?? null) ? $input['trip_ids'] : [];
        $tripIds = array_values(array_unique(array_filter(array_map(static fn (mixed $id): int => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0, $rawIds))));
        $action = (string) ($input['action'] ?? '');
        if ($tripIds === [] || count($tripIds) > 100 || !in_array($action, ['status', 'reschedule', 'bus'], true)) { Response::error('اختر من 1 إلى 100 رحلة وحدد إجراءً جماعيًا صالحًا.', 'VALIDATION_ERROR', 422); }
        $trips = $this->tripsForBulkUpdate($this->database->pdo(), $tripIds, false);
        if (count($trips) !== count($tripIds)) { Response::error('تعذر العثور على جميع الرحلات المحددة.', 'NOT_FOUND', 404); }
        foreach ($trips as $trip) { $this->companyScope($actor, $trip['company_id']); }
        $bookedCount = array_sum(array_map(static fn (array $trip): int => (int) $trip['booking_count'], $trips));
        $status = null; $shift = null; $busId = null; $label = '';
        if ($action === 'status') { $status = in_array($input['status'] ?? null, ['scheduled', 'open', 'boarding', 'completed', 'cancelled', 'expired'], true) ? (string) $input['status'] : null; $label = 'تغيير الحالة'; if ($status === null) { Response::error('اختر حالة صالحة للرحلات.', 'VALIDATION_ERROR', 422); } }
        if ($action === 'status') { $transitions = ['scheduled' => ['open', 'cancelled'], 'open' => ['boarding', 'cancelled', 'expired'], 'boarding' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => [], 'expired' => []]; foreach ($trips as $trip) { if ($status !== (string) ($trip['status'] ?? '') && !in_array($status, $transitions[(string) ($trip['status'] ?? '')] ?? [], true)) { Response::error('لا يمكن تطبيق الحالة الجماعية على جميع الرحلات؛ راجع ترتيب الحالات لكل رحلة.', 'INVALID_WORKFLOW_TRANSITION', 409); } } }
        if ($action === 'reschedule') { $shift = filter_var($input['shift_minutes'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => -43200, 'max_range' => 43200]]); $label = 'تحريك المواعيد'; if ($shift === false || $shift === 0) { Response::error('أدخل عدد دقائق صحيحًا لتحريك المواعيد.', 'VALIDATION_ERROR', 422); } }
        if ($action === 'bus') { $busId = filter_var($input['bus_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); $label = 'تبديل الباص'; if ($busId === false) { Response::error('اختر باصًا بديلًا صالحًا.', 'VALIDATION_ERROR', 422); } }
        if (in_array($action, ['reschedule', 'bus'], true) && $bookedCount > 0) { Response::error('لا يمكن تعديل الموعد أو الباص جماعيًا عند وجود حجوزات على أي رحلة محددة.', 'DEPENDENCY_EXISTS', 409); }
        return ['action' => $action, 'action_label' => $label, 'status' => $status, 'shift_minutes' => $shift, 'bus_id' => $busId, 'trips' => $trips, 'booked_count' => $bookedCount];
    }

    /** @return list<array<string,mixed>> */
    private function tripsForBulkUpdate(PDO $pdo, array $tripIds, bool $forUpdate): array
    {
        $holders = implode(',', array_fill(0, count($tripIds), '?'));
        $statement = $pdo->prepare('SELECT t.id, t.company_id, t.trip_number, t.departure_at, t.status, (SELECT COUNT(*) FROM bookings b WHERE b.trip_id = t.id) AS booking_count FROM trips t WHERE t.id IN (' . $holders . ')' . ($forUpdate ? ' FOR UPDATE' : ''));
        $statement->execute($tripIds);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function updateTrip(array $actor, int $tripId, array $input): array
    {
        $this->assertPermission($actor, 'manage_trips');
        $pdo = $this->database->pdo();
        $trip = $this->one($pdo, 'SELECT id, company_id, bus_id FROM trips WHERE id = :id', ['id' => $tripId]);
        if ($trip === null) { Response::error('الرحلة غير موجودة.', 'NOT_FOUND', 404); }
        $companyId = $this->companyScope($actor, $trip['company_id']);
        if ((int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM bookings WHERE trip_id = :id', ['id' => $tripId])['total'] > 0) { Response::error('لا يمكن تعديل موعد أو رقم رحلة مرتبطة بحجز. أوقفها أو ألغها بدلًا من ذلك.', 'DEPENDENCY_EXISTS', 409); }
        $number = strtoupper(Security::cleanText($input['trip_number'] ?? null, 64));
        $seatCount = filter_var($input['seat_count'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
        $tripType = in_array($input['trip_type'] ?? 'local', ['local', 'international'], true) ? (string) $input['trip_type'] : 'local';
        $busTypeRaw = strtolower(trim((string) ($input['bus_type'] ?? 'normal')));
        $busType = in_array($busTypeRaw, ['vip', 'tourist', 'tourism'], true) ? 'VIP' : 'normal';
        $agentCommissionType = in_array($input['agent_commission_type'] ?? null, ['percentage', 'fixed'], true) ? (string) $input['agent_commission_type'] : null;
        $agentCommissionValue = trim((string) ($input['agent_commission_value'] ?? '')) === '' ? null : filter_var($input['agent_commission_value'], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0, 'max_range' => 999999999]]);
        if ($agentCommissionValue === false || ($agentCommissionValue !== null && $agentCommissionType === null) || ($agentCommissionType === 'percentage' && $agentCommissionValue !== null && $agentCommissionValue > 100)) { Response::error('قيمة عمولة الوكيل غير صالحة؛ النسبة يجب أن تكون بين 0 و100.', 'VALIDATION_ERROR', 422); }
        $departure = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($input['departure_at'] ?? ''));
        $arrival = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) ($input['arrival_at'] ?? ''));
        if (mb_strlen($number) < 3 || $seatCount === false || $departure === false || $arrival === false || $arrival <= $departure) { Response::error('بيانات الرحلة غير صالحة.', 'VALIDATION_ERROR', 422); }
        $bus = $trip['bus_id'] === null ? null : $this->one($pdo, 'SELECT id, is_virtual FROM buses WHERE id = :id', ['id' => $trip['bus_id']]);
        $busId = $trip['bus_id'] === null || ($bus !== null && (int) ($bus['is_virtual'] ?? 0) === 1) ? null : (int) $trip['bus_id'];
        $result = $this->database->transaction(function (PDO $transactionPdo) use ($actor, $tripId, $companyId, $number, $tripType, $busType, $agentCommissionType, $agentCommissionValue, $seatCount, $departure, $arrival, $busId): array {
            $providerId = $busId ?? $this->ensureVirtualSeatProvider($transactionPdo, (int) $companyId, (int) $seatCount);
            $transactionPdo->prepare('UPDATE trips SET bus_id = :bus_id, trip_number = :trip_number, trip_type = :trip_type, bus_type = :bus_type, agent_commission_type = :agent_commission_type, agent_commission_value = :agent_commission_value, seat_count = :seat_count, departure_at = :departure_at, arrival_at = :arrival_at, booking_close_at = DATE_SUB(:departure_close, INTERVAL 30 MINUTE) WHERE id = :id')->execute(['bus_id' => $providerId, 'trip_number' => $number, 'trip_type' => $tripType, 'bus_type' => $busType, 'agent_commission_type' => $agentCommissionType, 'agent_commission_value' => $agentCommissionValue, 'seat_count' => $seatCount, 'departure_at' => $departure->format('Y-m-d H:i:s'), 'arrival_at' => $arrival->format('Y-m-d H:i:s'), 'departure_close' => $departure->format('Y-m-d H:i:s'), 'id' => $tripId]);
            $transactionPdo->prepare('DELETE FROM trip_seat_inventory WHERE trip_id = :trip_id')->execute(['trip_id' => $tripId]);
            $transactionPdo->prepare('INSERT INTO trip_seat_inventory (trip_id, bus_seat_id, is_available) SELECT :trip_id, id, 1 FROM bus_seats WHERE bus_id = :bus_id AND is_active = 1 ORDER BY id LIMIT ' . (int) $seatCount)->execute(['trip_id' => $tripId, 'bus_id' => $providerId]);
            return ['id' => $tripId, 'trip_number' => $number, 'trip_type' => $tripType, 'bus_type' => $busType, 'seat_count' => $seatCount];
        });
        $this->audit->log((int) $actor['id'], (int) $companyId, 'trip_updated', 'trip', $tripId, null, ['trip_number' => $number, 'seat_count' => $seatCount, 'bus_type' => $busType]);
        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function tripBookings(array $actor, int $tripId): array
    {
        $this->assertPermission($actor, 'manage_trips');
        $pdo = $this->database->pdo();
        $trip = $this->one($pdo, 'SELECT id, company_id, trip_number FROM trips WHERE id = :id', ['id' => $tripId]);
        if ($trip === null) { Response::error('الرحلة غير موجودة.', 'NOT_FOUND', 404); }
        $this->companyScope($actor, $trip['company_id']);
        $financeFields = $this->canManageFinancialAmounts($actor) ? 'b.commission_amount, b.platform_commission_amount' : 'NULL AS commission_amount, NULL AS platform_commission_amount';
        $statement = $pdo->prepare(
            "SELECT b.id, b.booking_number, b.status, b.payment_status, b.source, b.total_amount, {$financeFields}, b.created_at,
                    cu.code AS currency_code, cu.symbol_ar AS currency_symbol,
                    COALESCE(customer_user.full_name, 'عميل مباشر') AS customer_name,
                    COALESCE(agent_user.full_name, 'حجز مباشر') AS agent_name,
                    (SELECT COUNT(*) FROM booking_passengers bp WHERE bp.booking_id = b.id) AS passenger_count
             FROM bookings b
             INNER JOIN currencies cu ON cu.id = b.currency_id
             LEFT JOIN customers customer ON customer.id = b.customer_id
             LEFT JOIN users customer_user ON customer_user.id = customer.user_id
             LEFT JOIN agents agent ON agent.id = b.agent_id
             LEFT JOIN users agent_user ON agent_user.id = agent.user_id
             WHERE b.trip_id = :trip_id
             ORDER BY b.created_at DESC"
        );
        $statement->execute(['trip_id' => $tripId]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function updateTripStatus(array $actor, int $tripId, array $input): array
    {
        $this->assertPermission($actor, 'manage_trips');
        $status = in_array($input['status'] ?? null, ['scheduled', 'open', 'boarding', 'completed', 'cancelled', 'expired'], true) ? (string) $input['status'] : '';
        if ($status === '') { Response::error('حالة الرحلة غير صالحة.', 'VALIDATION_ERROR', 422); }
        $trip = $this->one($this->database->pdo(), 'SELECT company_id, status, departure_at FROM trips WHERE id = :id', ['id' => $tripId]);
        if ($trip === null) { Response::error('الرحلة غير موجودة.', 'NOT_FOUND', 404); }
        $companyId = $this->companyScope($actor, $trip['company_id']);
        $previousStatus = (string) $trip['status'];
        $transitions = ['scheduled' => ['open', 'cancelled'], 'open' => ['boarding', 'cancelled', 'expired'], 'boarding' => ['completed', 'cancelled'], 'completed' => [], 'cancelled' => [], 'expired' => []];
        if ($status !== (string) $trip['status'] && !in_array($status, $transitions[(string) $trip['status']] ?? [], true)) { Response::error('انتقال حالة الرحلة غير مسموح. استخدم المسار: مجدولة ← مفتوحة ← قيد الصعود ← مكتملة، أو الإلغاء عند الحاجة.', 'INVALID_WORKFLOW_TRANSITION', 409); }
        if ($status === 'expired' && strtotime((string) $trip['departure_at']) > time()) { Response::error('لا يمكن إنهاء الرحلة قبل موعد مغادرتها.', 'INVALID_WORKFLOW_TRANSITION', 409); }
        $pdo = $this->database->pdo();
        $bookingOwners = $pdo->prepare("SELECT b.id, b.booking_number, b.customer_id, c.user_id FROM bookings b INNER JOIN customers c ON c.id = b.customer_id WHERE b.trip_id = :trip_id AND b.customer_id IS NOT NULL AND b.status IN ('pending','confirmed')");
        $bookingOwners->execute(['trip_id' => $tripId]);
        $bookingOwners = $bookingOwners->fetchAll();
        $pdo->prepare('UPDATE trips SET status = :status WHERE id = :id')->execute(['status' => $status, 'id' => $tripId]);
        $affectedBookings = 0;
        if ($status === 'completed') {
            $affectedBookings = $pdo->prepare("UPDATE bookings SET status = 'completed' WHERE trip_id = :trip_id AND status = 'confirmed'");
            $affectedBookings->execute(['trip_id' => $tripId]);
            $affectedBookings = $affectedBookings->rowCount();
        } elseif ($status === 'cancelled') {
            $affectedBookings = $pdo->prepare("UPDATE bookings SET status = 'cancelled', cancellation_reason = 'أُلغيت الرحلة من لوحة التشغيل.', cancelled_at = NOW() WHERE trip_id = :trip_id AND status IN ('pending','confirmed')");
            $affectedBookings->execute(['trip_id' => $tripId]);
            $affectedBookings = $affectedBookings->rowCount();
        }
        $statusLabels = ['scheduled' => 'مجدولة', 'open' => 'مفتوحة', 'boarding' => 'قيد الصعود', 'completed' => 'مكتملة', 'cancelled' => 'ملغاة', 'expired' => 'منتهية'];
        $statusTitle = $status === 'completed' ? 'اكتملت الرحلة' : ($status === 'cancelled' ? 'أُلغيت الرحلة' : 'تغيرت حالة الرحلة');
        $statusBody = $status === 'completed' ? 'اكتملت رحلتك بنجاح. يمكنك الآن فتح الحجز وتقييم الشركة والرحلة.' : ($status === 'cancelled' ? 'تم إلغاء الرحلة من لوحة التشغيل، وسيتم التواصل معك عند الحاجة.' : "تم تحديث حالة رحلتك إلى: " . ($statusLabels[$status] ?? $status) . '.');
        if ($status !== $previousStatus) {
            foreach ($bookingOwners as $owner) {
                $ownerUserId = (int) ($owner['user_id'] ?? 0);
                if ($ownerUserId > 0) { $this->notifications->send($ownerUserId, (int) $companyId, 'trip_status_changed', $statusTitle, $statusBody, 'booking', (int) $owner['id']); }
            }
        }
        $this->audit->log((int) $actor['id'], (int) $companyId, 'trip_status_updated', 'trip', $tripId, null, ['status' => $status]);
        return ['id' => $tripId, 'status' => $status, 'affected_bookings' => $affectedBookings];
    }

    /** @return array<string, mixed> */
    public function deleteTrip(array $actor, int $tripId): array
    {
        $this->assertPermission($actor, 'manage_trips');
        $pdo = $this->database->pdo();
        $trip = $this->one($pdo, 'SELECT id, company_id, trip_number FROM trips WHERE id = :id', ['id' => $tripId]);
        if ($trip === null) { Response::error('الرحلة غير موجودة.', 'NOT_FOUND', 404); }
        $companyId = $this->companyScope($actor, $trip['company_id']);
        if ((int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM bookings WHERE trip_id = :id', ['id' => $tripId])['total'] > 0) { Response::error('لا يمكن حذف رحلة مرتبطة بحجز. أوقفها أو ألغها بدلًا من الحذف.', 'DEPENDENCY_EXISTS', 409); }
        $this->audit->log((int) $actor['id'], (int) $companyId, 'trip_deleted', 'trip', $tripId, $trip, []);
        $pdo->prepare('DELETE FROM trips WHERE id = :id')->execute(['id' => $tripId]);
        return ['id' => $tripId, 'deleted' => true];
    }

    public function updateCountry(array $actor, int $countryId, array $input): array
    {
        $this->assertSuperAdmin($actor);
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        $name = Security::cleanText($input['name_ar'] ?? '', 120);
        $phoneCode = trim((string) ($input['phone_code'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $code) || mb_strlen($name) < 2) { Response::error('رمز الدولة أو اسمها غير صالح.', 'VALIDATION_ERROR', 422); }
        if ($this->one($this->database->pdo(), 'SELECT id FROM countries WHERE code = :code AND id <> :id LIMIT 1', ['code' => $code, 'id' => $countryId]) !== null) { Response::error('رمز الدولة مسجل مسبقًا.', 'DUPLICATE_COUNTRY', 409); }
        $this->database->pdo()->prepare('UPDATE countries SET code = :code, name_ar = :name_ar, phone_code = :phone_code WHERE id = :id')->execute(['code' => $code, 'name_ar' => $name, 'phone_code' => $phoneCode !== '' ? Security::cleanText($phoneCode, 10) : null, 'id' => $countryId]);
        return ['id' => $countryId, 'code' => $code, 'name_ar' => $name];
    }

    public function deleteCountry(array $actor, int $countryId): array
    {
        $this->assertSuperAdmin($actor);
        $pdo = $this->database->pdo();
        if ($this->one($pdo, 'SELECT id FROM countries WHERE id = :id', ['id' => $countryId]) === null) { Response::error('الدولة غير موجودة.', 'NOT_FOUND', 404); }
        foreach ([['cities', 'المدن'], ['companies', 'الشركات'], ['customers', 'حسابات العملاء'], ['agents', 'حسابات الوكلاء']] as [$table, $label]) {
            if ((int) $this->one($pdo, "SELECT COUNT(*) AS total FROM {$table} WHERE country_id = :id", ['id' => $countryId])['total'] > 0) { Response::error("لا يمكن حذف الدولة المرتبطة بـ{$label}. أوقفها بدلًا من الحذف.", 'DEPENDENCY_EXISTS', 409); }
        }
        $pdo->prepare('DELETE FROM countries WHERE id = :id')->execute(['id' => $countryId]);
        return ['id' => $countryId, 'deleted' => true];
    }

    /** @return array<string, mixed> */
    public function createReference(array $actor, string $type, array $input): array
    {
        if ($type === 'stations') {
            $this->assertPermission($actor, 'manage_routes');
        } elseif (!in_array('super_admin', $actor['roles'], true)) {
            Response::error('إدارة البيانات المرجعية متاحة للمدير الرئيسي فقط.', 'FORBIDDEN', 403);
        }
        $pdo = $this->database->pdo();
        $cleanType = strtolower($type);
        $config = match ($cleanType) {
            'countries' => [
                'sql' => 'INSERT INTO countries (code, name_ar, phone_code, is_active) VALUES (:code, :name_ar, :phone_code, 1)',
                'values' => ['code' => strtoupper(Security::cleanText($input['code'] ?? '', 2)), 'name_ar' => Security::cleanText($input['name_ar'] ?? '', 120), 'phone_code' => trim((string) ($input['phone_code'] ?? '')) !== '' ? Security::cleanText($input['phone_code'], 10) : null],
                'required' => ['code', 'name_ar'],
            ],
            'cities' => [
                'sql' => 'INSERT INTO cities (country_id, name_ar, is_active) VALUES (:country_id, :name_ar, 1)',
                'values' => ['country_id' => filter_var($input['country_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), 'name_ar' => Security::cleanText($input['name_ar'] ?? '', 140)],
                'required' => ['country_id', 'name_ar'],
            ],
            'stations' => [
                'sql' => 'INSERT INTO stations (city_id, name_ar, address, latitude, longitude, station_type, company_id, agent_id, is_active) VALUES (:city_id, :name_ar, :address, :latitude, :longitude, :station_type, :company_id, :agent_id, 1)',
                'values' => ['city_id' => filter_var($input['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), 'name_ar' => Security::cleanText($input['name_ar'] ?? '', 180), 'address' => trim((string) ($input['address'] ?? '')) !== '' ? Security::cleanText($input['address'], 400) : null, 'latitude' => is_numeric($input['latitude'] ?? null) ? $input['latitude'] : null, 'longitude' => is_numeric($input['longitude'] ?? null) ? $input['longitude'] : null, 'station_type' => (($input['station_type'] ?? 'company') === 'agent' ? 'agent' : 'company'), 'company_id' => (($input['station_type'] ?? 'company') === 'company' ? filter_var($input['owner_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null), 'agent_id' => (($input['station_type'] ?? 'company') === 'agent' ? filter_var($input['owner_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null)],
                'required' => ['city_id', 'name_ar', 'station_type', 'company_id_or_agent_id'],
            ],
            'currencies' => [
                'sql' => 'INSERT INTO currencies (code, name_ar, symbol_ar, decimal_places, is_default, is_active) VALUES (:code, :name_ar, :symbol_ar, :decimal_places, :is_default, 1)',
                'values' => ['code' => strtoupper(Security::cleanText($input['code'] ?? '', 3)), 'name_ar' => Security::cleanText($input['name_ar'] ?? '', 120), 'symbol_ar' => Security::cleanText($input['symbol_ar'] ?? '', 16), 'decimal_places' => filter_var($input['decimal_places'] ?? 2, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]), 'is_default' => filter_var($input['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0],
                'required' => ['code', 'name_ar', 'symbol_ar', 'decimal_places'],
            ],
            'exchange-rates' => [
                'sql' => 'INSERT INTO exchange_rates (base_currency_id, quote_currency_id, rate, effective_at, expires_at, is_active, created_by) VALUES (:base_currency_id, :quote_currency_id, :rate, :effective_at, :expires_at, 1, :created_by)',
                'values' => ['base_currency_id' => filter_var($input['base_currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), 'quote_currency_id' => filter_var($input['quote_currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]), 'rate' => filter_var($input['rate'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.00000001]]), 'effective_at' => $input['effective_at'] ?? date('Y-m-d H:i:s'), 'expires_at' => $input['expires_at'] ?? null, 'created_by' => $actor['id']],
                'required' => ['base_currency_id', 'quote_currency_id', 'rate'],
            ],
            default => null,
        };
        if ($config === null) { Response::error('نوع البيانات المرجعية غير مدعوم.', 'VALIDATION_ERROR', 422); }
        if ($cleanType === 'stations') {
            $coordinates = $this->coordinates($input);
            $config['values']['latitude'] = $coordinates['latitude'];
            $config['values']['longitude'] = $coordinates['longitude'];
            $ownerType = (string) $config['values']['station_type'];
            $ownerId = $ownerType === 'company' ? $config['values']['company_id'] : $config['values']['agent_id'];
            if ($ownerId === false || $ownerId === null) { Response::error('اختر الشركة أو الوكيل المرتبط بالمحطة.', 'VALIDATION_ERROR', 422); }
            if ($ownerType === 'company' && $this->one($pdo, 'SELECT id FROM companies WHERE id = :id AND status = \'active\'', ['id' => $ownerId]) === null) { Response::error('الشركة المرتبطة بالمحطة غير موجودة أو غير نشطة.', 'VALIDATION_ERROR', 422); }
            if ($ownerType === 'agent' && $this->one($pdo, 'SELECT id FROM agents WHERE id = :id AND status = \'active\'', ['id' => $ownerId]) === null) { Response::error('الوكيل المرتبط بالمحطة غير موجود أو غير نشط.', 'VALIDATION_ERROR', 422); }
        }
        if ($cleanType === 'currencies' && (int) ($config['values']['is_default'] ?? 0) === 1) {
            $pdo->exec('UPDATE currencies SET is_default = 0 WHERE is_default = 1');
        }
        foreach ($config['required'] as $field) {
            if ($field === 'company_id_or_agent_id') { continue; }
            if (($config['values'][$field] ?? null) === null || ($config['values'][$field] ?? '') === '') { Response::error('بيانات مرجعية ناقصة أو غير صالحة.', 'VALIDATION_ERROR', 422); }
        }
        if ($cleanType === 'exchange-rates') {
            $default = $this->one($pdo, 'SELECT id FROM currencies WHERE is_default = 1 AND is_active = 1 LIMIT 1');
            if ($default === null) { Response::error('حدد عملة افتراضية نشطة أولًا لتكون مرجع أسعار الصرف.', 'DEFAULT_CURRENCY_REQUIRED', 422); }
            if ((int) $config['values']['base_currency_id'] !== (int) $default['id']) { Response::error('يجب أن تكون العملة الأساسية هي العملة الافتراضية المحددة.', 'DEFAULT_CURRENCY_ONLY', 422); }
            $quote = $this->one($pdo, 'SELECT id, is_active FROM currencies WHERE id = :id LIMIT 1', ['id' => $config['values']['quote_currency_id']]);
            if ($quote === null || (int) $quote['is_active'] !== 1 || (int) $quote['id'] === (int) $default['id']) { Response::error('عملة التسعير غير صالحة أو مطابقة للعملة الافتراضية.', 'INVALID_QUOTE_CURRENCY', 422); }
        }
        $statement = $pdo->prepare($config['sql']);
        $statement->execute($config['values']);
        $id = (int) $pdo->lastInsertId();
        $this->audit->log((int) $actor['id'], null, 'reference_created', $cleanType, $id, null, $config['values']);
        return ['id' => $id, 'type' => $cleanType];
    }

    /** @return list<array<string, mixed>> */
    public function references(array $actor, string $type): array
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_settings', $actor['permissions'], true)) {
            Response::error('إدارة البيانات المرجعية متاحة للمخولين فقط.', 'FORBIDDEN', 403);
        }
        $pdo = $this->database->pdo();
        if ($type === 'currencies') {
            return $this->allPdo($pdo, 'SELECT id, code, name_ar, symbol_ar, decimal_places, is_default, is_active, created_at FROM currencies ORDER BY code', []);
        }
        if ($type === 'exchange-rates') {
            return $this->allPdo($pdo, 'SELECT er.id, er.base_currency_id, er.quote_currency_id, er.rate, er.effective_at, er.expires_at, er.is_active, er.created_at, bc.code AS base_code, qc.code AS quote_code FROM exchange_rates er INNER JOIN currencies bc ON bc.id = er.base_currency_id INNER JOIN currencies qc ON qc.id = er.quote_currency_id ORDER BY er.effective_at DESC, er.id DESC LIMIT 200', []);
        }
        Response::error('نوع البيانات المرجعية غير مدعوم.', 'VALIDATION_ERROR', 422);
    }

    /** @return array<string, mixed> */
    public function updateReferenceStatus(array $actor, string $type, int $id, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_settings', $actor['permissions'], true)) {
            Response::error('تعديل البيانات المرجعية متاح للمخولين فقط.', 'FORBIDDEN', 403);
        }
        $status = (string) ($input['status'] ?? '');
        if (!in_array($status, ['active', 'inactive'], true)) { Response::error('الحالة غير صالحة.', 'VALIDATION_ERROR', 422); }
        $table = $type === 'currencies' ? 'currencies' : ($type === 'exchange-rates' ? 'exchange_rates' : '');
        if ($table === '') { Response::error('نوع البيانات المرجعية غير مدعوم.', 'VALIDATION_ERROR', 422); }
        $pdo = $this->database->pdo();
        $exists = $this->one($pdo, "SELECT id FROM {$table} WHERE id = :id", ['id' => $id]);
        if ($exists === null) { Response::error('السجل المرجعي غير موجود.', 'NOT_FOUND', 404); }
        if ($table === 'currencies' && $status === 'inactive') {
            $used = (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM companies WHERE base_currency_id = :id', ['id' => $id])['total'];
            if ($used > 0) { Response::error('لا يمكن إيقاف عملة مستخدمة في شركات قائمة.', 'DEPENDENCY_EXISTS', 409); }
        }
        $pdo->prepare("UPDATE {$table} SET is_active = :is_active WHERE id = :id")->execute(['is_active' => $status === 'active' ? 1 : 0, 'id' => $id]);
        $this->audit->log((int) $actor['id'], null, 'reference_status_updated', $type, $id, null, ['status' => $status]);
        return ['id' => $id, 'type' => $type, 'status' => $status];
    }

    public function updateReference(array $actor, string $type, int $id, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_settings', $actor['permissions'], true)) { Response::error('تعديل البيانات المرجعية متاح للمخولين فقط.', 'FORBIDDEN', 403); }
        $pdo = $this->database->pdo();
        if ($type === 'currencies') {
            $code = strtoupper(Security::cleanText($input['code'] ?? '', 3));
            $name = Security::cleanText($input['name_ar'] ?? '', 120);
            $symbol = Security::cleanText($input['symbol_ar'] ?? '', 16);
            $decimalPlaces = filter_var($input['decimal_places'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
            if (!preg_match('/^[A-Z]{3}$/', $code) || mb_strlen($name) < 2 || $symbol === '' || $decimalPlaces === false) { Response::error('بيانات العملة غير صالحة.', 'VALIDATION_ERROR', 422); }
            if ($this->one($pdo, 'SELECT id FROM currencies WHERE code = :code AND id <> :id LIMIT 1', ['code' => $code, 'id' => $id]) !== null) { Response::error('رمز العملة مسجل مسبقًا.', 'DUPLICATE_CURRENCY', 409); }
            if ($this->one($pdo, 'SELECT id FROM currencies WHERE id = :id', ['id' => $id]) === null) { Response::error('العملة غير موجودة.', 'NOT_FOUND', 404); }
            $isDefault = filter_var($input['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $currentCurrency = $this->one($pdo, 'SELECT id, is_default FROM currencies WHERE id = :id', ['id' => $id]);
            if ($isDefault === 0 && (int) ($currentCurrency['is_default'] ?? 0) === 1) { Response::error('لا يمكن إلغاء العملة الافتراضية الوحيدة. عيّن عملة أخرى كافتراضية أولًا.', 'DEFAULT_CURRENCY_REQUIRED', 422); }
            if ($isDefault === 1) $pdo->exec('UPDATE currencies SET is_default = 0 WHERE is_default = 1');
            $pdo->prepare('UPDATE currencies SET code = :code, name_ar = :name_ar, symbol_ar = :symbol_ar, decimal_places = :decimal_places, is_default = :is_default WHERE id = :id')->execute(['code' => $code, 'name_ar' => $name, 'symbol_ar' => $symbol, 'decimal_places' => $decimalPlaces, 'is_default' => $isDefault, 'id' => $id]);
            return ['id' => $id, 'type' => $type];
        }
        if ($type === 'exchange-rates') {
            $base = filter_var($input['base_currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $quote = filter_var($input['quote_currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $rate = filter_var($input['rate'] ?? null, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0.00000001]]);
            if ($base === false || $quote === false || $rate === false || $base === $quote) { Response::error('بيانات سعر الصرف غير صالحة.', 'VALIDATION_ERROR', 422); }
            $default = $this->one($pdo, 'SELECT id FROM currencies WHERE is_default = 1 AND is_active = 1 LIMIT 1');
            if ($default === null || (int) $default['id'] !== (int) $base) { Response::error('يجب أن تكون العملة الأساسية هي العملة الافتراضية المحددة.', 'DEFAULT_CURRENCY_ONLY', 422); }
            $quoteCurrency = $this->one($pdo, 'SELECT id, is_active FROM currencies WHERE id = :id LIMIT 1', ['id' => $quote]);
            if ($quoteCurrency === null || (int) $quoteCurrency['is_active'] !== 1) { Response::error('عملة التسعير غير موجودة أو موقوفة.', 'INVALID_QUOTE_CURRENCY', 422); }
            $pdo->prepare('UPDATE exchange_rates SET base_currency_id = :base, quote_currency_id = :quote, rate = :rate, effective_at = :effective_at, expires_at = :expires_at WHERE id = :id')->execute(['base' => $base, 'quote' => $quote, 'rate' => $rate, 'effective_at' => $input['effective_at'] ?? date('Y-m-d H:i:s'), 'expires_at' => ($input['expires_at'] ?? '') !== '' ? $input['expires_at'] : null, 'id' => $id]);
            return ['id' => $id, 'type' => $type];
        }
        Response::error('نوع البيانات المرجعية غير مدعوم.', 'VALIDATION_ERROR', 422);
    }

    public function deleteReference(array $actor, string $type, int $id): array
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array('manage_settings', $actor['permissions'], true)) { Response::error('حذف البيانات المرجعية متاح للمخولين فقط.', 'FORBIDDEN', 403); }
        $pdo = $this->database->pdo();
        if ($type === 'currencies') {
            $used = (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM companies WHERE base_currency_id = :id', ['id' => $id])['total'];
            $rates = (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM exchange_rates WHERE base_currency_id = :id OR quote_currency_id = :id', ['id' => $id])['total'];
            if ($used > 0 || $rates > 0) { Response::error('لا يمكن حذف عملة مرتبطة بشركات أو أسعار صرف. أوقفها بدلًا من حذفها.', 'DEPENDENCY_EXISTS', 409); }
            $table = 'currencies';
        } elseif ($type === 'exchange-rates') { $table = 'exchange_rates'; }
        else { Response::error('نوع البيانات المرجعية غير مدعوم.', 'VALIDATION_ERROR', 422); }
        if ($this->one($pdo, "SELECT id FROM {$table} WHERE id = :id", ['id' => $id]) === null) { Response::error('السجل المرجعي غير موجود.', 'NOT_FOUND', 404); }
        $pdo->prepare("DELETE FROM {$table} WHERE id = :id")->execute(['id' => $id]);
        return ['id' => $id, 'type' => $type, 'deleted' => true];
    }

    /** @return array<string, mixed> */
    public function assignUserRole(array $actor, int $userId, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) {
            Response::error('إدارة الأدوار متاحة للمدير الرئيسي فقط.', 'FORBIDDEN', 403);
        }
        $roleId = filter_var($input['role_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $companyId = $input['company_id'] ?? null;
        $companyId = $companyId === '' || $companyId === null ? null : filter_var($companyId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($roleId === false || $companyId === false) { Response::error('بيانات الدور أو الشركة غير صالحة.', 'VALIDATION_ERROR', 422); }
        $pdo = $this->database->pdo();
        if ($this->one($pdo, 'SELECT id FROM users WHERE id = :id', ['id' => $userId]) === null || $this->one($pdo, 'SELECT id FROM roles WHERE id = :id', ['id' => $roleId]) === null) {
            Response::error('المستخدم أو الدور غير موجود.', 'NOT_FOUND', 404);
        }
        if ($companyId !== null && $this->one($pdo, 'SELECT id FROM companies WHERE id = :id', ['id' => $companyId]) === null) {
            Response::error('الشركة غير موجودة.', 'NOT_FOUND', 404);
        }
        $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id, company_id) VALUES (:user_id, :role_id, :company_id)')->execute(['user_id' => $userId, 'role_id' => $roleId, 'company_id' => $companyId]);
        $this->audit->log((int) $actor['id'], $companyId, 'user_role_assigned', 'user', $userId, null, ['role_id' => $roleId, 'company_id' => $companyId]);
        return ['user_id' => $userId, 'role_id' => $roleId, 'company_id' => $companyId];
    }

    /** @return array{role_id:int,permission_ids:list<int>} */
    public function updateRolePermissions(array $actor, int $roleId, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) {
            Response::error('إدارة صلاحيات الأدوار متاحة للمدير الرئيسي فقط.', 'FORBIDDEN', 403);
        }
        $ids = $input['permission_ids'] ?? [];
        if (!is_array($ids)) { Response::error('قائمة الصلاحيات غير صالحة.', 'VALIDATION_ERROR', 422); }
        $permissionIds = array_values(array_unique(array_filter(array_map(static fn(mixed $id): int => (int) $id, $ids), static fn(int $id): bool => $id > 0)));
        $pdo = $this->database->pdo();
        if ($this->one($pdo, 'SELECT id FROM roles WHERE id = :id', ['id' => $roleId]) === null) {
            Response::error('الدور غير موجود.', 'NOT_FOUND', 404);
        }
        if ($permissionIds !== []) {
            $holders = implode(',', array_fill(0, count($permissionIds), '?'));
            $valid = $pdo->prepare("SELECT id FROM permissions WHERE id IN ({$holders})");
            $valid->execute($permissionIds);
            if (count($valid->fetchAll()) !== count($permissionIds)) { Response::error('تتضمن القائمة صلاحية غير موجودة.', 'VALIDATION_ERROR', 422); }
        }
        $this->database->transaction(function (PDO $transaction) use ($roleId, $permissionIds): void {
            $transaction->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
            if ($permissionIds === []) { return; }
            $insert = $transaction->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)');
            foreach ($permissionIds as $permissionId) { $insert->execute(['role_id' => $roleId, 'permission_id' => $permissionId]); }
        });
        $this->audit->log((int) $actor['id'], null, 'role_permissions_updated', 'role', $roleId, null, ['permission_ids' => $permissionIds]);
        return ['role_id' => $roleId, 'permission_ids' => $permissionIds];
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function catalog(array $actor): array
    {
        $pdo = $this->database->pdo();
        $isSuper = in_array('super_admin', $actor['roles'], true);
        $catalog = [
            'countries' => $this->allPdo($pdo, $isSuper ? 'SELECT id, code, name_ar, phone_code, is_active FROM countries ORDER BY name_ar' : 'SELECT id, code, name_ar, phone_code, is_active FROM countries WHERE is_active = 1 ORDER BY name_ar', []),
            'cities' => $this->allPdo($pdo, $isSuper ? 'SELECT id, country_id, name_ar, is_active FROM cities ORDER BY name_ar' : 'SELECT id, country_id, name_ar, is_active FROM cities WHERE is_active = 1 ORDER BY name_ar', []),
            'currencies' => $this->allPdo($pdo, 'SELECT id, code, name_ar FROM currencies WHERE is_active = 1 ORDER BY code', []),
        ];
        if (!$isSuper) { return $catalog; }
        return $catalog + [
            'users' => $this->allPdo($pdo, "SELECT u.id, u.full_name, u.username, u.email, u.phone, u.status, u.created_at,
                COALESCE((SELECT GROUP_CONCAT(DISTINCT r.code ORDER BY r.code SEPARATOR ', ') FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id), '') AS role_codes,
                COALESCE((SELECT GROUP_CONCAT(DISTINCT r.name_ar ORDER BY r.name_ar SEPARATOR '، ') FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id), '') AS role_names,
                COALESCE((SELECT GROUP_CONCAT(DISTINCT CAST(ur.company_id AS CHAR) ORDER BY ur.company_id SEPARATOR ',') FROM user_roles ur WHERE ur.user_id = u.id AND ur.company_id IS NOT NULL), '') AS company_ids,
                (SELECT COUNT(DISTINCT p.id) FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id = ur.role_id INNER JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = u.id) AS permission_count
                FROM users u ORDER BY u.full_name", []),
            'roles' => $this->allPdo($pdo, 'SELECT id, code, name_ar FROM roles ORDER BY id', []),
            'permissions' => $this->allPdo($pdo, 'SELECT id, code, name_ar, module_code FROM permissions ORDER BY module_code, name_ar', []),
            'role_permissions' => $this->allPdo($pdo, 'SELECT role_id, permission_id FROM role_permissions ORDER BY role_id, permission_id', []),
            'companies' => $this->allPdo($pdo, 'SELECT id, trade_name FROM companies ORDER BY trade_name', []),
            'stations' => $this->allPdo($pdo, 'SELECT s.id, s.city_id, s.name_ar, c.name_ar AS city_name FROM stations s INNER JOIN cities c ON c.id = s.city_id WHERE s.is_active = 1 ORDER BY c.name_ar, s.name_ar', []),
            'exchange_rates' => $this->allPdo($pdo, 'SELECT er.id, bc.code AS base_code, qc.code AS quote_code, er.rate, er.effective_at FROM exchange_rates er INNER JOIN currencies bc ON bc.id = er.base_currency_id INNER JOIN currencies qc ON qc.id = er.quote_currency_id WHERE er.is_active = 1 ORDER BY er.effective_at DESC LIMIT 50', []),
        ];
    }

    /** @return array<string, mixed> */
    public function createCompany(array $actor, array $input): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) {
            Response::error('إنشاء شركة جديدة متاح للمدير الرئيسي فقط.', 'FORBIDDEN', 403);
        }
        $legal = Security::cleanText($input['legal_name'] ?? null, 220);
        $trade = Security::cleanText($input['trade_name'] ?? null, 180);
        $countryId = filter_var($input['country_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cityId = filter_var($input['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $currencyId = filter_var($input['base_currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (mb_strlen($legal) < 3 || mb_strlen($trade) < 3 || $countryId === false || $cityId === false || $currencyId === false) {
            Response::error('بيانات الشركة الأساسية غير صالحة.', 'VALIDATION_ERROR', 422);
        }
        $coordinates = $this->coordinates($input);
        $address = trim((string) ($input['address'] ?? '')) !== '' ? Security::cleanText($input['address'], 500) : null;
        $statement = $this->database->pdo()->prepare('INSERT INTO companies (legal_name, trade_name, country_id, city_id, address, base_currency_id, phone, email, latitude, longitude, status) VALUES (:legal_name, :trade_name, :country_id, :city_id, :address, :base_currency_id, :phone, :email, :latitude, :longitude, \'active\')');
        $phone = trim((string) ($input['phone'] ?? '')) !== '' ? Security::cleanText($input['phone'], 40) : null;
        $statement->execute(['legal_name' => $legal, 'trade_name' => $trade, 'country_id' => $countryId, 'city_id' => $cityId, 'address' => $address, 'base_currency_id' => $currencyId, 'phone' => $phone, 'email' => filter_var($input['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null, 'latitude' => $coordinates['latitude'], 'longitude' => $coordinates['longitude']]);
        $id = (int) $this->database->pdo()->lastInsertId();
        $this->audit->log((int) $actor['id'], $id, 'company_created', 'company', $id, null, ['trade_name' => $trade]);
        return ['id' => $id, 'legal_name' => $legal, 'trade_name' => $trade, 'status' => 'active'];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $file @return array<string, string> */
    public function uploadCompanyMedia(array $actor, int $companyId, string $slot, array $file): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) {
            Response::error('رفع وسائط الشركة متاح للمدير الرئيسي فقط.', 'FORBIDDEN', 403);
        }
        if (!in_array($slot, ['logo', 'cover'], true)) { Response::error('نوع وسائط الشركة غير صالح.', 'VALIDATION_ERROR', 422); }
        $company = $this->one($this->database->pdo(), 'SELECT id FROM companies WHERE id = :id', ['id' => $companyId]);
        if ($company === null) { Response::error('الشركة غير موجودة.', 'NOT_FOUND', 404); }
        $path = $this->storeImage($file, 'companies/' . $companyId, $slot);
        $column = $slot === 'logo' ? 'logo_path' : 'cover_image_path';
        $this->database->pdo()->prepare("UPDATE companies SET {$column} = :path WHERE id = :id")->execute(['path' => $path, 'id' => $companyId]);
        $this->audit->log((int) $actor['id'], $companyId, $slot === 'logo' ? 'company_logo_updated' : 'company_cover_updated', 'company', $companyId, null, [$column => $path]);
        return ['slot' => $slot, 'path' => $path];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $file @return array<string, mixed> */
    public function uploadCompanyGalleryImage(array $actor, int $companyId, int $imageOrder, array $file): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) {
            Response::error('رفع معرض الشركة متاح للمدير الرئيسي فقط.', 'FORBIDDEN', 403);
        }
        if ($imageOrder < 1 || $imageOrder > 6) {
            Response::error('ترتيب صورة المعرض يجب أن يكون بين 1 و6.', 'VALIDATION_ERROR', 422);
        }
        $company = $this->one($this->database->pdo(), 'SELECT id FROM companies WHERE id = :id', ['id' => $companyId]);
        if ($company === null) { Response::error('الشركة غير موجودة.', 'NOT_FOUND', 404); }
        $path = $this->storeImage($file, 'companies/' . $companyId . '/gallery', 'image-' . $imageOrder);
        $pdo = $this->database->pdo();
        $statement = $pdo->prepare('SELECT id, image_path FROM company_images WHERE company_id = :company_id AND image_order = :image_order LIMIT 1');
        $statement->execute(['company_id' => $companyId, 'image_order' => $imageOrder]);
        $existing = $statement->fetch();
        if (is_array($existing)) {
            $update = $pdo->prepare('UPDATE company_images SET image_path = :path, status = \'active\' WHERE id = :id');
            $update->execute(['path' => $path, 'id' => $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $insert = $pdo->prepare('INSERT INTO company_images (company_id, image_path, image_order) VALUES (:company_id, :path, :image_order)');
            $insert->execute(['company_id' => $companyId, 'path' => $path, 'image_order' => $imageOrder]);
            $id = (int) $pdo->lastInsertId();
        }
        $this->audit->log((int) $actor['id'], $companyId, 'company_gallery_updated', 'company', $companyId, null, ['image_id' => $id, 'image_order' => $imageOrder, 'image_path' => $path]);
        return ['id' => $id, 'company_id' => $companyId, 'image_path' => $path, 'image_order' => $imageOrder, 'status' => 'active'];
    }

    /** @return list<array<string, mixed>> */
    public function companyGallery(int $companyId): array
    {
        return $this->all($this->database->pdo(), 'SELECT id, company_id, image_path, image_order, status, created_at, updated_at FROM company_images WHERE company_id = :company_id ORDER BY image_order', ['company_id' => $companyId]);
    }

    public function assertCompanyGalleryAccess(array $actor, int $companyId): void
    {
        if (in_array('super_admin', $actor['roles'], true)) { return; }
        if ((int) ($actor['company_id'] ?? 0) !== $companyId || !in_array('manage_companies', $actor['permissions'], true)) {
            Response::error('لا تملك صلاحية عرض معرض هذه الشركة.', 'FORBIDDEN', 403);
        }
    }

    public function deleteCompanyGalleryImage(array $actor, int $imageId): array
    {
        if (!in_array('super_admin', $actor['roles'], true)) {
            Response::error('حذف صور المعرض متاح للمدير الرئيسي فقط.', 'FORBIDDEN', 403);
        }
        $image = $this->one($this->database->pdo(), 'SELECT id, company_id, image_order, image_path FROM company_images WHERE id = :id', ['id' => $imageId]);
        if ($image === null) { Response::error('صورة المعرض غير موجودة.', 'NOT_FOUND', 404); }
        $this->database->pdo()->prepare('DELETE FROM company_images WHERE id = :id')->execute(['id' => $imageId]);
        $this->audit->log((int) $actor['id'], (int) $image['company_id'], 'company_gallery_deleted', 'company', (int) $image['company_id'], null, ['image_id' => $imageId, 'image_order' => $image['image_order']]);
        return ['id' => $imageId, 'company_id' => (int) $image['company_id'], 'image_order' => (int) $image['image_order']];
    }

    /** @param array<string, mixed> $actor @param array<string, mixed> $file @return array<string, string> */
    public function uploadBusMedia(array $actor, int $busId, string $slot, array $file): array
    {
        if (!in_array($slot, ['interior', 'exterior'], true)) { Response::error('نوع صورة الباص غير صالح.', 'VALIDATION_ERROR', 422); }
        $bus = $this->one($this->database->pdo(), 'SELECT id, company_id FROM buses WHERE id = :id', ['id' => $busId]);
        if ($bus === null) { Response::error('الباص غير موجود.', 'NOT_FOUND', 404); }
        $this->assertPermission($actor, 'manage_buses');
        $companyId = $this->companyScope($actor, $bus['company_id']);
        $path = $this->storeImage($file, 'buses/' . $busId, $slot);
        $column = $slot === 'interior' ? 'interior_image_path' : 'exterior_image_path';
        $this->database->pdo()->prepare("UPDATE buses SET {$column} = :path WHERE id = :id")->execute(['path' => $path, 'id' => $busId]);
        $this->audit->log((int) $actor['id'], (int) $companyId, 'bus_media_updated', 'bus', $busId, null, ['slot' => $slot, 'path' => $path]);
        return ['slot' => $slot, 'path' => $path];
    }

    /** @param array<string, mixed> $file */
    private function storeImage(array $file, string $folder, string $baseName): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            Response::error('تعذر قراءة ملف الصورة المرفوع.', 'UPLOAD_ERROR', 422);
        }
        if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
            Response::error('حجم الصورة يجب أن يكون حتى 5 ميغابايت.', 'UPLOAD_SIZE', 422);
        }
        $image = @getimagesize((string) $file['tmp_name']);
        $mime = (string) ($image['mime'] ?? '');
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($extensions[$mime]) || (int) ($image[0] ?? 0) > 5000 || (int) ($image[1] ?? 0) > 5000) {
            Response::error('استخدم صورة JPG أو PNG أو WEBP بأبعاد مناسبة.', 'UPLOAD_TYPE', 422);
        }
        $root = dirname(__DIR__) . '/uploads';
        $directory = $root . '/' . $folder;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            Response::error('تعذر تجهيز مجلد الصور.', 'UPLOAD_STORAGE', 500);
        }
        $htaccess = $root . '/.htaccess';
        if (!file_exists($htaccess)) { @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|php[0-9]*)$\">\nRequire all denied\n</FilesMatch>\n"); }
        $relative = 'uploads/' . $folder . '/' . $baseName . '.' . $extensions[$mime];
        if (!move_uploaded_file((string) $file['tmp_name'], dirname(__DIR__) . '/' . $relative)) {
            Response::error('تعذر حفظ الصورة على الخادم.', 'UPLOAD_STORAGE', 500);
        }
        return $relative;
    }

    /** @return array{agents:list<array<string,mixed>>,customers:list<array<string,mixed>>} */
    public function people(array $actor): array
    {
        $pdo = $this->database->pdo();
        $isSuper = in_array('super_admin', $actor['roles'], true);
        if (!$isSuper && !in_array('manage_users', $actor['permissions'], true) && !in_array('manage_agents', $actor['permissions'], true)) {
            Response::error('لا تملك صلاحية إدارة الوكلاء أو العملاء.', 'FORBIDDEN', 403);
        }
        $agentWhere = $isSuper ? '' : ' WHERE a.company_id = :company_id';
        $agentParams = $isSuper ? [] : ['company_id' => (int) ($actor['company_id'] ?? 0)];
        $agents = $this->allPdo($pdo, "SELECT a.id, a.user_id, a.agent_code, a.company_id, a.country_id, a.latitude, a.longitude, u.username, a.commission_type, a.commission_value, a.status AS agent_status, a.credit_enabled, a.block_at_minimum_balance, (SELECT w.currency_id FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS wallet_currency_id, (SELECT w.credit_limit FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS credit_limit, (SELECT w.minimum_balance FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS minimum_balance, (SELECT w.balance FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS wallet_balance, (SELECT w.used_debt FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS used_debt, (SELECT w.balance + GREATEST(0, w.credit_limit - w.used_debt) FROM agent_wallets w WHERE w.agent_id = a.id ORDER BY w.id LIMIT 1) AS booking_available, u.full_name, u.email, u.phone, u.status AS user_status, co.trade_name AS company_name, c.name_ar AS country_name FROM agents a INNER JOIN users u ON u.id = a.user_id INNER JOIN companies co ON co.id = a.company_id INNER JOIN countries c ON c.id = a.country_id{$agentWhere} ORDER BY u.full_name", $agentParams);
        $customers = $this->allPdo($pdo, 'SELECT c.id, c.user_id, c.country_id, c.city_id, u.full_name, u.email, u.phone, u.status AS user_status, co.name_ar AS country_name, ci.name_ar AS city_name FROM customers c INNER JOIN users u ON u.id = c.user_id INNER JOIN countries co ON co.id = c.country_id LEFT JOIN cities ci ON ci.id = c.city_id ORDER BY u.full_name', []);
        return ['agents' => $agents, 'customers' => $customers];
    }

    public function createAdminUser(array $actor, array $input): array
    {
        $this->assertSuperAdmin($actor);
        $fullName = Security::cleanText($input['full_name'] ?? null, 180);
        $username = strtolower(trim((string) ($input['username'] ?? '')));
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phoneRaw = trim((string) ($input['phone'] ?? ''));
        $phone = $phoneRaw === '' ? null : Security::cleanText($phoneRaw, 32);
        $password = (string) ($input['password'] ?? '');
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');
        $status = (string) ($input['status'] ?? 'active');
        $roleCode = (string) ($input['role_code'] ?? '');
        $companyId = filter_var($input['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $allowedRoles = ['company_admin', 'booking_officer', 'accountant', 'support'];
        if (mb_strlen($fullName) < 3 || !preg_match('/^[a-z0-9_.-]{3,80}$/', $username) || $email === false || $passwordConfirmation !== $password || !in_array($status, ['active', 'suspended'], true) || !in_array($roleCode, $allowedRoles, true) || $companyId === false) {
            Response::error('تحقق من الاسم واسم المستخدم والبريد وكلمة المرور والدور والشركة.', 'VALIDATION_ERROR', 422);
        }
        PasswordPolicy::validate($this->database, $password);
        return $this->database->transaction(function (PDO $pdo) use ($actor, $fullName, $username, $email, $phone, $password, $status, $roleCode, $companyId): array {
            $duplicate = $this->one($pdo, 'SELECT id FROM users WHERE username = :username OR email = :email OR (:phone IS NOT NULL AND phone = :phone) LIMIT 1', ['username' => $username, 'email' => $email, 'phone' => $phone]);
            if ($duplicate !== null) { Response::error('اسم المستخدم أو البريد أو الهاتف مسجل مسبقًا.', 'DUPLICATE_ACCOUNT', 409); }
            $company = $this->one($pdo, "SELECT id, trade_name FROM companies WHERE id = :id AND status = 'active' LIMIT 1", ['id' => $companyId]);
            if ($company === null) { Response::error('الشركة المختارة غير موجودة أو غير نشطة.', 'VALIDATION_ERROR', 422); }
            $role = $this->one($pdo, 'SELECT id, code, name_ar FROM roles WHERE code = :code AND code IN (\'company_admin\',\'booking_officer\',\'accountant\',\'support\') LIMIT 1', ['code' => $roleCode]);
            if ($role === null) { Response::error('الدور الإداري المختار غير متاح.', 'VALIDATION_ERROR', 422); }
            $pdo->prepare('INSERT INTO users (full_name, username, email, phone, password_hash, status) VALUES (:full_name, :username, :email, :phone, :password_hash, :status)')->execute([
                'full_name' => $fullName, 'username' => $username, 'email' => $email, 'phone' => $phone,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => $status,
            ]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id, company_id) VALUES (:user_id, :role_id, :company_id)')->execute(['user_id' => $userId, 'role_id' => $role['id'], 'company_id' => $companyId]);
            $this->audit->log((int) $actor['id'], (int) $companyId, 'admin_user_created', 'user', $userId, null, ['role_code' => $roleCode, 'status' => $status]);
            return ['id' => $userId, 'full_name' => $fullName, 'username' => $username, 'email' => $email, 'phone' => $phone, 'status' => $status, 'role_code' => $role['code'], 'role_name' => $role['name_ar'], 'company_id' => (int) $companyId, 'company_name' => $company['trade_name']];
        });
    }

    public function createCustomer(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_users');
        $fullName = Security::cleanText($input['full_name'] ?? null, 180);
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = Security::cleanText($input['phone'] ?? null, 32);
        $password = (string) ($input['password'] ?? '');
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');
        $countryId = filter_var($input['country_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $cityId = filter_var($input['city_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (mb_strlen($fullName) < 3 || $email === false || $countryId === false || $cityId === false || $passwordConfirmation !== $password) { Response::error('تحقق من بيانات العميل وتطابق كلمتي المرور.', 'VALIDATION_ERROR', 422); }
        PasswordPolicy::validate($this->database, $password);
        $result = $this->database->transaction(function (PDO $pdo) use ($actor, $fullName, $email, $phone, $password, $countryId, $cityId): array {
            $city = $this->one($pdo, 'SELECT id FROM cities WHERE id = :id AND country_id = :country_id AND is_active = 1', ['id' => $cityId, 'country_id' => $countryId]);
            if ($city === null) { Response::error('الدولة أو المدينة المختارة غير متاحة.', 'VALIDATION_ERROR', 422); }
            $duplicate = $this->one($pdo, 'SELECT id FROM users WHERE email = :email OR phone = :phone LIMIT 1', ['email' => $email, 'phone' => $phone]);
            if ($duplicate !== null) { Response::error('البريد الإلكتروني أو رقم الهاتف مسجل مسبقًا.', 'DUPLICATE_ACCOUNT', 409); }
            $pdo->prepare('INSERT INTO users (full_name, email, phone, password_hash, status) VALUES (:full_name, :email, :phone, :password_hash, \'pending\')')->execute(['full_name' => $fullName, 'email' => $email, 'phone' => $phone, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();
            $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'customer' LIMIT 1")->fetchColumn();
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id, company_id) VALUES (:user_id, :role_id, NULL)')->execute(['user_id' => $userId, 'role_id' => $roleId]);
            $pdo->prepare('INSERT INTO customers (user_id, country_id, city_id) VALUES (:user_id, :country_id, :city_id)')->execute(['user_id' => $userId, 'country_id' => $countryId, 'city_id' => $cityId]);
            $this->audit->log((int) $actor['id'], null, 'customer_created_by_admin', 'user', $userId);
            return ['id' => $userId, 'user_id' => $userId, 'full_name' => $fullName, 'email' => $email, 'status' => 'pending'];
        });
        $this->notifications->sendToCustomerManagers('عميل جديد', "تم إنشاء حساب العميل {$result['full_name']} من لوحة الإدارة ويحتاج إلى المتابعة.", (int) $result['id']);
        return $result;
    }

    public function createAgent(array $actor, array $input): array
    {
        $this->assertPermission($actor, 'manage_agents');
        $fullName = Security::cleanText($input['full_name'] ?? null, 180);
        $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $phone = Security::cleanText($input['phone'] ?? null, 32);
        $password = (string) ($input['password'] ?? '');
        $username = Security::cleanText($input['username'] ?? null, 80);
        $agentCodeRaw = trim((string) ($input['agent_code'] ?? ''));
        $agentCode = $agentCodeRaw === '' ? null : $agentCodeRaw;
        $companyInput = filter_var($input['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $companyId = $companyInput === false || $companyInput === null ? filter_var($actor['company_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : $companyInput;
        if ($companyId === false || $companyId === null) { $companyId = (int) $this->database->pdo()->query("SELECT id FROM companies WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn(); }
        $passwordConfirmation = (string) ($input['password_confirmation'] ?? '');
        $countryId = filter_var($input['country_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $currencyId = filter_var($input['currency_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $commissionType = (string) ($input['commission_type'] ?? 'percentage');
        $commissionValue = filter_var($input['commission_value'] ?? 0, FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 0]]);
        $coordinates = $this->coordinates($input);
        if (mb_strlen($fullName) < 3 || $email === false || mb_strlen($username) < 3 || $companyId === false || $countryId === false || $currencyId === false || $commissionValue === false || !in_array($commissionType, ['percentage', 'fixed'], true) || ($agentCode !== null && !preg_match('/^[0-9]{10}$/', $agentCode)) || $passwordConfirmation !== $password) { Response::error('تحقق من بيانات الوكيل، والرمز إن وُجد، وتطابق كلمتي المرور.', 'VALIDATION_ERROR', 422); }
        PasswordPolicy::validate($this->database, $password);
        if (!in_array('super_admin', $actor['roles'], true) && (int) ($actor['company_id'] ?? 0) !== $companyId) { Response::error('لا يمكن إنشاء وكيل لشركة أخرى.', 'FORBIDDEN', 403); }
        return $this->database->transaction(function (PDO $pdo) use ($actor, $fullName, $username, $agentCode, $email, $phone, $password, $companyId, $countryId, $currencyId, $commissionType, $commissionValue, $coordinates): array {
            if ($this->one($pdo, 'SELECT id FROM companies WHERE id = :id AND status = \'active\'', ['id' => $companyId]) === null || $this->one($pdo, 'SELECT id FROM countries WHERE id = :id AND is_active = 1', ['id' => $countryId]) === null || $this->one($pdo, 'SELECT id FROM currencies WHERE id = :id AND is_active = 1', ['id' => $currencyId]) === null) { Response::error('الشركة أو الدولة أو العملة غير متاحة.', 'VALIDATION_ERROR', 422); }
            if ($this->one($pdo, 'SELECT id FROM users WHERE email = :email OR phone = :phone OR username = :username LIMIT 1', ['email' => $email, 'phone' => $phone, 'username' => $username]) !== null) { Response::error('اسم المستخدم أو البريد الإلكتروني أو رقم الهاتف مسجل مسبقًا.', 'DUPLICATE_ACCOUNT', 409); }
            if ($agentCode !== null && $this->one($pdo, 'SELECT id FROM agents WHERE agent_code = :agent_code LIMIT 1', ['agent_code' => $agentCode]) !== null) { Response::error('رمز الوكيل مستخدم مسبقًا.', 'DUPLICATE_AGENT_CODE', 409); }
            $pdo->prepare('INSERT INTO users (full_name, username, email, phone, password_hash, status) VALUES (:full_name, :username, :email, :phone, :password_hash, \'active\')')->execute(['full_name' => $fullName, 'username' => $username, 'email' => $email, 'phone' => $phone, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int) $pdo->lastInsertId();
            $roleId = (int) $pdo->query("SELECT id FROM roles WHERE code = 'agent' LIMIT 1")->fetchColumn();
            $pdo->prepare('INSERT INTO user_roles (user_id, role_id, company_id) VALUES (:user_id, :role_id, :company_id)')->execute(['user_id' => $userId, 'role_id' => $roleId, 'company_id' => $companyId]);
            $pdo->prepare('INSERT INTO agents (company_id, user_id, agent_code, country_id, latitude, longitude, commission_type, commission_value) VALUES (:company_id, :user_id, :agent_code, :country_id, :latitude, :longitude, :commission_type, :commission_value)')->execute(['company_id' => $companyId, 'user_id' => $userId, 'agent_code' => $agentCode, 'country_id' => $countryId, 'latitude' => $coordinates['latitude'], 'longitude' => $coordinates['longitude'], 'commission_type' => $commissionType, 'commission_value' => $commissionValue]);
            $agentId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO agent_wallets (agent_id, currency_id) VALUES (:agent_id, :currency_id)')->execute(['agent_id' => $agentId, 'currency_id' => $currencyId]);
            $this->audit->log((int) $actor['id'], $companyId, 'agent_created_by_admin', 'agent', $agentId);
            return ['id' => $agentId, 'user_id' => $userId, 'username' => $username, 'agent_code' => $agentCode, 'full_name' => $fullName, 'email' => $email, 'company_id' => $companyId, 'status' => 'active'];
        });
    }

    public function updatePerson(array $actor, string $type, int $id, array $input): array
    {
        $permission = $type === 'agent' ? 'manage_agents' : 'manage_users';
        $this->assertPermission($actor, $permission);
        $pdo = $this->database->pdo();
        $person = $type === 'agent' ? $this->one($pdo, 'SELECT a.id, a.user_id, a.company_id FROM agents a WHERE a.id = :id', ['id' => $id]) : $this->one($pdo, 'SELECT c.id, c.user_id, NULL AS company_id FROM customers c WHERE c.id = :id', ['id' => $id]);
        if ($person === null) { Response::error('السجل المطلوب غير موجود.', 'NOT_FOUND', 404); }
        if (!in_array('super_admin', $actor['roles'], true) && $type === 'agent' && (int) $person['company_id'] !== (int) ($actor['company_id'] ?? 0)) { Response::error('لا يمكن تعديل سجل تابع لشركة أخرى.', 'FORBIDDEN', 403); }
        $name = Security::cleanText($input['full_name'] ?? null, 180); $username = $type === 'agent' ? Security::cleanText($input['username'] ?? null, 80) : ''; $agentCodeRaw = $type === 'agent' ? trim((string) ($input['agent_code'] ?? '')) : ''; $agentCode = $agentCodeRaw === '' ? null : $agentCodeRaw; $email = filter_var(trim((string) ($input['email'] ?? '')), FILTER_VALIDATE_EMAIL); $phone = Security::cleanText($input['phone'] ?? null, 32); $password = (string) ($input['password'] ?? ''); $status = (string) ($input['status'] ?? 'active');
        $allowed = $type === 'agent' ? ['active', 'financially_blocked', 'suspended'] : ['active', 'suspended', 'pending'];
        $agentCodeValid = $agentCode === null || preg_match('/^[0-9]{10}$/', $agentCode) === 1;
        if (mb_strlen($name) < 3 || $email === false || !in_array($status, $allowed, true) || ($type === 'agent' && (mb_strlen($username) < 3 || !$agentCodeValid))) { Response::error('بيانات التعديل غير صالحة.', 'VALIDATION_ERROR', 422); }
        if ($password !== '') PasswordPolicy::validate($this->database, $password);
        if ($type === 'agent' && $this->one($pdo, 'SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1', ['username' => $username, 'id' => $person['user_id']]) !== null) { Response::error('اسم المستخدم مسجل مسبقًا.', 'DUPLICATE_ACCOUNT', 409); }
        $coordinates = $type === 'agent' ? $this->coordinates($input) : ['latitude' => null, 'longitude' => null];
        $userStatus = $status === 'financially_blocked' ? 'active' : $status;
        $userUpdate = 'UPDATE users SET full_name = :full_name, username = :username, email = :email, phone = :phone, status = :status';
        $userParams = ['full_name' => $name, 'username' => $type === 'agent' ? $username : null, 'email' => $email, 'phone' => $phone, 'status' => $userStatus, 'id' => $person['user_id']];
        if ($password !== '') { $userUpdate .= ', password_hash = :password_hash'; $userParams['password_hash'] = password_hash($password, PASSWORD_DEFAULT); }
        $pdo->prepare($userUpdate . ' WHERE id = :id')->execute($userParams);
        if ($type === 'agent') { if ($agentCode !== null && $this->one($pdo, 'SELECT id FROM agents WHERE agent_code = :agent_code AND id <> :id LIMIT 1', ['agent_code' => $agentCode, 'id' => $id]) !== null) { Response::error('رمز الوكيل مستخدم مسبقًا.', 'DUPLICATE_AGENT_CODE', 409); } $pdo->prepare('UPDATE agents SET agent_code = :agent_code, status = :status, latitude = :latitude, longitude = :longitude WHERE id = :id')->execute(['agent_code' => $agentCode, 'status' => $status, 'latitude' => $coordinates['latitude'], 'longitude' => $coordinates['longitude'], 'id' => $id]); }
        $this->audit->log((int) $actor['id'], $person['company_id'] ? (int) $person['company_id'] : null, $type . '_updated_by_admin', $type, $id);
        return ['id' => $id, 'full_name' => $name, 'username' => $type === 'agent' ? $username : null, 'email' => $email, 'status' => $status];
    }

    public function deletePerson(array $actor, string $type, int $id): array
    {
        $permission = $type === 'agent' ? 'manage_agents' : 'manage_users';
        $this->assertPermission($actor, $permission);
        $pdo = $this->database->pdo();
        $person = $type === 'agent' ? $this->one($pdo, 'SELECT id, user_id, company_id FROM agents WHERE id = :id', ['id' => $id]) : $this->one($pdo, 'SELECT id, user_id, NULL AS company_id FROM customers WHERE id = :id', ['id' => $id]);
        if ($person === null) { Response::error('السجل المطلوب غير موجود.', 'NOT_FOUND', 404); }
        if (!in_array('super_admin', $actor['roles'], true) && $type === 'agent' && (int) $person['company_id'] !== (int) ($actor['company_id'] ?? 0)) { Response::error('لا يمكن حذف وكيل تابع لشركة أخرى.', 'FORBIDDEN', 403); }
        $checks = $type === 'agent' ? [(int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM bookings WHERE agent_id = :id', ['id' => $id])['total'], (int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM agent_wallet_transactions tx INNER JOIN agent_wallets w ON w.id = tx.agent_wallet_id WHERE w.agent_id = :id', ['id' => $id])['total']] : [(int) $this->one($pdo, 'SELECT COUNT(*) AS total FROM bookings WHERE customer_id = :id', ['id' => $id])['total']];
        if (array_sum($checks) > 0) { Response::error('لا يمكن حذف الحساب لوجود حجوزات أو حركات مالية مرتبطة به. استخدم تغيير الحالة بدلًا من الحذف.', 'DEPENDENCY_EXISTS', 409); }
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $person['user_id']]);
        $this->audit->log((int) $actor['id'], $person['company_id'] ? (int) $person['company_id'] : null, $type . '_deleted_by_admin', $type, $id);
        return ['id' => $id, 'deleted' => true];
    }

    /** @return list<array<string, mixed>> */
    public function companyReviews(array $actor, ?int $requestedCompanyId = null): array
    {
        $isSuper = in_array('super_admin', $actor['roles'], true);
        $companyId = $isSuper ? (int) ($requestedCompanyId ?? 0) : (int) ($actor['company_id'] ?? 0);
        if (!$isSuper && !in_array('view_company_bookings', $actor['permissions'], true) && !in_array('manage_settings', $actor['permissions'], true)) {
            Response::error('لا تملك صلاحية مشاهدة تقييمات العملاء.', 'FORBIDDEN', 403);
        }
        if ($companyId < 1) Response::error('حدد شركة صحيحة لعرض تقييماتها.', 'VALIDATION_ERROR', 422);
        return $this->all($this->database->pdo(),
            'SELECT rv.id, rv.booking_id, rv.trip_id, rv.rating, rv.company_rating, rv.agent_rating, rv.recommendation, rv.comment, rv.status, rv.created_at,
                    b.booking_number, t.trip_number, t.departure_at, co.trade_name AS company_name,
                    cu.full_name AS customer_name, cu.email AS customer_email, cu.phone AS customer_phone,
                    au.full_name AS agent_name
             FROM trip_reviews rv
             INNER JOIN bookings b ON b.id = rv.booking_id
             INNER JOIN trips t ON t.id = rv.trip_id
             INNER JOIN companies co ON co.id = b.company_id
             INNER JOIN customers c ON c.id = rv.customer_id
             INNER JOIN users cu ON cu.id = c.user_id
             LEFT JOIN agents a ON a.id = b.agent_id
             LEFT JOIN users au ON au.id = a.user_id
             WHERE b.company_id = :company_id AND rv.status = \'published\'
             ORDER BY rv.created_at DESC, rv.id DESC',
            ['company_id' => $companyId]
        );
    }

    private function ensureAdminRbacFoundation(): void
    {
        $pdo = $this->database->pdo();
        $roles = [
            ['company_admin', 'مدير شركة', 1],
            ['booking_officer', 'موظف حجوزات', 1],
            ['accountant', 'محاسب', 1],
            ['support', 'موظف دعم', 1],
        ];
        $roleInsert = $pdo->prepare('INSERT INTO roles (code, name_ar, is_system) VALUES (:code, :name_ar, :is_system) ON DUPLICATE KEY UPDATE name_ar = VALUES(name_ar), is_system = VALUES(is_system)');
        foreach ($roles as [$code, $name, $isSystem]) { $roleInsert->execute(['code' => $code, 'name_ar' => $name, 'is_system' => $isSystem]); }
        $permissions = [
            ['manage_roles_permissions', 'إدارة الأدوار والصلاحيات', 'users'],
            ['manage_users', 'إدارة المستخدمين', 'users'],
            ['view_all_bookings', 'عرض جميع الحجوزات', 'bookings'],
            ['view_company_bookings', 'عرض حجوزات الشركة', 'bookings'],
            ['create_booking', 'إنشاء حجز', 'bookings'],
            ['confirm_booking', 'تأكيد الحجز وإصدار التذاكر', 'bookings'],
            ['reject_booking', 'رفض أو إلغاء الحجز بسبب', 'bookings'],
            ['manage_payments', 'إدارة الدفع والمبالغ الداخلية', 'finance'],
            ['view_payments', 'عرض سجل المدفوعات ومحاولاتها', 'finance'],
            ['manage_payment_settings', 'إدارة إعدادات بوابات الدفع', 'finance'],
            ['refund_payments', 'تنفيذ أو طلب استرداد المدفوعات', 'finance'],
            ['view_invoices', 'عرض الفواتير واللقطات الضريبية', 'finance'],
            ['manage_tax_settings', 'إدارة إعدادات الضريبة والفوترة', 'finance'],
            ['receive_payment', 'تأكيد استلام الدفع وتسجيل الحركة المالية', 'finance'],
            ['view_financial_reports', 'عرض التقارير المالية', 'finance'],
            ['view_reports', 'عرض التقارير التشغيلية', 'reports'],
            ['manage_trips', 'إدارة الرحلات', 'trips'],
            ['manage_routes', 'إدارة المسارات', 'routes'],
            ['manage_companies', 'إدارة الشركات', 'companies'],
            ['manage_agents', 'إدارة الوكلاء', 'agents'],
            ['manage_buses', 'إدارة الباصات', 'buses'],
            ['manage_countries', 'إدارة الدول والمدن', 'references'],
            ['manage_settings', 'إدارة الإعدادات والبيانات المرجعية', 'settings'],
            ['languages.view', 'عرض إدارة اللغات', 'languages'],
            ['languages.create', 'إضافة لغة', 'languages'],
            ['languages.update', 'تعديل اللغات', 'languages'],
            ['languages.delete', 'تعطيل اللغات', 'languages'],
            ['translations.view', 'عرض الترجمة المركزية', 'translations'],
            ['translations.update', 'تعديل الترجمات', 'translations'],
        ];
        $permissionInsert = $pdo->prepare('INSERT INTO permissions (code, name_ar, module_code) VALUES (:code, :name_ar, :module_code) ON DUPLICATE KEY UPDATE name_ar = VALUES(name_ar), module_code = VALUES(module_code)');
        foreach ($permissions as [$code, $name, $module]) { $permissionInsert->execute(['code' => $code, 'name_ar' => $name, 'module_code' => $module]); }
        $defaults = [
            'booking_officer' => ['view_company_bookings', 'create_booking', 'confirm_booking', 'reject_booking'],
            'accountant' => ['view_company_bookings', 'manage_payments', 'view_payments', 'manage_payment_settings', 'refund_payments', 'view_invoices', 'manage_tax_settings', 'receive_payment', 'view_financial_reports'],
            'support' => ['view_company_bookings', 'view_reports'],
            'company_admin' => ['view_company_bookings', 'create_booking', 'confirm_booking', 'reject_booking', 'manage_trips', 'manage_routes', 'manage_agents', 'view_reports'],
        ];
        foreach ($defaults as $roleCode => $codes) {
            foreach ($codes as $permissionCode) {
                $statement = $pdo->prepare('INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = :role_code AND p.code = :permission_code');
                $statement->execute(['role_code' => $roleCode, 'permission_code' => $permissionCode]);
            }
        }
    }

    private function ensureAgentCodeColumn(): void
    {
        $pdo = $this->database->pdo();
        if ($pdo->query("SHOW COLUMNS FROM agents LIKE 'agent_code'")->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE agents ADD COLUMN agent_code CHAR(10) NULL AFTER user_id");
            $pdo->exec("ALTER TABLE agents ADD UNIQUE KEY uq_agents_agent_code (agent_code)");
        }
    }

    private function ensureCurrencyDefaultColumn(): void
    {
        $pdo = $this->database->pdo();
        if ($pdo->query("SHOW COLUMNS FROM currencies LIKE 'is_default'")->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE currencies ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER decimal_places");
        }
    }

    private function ensureCurrencyDefaultValue(): void
    {
        $pdo = $this->database->pdo();
        $defaultIds = $this->allPdo($pdo, 'SELECT id FROM currencies WHERE is_default = 1 ORDER BY id', []);
        if ($defaultIds === []) {
            $first = $this->one($pdo, 'SELECT id FROM currencies WHERE is_active = 1 ORDER BY id LIMIT 1', []);
            if ($first !== null) {
                $pdo->prepare('UPDATE currencies SET is_default = 1 WHERE id = :id')->execute(['id' => $first['id']]);
            }
            return;
        }
        if (count($defaultIds) > 1) {
            $keep = (int) $defaultIds[0]['id'];
            $pdo->prepare('UPDATE currencies SET is_default = 0 WHERE is_default = 1 AND id <> :id')->execute(['id' => $keep]);
        }
    }

    private function assertPermission(array $actor, string $permission): void
    {
        if (!in_array('super_admin', $actor['roles'], true) && !in_array($permission, $actor['permissions'], true)) {
            Response::error('لا تملك الصلاحية اللازمة لهذه العملية.', 'FORBIDDEN', 403);
        }
    }

    private function assertSuperAdmin(array $actor): void
    {
        if (!in_array('super_admin', $actor['roles'] ?? [], true)) {
            Response::error('إدارة الدول متاحة للمدير الرئيسي فقط.', 'FORBIDDEN', 403);
        }
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function createCommonDestinationBranchRoute(PDO $pdo, array $actor, int $companyId, int $routeId, string $code, string $routeName, array $items, int $currencyId, array $originStationIds = []): array
    {
        $destinationCityId = (int) $items[0]['destination_city_id'];
        $cityIds = [];
        foreach ($items as $item) {
            $originCityId = (int) $item['origin_city_id'];
            if (!in_array($originCityId, $cityIds, true)) { $cityIds[] = $originCityId; }
        }
        if (!in_array($destinationCityId, $cityIds, true)) { $cityIds[] = $destinationCityId; }

        $baseDepartureMinutes = $this->timeToMinutes($items[0]['origin_departure_time'] ?? null);
        $stationInsert = $pdo->prepare('INSERT INTO stations (city_id, name_ar, is_active) VALUES (:city_id, :name_ar, 1)');
        $stopInsert = $pdo->prepare('INSERT INTO route_stops (route_id, station_id, stop_order, arrival_offset_minutes, departure_offset_minutes) VALUES (:route_id, :station_id, :stop_order, :arrival_offset_minutes, :departure_offset_minutes)');
        $routeStopsByCity = [];
        foreach ($cityIds as $index => $cityId) {
            $requestedStationId = filter_var($originStationIds[(string) $cityId] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $station = $requestedStationId !== false
                ? $this->one($pdo, 'SELECT s.id FROM stations s LEFT JOIN agents a ON a.id = s.agent_id WHERE s.id = :station_id AND s.city_id = :city_id AND s.is_active = 1 AND (s.company_id IS NULL OR s.company_id = :company_id OR a.company_id = :company_id)', ['station_id' => $requestedStationId, 'city_id' => $cityId, 'company_id' => $companyId])
                : $this->one($pdo, 'SELECT s.id FROM stations s WHERE s.city_id = :city_id AND s.is_active = 1 ORDER BY s.id LIMIT 1', ['city_id' => $cityId]);
            if ($requestedStationId !== false && $station === null) { Response::error('محطة الانطلاق المختارة لا تتبع المدينة المحددة أو غير نشطة.', 'VALIDATION_ERROR', 422); }
            if ($station === null) {
                $city = $this->one($pdo, 'SELECT name_ar FROM cities WHERE id = :id', ['id' => $cityId]);
                $stationInsert->execute(['city_id' => $cityId, 'name_ar' => 'محطة ' . (string) ($city['name_ar'] ?? $cityId)]);
                $station = ['id' => (int) $pdo->lastInsertId()];
            }
            $originItem = null;
            $destinationItem = null;
            foreach ($items as $item) {
                if ((int) $item['origin_city_id'] === $cityId) { $originItem = $item; }
                if ((int) $item['destination_city_id'] === $cityId) { $destinationItem = $item; }
            }
            $arrivalTime = $destinationItem['destination_arrival_time'] ?? $originItem['origin_arrival_time'] ?? null;
            $departureTime = $originItem['origin_departure_time'] ?? $destinationItem['destination_departure_time'] ?? null;
            $stopInsert->execute([
                'route_id' => $routeId,
                'station_id' => $station['id'],
                'stop_order' => $index + 1,
                'arrival_offset_minutes' => $index === 0 ? 0 : $this->timeOffset($arrivalTime, $baseDepartureMinutes),
                'departure_offset_minutes' => $index === 0 ? 0 : $this->timeOffset($departureTime, $baseDepartureMinutes),
            ]);
            $routeStopsByCity[$cityId] = ['id' => (int) $pdo->lastInsertId(), 'order' => $index + 1];
        }

        $segmentInsert = $pdo->prepare('INSERT INTO route_segments (route_id, origin_stop_id, destination_stop_id, origin_order, destination_order) VALUES (:route_id, :origin_stop_id, :destination_stop_id, :origin_order, :destination_order)');
        $linkInsert = $pdo->prepare('INSERT INTO route_subroute_links (route_id, subroute_id, route_segment_id, stop_order) VALUES (:route_id, :subroute_id, :route_segment_id, :stop_order)');
        $priceInsert = $pdo->prepare('INSERT INTO segment_prices (company_id, route_segment_id, currency_id, amount, starts_at, status, created_by) VALUES (:company_id, :route_segment_id, :currency_id, :amount, NOW(), \'active\', :created_by)');
        foreach ($items as $index => $item) {
            $origin = $routeStopsByCity[(int) $item['origin_city_id']];
            $destination = $routeStopsByCity[$destinationCityId];
            $segmentInsert->execute([
                'route_id' => $routeId,
                'origin_stop_id' => $origin['id'],
                'destination_stop_id' => $destination['id'],
                'origin_order' => $origin['order'],
                'destination_order' => $destination['order'],
            ]);
            $segmentId = (int) $pdo->lastInsertId();
            $linkInsert->execute(['route_id' => $routeId, 'subroute_id' => $item['id'], 'route_segment_id' => $segmentId, 'stop_order' => $index + 1]);
            $priceInsert->execute(['company_id' => $companyId, 'route_segment_id' => $segmentId, 'currency_id' => $currencyId, 'amount' => $item['amount'], 'created_by' => $actor['id']]);
        }
        $this->audit->log((int) $actor['id'], $companyId, 'route_composed_from_subroutes', 'route', $routeId, null, ['code' => $code, 'subroute_ids' => array_column($items, 'id'), 'currency_id' => $currencyId, 'composition' => 'common_destination_branch']);
        return ['id' => $routeId, 'company_id' => $companyId, 'code' => $code, 'name_ar' => $routeName, 'status' => 'active'];
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function orderSubroutes(array $items): array
    {
        $byOrigin = [];
        $destinations = [];
        foreach ($items as $item) {
            $origin = (int) $item['origin_city_id'];
            if (isset($byOrigin[$origin])) { Response::error('لا يمكن ربط أكثر من مسار فرعي من المدينة نفسها في المسار الرئيسي.', 'VALIDATION_ERROR', 422); }
            $byOrigin[$origin] = $item;
            $destinations[(int) $item['destination_city_id']] = true;
        }
        $start = null;
        foreach (array_keys($byOrigin) as $origin) { if (!isset($destinations[$origin])) { $start = $origin; break; } }
        if ($start === null) { Response::error('المسارات الفرعية المختارة يجب أن تشكل تسلسلًا متصلًا بلا دورة.', 'VALIDATION_ERROR', 422); }
        $ordered = [];
        while (isset($byOrigin[$start])) {
            $item = $byOrigin[$start];
            $ordered[] = $item;
            unset($byOrigin[$start]);
            $start = (int) $item['destination_city_id'];
        }
        if ($byOrigin !== []) { Response::error('المسارات الفرعية المختارة غير متصلة بين المدن.', 'VALIDATION_ERROR', 422); }
        return $ordered;
    }

    private function timeToMinutes(mixed $time): ?int
    {
        if (!is_string($time) || preg_match('/^([01]\\d|2[0-3]):([0-5]\\d)/', $time, $matches) !== 1) {
            return null;
        }
        return ((int) $matches[1] * 60) + (int) $matches[2];
    }

    /** @return array<string, string|null> */
    private function subrouteTimes(array $input): array
    {
        $times = [];
        foreach (['origin_arrival_time', 'origin_departure_time', 'destination_arrival_time', 'destination_departure_time'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '' && preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $value) !== 1) {
                Response::error('صيغة وقت الوصول أو المغادرة غير صحيحة.', 'VALIDATION_ERROR', 422);
            }
            $times[$field] = $value === '' ? null : $value . ':00';
        }
        $departureMinutes = $this->timeToMinutes($times['origin_departure_time']);
        $arrivalMinutes = $this->timeToMinutes($times['destination_arrival_time']);
        if ($departureMinutes !== null && $arrivalMinutes !== null && $departureMinutes === $arrivalMinutes) {
            Response::error('يجب أن يختلف وقت المغادرة عن وقت الحضور. يمكن للمغادرة أن تقع في اليوم التالي.', 'VALIDATION_ERROR', 422);
        }
        return $times;
    }

    private function canManageFinancialAmounts(array $actor): bool
    {
        return in_array('super_admin', $actor['roles'] ?? [], true)
            || in_array('manage_payments', $actor['permissions'] ?? [], true);
    }

    private function timeOffset(mixed $time, ?int $baseMinutes): int
    {
        $minutes = $this->timeToMinutes($time);
        if ($minutes === null || $baseMinutes === null) {
            return 0;
        }
        return ($minutes - $baseMinutes + 1440) % 1440;
    }

    /** @return array{latitude: float|null, longitude: float|null} */
    private function coordinates(array $input): array
    {
        $latitudeRaw = trim((string) ($input['latitude'] ?? ''));
        $longitudeRaw = trim((string) ($input['longitude'] ?? ''));
        if ($latitudeRaw === '' && $longitudeRaw === '') { return ['latitude' => null, 'longitude' => null]; }
        $latitude = filter_var($latitudeRaw, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($longitudeRaw, FILTER_VALIDATE_FLOAT);
        if ($latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            Response::error('تحقق من خط العرض وخط الطول؛ يجب إدخالهما معًا وبحدود جغرافية صحيحة.', 'VALIDATION_ERROR', 422);
        }
        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }

    private function ensureTripPriceChangeLogTable(): void
    {
        $this->database->pdo()->exec("CREATE TABLE IF NOT EXISTS trip_price_change_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            trip_id BIGINT UNSIGNED NOT NULL,
            route_segment_id BIGINT UNSIGNED NOT NULL,
            currency_id BIGINT UNSIGNED NOT NULL,
            previous_amount DECIMAL(14,2) NOT NULL,
            current_amount DECIMAL(14,2) NOT NULL,
            change_type ENUM('increase','decrease') NOT NULL,
            changed_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_trip_price_change_trip_segment (trip_id, route_segment_id, created_at),
            CONSTRAINT fk_trip_price_change_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
            CONSTRAINT fk_trip_price_change_segment FOREIGN KEY (route_segment_id) REFERENCES route_segments(id) ON DELETE CASCADE,
            CONSTRAINT fk_trip_price_change_currency FOREIGN KEY (currency_id) REFERENCES currencies(id),
            CONSTRAINT fk_trip_price_change_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function ensureBrokerTripColumns(): void {
        $pdo = $this->database->pdo();
        $virtual = $pdo->query("SHOW COLUMNS FROM buses LIKE 'is_virtual'")->fetchColumn();
        if ($virtual === false) { $pdo->exec('ALTER TABLE buses ADD COLUMN is_virtual TINYINT(1) NOT NULL DEFAULT 0 AFTER status'); }
        $busType = $pdo->query("SHOW COLUMNS FROM trips LIKE 'bus_type'")->fetchColumn();
        if ($busType === false) { $pdo->exec("ALTER TABLE trips ADD COLUMN bus_type VARCHAR(100) NULL AFTER trip_type"); }
        $seatCount = $pdo->query("SHOW COLUMNS FROM trips LIKE 'seat_count'")->fetchColumn();
        if ($seatCount === false) { $pdo->exec("ALTER TABLE trips ADD COLUMN seat_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER bus_type"); }
        $commissionType = $pdo->query("SHOW COLUMNS FROM trips LIKE 'agent_commission_type'")->fetchColumn();
        if ($commissionType === false) { $pdo->exec("ALTER TABLE trips ADD COLUMN agent_commission_type ENUM('percentage','fixed') NULL AFTER bus_type"); }
        $commissionValue = $pdo->query("SHOW COLUMNS FROM trips LIKE 'agent_commission_value'")->fetchColumn();
        if ($commissionValue === false) { $pdo->exec("ALTER TABLE trips ADD COLUMN agent_commission_value DECIMAL(12,4) NULL AFTER agent_commission_type"); }
    }

    private function ensureLocationColumns(): void
    {
        $pdo = $this->database->pdo();
        $companyLatitude = $pdo->query("SHOW COLUMNS FROM companies LIKE 'latitude'")->fetchColumn();
        if ($companyLatitude === false) {
            $pdo->exec('ALTER TABLE companies ADD COLUMN latitude DECIMAL(10,7) NULL AFTER address, ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude');
        }
        $agentLatitude = $pdo->query("SHOW COLUMNS FROM agents LIKE 'latitude'")->fetchColumn();
        if ($agentLatitude === false) {
            $pdo->exec('ALTER TABLE agents ADD COLUMN latitude DECIMAL(10,7) NULL AFTER country_id, ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude');
        }
    }

    private function ensureMainRouteColumns(): void
    {
        $pdo = $this->database->pdo();
        $routeType = $pdo->query("SHOW COLUMNS FROM routes LIKE 'route_type'")->fetchColumn();
        if ($routeType === false) {
            $pdo->exec("ALTER TABLE routes ADD COLUMN route_type ENUM('normal','tourist') NOT NULL DEFAULT 'normal' AFTER name_ar");
        }
        $journeyType = $pdo->query("SHOW COLUMNS FROM routes LIKE 'journey_type'")->fetchColumn();
        if ($journeyType === false) {
            $pdo->exec("ALTER TABLE routes ADD COLUMN journey_type ENUM('direct','indirect') NOT NULL DEFAULT 'direct' AFTER route_type");
        }
    }

    private function ensureStationOwnershipColumns(): void
    {
        $pdo = $this->database->pdo();
        $columns = [
            ['station_type', "ENUM('company','agent') NOT NULL DEFAULT 'company' AFTER longitude"],
            ['company_id', 'BIGINT UNSIGNED NULL AFTER station_type'],
            ['agent_id', 'BIGINT UNSIGNED NULL AFTER company_id'],
        ];
        foreach ($columns as [$column, $definition]) {
            if ($pdo->query("SHOW COLUMNS FROM stations LIKE '{$column}'")->fetchColumn() === false) {
                $pdo->exec("ALTER TABLE stations ADD COLUMN {$column} {$definition}");
            }
        }
    }

    private function ensureOperationalFinanceColumns(): void
    {
        $pdo = $this->database->pdo();
        $columns = [
            ['route_subroutes', 'company_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER currency_id'],
            ['trip_segment_prices', 'company_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER currency_id'],
            ['bookings', 'company_cost_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER commission_amount'],
            ['bookings', 'agent_commission_type', "ENUM('percentage','fixed') NULL AFTER commission_amount"],
            ['bookings', 'agent_commission_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0 AFTER agent_commission_type'],
            ['bookings', 'company_payable_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER company_cost_amount'],
            ['bookings', 'platform_commission_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER company_payable_amount'],
            ['booking_segments', 'company_unit_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER destination_name_ar'],
            ['trips', 'route_subroute_id', 'BIGINT UNSIGNED NULL AFTER route_id'],
            ['trips', 'recurrence_group', 'VARCHAR(64) NULL AFTER trip_number'],
            ['trips', 'recurrence_index', 'SMALLINT UNSIGNED NULL AFTER recurrence_group'],
        ];
        foreach ($columns as [$table, $column, $definition]) {
            $exists = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'")->fetchColumn();
            if ($exists === false) {
                $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }

    /** @return list<int> */
    private function mainRouteSubrouteIds(array $input): array
    {
        $raw = is_array($input['subroute_ids'] ?? null) ? $input['subroute_ids'] : [];
        $ids = array_values(array_filter(array_map(static fn (mixed $id): int => filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0, $raw)));
        if (count($ids) !== count(array_unique($ids))) { Response::error('لا يمكن تكرار المسار الفرعي نفسه داخل المسار الرئيسي.', 'VALIDATION_ERROR', 422); }
        return $ids;
    }

    private function mainRouteType(mixed $value): string
    {
        if (!in_array($value, ['normal', 'tourist'], true)) { Response::error('نوع المسار يجب أن يكون عاديًا أو سياحيًا.', 'VALIDATION_ERROR', 422); }
        return (string) $value;
    }

    private function mainJourneyType(mixed $value): string
    {
        if (!in_array($value, ['direct', 'indirect'], true)) { Response::error('نوع الرحلة يجب أن يكون مباشرًا أو غير مباشر.', 'VALIDATION_ERROR', 422); }
        return (string) $value;
    }

    private function validateJourneyType(string $journeyType, int $subrouteCount): void
    {
        if ($journeyType === 'direct' && $subrouteCount !== 1) { Response::error('الرحلة المباشرة يجب أن تحتوي على مسار فرعي واحد فقط.', 'VALIDATION_ERROR', 422); }
        if ($journeyType === 'indirect' && $subrouteCount < 2) { Response::error('الرحلة غير المباشرة تحتاج إلى مسارين فرعيين أو أكثر.', 'VALIDATION_ERROR', 422); }
    }

    private function mainRouteStatus(mixed $value): string
    {
        if (!in_array($value, ['active', 'inactive'], true)) { Response::error('حالة المسار الرئيسي غير صالحة.', 'VALIDATION_ERROR', 422); }
        return (string) $value;
    }

    private function nextMainRouteCode(PDO $pdo): string
    {
        $sequence = $this->one($pdo, "SELECT AUTO_INCREMENT AS next_id FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'routes'", []);
        $next = max(1, (int) ($sequence['next_id'] ?? 1));
        return 'RT-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    private function companyScope(array $actor, mixed $requestedCompanyId): ?int
    {
        if (in_array('super_admin', $actor['roles'], true)) {
            if ($requestedCompanyId === null || $requestedCompanyId === '') { return null; }
            $companyId = filter_var($requestedCompanyId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($companyId === false) { Response::error('معرّف الشركة غير صالح.', 'VALIDATION_ERROR', 422); }
            return $companyId;
        }
        if ($actor['company_id'] === null) { Response::error('المستخدم غير مرتبط بشركة تشغيلية.', 'FORBIDDEN', 403); }
        if ($requestedCompanyId !== null && $requestedCompanyId !== '' && (int) $requestedCompanyId !== (int) $actor['company_id']) {
            Response::error('لا يمكن استخدام شركة خارج نطاق صلاحياتك.', 'FORBIDDEN', 403);
        }
        return (int) $actor['company_id'];
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null */
    private function one(PDO $pdo, string $sql, array $params): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $params @return list<array<string, mixed>> */
    private function allPdo(PDO $pdo, string $sql, array $params): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $params @return list<array<string, mixed>> */
    private function all(string $sql, array $params): array
    {
        $statement = $this->database->pdo()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
