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
        $monthlyComparison = $this->monthlyComparison($pdo, $actor);
        return [
            'companies' => $companyCount,
            'trips' => (int) ($tripMetrics['total'] ?? 0),
            'open_trips' => (int) ($tripMetrics['open_count'] ?? 0),
            'bookings' => (int) ($bookingMetrics['total'] ?? 0),
            'pending_bookings' => (int) ($bookingMetrics['pending'] ?? 0),
            'confirmed_bookings' => (int) ($bookingMetrics['confirmed'] ?? 0),
            'rejected_bookings' => (int) ($bookingMetrics['rejected'] ?? 0),
            'monthly_comparison' => $monthlyComparison,
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
        return ['filters' => $filters, 'can_view_financials' => $canViewFinancials, 'by_currency' => $statement->fetchAll(), 'monthly_comparison' => $this->monthlyComparison($this->database->pdo(), $actor)];
    }

    /** @return array<string, mixed> */
    private function monthlyComparison(\PDO $pdo, array $actor): array
    {
        $isAgent = $actor['agent_id'] !== null;
        $scope = '';
        $params = [];
        if ($isAgent) {
            $scope = ' WHERE agent_id = :monthly_agent_id';
            $params['monthly_agent_id'] = $actor['agent_id'];
        } elseif (!in_array('super_admin', $actor['roles'], true)) {
            $scope = ' WHERE company_id = :monthly_company_id';
            $params['monthly_company_id'] = $actor['company_id'];
        }
        $currentStart = new \DateTimeImmutable('first day of this month 00:00:00');
        $previousStart = $currentStart->modify('-1 month');
        $nextStart = $currentStart->modify('+1 month');
        $params['current_start_a'] = $currentStart->format('Y-m-d H:i:s');
        $params['current_start_b'] = $currentStart->format('Y-m-d H:i:s');
        $params['current_start_c'] = $currentStart->format('Y-m-d H:i:s');
        $params['current_start_d'] = $currentStart->format('Y-m-d H:i:s');
        $params['previous_start_a'] = $previousStart->format('Y-m-d H:i:s');
        $params['previous_start_b'] = $previousStart->format('Y-m-d H:i:s');
        $params['next_start_a'] = $nextStart->format('Y-m-d H:i:s');
        $params['next_start_b'] = $nextStart->format('Y-m-d H:i:s');
        $statement = $pdo->prepare("SELECT
            SUM(created_at >= :current_start_a AND created_at < :next_start_a) AS current_bookings,
            SUM(created_at >= :previous_start_a AND created_at < :current_start_b) AS previous_bookings,
            SUM(status = 'confirmed' AND created_at >= :current_start_c AND created_at < :next_start_b) AS current_confirmed,
            SUM(status = 'confirmed' AND created_at >= :previous_start_b AND created_at < :current_start_d) AS previous_confirmed
            FROM bookings{$scope}");
        $statement->execute($params);
        $row = $statement->fetch() ?: [];
        $current = (int) ($row['current_bookings'] ?? 0);
        $previous = (int) ($row['previous_bookings'] ?? 0);
        $delta = $current - $previous;
        return [
            'current_month' => $currentStart->format('Y-m'),
            'previous_month' => $previousStart->format('Y-m'),
            'current_bookings' => $current,
            'previous_bookings' => $previous,
            'current_confirmed' => (int) ($row['current_confirmed'] ?? 0),
            'previous_confirmed' => (int) ($row['previous_confirmed'] ?? 0),
            'delta' => $delta,
            'percentage' => $previous > 0 ? round(($delta / $previous) * 100, 1) : ($current > 0 ? 100.0 : 0.0),
        ];
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

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function overview(array $actor, array $filters = []): array
    {
        if (!in_array('view_reports', $actor['permissions'], true) && !in_array('super_admin', $actor['roles'], true)) {
            Response::error('لا تملك صلاحية عرض لوحة التقارير.', 'FORBIDDEN', 403);
        }
        $pdo = $this->database->pdo();
        [$start, $end, $previousStart, $previousEnd, $period] = $this->periodWindow($filters);
        $bookingConditions = ['1=1']; $bookingParams = [];
        $tripConditions = ['1=1']; $tripParams = [];
        if ($actor['agent_id'] !== null) {
            $bookingConditions[] = 'b.agent_id = :actor_agent_id'; $bookingParams['actor_agent_id'] = $actor['agent_id'];
            $tripConditions[] = 't.company_id = :actor_company_id'; $tripParams['actor_company_id'] = $actor['company_id'];
        } elseif (!in_array('super_admin', $actor['roles'], true)) {
            $bookingConditions[] = 'b.company_id = :actor_company_id'; $bookingParams['actor_company_id'] = $actor['company_id'];
            $tripConditions[] = 't.company_id = :actor_company_id'; $tripParams['actor_company_id'] = $actor['company_id'];
        }
        $this->overviewIntegerFilter($filters, 'company_id', 'b.company_id', $bookingConditions, $bookingParams);
        $this->overviewIntegerFilter($filters, 'agent_id', 'b.agent_id', $bookingConditions, $bookingParams);
        $this->overviewIntegerFilter($filters, 'route_id', 't.route_id', $bookingConditions, $bookingParams);
        $this->overviewIntegerFilter($filters, 'route_subroute_id', 't.route_subroute_id', $bookingConditions, $bookingParams);
        $this->overviewIntegerFilter($filters, 'currency_id', 'b.currency_id', $bookingConditions, $bookingParams);
        $this->overviewIntegerFilter($filters, 'company_id', 't.company_id', $tripConditions, $tripParams, 'trip_company_id');
        $this->overviewIntegerFilter($filters, 'route_id', 't.route_id', $tripConditions, $tripParams, 'trip_route_id');
        $this->overviewIntegerFilter($filters, 'route_subroute_id', 't.route_subroute_id', $tripConditions, $tripParams, 'trip_subroute_id');
        $bookingWhere = implode(' AND ', $bookingConditions); $tripWhere = implode(' AND ', $tripConditions); $tripFilterParams = $tripParams;
        $params = $bookingParams + ['metric_cs1' => $start, 'metric_ce1' => $end, 'metric_ps1' => $previousStart, 'metric_pe1' => $previousEnd, 'metric_cs2' => $start, 'metric_ce2' => $end, 'metric_ps2' => $previousStart, 'metric_pe2' => $previousEnd, 'metric_cs3' => $start, 'metric_ce3' => $end, 'metric_cs4' => $start, 'metric_ce4' => $end, 'metric_cs5' => $start, 'metric_ce5' => $end, 'metric_cs6' => $start, 'metric_ce6' => $end, 'metric_cs7' => $start, 'metric_ce7' => $end, 'metric_ps4' => $previousStart, 'metric_pe4' => $previousEnd, 'metric_cs8' => $start, 'metric_ce8' => $end, 'metric_ps3' => $previousStart, 'metric_pe3' => $previousEnd];
        $metricsStatement = $pdo->prepare("SELECT
            COUNT(CASE WHEN b.created_at >= :metric_cs1 AND b.created_at < :metric_ce1 THEN 1 END) AS current_bookings,
            COUNT(CASE WHEN b.created_at >= :metric_ps1 AND b.created_at < :metric_pe1 THEN 1 END) AS previous_bookings,
            COUNT(CASE WHEN b.created_at >= :metric_cs2 AND b.created_at < :metric_ce2 AND b.status = 'confirmed' THEN 1 END) AS current_confirmed,
            COUNT(CASE WHEN b.created_at >= :metric_ps2 AND b.created_at < :metric_pe2 AND b.status = 'confirmed' THEN 1 END) AS previous_confirmed,
            COUNT(CASE WHEN b.created_at >= :metric_cs3 AND b.created_at < :metric_ce3 AND b.status = 'cancelled' THEN 1 END) AS current_cancelled,
            COUNT(CASE WHEN b.created_at >= :metric_ps3 AND b.created_at < :metric_pe3 AND b.status = 'cancelled' THEN 1 END) AS previous_cancelled,
            COUNT(CASE WHEN b.created_at >= :metric_cs4 AND b.created_at < :metric_ce4 AND b.status = 'pending' THEN 1 END) AS current_pending,
            COUNT(CASE WHEN b.created_at >= :metric_cs5 AND b.created_at < :metric_ce5 AND b.status = 'confirmed' THEN 1 END) AS current_sales_count,
            COUNT(DISTINCT CASE WHEN b.created_at >= :metric_cs6 AND b.created_at < :metric_ce6 THEN b.customer_id END) AS current_customers,
            COALESCE(SUM(CASE WHEN b.created_at >= :metric_cs7 AND b.created_at < :metric_ce7 AND b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS current_sales,
            COALESCE(SUM(CASE WHEN b.created_at >= :metric_ps4 AND b.created_at < :metric_pe4 AND b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS previous_sales,
            COALESCE(SUM(CASE WHEN b.created_at >= :metric_cs8 AND b.created_at < :metric_ce8 AND b.payment_status = 'refunded' THEN b.total_amount ELSE 0 END), 0) AS current_refunded
            FROM bookings b INNER JOIN trips t ON t.id = b.trip_id WHERE {$bookingWhere}");
        $metricsStatement->execute($params); $metrics = $metricsStatement->fetch() ?: [];
        $todayStart = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s'); $todayEnd = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d H:i:s');
        $extraParams = $bookingParams + ['today_start' => $todayStart, 'today_end' => $todayEnd, 'extra_cs' => $start, 'extra_ce' => $end, 'extra_ps' => $previousStart, 'extra_pe' => $previousEnd];
        $extra = $pdo->prepare("SELECT COUNT(*) AS all_bookings, COUNT(CASE WHEN b.created_at >= :today_start AND b.created_at < :today_end THEN 1 END) AS today_bookings,
            COALESCE(SUM(CASE WHEN b.status='confirmed' AND b.created_at >= :today_start_sales AND b.created_at < :today_end_sales THEN b.total_amount ELSE 0 END),0) AS today_sales,
            COALESCE(SUM(CASE WHEN b.status='confirmed' THEN b.total_amount ELSE 0 END),0) AS all_sales,
            COALESCE(SUM(CASE WHEN b.status='confirmed' AND b.created_at >= :extra_cs AND b.created_at < :extra_ce THEN b.platform_commission_amount ELSE 0 END),0) AS current_profit,
            COALESCE(SUM(CASE WHEN b.status='confirmed' AND b.created_at >= :extra_ps AND b.created_at < :extra_pe THEN b.platform_commission_amount ELSE 0 END),0) AS previous_profit,
            COUNT(CASE WHEN b.agent_id IS NOT NULL AND b.created_at >= :agent_cs AND b.created_at < :agent_ce THEN 1 END) AS agent_bookings
            FROM bookings b INNER JOIN trips t ON t.id=b.trip_id WHERE {$bookingWhere}");
        $extraParams += ['today_start_sales' => $todayStart, 'today_end_sales' => $todayEnd, 'agent_cs' => $start, 'agent_ce' => $end]; $extra->execute($extraParams); $extraMetrics = $extra->fetch() ?: [];
        $agentScope = [];
        $agentParams = [];
        if ($actor['agent_id'] !== null) { $agentScope[] = 'a.id = :scope_agent_id'; $agentParams['scope_agent_id'] = $actor['agent_id']; }
        elseif (!in_array('super_admin', $actor['roles'], true)) { $agentScope[] = 'a.company_id = :scope_agent_company_id'; $agentParams['scope_agent_company_id'] = $actor['company_id']; }
        $agentWhere = $agentScope ? 'WHERE ' . implode(' AND ', $agentScope) : '';
        $agentTotals = $pdo->prepare("SELECT COUNT(DISTINCT a.id) AS total_agents, SUM(a.status='active') AS active_agents, SUM(a.status <> 'active') AS inactive_agents,
            COALESCE(SUM(aw.balance),0) AS agent_balance, COALESCE(SUM(aw.used_debt),0) AS agent_debt
            FROM agents a LEFT JOIN agent_wallets aw ON aw.agent_id=a.id {$agentWhere}"); $agentTotals->execute($agentParams); $agentMetrics = $agentTotals->fetch() ?: [];
        $companyConditions = ['1=1']; $companyParams = [];
        if ($actor['agent_id'] !== null) { $companyConditions[] = 'c.id = :company_agent_company'; $companyParams['company_agent_company'] = $actor['company_id']; }
        elseif (!in_array('super_admin', $actor['roles'], true)) { $companyConditions[] = 'c.id = :company_scope'; $companyParams['company_scope'] = $actor['company_id']; }
        $companyWhere = implode(' AND ', $companyConditions);
        $companyTotals = $pdo->prepare("SELECT COUNT(*) AS total_companies, SUM(c.status='active') AS active_companies, COALESCE(SUM((SELECT COUNT(*) FROM trips t2 WHERE t2.company_id = c.id)),0) AS total_company_trips FROM companies c WHERE {$companyWhere}");
        $companyTotals->execute($companyParams); $companyMetrics = $companyTotals->fetch() ?: [];
        $tripPeriodParams = $tripParams + ['trip_current_start' => $start, 'trip_current_end' => $end, 'trip_previous_start_count' => $previousStart, 'trip_previous_end_count' => $previousEnd, 'trip_open_start' => $start, 'trip_open_end' => $end, 'trip_completed_start' => $start, 'trip_completed_end' => $end, 'trip_cancelled_start' => $start, 'trip_cancelled_end' => $end, 'trip_range_start' => $previousStart, 'trip_range_end' => $end];
        $tripStatement = $pdo->prepare("SELECT COUNT(CASE WHEN t.departure_at >= :trip_current_start AND t.departure_at < :trip_current_end THEN 1 END) AS total_trips,
            COUNT(CASE WHEN t.departure_at >= :trip_previous_start_count AND t.departure_at < :trip_previous_end_count THEN 1 END) AS previous_trips,
            SUM(CASE WHEN t.departure_at >= :trip_open_start AND t.departure_at < :trip_open_end AND t.status = 'open' THEN 1 ELSE 0 END) AS open_trips,
            SUM(CASE WHEN t.departure_at >= :trip_completed_start AND t.departure_at < :trip_completed_end AND t.status = 'completed' THEN 1 ELSE 0 END) AS completed_trips,
            SUM(CASE WHEN t.departure_at >= :trip_cancelled_start AND t.departure_at < :trip_cancelled_end AND t.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_trips
            FROM trips t WHERE {$tripWhere} AND t.departure_at >= :trip_range_start AND t.departure_at < :trip_range_end");
        $tripStatement->execute($tripPeriodParams); $tripMetrics = $tripStatement->fetch() ?: [];
        $seatParams = $tripFilterParams; $seatStatement = $pdo->prepare("SELECT COUNT(tsi.id) AS total_seats,
            SUM(CASE WHEN tsi.is_available = 0 THEN 1 ELSE 0 END) AS booked_seats
            FROM trip_seat_inventory tsi INNER JOIN trips t ON t.id = tsi.trip_id
            WHERE {$tripWhere} AND t.departure_at >= :seat_start AND t.departure_at < :seat_end");
        $seatParams['seat_start'] = $start; $seatParams['seat_end'] = $end; $seatStatement->execute($seatParams); $seatMetrics = $seatStatement->fetch() ?: [];
        $seriesParams = $bookingParams + ['series_start' => $start, 'series_end' => $end];
        $series = $pdo->prepare("SELECT DATE(b.created_at) AS label, cu.code AS currency_code, cu.symbol_ar AS currency_symbol, COUNT(*) AS bookings,
            COALESCE(SUM(CASE WHEN b.status = 'confirmed' THEN b.total_amount ELSE 0 END),0) AS sales
            FROM bookings b INNER JOIN trips t ON t.id = b.trip_id INNER JOIN currencies cu ON cu.id=b.currency_id WHERE {$bookingWhere}
            AND b.created_at >= :series_start AND b.created_at < :series_end GROUP BY DATE(b.created_at), cu.id, cu.code, cu.symbol_ar ORDER BY label, cu.code");
        $series->execute($seriesParams);
        $tripSeriesParams = $tripFilterParams + ['trip_series_start' => $start, 'trip_series_end' => $end];
        $tripSeries = $pdo->prepare("SELECT t.status AS label, COUNT(*) AS total FROM trips t WHERE {$tripWhere}
            AND t.departure_at >= :trip_series_start AND t.departure_at < :trip_series_end GROUP BY t.status ORDER BY t.status");
        $tripSeries->execute($tripSeriesParams);
        $upcoming = $pdo->prepare("SELECT t.id, t.trip_number, t.departure_at, t.arrival_at, t.status, co.trade_name AS company_name,
            r.name_ar AS route_name, bu.bus_number, (SELECT COUNT(*) FROM trip_seat_inventory x WHERE x.trip_id=t.id) AS total_seats,
            (SELECT COUNT(*) FROM trip_seat_inventory x WHERE x.trip_id=t.id AND x.is_available=1) AS available_seats
            FROM trips t INNER JOIN companies co ON co.id=t.company_id INNER JOIN routes r ON r.id=t.route_id INNER JOIN buses bu ON bu.id=t.bus_id
            WHERE {$tripWhere} AND t.departure_at >= NOW() ORDER BY t.departure_at LIMIT :upcoming_limit");
        $upcoming->bindValue(':upcoming_limit', 8, \PDO::PARAM_INT); foreach ($tripFilterParams as $key => $value) $upcoming->bindValue(':' . $key, $value); $upcoming->execute();
        $topCompanyParams = $bookingParams + ['top_company_start' => $start, 'top_company_end' => $end];
        $topCompanies = $pdo->prepare("SELECT co.trade_name AS company_name, COUNT(DISTINCT b.id) AS bookings,
            COALESCE(SUM(CASE WHEN b.status='confirmed' THEN b.total_amount ELSE 0 END),0) AS sales, COUNT(DISTINCT t.id) AS trips,
            COALESCE((SELECT COUNT(*) FROM trip_seat_inventory tsi INNER JOIN trips tc ON tc.id=tsi.trip_id WHERE tc.company_id=co.id),0) AS total_seats,
            COALESCE((SELECT COUNT(*) FROM trip_seat_inventory tsi INNER JOIN trips tc ON tc.id=tsi.trip_id WHERE tc.company_id=co.id AND tsi.is_available=0),0) AS booked_seats
            FROM bookings b INNER JOIN trips t ON t.id=b.trip_id INNER JOIN companies co ON co.id=b.company_id WHERE {$bookingWhere}
            AND b.created_at >= :top_company_start AND b.created_at < :top_company_end GROUP BY co.id, co.trade_name ORDER BY sales DESC, bookings DESC LIMIT 5");
        $topCompanies->execute($topCompanyParams);
        $topAgentParams = $bookingParams + ['top_agent_start' => $start, 'top_agent_end' => $end];
        $topAgents = $pdo->prepare("SELECT COALESCE(u.full_name, 'حجز مباشر') AS agent_name, COUNT(b.id) AS bookings,
            COALESCE(SUM(CASE WHEN b.status='confirmed' THEN b.total_amount ELSE 0 END),0) AS sales,
            COALESCE(SUM(CASE WHEN b.status='confirmed' THEN b.commission_amount ELSE 0 END),0) AS commission,
            COALESCE(MAX(aw.balance),0) AS balance, COALESCE(MAX(aw.used_debt),0) AS debt
            FROM bookings b INNER JOIN trips t ON t.id=b.trip_id LEFT JOIN agents a ON a.id=b.agent_id LEFT JOIN users u ON u.id=a.user_id LEFT JOIN agent_wallets aw ON aw.agent_id=a.id
            WHERE {$bookingWhere} AND b.created_at >= :top_agent_start AND b.created_at < :top_agent_end GROUP BY b.agent_id, u.full_name ORDER BY bookings DESC LIMIT 5");
        $topAgents->execute($topAgentParams);
        $alerts = $pdo->prepare("SELECT t.trip_number, co.trade_name AS company_name, t.departure_at,
            (SELECT COUNT(*) FROM trip_seat_inventory x WHERE x.trip_id=t.id) AS total_seats,
            (SELECT COUNT(*) FROM trip_seat_inventory x WHERE x.trip_id=t.id AND x.is_available=0) AS booked_seats
            FROM trips t INNER JOIN companies co ON co.id=t.company_id WHERE {$tripWhere} AND t.status='open' AND t.departure_at >= NOW()
            ORDER BY t.departure_at LIMIT 20");
        foreach ($tripFilterParams as $key => $value) $alerts->bindValue(':' . $key, $value); $alerts->execute();
        $alertItems = []; foreach ($alerts->fetchAll() as $item) { $total = (int) $item['total_seats']; $booked = (int) $item['booked_seats']; if ($total > 0 && ($booked >= $total || ($booked / $total) >= .8)) { $alertItems[] = ['type' => $booked >= $total ? 'full' : 'near_full', 'message' => ($booked >= $total ? 'رحلة ممتلئة: ' : 'الرحلة قاربت على الامتلاء: ') . $item['company_name'] . ' — ' . $item['trip_number'], 'departure_at' => $item['departure_at']]; } }
        if ((int) ($metrics['current_pending'] ?? 0) > 0) $alertItems[] = ['type' => 'pending', 'message' => 'حجوزات بانتظار الإجراء: ' . (int) $metrics['current_pending'], 'departure_at' => null];
        if ((int) ($metrics['current_cancelled'] ?? 0) > 0) $alertItems[] = ['type' => 'cancelled', 'message' => 'حجوزات ملغاة في الفترة: ' . (int) $metrics['current_cancelled'], 'departure_at' => null];
        if ((float) ($agentMetrics['agent_debt'] ?? 0) > 0 && $this->canViewInternalFinancials($actor)) $alertItems[] = ['type' => 'debt', 'message' => 'يوجد رصيد دين على وكلاء النظام.', 'departure_at' => null];
        $latestParams = $bookingParams + ['latest_limit' => 8];
        $latest = $pdo->prepare("SELECT b.id, b.booking_number, b.status, b.total_amount, b.created_at, co.trade_name AS company_name, t.trip_number, cu.code AS currency_code, cu.symbol_ar AS currency_symbol, COALESCE(u.full_name, 'عميل مباشر') AS customer_name, COALESCE(au.full_name, 'مباشر') AS agent_name
            FROM bookings b INNER JOIN trips t ON t.id=b.trip_id INNER JOIN companies co ON co.id=b.company_id INNER JOIN currencies cu ON cu.id=b.currency_id
            LEFT JOIN customers c ON c.id=b.customer_id LEFT JOIN users u ON u.id=c.user_id LEFT JOIN agents ag ON ag.id=b.agent_id LEFT JOIN users au ON au.id=ag.user_id WHERE {$bookingWhere} ORDER BY b.created_at DESC LIMIT :latest_limit");
        $latest->bindValue(':latest_limit', 8, \PDO::PARAM_INT); foreach ($bookingParams as $key => $value) $latest->bindValue(':' . $key, $value); $latest->execute();
        $companyOptions = $pdo->query("SELECT id, trade_name FROM companies WHERE status='active' ORDER BY trade_name LIMIT 100")->fetchAll();
        $routeOptions = $pdo->query("SELECT id, name_ar FROM routes WHERE status='active' ORDER BY name_ar LIMIT 100")->fetchAll();
        $currencyOptions = $pdo->query("SELECT id, code, symbol_ar FROM currencies WHERE is_active=1 ORDER BY code LIMIT 50")->fetchAll();
        $agentOptions = $pdo->query("SELECT a.id, u.full_name FROM agents a INNER JOIN users u ON u.id=a.user_id WHERE a.status='active' ORDER BY u.full_name LIMIT 100")->fetchAll();
        $canViewFinancials = $this->canViewInternalFinancials($actor);
        $salesByCurrency = [];
        if ($canViewFinancials) {
            $currencySalesParams = $bookingParams + ['currency_sales_start' => $start, 'currency_sales_end' => $end, 'currency_sales_previous_start' => $previousStart, 'currency_sales_previous_end' => $previousEnd];
            $currencySales = $pdo->prepare("SELECT cu.code AS currency_code, cu.symbol_ar AS currency_symbol,
                COALESCE(SUM(CASE WHEN b.status='confirmed' AND b.created_at >= :currency_sales_start AND b.created_at < :currency_sales_end THEN b.total_amount ELSE 0 END),0) AS current_sales,
                COALESCE(SUM(CASE WHEN b.status='confirmed' AND b.created_at >= :currency_sales_previous_start AND b.created_at < :currency_sales_previous_end THEN b.total_amount ELSE 0 END),0) AS previous_sales
                FROM bookings b INNER JOIN currencies cu ON cu.id=b.currency_id INNER JOIN trips t ON t.id=b.trip_id WHERE {$bookingWhere} GROUP BY cu.id, cu.code, cu.symbol_ar ORDER BY cu.code");
            $currencySales->execute($currencySalesParams); $salesByCurrency = $currencySales->fetchAll();
        }
        $financial = $canViewFinancials ? ['current_sales' => (float) ($metrics['current_sales'] ?? 0), 'previous_sales' => (float) ($metrics['previous_sales'] ?? 0)] : ['current_sales' => null, 'previous_sales' => null];
        return ['period' => ['key' => $period, 'start' => $start, 'end' => $end, 'previous_start' => $previousStart, 'previous_end' => $previousEnd],
            'metrics' => ['bookings' => (int) ($metrics['current_bookings'] ?? 0), 'previous_bookings' => (int) ($metrics['previous_bookings'] ?? 0), 'all_bookings' => (int) ($extraMetrics['all_bookings'] ?? 0), 'today_bookings' => (int) ($extraMetrics['today_bookings'] ?? 0), 'confirmed' => (int) ($metrics['current_confirmed'] ?? 0), 'previous_confirmed' => (int) ($metrics['previous_confirmed'] ?? 0), 'cancelled' => (int) ($metrics['current_cancelled'] ?? 0), 'previous_cancelled' => (int) ($metrics['previous_cancelled'] ?? 0), 'pending' => (int) ($metrics['current_pending'] ?? 0), 'sales_count' => (int) ($metrics['current_sales_count'] ?? 0), 'customers' => (int) ($metrics['current_customers'] ?? 0), 'refunded' => (float) ($metrics['current_refunded'] ?? 0), 'agent_bookings' => (int) ($extraMetrics['agent_bookings'] ?? 0), 'trips' => (int) ($tripMetrics['total_trips'] ?? 0), 'previous_trips' => (int) ($tripMetrics['previous_trips'] ?? 0), 'open_trips' => (int) ($tripMetrics['open_trips'] ?? 0), 'completed_trips' => (int) ($tripMetrics['completed_trips'] ?? 0), 'cancelled_trips' => (int) ($tripMetrics['cancelled_trips'] ?? 0), 'total_seats' => (int) ($seatMetrics['total_seats'] ?? 0), 'booked_seats' => (int) ($seatMetrics['booked_seats'] ?? 0), 'available_seats' => max(0, (int) ($seatMetrics['total_seats'] ?? 0) - (int) ($seatMetrics['booked_seats'] ?? 0)), 'occupancy' => (int) ($seatMetrics['total_seats'] ?? 0) > 0 ? round(((int) ($seatMetrics['booked_seats'] ?? 0) / (int) $seatMetrics['total_seats']) * 100, 1) : 0, 'sales' => $financial['current_sales'], 'previous_sales' => $financial['previous_sales'], 'all_sales' => $financial['current_sales'] === null ? null : (float) ($extraMetrics['all_sales'] ?? 0), 'today_sales' => $financial['current_sales'] === null ? null : (float) ($extraMetrics['today_sales'] ?? 0), 'profit' => $financial['current_sales'] === null ? null : (float) ($extraMetrics['current_profit'] ?? 0), 'previous_profit' => $financial['current_sales'] === null ? null : (float) ($extraMetrics['previous_profit'] ?? 0), 'total_agents' => (int) ($agentMetrics['total_agents'] ?? 0), 'active_agents' => (int) ($agentMetrics['active_agents'] ?? 0), 'inactive_agents' => (int) ($agentMetrics['inactive_agents'] ?? 0), 'agent_balance' => $canViewFinancials ? (float) ($agentMetrics['agent_balance'] ?? 0) : null, 'agent_debt' => $canViewFinancials ? (float) ($agentMetrics['agent_debt'] ?? 0) : null, 'total_companies' => (int) ($companyMetrics['total_companies'] ?? 0), 'active_companies' => (int) ($companyMetrics['active_companies'] ?? 0), 'company_trips' => (int) ($companyMetrics['total_company_trips'] ?? 0)],
            'series' => ['bookings_sales' => $series->fetchAll(), 'trips' => $tripSeries->fetchAll()], 'sales_by_currency' => $salesByCurrency, 'service_breakdown' => [['label' => 'حجوزات الحافلات', 'total' => (int) ($metrics['current_bookings'] ?? 0)]], 'latest_bookings' => $latest->fetchAll(), 'upcoming_trips' => $upcoming->fetchAll(), 'top_companies' => $topCompanies->fetchAll(), 'top_agents' => $topAgents->fetchAll(), 'alerts' => $alertItems, 'filters' => ['companies' => $companyOptions, 'routes' => $routeOptions, 'currencies' => $currencyOptions, 'agents' => $agentOptions], 'can_view_financials' => $canViewFinancials];
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string} */
    private function periodWindow(array $filters): array
    {
        $period = in_array(($filters['period'] ?? 'this_month'), ['today', 'yesterday', 'this_week', 'previous_week', 'this_month', 'previous_month', 'this_year', 'custom'], true) ? (string) $filters['period'] : 'this_month';
        $now = new \DateTimeImmutable('now');
        if ($period === 'today') { $start = $now->setTime(0, 0); $end = $start->modify('+1 day'); }
        elseif ($period === 'yesterday') { $end = $now->setTime(0, 0); $start = $end->modify('-1 day'); }
        elseif ($period === 'this_week') { $start = $now->modify('monday this week')->setTime(0, 0); $end = $start->modify('+7 days'); }
        elseif ($period === 'previous_week') { $end = $now->modify('monday this week')->setTime(0, 0); $start = $end->modify('-7 days'); }
        elseif ($period === 'previous_month') { $end = $now->modify('first day of this month')->setTime(0, 0); $start = $end->modify('-1 month'); }
        elseif ($period === 'this_year') { $start = $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0); $end = $start->modify('+1 year'); }
        elseif ($period === 'custom' && $this->isDate((string) ($filters['start_date'] ?? '')) && $this->isDate((string) ($filters['end_date'] ?? ''))) { $start = new \DateTimeImmutable((string) $filters['start_date'] . ' 00:00:00'); $end = new \DateTimeImmutable((string) $filters['end_date'] . ' 00:00:00'); $end = $end->modify('+1 day'); }
        else { $start = $now->modify('first day of this month')->setTime(0, 0); $end = $start->modify('+1 month'); $period = 'this_month'; }
        $duration = max(86400, $end->getTimestamp() - $start->getTimestamp()); $previousStart = $start->modify('-' . $duration . ' seconds'); $previousEnd = $start;
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'), $previousStart->format('Y-m-d H:i:s'), $previousEnd->format('Y-m-d H:i:s'), $period];
    }

    /** @param list<string> $conditions @param array<string, mixed> $params */
    private function overviewIntegerFilter(array $filters, string $key, string $column, array &$conditions, array &$params, ?string $parameter = null): void
    {
        if (!isset($filters[$key]) || $filters[$key] === '') return;
        $value = filter_var($filters[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]); if ($value === false) return;
        $name = $parameter ?? 'filter_' . $key; $conditions[] = "{$column} = :{$name}"; $params[$name] = $value;
    }

    private function canViewInternalFinancials(array $actor): bool
    {
        return in_array('super_admin', $actor['roles'], true) || in_array('manage_payments', $actor['permissions'], true) || in_array('view_financial_reports', $actor['permissions'], true);
    }
}
