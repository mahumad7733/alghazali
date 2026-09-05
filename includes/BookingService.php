<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;
use PDO;

final class BookingService
{
    private AuditLogger $audit;
    private NotificationService $notifications;
    private InvoiceService $invoices;

    public function __construct(private Database $database, private int $holdMinutes = 30)
    {
        $this->ensureTripCommissionColumns();
        $this->ensurePassengerGenderColumn();
        $this->ensurePaymentColumns();
        $this->ensureBookingTaxColumns();
        $this->ensureReviewTable();
        $this->ensureReviewRatingColumns();
        $this->audit = new AuditLogger($database);
        $this->notifications = new NotificationService($database);
        $this->invoices = new InvoiceService();
    }

    private function ensureTripCommissionColumns(): void
    {
        $pdo = $this->database->pdo();
        $type = $pdo->query("SHOW COLUMNS FROM trips LIKE 'agent_commission_type'")->fetchColumn();
        if ($type === false) { $pdo->exec("ALTER TABLE trips ADD COLUMN agent_commission_type ENUM('percentage','fixed') NULL AFTER bus_type"); }
        $value = $pdo->query("SHOW COLUMNS FROM trips LIKE 'agent_commission_value'")->fetchColumn();
        if ($value === false) { $pdo->exec("ALTER TABLE trips ADD COLUMN agent_commission_value DECIMAL(12,4) NULL AFTER agent_commission_type"); }
    }

    private function ensurePassengerGenderColumn(): void
    {
        $pdo = $this->database->pdo();
        if ($pdo->query("SHOW COLUMNS FROM passengers LIKE 'gender'")->fetchColumn() === false) {
            $pdo->exec("ALTER TABLE passengers ADD COLUMN gender ENUM('male','female') NULL AFTER full_name_ar");
        }
    }

    private function ensurePaymentColumns(): void
    {
        $pdo = $this->database->pdo();
        foreach ([
            'bank_id' => "ALTER TABLE payments ADD COLUMN bank_id BIGINT UNSIGNED NULL AFTER payment_method",
            'payment_channel' => "ALTER TABLE payments ADD COLUMN payment_channel VARCHAR(32) NULL AFTER payment_method",
            'receipt_image_path' => "ALTER TABLE payments ADD COLUMN receipt_image_path VARCHAR(500) NULL AFTER reference_number",
        ] as $column => $sql) {
            if ($pdo->query("SHOW COLUMNS FROM payments LIKE '{$column}'")->fetchColumn() === false) {
                $pdo->exec($sql);
            }
        }
    }

    private function ensureBookingTaxColumns(): void
    {
        $pdo = $this->database->pdo();
        foreach ([
            'tax_amount' => "ALTER TABLE bookings ADD COLUMN tax_amount DECIMAL(14,2) NULL AFTER total_amount",
            'tax_rate' => "ALTER TABLE bookings ADD COLUMN tax_rate DECIMAL(9,4) NULL AFTER tax_amount",
            'tax_snapshot_json' => "ALTER TABLE bookings ADD COLUMN tax_snapshot_json LONGTEXT NULL AFTER tax_rate",
        ] as $column => $sql) {
            if ($pdo->query("SHOW COLUMNS FROM bookings LIKE '{$column}'")->fetchColumn() === false) $pdo->exec($sql);
        }
    }

