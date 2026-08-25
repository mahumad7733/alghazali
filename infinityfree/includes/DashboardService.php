<?php
declare(strict_types=1);

namespace App\Includes;

use App\Classes\Database;

final class DashboardService
{
    public function __construct(private Database $database, private BookingService $bookingService)
    {
    }

    /** @return array<string, mixed> */
    public function summary(array $actor): array
    {
        $this->bookingService->expirePendingBookings();
        $isAgent = $actor['agent_id'] !== null;
        if (!$isAgent && !in_array('view_reports', $actor['permissions'], true) && !in_array('super_admin', $actor['roles'], true)) {
            Response::error('لا تملك صلاحية عرض لوحة التقارير.', 'FORBIDDEN', 403);
        }
        $scope = '';
        $params = [];
        if ($isAgent) {
            $scope = ' WHERE agent_id = :agent_id';
            $params['agent_id'] = $actor['agent_id'];
        } elseif (!in_array('super_admin', $actor['roles'], true)) {
            $scope = ' WHERE company_id = :company_id';
            $params['company_id'] = $actor['company_id'];
        }
        $pdo = $this->database->pdo();
        $bookings = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'pending') AS pending, SUM(status = 'confirmed') AS confirmed, SUM(status = 'rejected') AS rejected FROM bookings{$scope}");
        $bookings->execute($params);
        $bookingMetrics = $bookings->fetch() ?: [];
        $tripScope = in_array('super_admin', $actor['roles'], true) ? '' : ' WHERE company_id = :company_id';
        $trips = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'open') AS open_count FROM trips{$tripScope}");
        $trips->execute($tripScope === '' ? [] : ['company_id' => $actor['company_id']]);
        $tripMetrics = $trips->fetch() ?: [];
        $companyCount = in_array('super_admin', $actor['roles'], true) ? (int) $pdo->query("SELECT COUNT(*) FROM companies WHERE status = 'active'")->fetchColumn() : 1;
        return [
            'companies' => $companyCount,
            'trips' => (int) ($tripMetrics['total'] ?? 0),
            'open_trips' => (int) ($tripMetrics['open_count'] ?? 0),
            'bookings' => (int) ($bookingMetrics['total'] ?? 0),
            'pending_bookings' => (int) ($bookingMetrics['pending'] ?? 0),
            'confirmed_bookings' => (int) ($bookingMetrics['confirmed'] ?? 0),
            'rejected_bookings' => (int) ($bookingMetrics['rejected'] ?? 0),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function report(array $actor, array $filters): array
    {
        if (!in_array('view_reports', $actor['permissions'], true) && !in_array('super_admin', $actor['roles'], true)) {
            Response::error('لا تملك صلاحية عرض التقارير.', 'FORBIDDEN', 403);
        }
        $conditions = ['1=1'];
        $params = [];
        $this->addIntegerFilter($filters, 'country_id', 'co.country_id', $conditions, $params);
        $this->addIntegerFilter($filters, 'company_id', 'b.company_id', $conditions, $params);
        $this->addIntegerFilter($filters, 'agent_id', 'b.agent_id', $conditions, $params);
        $this->addIntegerFilter($filters, 'currency_id', 'b.currency_id', $conditions, $params);
        $this->addIntegerFilter($filters, 'trip_id', 'b.trip_id', $conditions, $params);
        if (!empty($filters['start_date']) && $this->isDate((string) $filters['start_date'])) {
            $conditions[] = 'DATE(b.created_at) >= :start_date'; $params['start_date'] = $filters['start_date'];
        }
        if (!empty($filters['end_date']) && $this->isDate((string) $filters['end_date'])) {
            $conditions[] = 'DATE(b.created_at) <= :end_date'; $params['end_date'] = $filters['end_date'];
        }
        if (!in_array('super_admin', $actor['roles'], true)) {
            $conditions[] = 'b.company_id = :access_company_id'; $params['access_company_id'] = $actor['company_id'];
        }
        $where = implode(' AND ', $conditions);
        $canViewFinancials = $this->canViewInternalFinancials($actor);
        $financialSelect = $canViewFinancials
            ? ", SUM(CASE WHEN b.status = 'confirmed' THEN b.company_cost_amount ELSE 0 END) AS confirmed_company_cost, SUM(CASE WHEN b.status = 'confirmed' THEN b.company_payable_amount ELSE 0 END) AS confirmed_company_payable, SUM(CASE WHEN b.status = 'confirmed' THEN b.platform_commission_amount ELSE 0 END) AS confirmed_platform_commission"
            : ", 0 AS confirmed_company_cost, 0 AS confirmed_company_payable, 0 AS confirmed_platform_commission";
        $statement = $this->database->pdo()->prepare(
            "SELECT cu.code AS currency_code, cu.symbol_ar AS currency_symbol, COUNT(*) AS total_bookings,
                    SUM(b.status = 'pending') AS pending_bookings, SUM(b.status = 'confirmed') AS confirmed_bookings,
                    SUM(b.status = 'rejected') AS rejected_bookings, SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END) AS confirmed_sales,
                    SUM(b.commission_amount) AS commissions {$financialSelect}
             FROM bookings b INNER JOIN companies co ON co.id = b.company_id INNER JOIN currencies cu ON cu.id = b.currency_id
             WHERE {$where} GROUP BY cu.id, cu.code, cu.symbol_ar ORDER BY cu.code"
        );
        $statement->execute($params);
        return ['filters' => $filters, 'can_view_financials' => $canViewFinancials, 'by_currency' => $statement->fetchAll()];
    }

    /** @param list<string> $conditions @param array<string, mixed> $params */
    private function addIntegerFilter(array $filters, string $key, string $column, array &$conditions, array &$params): void
    {
        if (!isset($filters[$key]) || $filters[$key] === '') {
            return;
        }
        $value = filter_var($filters[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($value === false) {
            Response::error("قيمة {$key} غير صالحة.", 'VALIDATION_ERROR', 422);
        }
        $conditions[] = "{$column} = :{$key}";
        $params[$key] = $value;
    }

    private function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function canViewInternalFinancials(array $actor): bool
    {
        return in_array('super_admin', $actor['roles'], true) || in_array('manage_payments', $actor['permissions'], true) || in_array('view_financial_reports', $actor['permissions'], true);
    }
}
