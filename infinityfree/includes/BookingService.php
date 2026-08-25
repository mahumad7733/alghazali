<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class BookingService
{
    private AuditLogger $audit;
    private NotificationService $notifications;

    public function __construct(private Database $database, private int $holdMinutes = 30)
    {
        $this->audit = new AuditLogger($database);
        $this->notifications = new NotificationService($database);
    }

    public function expirePendingBookings(): int
    {
        return $this->database->transaction(function (PDO $pdo): int {
            $expired = $pdo->query(
                "SELECT id, company_id, customer_id, agent_id FROM bookings WHERE status = 'pending' AND held_until < NOW() FOR UPDATE"
            )->fetchAll();
            if ($expired === []) {
                return 0;
            }
            $ids = array_map(static fn(array $booking): int => (int) $booking['id'], $expired);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $pdo->prepare("UPDATE bookings SET status = 'expired', cancellation_reason = 'انتهت مهلة تأكيد الحجز وتم تحرير المقاعد.', cancelled_at = NOW() WHERE id IN ({$placeholders})");
            $update->execute($ids);

            foreach ($expired as $booking) {
                $ownerId = $booking['customer_id'] ? (int) $this->scalar($pdo, 'SELECT user_id FROM customers WHERE id = :id', ['id' => $booking['customer_id']]) : null;
                if ($ownerId === null && $booking['agent_id']) {
                    $ownerId = (int) $this->scalar($pdo, 'SELECT user_id FROM agents WHERE id = :id', ['id' => $booking['agent_id']]);
                }
                if ($ownerId !== null && $ownerId > 0) {
                    $this->notifications->send($ownerId, (int) $booking['company_id'], 'booking_expired', 'انتهت مهلة الحجز', 'انتهت مهلة تأكيد الطلب وتم تحرير المقاعد المختارة.', 'booking', (int) $booking['id']);
                }
                $this->audit->log(null, (int) $booking['company_id'], 'booking_expired_on_request', 'booking', (int) $booking['id'], ['status' => 'pending'], ['status' => 'expired']);
            }
            return count($expired);
        });
    }

    /** @return array<string, mixed> */
    public function create(array $actor, array $input): array
    {
        $tripId = $this->positiveInt($input['trip_id'] ?? null);
        $segmentId = $this->positiveInt($input['segment_id'] ?? null);
        $seats = $input['seats'] ?? [];
        $passengers = $input['passengers'] ?? [];
        if (!is_array($seats) || !is_array($passengers) || count($seats) === 0 || count($seats) !== count($passengers)) {
            Response::error('يجب اختيار مقعد وإدخال بيانات كاملة لكل مسافر.', 'VALIDATION_ERROR', 422);
        }
        if (count($seats) > 8) {
            Response::error('الحد الأقصى لعدد المسافرين في الحجز الواحد هو 8.', 'VALIDATION_ERROR', 422);
        }

        $this->expirePendingBookings();
        return $this->database->transaction(function (PDO $pdo) use ($actor, $tripId, $segmentId, $seats, $passengers): array {
            $trip = $this->one($pdo,
                "SELECT t.*, rs.origin_order, rs.destination_order, tsp.amount, tsp.company_amount, tsp.currency_id FROM trips t
                 INNER JOIN route_segments rs ON rs.id = :segment_id AND rs.route_id = t.route_id AND rs.is_active = 1
                 INNER JOIN trip_segment_prices tsp ON tsp.trip_id = t.id AND tsp.route_segment_id = rs.id
                 WHERE t.id = :trip_id AND t.status = 'open' AND t.departure_at > NOW() FOR UPDATE",
                ['segment_id' => $segmentId, 'trip_id' => $tripId]
            );
            if ($trip === null) {
                Response::error('الرحلة أو المسار المطلوب غير متاح للحجز.', 'BOOKING_CLOSED', 409);
            }
            $this->assertActorCanBookForCompany($actor, (int) $trip['company_id']);
            if (!empty($actor['agent_id'])) {
                $agent = $this->one($pdo, 'SELECT status, commission_type, commission_value FROM agents WHERE id = :id FOR UPDATE', ['id' => $actor['agent_id']]);
                if ($agent === null || $agent['status'] === 'financially_blocked') {
                    Response::error('لا يمكن إنشاء حجز جديد لأن حساب الوكيل موقوف ماليًا.', 'AGENT_FINANCIALLY_BLOCKED', 403);
                }
                if ($agent['status'] !== 'active') {
                    Response::error('حساب الوكيل غير نشط.', 'FORBIDDEN', 403);
                }
            }

            $seatIds = [];
            foreach ($seats as $seat) {
                $seatCode = Security::cleanText($seat, 16);
                if (isset($seatIds[$seatCode])) {
                    Response::error('لا يمكن اختيار المقعد نفسه أكثر من مرة.', 'VALIDATION_ERROR', 422);
                }
                $seatRow = $this->one($pdo,
                    'SELECT bs.id, bs.seat_code FROM trip_seat_inventory inventory INNER JOIN bus_seats bs ON bs.id = inventory.bus_seat_id
                     WHERE inventory.trip_id = :trip_id AND bs.bus_id = :bus_id AND bs.seat_code = :seat_code AND inventory.is_available = 1 FOR UPDATE',
                    ['trip_id' => $tripId, 'bus_id' => $trip['bus_id'], 'seat_code' => $seatCode]
                );
                if ($seatRow === null) {
                    Response::error("المقعد {$seatCode} غير متاح.", 'SEAT_NOT_AVAILABLE', 409);
                }
                $conflict = $this->one($pdo,
                    "SELECT booking_seats.id FROM booking_seats
                     INNER JOIN bookings b ON b.id = booking_seats.booking_id
                     INNER JOIN booking_segments bs ON bs.booking_id = b.id
                     WHERE b.trip_id = :trip_id AND booking_seats.bus_seat_id = :seat_id AND b.status IN ('pending','confirmed')
                       AND bs.origin_stop_order < :destination_order AND bs.destination_stop_order > :origin_order
                     LIMIT 1 FOR UPDATE",
                    ['trip_id' => $tripId, 'seat_id' => $seatRow['id'], 'destination_order' => $trip['destination_order'], 'origin_order' => $trip['origin_order']]
                );
                if ($conflict !== null) {
                    Response::error("المقعد {$seatCode} محجوز في جزء متداخل من الرحلة.", 'SEAT_CONFLICT', 409);
                }
                $seatIds[$seatCode] = (int) $seatRow['id'];
            }

            $total = number_format(((float) $trip['amount']) * count($passengers), 2, '.', '');
            $companyCost = number_format(((float) $trip['company_amount']) * count($passengers), 2, '.', '');
            $commissionType = $agentId === null ? null : (string) $agent['commission_type'];
            $commissionRate = $agentId === null ? 0.0 : (float) $agent['commission_value'];
            $commission = $agentId === null ? 0.0 : ($commissionType === 'percentage' ? round(((float) $total) * ($commissionRate / 100), 2) : $commissionRate);
            $companyPayable = round(((float) $total) - $commission, 2);
            $platformCommission = round(((float) $total) - ((float) $companyCost) - $commission, 2);
            $bookingNumber = $this->newReference($pdo, 'BK');
            $heldUntil = date('Y-m-d H:i:s', time() + ($this->holdMinutes * 60));
            $customerId = !empty($actor['customer_id']) ? (int) $actor['customer_id'] : null;
            $agentId = !empty($actor['agent_id']) ? (int) $actor['agent_id'] : null;
            $source = $agentId ? 'agent' : (in_array('super_admin', $actor['roles'], true) ? 'admin' : 'website');
            $booking = $pdo->prepare(
                'INSERT INTO bookings (booking_number, company_id, trip_id, customer_id, agent_id, created_by_user_id, source, currency_id, subtotal_amount, total_amount, commission_amount, agent_commission_type, agent_commission_rate, company_cost_amount, company_payable_amount, platform_commission_amount, held_until)
                 VALUES (:booking_number, :company_id, :trip_id, :customer_id, :agent_id, :created_by_user_id, :source, :currency_id, :subtotal_amount, :total_amount, :commission_amount, :agent_commission_type, :agent_commission_rate, :company_cost_amount, :company_payable_amount, :platform_commission_amount, :held_until)'
            );
            $booking->execute([
                'booking_number' => $bookingNumber, 'company_id' => $trip['company_id'], 'trip_id' => $tripId, 'customer_id' => $customerId,
                'agent_id' => $agentId, 'created_by_user_id' => $actor['id'], 'source' => $source, 'currency_id' => $trip['currency_id'],
                'subtotal_amount' => $total, 'total_amount' => $total, 'commission_amount' => $commission, 'agent_commission_type' => $commissionType, 'agent_commission_rate' => $commissionRate, 'company_cost_amount' => $companyCost, 'company_payable_amount' => $companyPayable, 'platform_commission_amount' => $platformCommission, 'held_until' => $heldUntil,
            ]);
            $bookingId = (int) $pdo->lastInsertId();
            $this->insertBookingSegment($pdo, $bookingId, $segmentId, $trip);
            foreach (array_values($passengers) as $index => $passenger) {
                if (!is_array($passenger)) {
                    Response::error('بيانات المسافر غير صالحة.', 'VALIDATION_ERROR', 422);
                }
                $passengerId = $this->createPassenger($pdo, $customerId, $tripId, $passenger);
                $bookingPassenger = $pdo->prepare('INSERT INTO booking_passengers (booking_id, passenger_id) VALUES (:booking_id, :passenger_id)');
                $bookingPassenger->execute(['booking_id' => $bookingId, 'passenger_id' => $passengerId]);
                $bookingPassengerId = (int) $pdo->lastInsertId();
                $selectedSeat = array_values($seats)[$index];
                $seatInsert = $pdo->prepare('INSERT INTO booking_seats (booking_id, booking_passenger_id, bus_seat_id) VALUES (:booking_id, :booking_passenger_id, :bus_seat_id)');
                $seatInsert->execute(['booking_id' => $bookingId, 'booking_passenger_id' => $bookingPassengerId, 'bus_seat_id' => $seatIds[(string) $selectedSeat]]);
            }

            $this->notifications->send((int) $actor['id'], (int) $trip['company_id'], 'booking_created', 'تم إرسال طلب الحجز', "تم إنشاء طلب الحجز رقم {$bookingNumber} وهو بانتظار تأكيد الإدارة.", 'booking', $bookingId);
            $this->notifications->sendToBookingManagers((int) $trip['company_id'], 'حجز جديد يحتاج المراجعة', "وصل طلب حجز جديد رقم {$bookingNumber} ويحتاج إلى المراجعة والتأكيد.", $bookingId, (int) $actor['id']);
            $this->audit->log((int) $actor['id'], (int) $trip['company_id'], 'booking_created', 'booking', $bookingId, null, ['status' => 'pending', 'booking_number' => $bookingNumber]);
            return $this->redactInternalFinancials($this->bookingDetails($pdo, $bookingId), $actor);
        });
    }

    /** @return array<string, mixed> */
    public function confirm(array $actor, int $bookingId): array
    {
        return $this->database->transaction(function (PDO $pdo) use ($actor, $bookingId): array {
            $booking = $this->loadBookingForUpdate($pdo, $bookingId);
            $this->assertManagementScope($actor, $booking);
            if ($booking['status'] !== 'pending') {
                Response::error('لا يمكن تأكيد حجز لا يزال خارج حالة الانتظار.', 'BOOKING_ALREADY_CONFIRMED', 409);
            }
            if (strtotime((string) $booking['held_until']) < time()) {
                $this->expireSingle($pdo, $booking, 'انتهت مهلة تأكيد الحجز قبل تنفيذ قرار التأكيد.');
                Response::error('انتهت مهلة الحجز وتم تحرير المقاعد.', 'BOOKING_EXPIRED', 409);
            }

            if ($booking['agent_id'] !== null) {
                $this->chargeAgentWallet($pdo, $booking, $actor);
            }
            $update = $pdo->prepare("UPDATE bookings SET status = 'confirmed', payment_status = :payment_status, confirmed_at = NOW() WHERE id = :id");
            $update->execute(['id' => $bookingId, 'payment_status' => $booking['agent_id'] !== null ? 'paid' : 'pending']);
            $this->issueTickets($pdo, $booking);
            $ownerId = $this->bookingOwnerUserId($pdo, $booking);
            if ($ownerId !== null) {
                $this->notifications->send($ownerId, (int) $booking['company_id'], 'booking_confirmed', 'تم تأكيد الحجز', "تم تأكيد حجزك رقم {$booking['booking_number']} وإصدار التذاكر.", 'booking', $bookingId);
            }
            $this->audit->log((int) $actor['id'], (int) $booking['company_id'], 'booking_confirmed', 'booking', $bookingId, ['status' => 'pending'], ['status' => 'confirmed']);
            return $this->bookingDetails($pdo, $bookingId);
        });
    }

    /** @return array<string, mixed> */
    public function reject(array $actor, int $bookingId, string $reason): array
    {
        return $this->closeBooking($actor, $bookingId, 'rejected', $reason, 'booking_rejected', 'تم رفض الحجز');
    }

    /** @return array<string, mixed> */
    public function cancel(array $actor, int $bookingId, string $reason): array
    {
        return $this->database->transaction(function (PDO $pdo) use ($actor, $bookingId, $reason): array {
            $booking = $this->loadBookingForUpdate($pdo, $bookingId);
            $isOwner = ($booking['customer_id'] !== null && (int) $booking['customer_id'] === (int) ($actor['customer_id'] ?? 0)) || ($booking['agent_id'] !== null && (int) $booking['agent_id'] === (int) ($actor['agent_id'] ?? 0));
            if (!$isOwner && !in_array('cancel_booking', $actor['permissions'], true) && !in_array('super_admin', $actor['roles'], true)) {
                Response::error('لا تملك صلاحية إلغاء هذا الحجز.', 'FORBIDDEN', 403);
            }
            if ($booking['status'] !== 'pending') {
                Response::error('لا يمكن إلغاء الحجز بعد تأكيده أو إغلاقه.', 'BOOKING_CLOSED', 409);
            }
            return $this->closeBookingWithinTransaction($pdo, $actor, $booking, 'cancelled', $reason, 'booking_cancelled', 'تم إلغاء الحجز');
        });
    }

    /** @return list<array<string, mixed>> */
    public function listFor(array $actor): array
    {
        $this->expirePendingBookings();
        $pdo = $this->database->pdo();
        $params = [];
        $where = '1=1';
        if (in_array('super_admin', $actor['roles'], true)) {
            $where = '1=1';
        } elseif ($actor['agent_id'] !== null) {
            $where = 'b.agent_id = :agent_id'; $params['agent_id'] = $actor['agent_id'];
        } elseif ($actor['customer_id'] !== null) {
            $where = 'b.customer_id = :customer_id'; $params['customer_id'] = $actor['customer_id'];
        } elseif ($actor['company_id'] !== null && in_array('view_company_bookings', $actor['permissions'], true)) {
            $where = 'b.company_id = :company_id'; $params['company_id'] = $actor['company_id'];
        } else {
            Response::error('لا تملك صلاحية مشاهدة الحجوزات.', 'FORBIDDEN', 403);
        }
        $statement = $pdo->prepare(
            "SELECT b.id, b.booking_number, b.status, b.payment_status, b.total_amount, b.held_until, b.created_at, cu.code AS currency_code, cu.symbol_ar AS currency_symbol,
                    t.trip_number, t.departure_at, co.trade_name AS company_name
             FROM bookings b INNER JOIN currencies cu ON cu.id = b.currency_id INNER JOIN trips t ON t.id = b.trip_id INNER JOIN companies co ON co.id = b.company_id
             WHERE {$where} ORDER BY b.created_at DESC"
        );
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    public function detailsFor(array $actor, int $bookingId): array
    {
        $this->expirePendingBookings();
        $booking = $this->bookingDetails($this->database->pdo(), $bookingId);
        $isOwner = ($booking['customer_id'] !== null && (int) $booking['customer_id'] === (int) ($actor['customer_id'] ?? 0)) || ($booking['agent_id'] !== null && (int) $booking['agent_id'] === (int) ($actor['agent_id'] ?? 0));
        if (!in_array('super_admin', $actor['roles'], true) && !$isOwner && (int) ($actor['company_id'] ?? 0) !== (int) $booking['company_id']) {
            Response::error('لا يمكن الوصول إلى هذا الحجز.', 'FORBIDDEN', 403);
        }
        return $this->redactInternalFinancials($booking, $actor);
    }

    /** @return list<array<string, mixed>> */
    public function seatsForSegment(int $tripId, int $segmentId): array
    {
        $segment = $this->one($this->database->pdo(), 'SELECT route_id, origin_order, destination_order FROM route_segments WHERE id = :id', ['id' => $segmentId]);
        if ($segment === null) {
            Response::error('المسار الفرعي غير موجود.', 'NOT_FOUND', 404);
        }
        $statement = $this->database->pdo()->prepare(
            "SELECT bs.id, bs.seat_code, bs.seat_row, bs.column_code, bs.seat_type,
              EXISTS(SELECT 1 FROM booking_seats booked INNER JOIN bookings b ON b.id = booked.booking_id INNER JOIN booking_segments bs2 ON bs2.booking_id = b.id
                     WHERE b.trip_id = inventory.trip_id AND booked.bus_seat_id = inventory.bus_seat_id AND b.status IN ('pending','confirmed')
                       AND bs2.origin_stop_order < :destination_order AND bs2.destination_stop_order > :origin_order) AS is_booked
             FROM trip_seat_inventory inventory INNER JOIN bus_seats bs ON bs.id = inventory.bus_seat_id
             INNER JOIN trips t ON t.id = inventory.trip_id
             WHERE inventory.trip_id = :trip_id AND inventory.is_available = 1 AND t.route_id = :route_id ORDER BY bs.seat_row, bs.column_code"
        );
        $statement->execute(['trip_id' => $tripId, 'route_id' => $segment['route_id'], 'origin_order' => $segment['origin_order'], 'destination_order' => $segment['destination_order']]);
        return $statement->fetchAll();
    }

    /** @return array<string, mixed> */
    private function closeBooking(array $actor, int $bookingId, string $status, string $reason, string $notificationType, string $title): array
    {
        return $this->database->transaction(function (PDO $pdo) use ($actor, $bookingId, $status, $reason, $notificationType, $title): array {
            $booking = $this->loadBookingForUpdate($pdo, $bookingId);
            $this->assertManagementScope($actor, $booking);
            if ($booking['status'] !== 'pending') {
                Response::error('لا يمكن تعديل حجز مغلق أو مؤكد.', 'BOOKING_CLOSED', 409);
            }
            return $this->closeBookingWithinTransaction($pdo, $actor, $booking, $status, $reason, $notificationType, $title);
        });
    }

    /** @return array<string, mixed> */
    private function closeBookingWithinTransaction(PDO $pdo, array $actor, array $booking, string $status, string $reason, string $notificationType, string $title): array
    {
        $reason = Security::cleanText($reason, 500);
        $statement = $pdo->prepare("UPDATE bookings SET status = :status, cancellation_reason = :reason, rejected_at = IF(:is_rejected = 1, NOW(), rejected_at), cancelled_at = IF(:is_cancelled = 1, NOW(), cancelled_at) WHERE id = :id");
        $statement->execute(['status' => $status, 'reason' => $reason, 'is_rejected' => $status === 'rejected' ? 1 : 0, 'is_cancelled' => $status === 'cancelled' ? 1 : 0, 'id' => $booking['id']]);
        $ownerId = $this->bookingOwnerUserId($pdo, $booking);
        if ($ownerId !== null) {
            $this->notifications->send($ownerId, (int) $booking['company_id'], $notificationType, $title, $reason, 'booking', (int) $booking['id']);
        }
        $this->audit->log((int) $actor['id'], (int) $booking['company_id'], $notificationType, 'booking', (int) $booking['id'], ['status' => 'pending'], ['status' => $status, 'reason' => $reason]);
        return $this->bookingDetails($pdo, (int) $booking['id']);
    }

    private function assertActorCanBookForCompany(array $actor, int $companyId): void
    {
        if (!in_array('create_booking', $actor['permissions'], true) && !in_array('super_admin', $actor['roles'], true)) {
            Response::error('لا تملك صلاحية إنشاء حجز.', 'FORBIDDEN', 403);
        }
        if ($actor['agent_id'] !== null && (int) ($actor['company_id'] ?? 0) !== $companyId) {
            Response::error('لا يمكن للوكيل إنشاء حجز لشركة أخرى.', 'FORBIDDEN', 403);
        }
    }

    private function assertManagementScope(array $actor, array $booking): void
    {
        if (!in_array('confirm_booking', $actor['permissions'], true) && !in_array('super_admin', $actor['roles'], true)) {
            Response::error('لا تملك صلاحية تأكيد أو رفض الحجوزات.', 'FORBIDDEN', 403);
        }
        if (!in_array('super_admin', $actor['roles'], true) && (int) ($actor['company_id'] ?? 0) !== (int) $booking['company_id']) {
            Response::error('لا يمكن تعديل حجز تابع لشركة أخرى.', 'FORBIDDEN', 403);
        }
    }

    private function insertBookingSegment(PDO $pdo, int $bookingId, int $segmentId, array $trip): void
    {
        $stops = $this->one($pdo,
            'SELECT ost.name_ar AS origin_name, dst.name_ar AS destination_name FROM route_segments seg
             INNER JOIN route_stops o ON o.id = seg.origin_stop_id INNER JOIN route_stops d ON d.id = seg.destination_stop_id
             INNER JOIN stations ost ON ost.id = o.station_id INNER JOIN stations dst ON dst.id = d.station_id WHERE seg.id = :id',
            ['id' => $segmentId]
        );
        $statement = $pdo->prepare('INSERT INTO booking_segments (booking_id, route_segment_id, origin_stop_order, destination_stop_order, origin_name_ar, destination_name_ar, company_unit_amount, unit_amount) VALUES (:booking_id, :route_segment_id, :origin_order, :destination_order, :origin_name, :destination_name, :company_amount, :amount)');
        $statement->execute(['booking_id' => $bookingId, 'route_segment_id' => $segmentId, 'origin_order' => $trip['origin_order'], 'destination_order' => $trip['destination_order'], 'origin_name' => $stops['origin_name'], 'destination_name' => $stops['destination_name'], 'company_amount' => $trip['company_amount'], 'amount' => $trip['amount']]);
    }

    private function createPassenger(PDO $pdo, ?int $customerId, int $tripId, array $data): int
    {
        $fullName = Security::cleanText($data['full_name_ar'] ?? null, 220);
        $countryCode = Security::cleanText($data['phone_country_code'] ?? null, 10);
        $phone = Security::cleanText($data['phone'] ?? null, 32);
        $passport = strtoupper(Security::cleanText($data['passport_number'] ?? null, 64));
        $birthDate = $this->validDate($data['birth_date'] ?? null);
        $birthPlace = Security::cleanText($data['birth_place'] ?? null, 180);
        $issueDate = $this->validDate($data['passport_issue_date'] ?? null);
        $issuePlace = Security::cleanText($data['passport_issue_place'] ?? null, 180);
        $duplicate = $this->one($pdo,
            "SELECT bp.id FROM booking_passengers bp INNER JOIN passengers p ON p.id = bp.passenger_id INNER JOIN bookings b ON b.id = bp.booking_id
             WHERE b.trip_id = :trip_id AND p.passport_number = :passport AND b.status IN ('pending','confirmed') LIMIT 1 FOR UPDATE",
            ['trip_id' => $tripId, 'passport' => $passport]
        );
        if ($duplicate !== null) {
            Response::error('رقم الجواز مستخدم لمسافر آخر في هذه الرحلة.', 'DUPLICATE_PASSPORT', 409);
        }
        $statement = $pdo->prepare('INSERT INTO passengers (customer_id, full_name_ar, phone_country_code, phone, passport_number, birth_date, birth_place, passport_issue_date, passport_issue_place) VALUES (:customer_id, :full_name_ar, :phone_country_code, :phone, :passport_number, :birth_date, :birth_place, :passport_issue_date, :passport_issue_place)');
        $statement->execute(['customer_id' => $customerId, 'full_name_ar' => $fullName, 'phone_country_code' => $countryCode, 'phone' => $phone, 'passport_number' => $passport, 'birth_date' => $birthDate, 'birth_place' => $birthPlace, 'passport_issue_date' => $issueDate, 'passport_issue_place' => $issuePlace]);
        return (int) $pdo->lastInsertId();
    }

    private function chargeAgentWallet(PDO $pdo, array $booking, array $actor): void
    {
        $agent = $this->one($pdo, 'SELECT a.*, w.id AS wallet_id, w.balance, w.credit_limit, w.used_debt, w.minimum_balance FROM agents a INNER JOIN agent_wallets w ON w.agent_id = a.id AND w.currency_id = :currency_id WHERE a.id = :agent_id FOR UPDATE', ['currency_id' => $booking['currency_id'], 'agent_id' => $booking['agent_id']]);
        if ($agent === null || $agent['status'] !== 'active') {
            Response::error('حساب الوكيل غير متاح لإتمام العملية المالية.', 'AGENT_FINANCIALLY_BLOCKED', 409);
        }
        $cashAvailable = (float) $agent['balance'];
        $creditAvailable = $agent['credit_enabled'] ? max(0, (float) $agent['credit_limit'] - (float) $agent['used_debt']) : 0.0;
        $blockedMinimum = $agent['block_at_minimum_balance'] ? (float) $agent['minimum_balance'] : 0.0;
        $available = max(0, $cashAvailable - $blockedMinimum) + $creditAvailable;
        $amount = (float) $booking['total_amount'];
        if (!$agent['credit_enabled'] && $amount > max(0, $cashAvailable - $blockedMinimum) + 0.0001) {
            Response::error('لا يمكن استخدام الحد الائتماني لأن الشراء الآجل موقوف لهذا الوكيل.', 'CREDIT_DISABLED', 409);
        }
        if ($amount > $available + 0.0001) {
            Response::error('لا يمكن إتمام الحجز، الرصيد والحد الائتماني المتاحان غير كافيين.', 'INSUFFICIENT_BALANCE', 409);
        }
        $fromBalance = min(max(0, $cashAvailable - $blockedMinimum), $amount);
        $fromCredit = $amount - $fromBalance;
        $newBalance = $cashAvailable - $fromBalance;
        $newDebt = (float) $agent['used_debt'] + $fromCredit;
        $update = $pdo->prepare('UPDATE agent_wallets SET balance = :balance, used_debt = :used_debt WHERE id = :id');
        $update->execute(['balance' => $newBalance, 'used_debt' => $newDebt, 'id' => $agent['wallet_id']]);
        $transaction = $pdo->prepare('INSERT INTO agent_wallet_transactions (agent_wallet_id, booking_id, transaction_type, debit_amount, balance_before, balance_after, debt_before, debt_after, performed_by_user_id, reason) VALUES (:wallet_id, :booking_id, :transaction_type, :debit_amount, :balance_before, :balance_after, :debt_before, :debt_after, :performed_by_user_id, :reason)');
        $transaction->execute(['wallet_id' => $agent['wallet_id'], 'booking_id' => $booking['id'], 'transaction_type' => $fromCredit > 0 ? 'credit_usage' : 'booking', 'debit_amount' => $amount, 'balance_before' => $cashAvailable, 'balance_after' => $newBalance, 'debt_before' => $agent['used_debt'], 'debt_after' => $newDebt, 'performed_by_user_id' => $actor['id'], 'reason' => "تأكيد الحجز {$booking['booking_number']}"]);
        $commissionInsert = $pdo->prepare('INSERT INTO agent_commissions (agent_id, booking_id, currency_id, commission_type, rate_value, amount, status) VALUES (:agent_id, :booking_id, :currency_id, :commission_type, :rate_value, :amount, \'payable\')');
        $commissionInsert->execute(['agent_id' => $booking['agent_id'], 'booking_id' => $booking['id'], 'currency_id' => $booking['currency_id'], 'commission_type' => $booking['agent_commission_type'], 'rate_value' => $booking['agent_commission_rate'], 'amount' => $booking['commission_amount']]);
        $this->notifications->send((int) $agent['user_id'], (int) $booking['company_id'], 'wallet_updated', 'تم تحديث الحساب المالي', "تم خصم قيمة الحجز رقم {$booking['booking_number']} من حسابك.", 'booking', (int) $booking['id']);
    }

    private function issueTickets(PDO $pdo, array $booking): void
    {
        $rows = $pdo->prepare('SELECT bp.id AS booking_passenger_id, bs.id AS booking_seat_id FROM booking_passengers bp INNER JOIN booking_seats bs ON bs.booking_passenger_id = bp.id WHERE bp.booking_id = :booking_id');
        $rows->execute(['booking_id' => $booking['id']]);
        foreach ($rows->fetchAll() as $row) {
            $ticket = $pdo->prepare('INSERT INTO tickets (ticket_number, booking_id, booking_passenger_id, booking_seat_id, currency_id, amount, qr_token) VALUES (:ticket_number, :booking_id, :booking_passenger_id, :booking_seat_id, :currency_id, :amount, :qr_token)');
            $ticket->execute(['ticket_number' => $this->newReference($pdo, 'TK'), 'booking_id' => $booking['id'], 'booking_passenger_id' => $row['booking_passenger_id'], 'booking_seat_id' => $row['booking_seat_id'], 'currency_id' => $booking['currency_id'], 'amount' => $booking['total_amount'], 'qr_token' => bin2hex(random_bytes(32))]);
        }
        $ownerId = $this->bookingOwnerUserId($pdo, $booking);
        if ($ownerId !== null) {
            $this->notifications->send($ownerId, (int) $booking['company_id'], 'ticket_issued', 'تم إصدار التذاكر', "تم إصدار تذاكر الحجز رقم {$booking['booking_number']} وهي جاهزة للعرض.", 'booking', (int) $booking['id']);
        }
    }

    /** @return array<string, mixed> */
    private function loadBookingForUpdate(PDO $pdo, int $bookingId): array
    {
        $booking = $this->one($pdo, 'SELECT * FROM bookings WHERE id = :id FOR UPDATE', ['id' => $bookingId]);
        if ($booking === null) {
            Response::error('الحجز المطلوب غير موجود.', 'NOT_FOUND', 404);
        }
        return $booking;
    }

    /** @return array<string, mixed> */
    private function bookingDetails(PDO $pdo, int $bookingId): array
    {
        $booking = $this->one($pdo,
            'SELECT b.*, co.trade_name AS company_name, t.trip_number, t.departure_at, cu.code AS currency_code, cu.symbol_ar AS currency_symbol FROM bookings b INNER JOIN companies co ON co.id = b.company_id INNER JOIN trips t ON t.id = b.trip_id INNER JOIN currencies cu ON cu.id = b.currency_id WHERE b.id = :id',
            ['id' => $bookingId]
        );
        if ($booking === null) {
            Response::error('الحجز المطلوب غير موجود.', 'NOT_FOUND', 404);
        }
        $passengers = $pdo->prepare('SELECT p.full_name_ar, p.phone_country_code, p.phone, p.passport_number, bs.seat_code FROM booking_passengers bp INNER JOIN passengers p ON p.id = bp.passenger_id INNER JOIN booking_seats bks ON bks.booking_passenger_id = bp.id INNER JOIN bus_seats bs ON bs.id = bks.bus_seat_id WHERE bp.booking_id = :booking_id');
        $passengers->execute(['booking_id' => $bookingId]);
        $booking['passengers'] = $passengers->fetchAll();
        $segments = $pdo->prepare('SELECT origin_name_ar, destination_name_ar, company_unit_amount, unit_amount FROM booking_segments WHERE booking_id = :booking_id');
        $segments->execute(['booking_id' => $bookingId]);
        $booking['segments'] = $segments->fetchAll();
        return $booking;
    }

    private function expireSingle(PDO $pdo, array $booking, string $reason): void
    {
        $pdo->prepare("UPDATE bookings SET status = 'expired', cancellation_reason = :reason, cancelled_at = NOW() WHERE id = :id")->execute(['reason' => $reason, 'id' => $booking['id']]);
        $this->audit->log(null, (int) $booking['company_id'], 'booking_expired_on_confirm', 'booking', (int) $booking['id'], ['status' => 'pending'], ['status' => 'expired']);
    }

    private function bookingOwnerUserId(PDO $pdo, array $booking): ?int
    {
        if ($booking['customer_id'] !== null) {
            $value = $this->scalar($pdo, 'SELECT user_id FROM customers WHERE id = :id', ['id' => $booking['customer_id']]);
            return $value === null ? null : (int) $value;
        }
        if ($booking['agent_id'] !== null) {
            $value = $this->scalar($pdo, 'SELECT user_id FROM agents WHERE id = :id', ['id' => $booking['agent_id']]);
            return $value === null ? null : (int) $value;
        }
        return null;
    }

    private function canViewInternalFinancials(array $actor): bool
    {
        return in_array('super_admin', $actor['roles'], true) || in_array('manage_payments', $actor['permissions'], true) || in_array('view_financial_reports', $actor['permissions'], true);
    }

    /** @param array<string,mixed> $booking @return array<string,mixed> */
    private function redactInternalFinancials(array $booking, array $actor): array
    {
        if ($this->canViewInternalFinancials($actor)) {
            return $booking;
        }
        unset($booking['company_cost_amount'], $booking['company_payable_amount'], $booking['platform_commission_amount'], $booking['commission_amount'], $booking['agent_commission_type'], $booking['agent_commission_rate']);
        foreach ($booking['segments'] as &$segment) { unset($segment['company_unit_amount']); }
        unset($segment);
        return $booking;
    }

    private function positiveInt(mixed $value): int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($parsed === false) {
            Response::error('معرّف المورد غير صالح.', 'VALIDATION_ERROR', 422);
        }
        return $parsed;
    }

    private function validDate(mixed $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            Response::error('صيغة التاريخ غير صالحة.', 'VALIDATION_ERROR', 422);
        }
        return (string) $value;
    }

    private function newReference(PDO $pdo, string $prefix): string
    {
        do {
            $reference = sprintf('%s-%s-%06d', $prefix, date('Y'), random_int(1, 999999));
            $exists = $pdo->prepare('SELECT id FROM bookings WHERE booking_number = :booking_reference UNION SELECT id FROM tickets WHERE ticket_number = :ticket_reference LIMIT 1');
            $exists->execute(['booking_reference' => $reference, 'ticket_reference' => $reference]);
        } while ($exists->fetch() !== false);
        return $reference;
    }

    /** @param array<string, mixed> $params @return array<string, mixed>|null */
    private function one(PDO $pdo, string $sql, array $params): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $params */
    private function scalar(PDO $pdo, string $sql, array $params): mixed
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $value = $statement->fetchColumn();
        return $value === false ? null : $value;
    }
}