    private function ensureReviewTable(): void
    {
        $this->database->pdo()->exec("CREATE TABLE IF NOT EXISTS trip_reviews (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            trip_id BIGINT UNSIGNED NOT NULL,
            booking_id BIGINT UNSIGNED NOT NULL,
            customer_id BIGINT UNSIGNED NOT NULL,
            rating TINYINT UNSIGNED NOT NULL,
            recommendation TINYINT(1) NOT NULL DEFAULT 1,
            comment VARCHAR(1000) NULL,
            status ENUM('published','hidden') NOT NULL DEFAULT 'published',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_trip_reviews_booking (booking_id),
            INDEX idx_trip_reviews_trip_status (trip_id, status),
            CONSTRAINT fk_trip_reviews_trip FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
            CONSTRAINT fk_trip_reviews_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
            CONSTRAINT fk_trip_reviews_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private function ensureReviewRatingColumns(): void
    {
        $pdo = $this->database->pdo();
        foreach ([
            'company_rating' => "ALTER TABLE trip_reviews ADD COLUMN company_rating TINYINT UNSIGNED NULL AFTER rating",
            'agent_rating' => "ALTER TABLE trip_reviews ADD COLUMN agent_rating TINYINT UNSIGNED NULL AFTER company_rating",
        ] as $column => $sql) {
            if ($pdo->query("SHOW COLUMNS FROM trip_reviews LIKE '{$column}'")->fetchColumn() === false) {
                $pdo->exec($sql);
            }
        }
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
        return $this->database->transaction(function (PDO $pdo) use ($actor, $tripId, $segmentId, $seats, $passengers, $input): array {
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
            $payment = $this->paymentSelection($pdo, $input, (int) $trip['currency_id']);
            $referenceNumber = trim((string) ($input['reference_number'] ?? ''));
            if (mb_strlen($referenceNumber) > 128) {
                Response::error('رقم مرجع العملية طويل أكثر من اللازم.', 'VALIDATION_ERROR', 422);
            }
            if ($payment['payment_channel'] === 'bank_transfer' && mb_strlen($referenceNumber) < 3) {
                Response::error('رقم العملية مطلوب عند اختيار التحويل البنكي.', 'VALIDATION_ERROR', 422);
            }
            $referenceNumber = $referenceNumber !== '' ? Security::cleanText($referenceNumber, 128) : null;
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

            $agentId = !empty($actor['agent_id']) ? (int) $actor['agent_id'] : null;
            $subtotal = number_format(((float) $trip['amount']) * count($passengers), 2, '.', '');
            $taxSnapshot = $this->taxSnapshot($pdo, $subtotal);
            $taxAmount = number_format((float) $taxSnapshot['tax_amount'], 2, '.', '');
            $grandTotal = number_format((float) $subtotal + (float) $taxAmount, 2, '.', '');
            $companyCost = number_format(((float) $trip['company_amount']) * count($passengers), 2, '.', '');
            $tripCommissionConfigured = $agentId !== null && $trip['agent_commission_type'] !== null && $trip['agent_commission_value'] !== null;
            $commissionType = $agentId === null ? null : ($tripCommissionConfigured ? (string) $trip['agent_commission_type'] : (string) $agent['commission_type']);
            $commissionRate = $agentId === null ? 0.0 : ($tripCommissionConfigured ? (float) $trip['agent_commission_value'] : (float) $agent['commission_value']);
            $grossProfit = max(0.0, (float) $subtotal - (float) $companyCost);
            $commission = $agentId === null ? 0.0 : min($grossProfit, $commissionType === 'percentage' ? round($grossProfit * ($commissionRate / 100), 2) : max(0.0, $commissionRate));
            $companyPayable = round((float) $companyCost, 2);
            $platformCommission = round($grossProfit - $commission, 2);
            $bookingNumber = $this->newReference($pdo, 'BK');
            $heldUntil = date('Y-m-d H:i:s', time() + ($this->holdMinutes * 60));
            $customerId = !empty($actor['customer_id']) ? (int) $actor['customer_id'] : null;
            $source = $agentId ? 'agent' : (in_array('super_admin', $actor['roles'], true) ? 'admin' : 'website');
            $booking = $pdo->prepare(
                'INSERT INTO bookings (booking_number, company_id, trip_id, customer_id, agent_id, created_by_user_id, source, currency_id, subtotal_amount, total_amount, tax_amount, tax_rate, tax_snapshot_json, commission_amount, agent_commission_type, agent_commission_rate, company_cost_amount, company_payable_amount, platform_commission_amount, held_until)
                 VALUES (:booking_number, :company_id, :trip_id, :customer_id, :agent_id, :created_by_user_id, :source, :currency_id, :subtotal_amount, :total_amount, :tax_amount, :tax_rate, :tax_snapshot_json, :commission_amount, :agent_commission_type, :agent_commission_rate, :company_cost_amount, :company_payable_amount, :platform_commission_amount, :held_until)'
            );
            $booking->execute([
                'booking_number' => $bookingNumber, 'company_id' => $trip['company_id'], 'trip_id' => $tripId, 'customer_id' => $customerId,
                'agent_id' => $agentId, 'created_by_user_id' => $actor['id'], 'source' => $source, 'currency_id' => $trip['currency_id'],
                'subtotal_amount' => $subtotal, 'total_amount' => $grandTotal, 'tax_amount' => $taxAmount, 'tax_rate' => $taxSnapshot['tax_rate'], 'tax_snapshot_json' => json_encode($taxSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'commission_amount' => $commission, 'agent_commission_type' => $commissionType, 'agent_commission_rate' => $commissionRate, 'company_cost_amount' => $companyCost, 'company_payable_amount' => $companyPayable, 'platform_commission_amount' => $platformCommission, 'held_until' => $heldUntil,
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
            $paymentInsert = $pdo->prepare(
                'INSERT INTO payments (booking_id, currency_id, amount, payment_method, payment_channel, bank_id, status, reference_number)
                 VALUES (:booking_id, :currency_id, :amount, :payment_method, :payment_channel, :bank_id, \'pending\', :reference_number)'
            );
            $paymentInsert->execute([
                'booking_id' => $bookingId,
                'currency_id' => $trip['currency_id'],
                'amount' => $grandTotal,
                'payment_method' => $payment['payment_method'],
                'payment_channel' => $payment['payment_channel'],
                'bank_id' => $payment['bank_id'],
                'reference_number' => $referenceNumber,
            ]);

            $createdBooking = $this->bookingDetails($pdo, $bookingId);
            $customerName = trim((string) ($createdBooking['customer_name'] ?? 'عميل غير مسمى')) ?: 'عميل غير مسمى';
            $this->notifications->send((int) $actor['id'], (int) $trip['company_id'], 'booking_created', 'تم إرسال طلب الحجز', "تم إنشاء طلب الحجز رقم {$bookingNumber} للعميل {$customerName}، والحالة: قيد الانتظار.", 'booking', $bookingId);
            $this->notifications->sendToBookingManagers((int) $trip['company_id'], "حجز جديد — {$customerName}", "العميل: {$customerName} · رقم الحجز: {$bookingNumber} · الحالة: قيد الانتظار. افتح التفاصيل للمراجعة والتأكيد.", $bookingId, (int) $actor['id']);
            $this->audit->log((int) $actor['id'], (int) $trip['company_id'], 'booking_created', 'booking', $bookingId, null, ['status' => 'pending', 'booking_number' => $bookingNumber, 'customer_name' => $customerName]);
            return $this->redactInternalFinancials($createdBooking, $actor);
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

            $payment = $this->one($pdo, 'SELECT id, payment_channel, status FROM payments WHERE booking_id = :booking_id ORDER BY id DESC LIMIT 1 FOR UPDATE', ['booking_id' => $bookingId]);
            if ($payment === null) {
                Response::error('لا توجد عملية دفع مرتبطة بهذا الحجز.', 'PAYMENT_NOT_FOUND', 404);
            }
            $agentWalletCharge = $booking['agent_id'] !== null && (string) ($payment['payment_channel'] ?? '') === 'agent' && (string) $booking['payment_status'] !== 'paid';
            if ($agentWalletCharge) {
                $this->chargeAgentWallet($pdo, $booking, $actor);
                $pdo->prepare("UPDATE payments SET status = 'completed', received_by_user_id = :received_by_user_id WHERE id = :id AND status <> 'completed'")->execute(['received_by_user_id' => $actor['id'], 'id' => $payment['id']]);
            }
            $paymentAlreadyCompleted = (string) $payment['status'] === 'completed';
            $bookingPaymentStatus = ((string) $booking['payment_status'] === 'paid' || $agentWalletCharge || $paymentAlreadyCompleted) ? 'paid' : 'pending';
            if ($bookingPaymentStatus === 'paid') $this->invoices->issueForPayment($pdo, (int) $payment['id']);
            $update = $pdo->prepare("UPDATE bookings SET status = 'confirmed', payment_status = :payment_status, confirmed_at = NOW() WHERE id = :id");
            $update->execute(['id' => $bookingId, 'payment_status' => $bookingPaymentStatus]);
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
    public function receivePayment(array $actor, int $bookingId, array $input): array
    {
        return $this->database->transaction(function (PDO $pdo) use ($actor, $bookingId, $input): array {
            $booking = $this->loadBookingForUpdate($pdo, $bookingId);
            if (!in_array('super_admin', $actor['roles'], true) && (int) ($actor['company_id'] ?? 0) !== (int) $booking['company_id']) {
                Response::error('لا يمكن تسجيل دفعة لحجز تابع لشركة أخرى.', 'FORBIDDEN', 403);
            }
            if (in_array((string) $booking['status'], ['cancelled', 'rejected', 'expired'], true)) {
                Response::error('لا يمكن تسجيل دفعة لحجز مغلق أو ملغي.', 'BOOKING_CLOSED', 409);
            }
            $payment = $this->one($pdo, 'SELECT * FROM payments WHERE booking_id = :booking_id ORDER BY id DESC LIMIT 1 FOR UPDATE', ['booking_id' => $bookingId]);
            if ($payment === null) {
                Response::error('لا توجد عملية دفع مرتبطة بهذا الحجز.', 'PAYMENT_NOT_FOUND', 404);
            }
            if ((string) $payment['status'] === 'completed') {
                return $this->bookingDetails($pdo, $bookingId);
            }
            if ((string) $payment['status'] === 'refunded') {
                Response::error('لا يمكن إكمال دفعة مستردة.', 'PAYMENT_REFUNDED', 409);
            }
            $channel = (string) ($payment['payment_channel'] ?? '');
            if (!in_array($channel, ['agent', 'company', 'bank_transfer'], true)) {
                Response::error('قناة الدفع المرتبطة بالحجز غير صالحة.', 'PAYMENT_CHANNEL_INVALID', 422);
            }
            $reference = trim((string) ($input['reference_number'] ?? ''));
            if (mb_strlen($reference) > 128) {
                Response::error('رقم مرجع الدفع طويل أكثر من اللازم.', 'VALIDATION_ERROR', 422);
            }
            if ($channel === 'bank_transfer' && $payment['bank_id'] !== null) {
                $bank = $this->one($pdo, 'SELECT id, currency_id, is_active FROM banks WHERE id = :id FOR UPDATE', ['id' => $payment['bank_id']]);
                if ($bank === null || (int) $bank['is_active'] !== 1 || (int) $bank['currency_id'] !== (int) $payment['currency_id']) {
                    Response::error('حساب التحويل البنكي غير متاح أو لا يطابق عملة الحجز.', 'BANK_NOT_AVAILABLE', 409);
                }
            }
            if ($channel === 'agent' && $booking['agent_id'] !== null && (string) $booking['payment_status'] !== 'paid') {
                $this->chargeAgentWallet($pdo, $booking, $actor);
            }
            $pdo->prepare('UPDATE payments SET status = \'completed\', reference_number = COALESCE(:reference_number, reference_number), received_by_user_id = :received_by_user_id WHERE id = :id')->execute([
                'reference_number' => $reference !== '' ? Security::cleanText($reference, 128) : null,
                'received_by_user_id' => $actor['id'],
                'id' => $payment['id'],
            ]);
            $pdo->prepare("UPDATE bookings SET payment_status = 'paid' WHERE id = :id")->execute(['id' => $bookingId]);
            if (in_array($channel, ['company', 'bank_transfer'], true)) {
                $this->recordCompanyPayment($pdo, $booking, $payment, $actor);
            }
            $this->invoices->issueForPayment($pdo, (int) $payment['id']);
            $ownerId = $this->bookingOwnerUserId($pdo, $booking);
            if ($ownerId !== null) {
                $this->notifications->send($ownerId, (int) $booking['company_id'], 'payment_received', 'تم تأكيد استلام الدفع', "تم تأكيد استلام دفعة الحجز رقم {$booking['booking_number']}.", 'booking', $bookingId);
            }
            $this->audit->log((int) $actor['id'], (int) $booking['company_id'], 'payment_received', 'payment', (int) $payment['id'], ['status' => $payment['status']], ['status' => 'completed', 'channel' => $channel]);
            return $this->bookingDetails($pdo, $bookingId);
        });
    }

    /** @return array<string, mixed> */
    public function reject(array $actor, int $bookingId, string $reason): array
    {
        return $this->closeBooking($actor, $bookingId, 'rejected', $reason, 'booking_rejected', 'تم رفض الحجز');
    }

    /** @return array<string, mixed> */
    /** @return array{receipt_image_path:string} */
    public function uploadPaymentReceipt(array $actor, int $bookingId, array $file): array
    {
        return $this->database->transaction(function (PDO $pdo) use ($actor, $bookingId, $file): array {
            $booking = $this->loadBookingForUpdate($pdo, $bookingId);
            $isOwner = ($booking['customer_id'] !== null && (int) $booking['customer_id'] === (int) ($actor['customer_id'] ?? 0)) || ($booking['agent_id'] !== null && (int) $booking['agent_id'] === (int) ($actor['agent_id'] ?? 0));
            if (!in_array('super_admin', $actor['roles'], true) && !$isOwner && (int) ($actor['company_id'] ?? 0) !== (int) $booking['company_id']) {
                Response::error('لا يمكن رفع إيصال لحجز خارج نطاق حسابك.', 'FORBIDDEN', 403);
            }
            if (in_array((string) $booking['status'], ['cancelled', 'rejected', 'expired'], true)) {
                Response::error('لا يمكن رفع إيصال لحجز مغلق.', 'BOOKING_CLOSED', 409);
            }
            $payment = $this->one($pdo, 'SELECT id, payment_channel FROM payments WHERE booking_id = :booking_id ORDER BY id DESC LIMIT 1 FOR UPDATE', ['booking_id' => $bookingId]);
            if ($payment === null || (string) $payment['payment_channel'] !== 'bank_transfer') {
                Response::error('رفع صورة الإشعار متاح للتحويل البنكي فقط.', 'PAYMENT_CHANNEL_INVALID', 422);
            }
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                Response::error('اختر صورة إشعار صحيحة.', 'UPLOAD_ERROR', 422);
            }
            if ((int) ($file['size'] ?? 0) < 1 || (int) $file['size'] > 5 * 1024 * 1024) {
                Response::error('حجم صورة الإشعار يجب ألا يتجاوز 5 ميجابايت.', 'UPLOAD_SIZE', 422);
            }
            $image = @getimagesize((string) $file['tmp_name']);
            $mime = (string) ($image['mime'] ?? '');
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime]) || (int) ($image[0] ?? 0) > 5000 || (int) ($image[1] ?? 0) > 5000) {
                Response::error('استخدم صورة JPG أو PNG أو WEBP بأبعاد مناسبة.', 'UPLOAD_TYPE', 422);
            }
            $directory = dirname(__DIR__) . '/uploads/payment-receipts';
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                Response::error('تعذر تجهيز مجلد إيصالات الدفع.', 'UPLOAD_STORAGE', 500);
            }
            $root = dirname(__DIR__) . '/uploads';
            $htaccess = $root . '/.htaccess';
            if (!file_exists($htaccess)) { @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \\\"\\.(php|phtml|phar|php[0-9]*)$\\\">\nRequire all denied\n</FilesMatch>\n"); }
            $relative = 'uploads/payment-receipts/booking_' . $bookingId . '_' . bin2hex(random_bytes(5)) . '.' . $extensions[$mime];
            if (!move_uploaded_file((string) $file['tmp_name'], dirname(__DIR__) . '/' . $relative)) {
                Response::error('تعذر حفظ صورة إشعار الدفع.', 'UPLOAD_STORAGE', 500);
            }
            $pdo->prepare('UPDATE payments SET receipt_image_path = :path WHERE id = :id')->execute(['path' => $relative, 'id' => $payment['id']]);
            $this->audit->log((int) $actor['id'], (int) $booking['company_id'], 'payment_receipt_uploaded', 'payment', (int) $payment['id'], null, ['booking_id' => $bookingId]);
            return ['receipt_image_path' => $relative];
        });
    }

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
                    t.trip_number, t.departure_at, co.trade_name AS company_name,
                    COALESCE(u.full_name, (SELECT p0.full_name_ar FROM booking_passengers bp0 INNER JOIN passengers p0 ON p0.id = bp0.passenger_id WHERE bp0.booking_id = b.id ORDER BY bp0.id LIMIT 1)) AS customer_name,
                    u.email AS customer_email,
                    COALESCE(u.phone, (SELECT p1.phone FROM booking_passengers bp1 INNER JOIN passengers p1 ON p1.id = bp1.passenger_id WHERE bp1.booking_id = b.id ORDER BY bp1.id LIMIT 1)) AS customer_phone,
                    (SELECT pay.payment_channel FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_channel,
                    (SELECT pay.bank_id FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_bank_id,
                    b.cancellation_reason, EXISTS (SELECT 1 FROM trip_reviews rv WHERE rv.booking_id = b.id) AS review_submitted
             FROM bookings b INNER JOIN currencies cu ON cu.id = b.currency_id INNER JOIN trips t ON t.id = b.trip_id INNER JOIN companies co ON co.id = b.company_id
             LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN users u ON u.id = c.user_id
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

    /** @return array<string, mixed> */
    public function createReview(array $actor, int $bookingId, array $input): array
    {
        $customerId = (int) ($actor['customer_id'] ?? 0);
        if ($customerId < 1) { Response::error('التقييم متاح للعملاء المسجلين فقط.', 'FORBIDDEN', 403); }
        $rating = filter_var($input['rating'] ?? $input['trip_rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $companyRating = filter_var($input['company_rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $agentRating = filter_var($input['agent_rating'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        $recommendation = filter_var($input['recommendation'] ?? 1, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $comment = trim((string) ($input['comment'] ?? ''));
        if ($rating === false || $recommendation === null || mb_strlen($comment) > 1000 || ($companyRating !== false && $companyRating !== null && ($companyRating < 1 || $companyRating > 5)) || ($agentRating !== false && $agentRating !== null && ($agentRating < 1 || $agentRating > 5))) { Response::error('أدخل تقييمات صحيحة من 1 إلى 5 وتعليقًا لا يتجاوز 1000 حرف.', 'VALIDATION_ERROR', 422); }
        $pdo = $this->database->pdo();
        $booking = $this->one($pdo, 'SELECT b.id, b.trip_id, b.customer_id, b.agent_id, b.status, t.departure_at FROM bookings b INNER JOIN trips t ON t.id = b.trip_id WHERE b.id = :id', ['id' => $bookingId]);
        if ($booking === null || (int) $booking['customer_id'] !== $customerId) { Response::error('الحجز غير موجود أو لا يتبع حسابك.', 'FORBIDDEN', 403); }
        if ((string) $booking['status'] !== 'completed' || strtotime((string) $booking['departure_at']) > time()) { Response::error('يمكن تقييم الرحلة بعد اكتمالها ومرور موعد المغادرة.', 'REVIEW_NOT_READY', 409); }
        if ($this->one($pdo, 'SELECT id FROM trip_reviews WHERE booking_id = :booking_id', ['booking_id' => $bookingId]) !== null) { Response::error('تم إرسال تقييم هذا الحجز مسبقًا.', 'DUPLICATE_REVIEW', 409); }
        if ($booking['agent_id'] === null) $agentRating = null;
        $statement = $pdo->prepare('INSERT INTO trip_reviews (trip_id, booking_id, customer_id, rating, company_rating, agent_rating, recommendation, comment) VALUES (:trip_id, :booking_id, :customer_id, :rating, :company_rating, :agent_rating, :recommendation, :comment)');
        $statement->execute(['trip_id' => $booking['trip_id'], 'booking_id' => $bookingId, 'customer_id' => $customerId, 'rating' => $rating, 'company_rating' => $companyRating ?: $rating, 'agent_rating' => $agentRating, 'recommendation' => $recommendation ? 1 : 0, 'comment' => $comment !== '' ? Security::cleanText($comment, 1000) : null]);
        return ['id' => (int) $pdo->lastInsertId(), 'trip_id' => (int) $booking['trip_id'], 'rating' => $rating, 'company_rating' => $companyRating ?: $rating, 'agent_rating' => $agentRating, 'recommendation' => (bool) $recommendation];
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
                       AND bs2.origin_stop_order < :destination_order AND bs2.destination_stop_order > :origin_order) AS is_booked,
              (SELECT p.gender FROM booking_seats booked_gender INNER JOIN bookings b_gender ON b_gender.id = booked_gender.booking_id
                       INNER JOIN booking_segments bs_gender ON bs_gender.booking_id = b_gender.id
                       INNER JOIN booking_passengers bp_gender ON bp_gender.id = booked_gender.booking_passenger_id AND bp_gender.booking_id = b_gender.id
                       INNER JOIN passengers p ON p.id = bp_gender.passenger_id
                       WHERE b_gender.trip_id = inventory.trip_id AND booked_gender.bus_seat_id = inventory.bus_seat_id AND b_gender.status IN ('pending','confirmed')
                         AND bs_gender.origin_stop_order < :destination_order_gender AND bs_gender.destination_stop_order > :origin_order_gender
                       LIMIT 1) AS seat_gender
             FROM trip_seat_inventory inventory INNER JOIN bus_seats bs ON bs.id = inventory.bus_seat_id
             INNER JOIN trips t ON t.id = inventory.trip_id
             WHERE inventory.trip_id = :trip_id AND inventory.is_available = 1 AND t.route_id = :route_id ORDER BY bs.seat_row, bs.column_code"
        );
        $statement->execute(['trip_id' => $tripId, 'route_id' => $segment['route_id'], 'origin_order' => $segment['origin_order'], 'destination_order' => $segment['destination_order'], 'origin_order_gender' => $segment['origin_order'], 'destination_order_gender' => $segment['destination_order']]);
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
        if (mb_strlen($reason) < 3) {
            Response::error('اكتب سببًا واضحًا لا يقل عن 3 أحرف ليظهر للعميل.', 'VALIDATION_ERROR', 422);
        }
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
        $gender = (string) ($data['gender'] ?? '');
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
        if (!in_array($gender, ['male', 'female'], true)) {
            Response::error('اختر جنس المسافر: ذكر أو أنثى.', 'VALIDATION_ERROR', 422);
        }
        $statement = $pdo->prepare('INSERT INTO passengers (customer_id, full_name_ar, gender, phone_country_code, phone, passport_number, birth_date, birth_place, passport_issue_date, passport_issue_place) VALUES (:customer_id, :full_name_ar, :gender, :phone_country_code, :phone, :passport_number, :birth_date, :birth_place, :passport_issue_date, :passport_issue_place)');
        $statement->execute(['customer_id' => $customerId, 'full_name_ar' => $fullName, 'gender' => $gender, 'phone_country_code' => $countryCode, 'phone' => $phone, 'passport_number' => $passport, 'birth_date' => $birthDate, 'birth_place' => $birthPlace, 'passport_issue_date' => $issueDate, 'passport_issue_place' => $issuePlace]);
        return (int) $pdo->lastInsertId();
    }

    private function recordCompanyPayment(PDO $pdo, array $booking, array $payment, array $actor): void
    {
        $account = $this->one($pdo, 'SELECT id, current_balance FROM accounts WHERE company_id = :company_id AND currency_id = :currency_id AND account_code = :account_code LIMIT 1 FOR UPDATE', [
            'company_id' => $booking['company_id'],
            'currency_id' => $payment['currency_id'],
            'account_code' => 'customer_collections',
        ]);
        if ($account === null) {
            $insert = $pdo->prepare('INSERT INTO accounts (company_id, currency_id, account_code, name_ar, account_type, current_balance, is_active) VALUES (:company_id, :currency_id, :account_code, :name_ar, \'asset\', 0, 1)');
            $insert->execute(['company_id' => $booking['company_id'], 'currency_id' => $payment['currency_id'], 'account_code' => 'customer_collections', 'name_ar' => 'متحصلات العملاء']);
            $accountId = (int) $pdo->lastInsertId();
            $balanceBefore = 0.0;
        } else {
            $accountId = (int) $account['id'];
            $balanceBefore = (float) $account['current_balance'];
        }
        $amount = (float) $payment['amount'];
        $balanceAfter = $balanceBefore + $amount;
        $pdo->prepare('UPDATE accounts SET current_balance = :balance, is_active = 1 WHERE id = :id')->execute(['balance' => $balanceAfter, 'id' => $accountId]);
        $pdo->prepare('INSERT INTO account_transactions (account_id, booking_id, transaction_type, debit_amount, credit_amount, reference_type, reference_id, note_ar, created_by_user_id) VALUES (:account_id, :booking_id, :transaction_type, :debit_amount, 0, :reference_type, :reference_id, :note_ar, :created_by_user_id)')->execute([
            'account_id' => $accountId,
            'booking_id' => $booking['id'],
            'transaction_type' => 'booking_payment_received',
            'debit_amount' => $amount,
            'reference_type' => 'payment',
            'reference_id' => $payment['id'],
            'note_ar' => 'استلام دفعة الحجز ' . $booking['booking_number'],
            'created_by_user_id' => $actor['id'],
        ]);
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
    public function updateTicket(array $actor, int $ticketId, array $input): array
    {
        $ticketId = $this->positiveInt($ticketId);
        $gender = trim((string) ($input['gender'] ?? ''));
        if (!in_array($gender, ['male', 'female'], true)) {
            Response::error('اختر جنس المسافر: ذكر أو أنثى.', 'VALIDATION_ERROR', 422);
        }
        $seatId = $this->positiveInt($input['bus_seat_id'] ?? null);

        return $this->database->transaction(function (PDO $pdo) use ($actor, $ticketId, $gender, $seatId): array {
            $ticket = $this->one($pdo,
                'SELECT tk.id, tk.booking_id, tk.booking_passenger_id, tk.booking_seat_id, b.company_id, b.status AS booking_status,
                        b.trip_id, bks.bus_seat_id AS current_seat_id, seg.origin_stop_order, seg.destination_stop_order,
                        p.id AS passenger_id
                 FROM tickets tk
                 INNER JOIN bookings b ON b.id = tk.booking_id
                 INNER JOIN booking_passengers bp ON bp.id = tk.booking_passenger_id
                 INNER JOIN passengers p ON p.id = bp.passenger_id
                 INNER JOIN booking_seats bks ON bks.id = tk.booking_seat_id
                 INNER JOIN booking_segments seg ON seg.booking_id = b.id
                 WHERE tk.id = :id LIMIT 1 FOR UPDATE',
                ['id' => $ticketId]
            );
            if ($ticket === null) {
                Response::error('التذكرة المطلوبة غير موجودة.', 'NOT_FOUND', 404);
            }
            $this->assertManagementScope($actor, $ticket);
            if (!in_array((string) $ticket['booking_status'], ['confirmed', 'completed'], true)) {
                Response::error('لا يمكن تعديل تذكرة قبل تأكيد الحجز.', 'TICKET_NOT_EDITABLE', 409);
            }

            $seat = $this->one($pdo,
                'SELECT inventory.bus_seat_id FROM trip_seat_inventory inventory
                 WHERE inventory.trip_id = :trip_id AND inventory.bus_seat_id = :seat_id AND inventory.is_available = 1 LIMIT 1',
                ['trip_id' => $ticket['trip_id'], 'seat_id' => $seatId]
            );
            if ($seat === null) {
                Response::error('رقم المقعد غير متاح ضمن هذه الرحلة.', 'SEAT_NOT_AVAILABLE', 422);
            }
            if ((int) $ticket['current_seat_id'] !== $seatId) {
                $conflict = $this->one($pdo,
                    "SELECT bks2.id FROM booking_seats bks2
                     INNER JOIN bookings b2 ON b2.id = bks2.booking_id
                     INNER JOIN booking_segments seg2 ON seg2.booking_id = b2.id
                     WHERE b2.trip_id = :trip_id AND b2.id <> :booking_id AND b2.status IN ('pending','confirmed','completed')
                       AND bks2.bus_seat_id = :seat_id
                       AND seg2.origin_stop_order < :destination_order AND seg2.destination_stop_order > :origin_order
                     LIMIT 1 FOR UPDATE",
                    ['trip_id' => $ticket['trip_id'], 'booking_id' => $ticket['booking_id'], 'seat_id' => $seatId, 'origin_order' => $ticket['origin_stop_order'], 'destination_order' => $ticket['destination_stop_order']]
                );
                if ($conflict !== null) {
                    Response::error('المقعد المختار مرتبط بحجز آخر في نفس مقطع الرحلة.', 'SEAT_CONFLICT', 409);
                }
                $pdo->prepare('UPDATE booking_seats SET bus_seat_id = :bus_seat_id WHERE id = :id')->execute(['bus_seat_id' => $seatId, 'id' => $ticket['booking_seat_id']]);
            }
            $pdo->prepare('UPDATE passengers SET gender = :gender WHERE id = :id')->execute(['gender' => $gender, 'id' => $ticket['passenger_id']]);
            $this->audit->log((int) $actor['id'], (int) $ticket['company_id'], 'ticket_updated', 'ticket', $ticketId, ['seat_id' => $ticket['current_seat_id']], ['seat_id' => $seatId, 'gender' => $gender]);

            $updated = $this->one($pdo,
                'SELECT tk.*, b.booking_number, b.status AS booking_status, b.payment_status, b.company_id, b.customer_id, b.agent_id,
                        p.full_name_ar, p.gender, bs.seat_code, t.trip_number, t.departure_at, co.trade_name AS company_name,
                        co.latitude AS company_latitude, co.longitude AS company_longitude, cu.code AS currency_code, cu.symbol_ar AS currency_symbol
                 FROM tickets tk
                 INNER JOIN bookings b ON b.id = tk.booking_id
                 INNER JOIN booking_passengers bp ON bp.id = tk.booking_passenger_id
                 INNER JOIN passengers p ON p.id = bp.passenger_id
                 INNER JOIN booking_seats bks ON bks.id = tk.booking_seat_id
                 INNER JOIN bus_seats bs ON bs.id = bks.bus_seat_id
                 INNER JOIN trips t ON t.id = b.trip_id
                 INNER JOIN companies co ON co.id = b.company_id
                 INNER JOIN currencies cu ON cu.id = tk.currency_id
                 WHERE tk.id = :id LIMIT 1',
                ['id' => $ticketId]
            );
            return $updated ?? [];
        });
    }

    private function bookingDetails(PDO $pdo, int $bookingId): array
    {
        $booking = $this->one($pdo,
            'SELECT b.*, co.trade_name AS company_name, co.phone AS company_phone, co.email AS company_email, t.trip_number, t.departure_at, t.arrival_at, t.status AS trip_status, r.name_ar AS route_name, cu.code AS currency_code, cu.symbol_ar AS currency_symbol, u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                    (SELECT pay.payment_channel FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_channel,
                    (SELECT pay.bank_id FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_bank_id,
                    (SELECT bk.name_ar FROM payments pay INNER JOIN banks bk ON bk.id = pay.bank_id WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_bank_name,
                    (SELECT bk.account_number FROM payments pay INNER JOIN banks bk ON bk.id = pay.bank_id WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_bank_account,
                    (SELECT pay.reference_number FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_reference_number,
                    (SELECT pay.receipt_image_path FROM payments pay WHERE pay.booking_id = b.id ORDER BY pay.id DESC LIMIT 1) AS payment_receipt_image_path,
                    EXISTS (SELECT 1 FROM trip_reviews rv WHERE rv.booking_id = b.id) AS review_submitted FROM bookings b INNER JOIN companies co ON co.id = b.company_id INNER JOIN trips t ON t.id = b.trip_id INNER JOIN routes r ON r.id = t.route_id INNER JOIN currencies cu ON cu.id = b.currency_id LEFT JOIN customers c ON c.id = b.customer_id LEFT JOIN users u ON u.id = c.user_id WHERE b.id = :id',
            ['id' => $bookingId]
        );
        if ($booking === null) {
            Response::error('الحجز المطلوب غير موجود.', 'NOT_FOUND', 404);
        }
        $passengers = $pdo->prepare('SELECT p.full_name_ar, p.gender, p.phone_country_code, p.phone, p.passport_number, p.birth_date, p.birth_place, p.passport_issue_date, p.passport_issue_place, bs.seat_code FROM booking_passengers bp INNER JOIN passengers p ON p.id = bp.passenger_id INNER JOIN booking_seats bks ON bks.booking_passenger_id = bp.id INNER JOIN bus_seats bs ON bs.id = bks.bus_seat_id WHERE bp.booking_id = :booking_id ORDER BY bks.id');
        $passengers->execute(['booking_id' => $bookingId]);
        $booking['passengers'] = $passengers->fetchAll();
        $segments = $pdo->prepare('SELECT bs.origin_name_ar, bs.destination_name_ar, bs.company_unit_amount, bs.unit_amount, ost.address AS origin_station_address, ost.latitude AS origin_station_latitude, ost.longitude AS origin_station_longitude, dst.address AS destination_station_address, dst.latitude AS destination_station_latitude, dst.longitude AS destination_station_longitude FROM booking_segments bs LEFT JOIN route_segments rs ON rs.id = bs.route_segment_id LEFT JOIN route_stops ros ON ros.id = rs.origin_stop_id LEFT JOIN route_stops rds ON rds.id = rs.destination_stop_id LEFT JOIN stations ost ON ost.id = ros.station_id LEFT JOIN stations dst ON dst.id = rds.station_id WHERE bs.booking_id = :booking_id ORDER BY bs.id');
        $segments->execute(['booking_id' => $bookingId]);
        $booking['segments'] = $segments->fetchAll();
        $tickets = $pdo->prepare('SELECT tk.id, tk.ticket_number, tk.status, tk.amount, tk.issued_at, bs.seat_code FROM tickets tk INNER JOIN booking_seats bks ON bks.id = tk.booking_seat_id INNER JOIN bus_seats bs ON bs.id = bks.bus_seat_id WHERE tk.booking_id = :booking_id ORDER BY tk.id');
        $tickets->execute(['booking_id' => $bookingId]);
        $booking['tickets'] = $tickets->fetchAll();
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

    /** @return array{payment_method:string,payment_channel:string,bank_id:int|null} */
    private function paymentSelection(PDO $pdo, array $input, int $currencyId): array
    {
        $channel = strtolower(trim((string) ($input['payment_channel'] ?? 'agent')));
        if (!in_array($channel, ['agent', 'company', 'bank_transfer', 'gateway'], true)) {
            Response::error('طريقة الدفع المختارة غير صالحة.', 'VALIDATION_ERROR', 422);
        }
        if ($channel === 'gateway') {
            $gatewayAvailable = (int) $pdo->query("SELECT COUNT(*) FROM payment_gateway_settings WHERE provider_code = 'moyasar' AND is_enabled = 1")->fetchColumn() > 0;
            if (!$gatewayAvailable) Response::error('الدفع الإلكتروني غير مفعّل حاليًا.', 'PAYMENT_METHOD_DISABLED', 409);
            return ['payment_method' => 'card', 'payment_channel' => 'gateway', 'bank_id' => null];
        }
        $settings = $this->one($pdo, 'SELECT allow_agent_payment, allow_company_payment, allow_bank_transfer FROM trip_display_settings WHERE id = 1', []) ?? ['allow_agent_payment' => 1, 'allow_company_payment' => 1, 'allow_bank_transfer' => 1];
        $settingKey = ['agent' => 'allow_agent_payment', 'company' => 'allow_company_payment', 'bank_transfer' => 'allow_bank_transfer'][$channel];
        if ((int) ($settings[$settingKey] ?? 1) !== 1) {
            Response::error('طريقة الدفع المختارة غير متاحة حاليًا.', 'PAYMENT_METHOD_DISABLED', 409);
        }
        $bankId = null;
        if ($channel === 'bank_transfer') {
            $bankId = filter_var($input['bank_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($bankId === false) {
                Response::error('اختر الحساب البنكي للتحويل.', 'VALIDATION_ERROR', 422);
            }
            $bank = $this->one($pdo, 'SELECT id, currency_id, is_active FROM banks WHERE id = :id LIMIT 1', ['id' => $bankId]);
            if ($bank === null || (int) $bank['is_active'] !== 1) {
                Response::error('الحساب البنكي المختار غير متاح.', 'BANK_NOT_AVAILABLE', 409);
            }
            if ((int) $bank['currency_id'] !== $currencyId) {
                Response::error('عملة الحساب البنكي لا تطابق عملة سعر الرحلة.', 'BANK_CURRENCY_MISMATCH', 422);
            }
        }
        return ['payment_method' => $channel === 'bank_transfer' ? 'bank_transfer' : 'cash', 'payment_channel' => $channel, 'bank_id' => $bankId === false ? null : $bankId];
    }

    /** @return array{enabled:int,tax_rate:?string,tax_label_ar:?string,subtotal:string,tax_amount:string,total:string} */
    private function taxSnapshot(PDO $pdo, string $subtotal): array
    {
        try {
            $settings = $this->one($pdo, 'SELECT vat_enabled, vat_rate, tax_label_ar FROM tax_settings WHERE id = 1 LIMIT 1', []);
        } catch (\Throwable) {
            $settings = null;
        }
        $enabled = is_array($settings) && (int) ($settings['vat_enabled'] ?? 0) === 1 && $settings['vat_rate'] !== null && (float) $settings['vat_rate'] >= 0 && (float) $settings['vat_rate'] <= 100;
        $rate = $enabled ? (float) $settings['vat_rate'] : 0.0;
        $taxAmount = $enabled ? round((float) $subtotal * ($rate / 100), 2) : 0.0;
        return [
            'enabled' => $enabled ? 1 : 0,
            'tax_rate' => $enabled ? number_format($rate, 4, '.', '') : null,
            'tax_label_ar' => $enabled ? (string) ($settings['tax_label_ar'] ?? '') : null,
            'subtotal' => $subtotal,
            'tax_amount' => number_format($taxAmount, 2, '.', ''),
            'total' => number_format((float) $subtotal + $taxAmount, 2, '.', ''),
        ];
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
