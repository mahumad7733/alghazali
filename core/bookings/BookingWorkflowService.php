<?php

class BookingWorkflowService
{
    /** @var PDO */
    private $pdo;

    /** @var int */
    private $userId;

    /** @var string */
    private $transactionType;

    public function __construct(PDO $pdo, int $userId, string $transactionType = 'bus_flight_bookings')
    {
        $this->pdo = $pdo;
        $this->userId = $userId;
        $this->transactionType = $transactionType;
    }

    public function handleQuickAction(int $bookingId, string $action): void
    {
        if ($action === 'confirm') {
            $this->confirmBooking($bookingId);
            return;
        }

        if ($action === 'cancel') {
            $this->cancelBooking($bookingId);
            return;
        }

        throw new Exception('الإجراء غير مدعوم.');
    }

    public function changeWorkflowStatus(int $bookingId, int $toStatusId, string $notes = '', array $extraFields = [], ?int $transitionId = null): bool
    {
        return change_booking_status($bookingId, $toStatusId, $this->userId, $notes, $extraFields, $transitionId);
    }

    public function requestApproval(int $bookingId, int $toStatusId, float $discountAmount, string $notes = '', ?int $requestedRoleId = null, ?float $discountPercent = null, array $extraFields = []): void
    {
        $this->pdo->beginTransaction();

        try {
            $this->ensureNoPendingApproval($bookingId);

            $booking = $this->getBookingStatusContext($bookingId);
            if (!$booking) {
                throw new Exception('الحجز غير موجود');
            }

            if (in_array($booking['status_name'], ['سافر', 'مسافر'], true)) {
                throw new Exception("لا يمكن إلغاء أو تعديل حجز في حالة 'مسافر'");
            }

            if ($booking['status_name'] === 'تم إلغاء الحجز') {
                throw new Exception('الحجز ملغي بالفعل');
            }

            $fromStepId = $this->resolveWorkflowStepId((int)$booking['branch_id'], (int)$booking['status_id']);
            $toStepId = $this->resolveWorkflowStepId((int)$booking['branch_id'], $toStatusId);
            $requestNumber = $this->generateRequestNumber();

            $stmt = $this->pdo->prepare("
                INSERT INTO workflow_approval_requests
                (request_number, booking_id, from_step_id, to_step_id, requested_by, requested_role_id, notes, extra_data, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')
            ");
            $extraData = ['discount_amount' => round(max(0, $discountAmount), 2)];
            if (isset($extraFields['requested_mod_date']) && !empty($booking['departure_date'])) {
                $extraData['current_departure_date'] = $booking['departure_date'];
            }
            if ($discountPercent !== null) {
                $extraData['discount_percent'] = round(max(0, min(100, $discountPercent)), 2);
            }
            foreach ($extraFields as $field => $value) {
                if (in_array($field, ['mod_reason', 'requested_mod_date', 'ticket_number', 'cancel_reason', 'refund_amount', 'refund_method', 'discount_percent', 'discount_amount', 'net_amount', 'modification_type', 'requested_from_city_id', 'requested_to_city_id', 'requested_departure_time', 'charge_penalty', 'modification_penalty_percent', 'modification_penalty_amount', 'collect_penalty', 'penalty_payment_method', 'penalty_cash_bank_account_id'], true)) {
                    $extraData[$field] = is_string($value) ? trim($value) : $value;
                }
            }
            $stmt->execute([
                $requestNumber,
                $bookingId,
                $fromStepId,
                $toStepId,
                $this->userId,
                $requestedRoleId,
                $notes,
                json_encode($extraData, JSON_UNESCAPED_UNICODE),
            ]);

            $this->notifyApprovalTargets($requestNumber, $booking, $discountAmount, $notes);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function requestTicketApproval(int $bookingId, array $ticketData, string $notes = '', ?int $requestedRoleId = null): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->ensureNoPendingApproval($bookingId);
            $booking = $this->getBookingStatusContext($bookingId);
            if (!$booking) {
                throw new Exception('الحجز غير موجود');
            }
            $fromStepId = $this->resolveWorkflowStepId((int)$booking['branch_id'], (int)$booking['status_id']);
            $requestNumber = $this->generateRequestNumber();
            $extraData = ['action' => 'issue_ticket'];
            foreach (['seat_number', 'pnr', 'supplier_reference', 'bus_flight_number', 'public_base_url'] as $field) {
                if (array_key_exists($field, $ticketData)) {
                    $extraData[$field] = trim((string)$ticketData[$field]);
                }
            }
            $stmt = $this->pdo->prepare("INSERT INTO workflow_approval_requests (request_number, booking_id, from_step_id, to_step_id, requested_by, requested_role_id, notes, extra_data, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$requestNumber, $bookingId, $fromStepId, $fromStepId, $this->userId, $requestedRoleId, $notes, json_encode($extraData, JSON_UNESCAPED_UNICODE)]);
            $this->notifyApprovalTargets($requestNumber, $booking, 0, $notes, 'إصدار تذكرة رقمية');
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function confirmBooking(int $bookingId): void
    {
        $statusId = $this->getStatusIdByName('تم تأكيد الحجز');
        if (!$statusId) {
            throw new Exception('تعذر العثور على حالة تأكيد الحجز.');
        }

        $this->pdo->prepare("UPDATE bus_flight_bookings SET status_id = ? WHERE id = ?")->execute([$statusId, $bookingId]);
        change_booking_status($bookingId, $statusId, $this->userId, 'تم تأكيد الحجز');

        $settings = getSettings($this->pdo);
        if (($settings['booking_post_trigger'] ?? '') === 'on_confirm') {
            post_booking_to_financials($bookingId, $this->userId);
        }
    }

    private function cancelBooking(int $bookingId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT s.status_name
            FROM bus_flight_bookings b
            JOIN statuses s ON b.status_id = s.id
            WHERE b.id = ?
        ");
        $stmt->execute([$bookingId]);
        $currentStatus = $stmt->fetchColumn();

        if (in_array($currentStatus, ['سافر', 'مسافر'], true)) {
            throw new Exception("لا يمكن إلغاء حجز في حالة 'مسافر'");
        }

        $statusId = $this->getStatusIdByName('تم إلغاء الحجز');
        if (!$statusId) {
            throw new Exception('تعذر العثور على حالة إلغاء الحجز.');
        }

        $this->pdo->prepare("UPDATE bus_flight_bookings SET status_id = ? WHERE id = ?")->execute([$statusId, $bookingId]);
        change_booking_status($bookingId, $statusId, $this->userId, 'تم إلغاء الحجز');
    }

    private function ensureNoPendingApproval(int $bookingId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT id
            FROM workflow_approval_requests
            WHERE booking_id = ? AND status = 'pending'
            LIMIT 1
        ");
        $stmt->execute([$bookingId]);

        if ($stmt->fetch()) {
            throw new Exception('يوجد طلب معلق بالفعل لهذا الحجز، بانتظار موافقة المدير.');
        }
    }

    private function getBookingStatusContext(int $bookingId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT b.status_id, b.branch_id, b.traveler_name, b.booking_number, b.departure_date, s.status_name
            FROM bus_flight_bookings b
            JOIN statuses s ON b.status_id = s.id
            WHERE b.id = ?
        ");
        $stmt->execute([$bookingId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function resolveWorkflowStepId(int $branchId, int $statusId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT ws.id
            FROM workflow_steps ws
            JOIN workflows w ON ws.workflow_id = w.id
            WHERE w.transaction_type = ?
              AND (w.branch_id = ? OR w.branch_id IS NULL)
              AND ws.status_id = ?
            ORDER BY w.branch_id DESC
            LIMIT 1
        ");
        $stmt->execute([$this->transactionType, $branchId, $statusId]);

        return (int)($stmt->fetchColumn() ?: 0);
    }

    private function generateRequestNumber(): string
    {
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM workflow_approval_requests")->fetchColumn() + 1;
        return 'REQ-' . date('Ymd') . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
    }

    private function notifyApprovalTargets(string $requestNumber, array $booking, float $discountAmount, string $notes, string $requestTitle = 'تعديل حجز'): void
    {
        $title = "طلب اعتماد جديد ({$requestNumber}): {$requestTitle}";
        $message = "المسافر: {$booking['traveler_name']}\n";
        $message .= "رقم الحجز: {$booking['booking_number']}\n";
            $message .= 'الغرامة المقترحة: ' . number_format($discountAmount, 2) . "\n";
        $message .= 'ملاحظات: ' . $notes;
        $link = 'workflow_approvals.php?status=pending';

        $targetIds = $this->findApprovalTargetIds();
        if (empty($targetIds)) {
            return;
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO notifications (user_id, title, message, link, type, created_by)
            VALUES (?, ?, ?, ?, 'warning', ?)
        ");

        foreach ($targetIds as $targetId) {
            $stmt->execute([$targetId, $title, $message, $link, $this->userId]);
        }
    }

    private function findApprovalTargetIds(): array
    {
        $stmt = $this->pdo->query("
            SELECT u.id
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            WHERE r.name IN ('admin', 'developer', 'super_admin', 'مدير', 'مطور')
               OR u.user_type IN ('admin', 'developer')
               OR u.id IN (
                   SELECT u2.id
                   FROM users u2
                   JOIN role_permissions_unified rp ON u2.role_id = rp.role_id
                   JOIN unified_permissions p ON rp.permission_id = p.id
                   WHERE p.permission_code = 'bookings_approve_requests'
               )
        ");

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function getStatusIdByName(string $statusName): ?int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM statuses WHERE status_name = ? LIMIT 1");
        $stmt->execute([$statusName]);
        $statusId = $stmt->fetchColumn();

        return $statusId ? (int)$statusId : null;
    }
}
