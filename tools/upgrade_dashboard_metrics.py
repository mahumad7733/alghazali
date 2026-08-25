from pathlib import Path

root = Path('/home/ubuntu/bus-booking-system/infinityfree')
service = root / 'includes' / 'DashboardService.php'
text = service.read_text()

old = """            COUNT(CASE WHEN b.created_at >= :metric_cs5 AND b.created_at < :metric_ce5 AND b.status = 'confirmed' THEN 1 END) AS current_confirmed,
            COUNT(CASE WHEN b.created_at >= :metric_ps3 AND b.created_at < :metric_pe3 AND b.status = 'confirmed' THEN 1 END) AS previous_confirmed,
            COUNT(CASE WHEN b.created_at >= :metric_cs3 AND b.created_at < :metric_ce3 AND b.status = 'cancelled' THEN 1 END) AS current_cancelled,
            COUNT(CASE WHEN b.created_at >= :metric_cs4 AND b.created_at < :metric_ce4 AND b.status = 'pending' THEN 1 END) AS current_pending,
            COALESCE(SUM(CASE WHEN b.created_at >= :metric_cs5 AND b.created_at < :metric_ce5 AND b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS current_sales,
            COALESCE(SUM(CASE WHEN b.created_at >= :metric_ps3 AND b.created_at < :metric_pe3 AND b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS previous_sales"""
new = """            COUNT(CASE WHEN b.created_at >= :metric_cs5 AND b.created_at < :metric_ce5 AND b.status = 'confirmed' THEN 1 END) AS current_confirmed,
            COUNT(CASE WHEN b.created_at >= :metric_ps3 AND b.created_at < :metric_pe3 AND b.status = 'confirmed' THEN 1 END) AS previous_confirmed,
            COUNT(CASE WHEN b.created_at >= :metric_cs3 AND b.created_at < :metric_ce3 AND b.status = 'cancelled' THEN 1 END) AS current_cancelled,
            COUNT(CASE WHEN b.created_at >= :metric_cs4 AND b.created_at < :metric_ce4 AND b.status = 'pending' THEN 1 END) AS current_pending,
            COUNT(CASE WHEN b.created_at >= :metric_cs5 AND b.created_at < :metric_ce5 AND b.status = 'confirmed' THEN 1 END) AS current_sales_count,
            COUNT(DISTINCT CASE WHEN b.created_at >= :metric_cs5 AND b.created_at < :metric_ce5 THEN b.customer_id END) AS current_customers,
            COALESCE(SUM(CASE WHEN b.created_at >= :metric_cs5 AND b.created_at < :metric_ce5 AND b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS current_sales,
            COALESCE(SUM(CASE WHEN b.created_at >= :metric_ps3 AND b.created_at < :metric_pe3 AND b.status = 'confirmed' THEN b.total_amount ELSE 0 END), 0) AS previous_sales,
            COALESCE(SUM(CASE WHEN b.created_at >= :metric_cs5 AND b.created_at < :metric_ce5 AND b.payment_status = 'refunded' THEN b.total_amount ELSE 0 END), 0) AS current_refunded"""
if old not in text:
    raise SystemExit('metrics select target not found')
text = text.replace(old, new, 1)

old = """        $companyTotals = $pdo->prepare(\"SELECT COUNT(*) AS total_companies, SUM(c.status='active') AS active_companies, (SELECT COUNT(*) FROM trips) AS total_company_trips FROM companies c\");
        $companyTotals->execute(); $companyMetrics = $companyTotals->fetch() ?: [];"""
new = """        $companyConditions = ['1=1']; $companyParams = [];
        if ($actor['agent_id'] !== null) { $companyConditions[] = 'c.id = :company_agent_company'; $companyParams['company_agent_company'] = $actor['company_id']; }
        elseif (!in_array('super_admin', $actor['roles'], true)) { $companyConditions[] = 'c.id = :company_scope'; $companyParams['company_scope'] = $actor['company_id']; }
        $companyWhere = implode(' AND ', $companyConditions);
        $companyTotals = $pdo->prepare(\"SELECT COUNT(*) AS total_companies, SUM(c.status='active') AS active_companies, (SELECT COUNT(*) FROM trips t2 WHERE t2.company_id = c.id) AS total_company_trips FROM companies c WHERE {$companyWhere}\");
        $companyTotals->execute($companyParams); $companyMetrics = $companyTotals->fetch() ?: [];"""
if old not in text:
    raise SystemExit('company totals target not found')
text = text.replace(old, new, 1)

old = """        $latestParams = $bookingParams + ['latest_limit' => 8];
        $latest = $pdo->prepare(\"SELECT b.id, b.booking_number, b.status, b.total_amount, b.created_at, co.trade_name AS company_name, t.trip_number, cu.code AS currency_code, cu.symbol_ar AS currency_symbol, COALESCE(u.full_name, 'عميل مباشر') AS customer_name, COALESCE(au.full_name, 'مباشر') AS agent_name
            FROM bookings b INNER JOIN trips t ON t.id=b.trip_id INNER JOIN companies co ON co.id=b.company_id INNER JOIN currencies cu ON cu.id=b.currency_id
            LEFT JOIN customers c ON c.id=b.customer_id LEFT JOIN users u ON u.id=c.user_id LEFT JOIN agents ag ON ag.id=b.agent_id LEFT JOIN users au ON au.id=ag.user_id WHERE {$bookingWhere} ORDER BY b.created_at DESC LIMIT :latest_limit\");"""
new = """        $latestParams = $bookingParams + ['latest_limit' => 8];
        $latest = $pdo->prepare(\"SELECT b.id, b.booking_number, b.status, b.total_amount, b.created_at, co.trade_name AS company_name, t.trip_number, cu.code AS currency_code, cu.symbol_ar AS currency_symbol, COALESCE(u.full_name, 'عميل مباشر') AS customer_name, COALESCE(au.full_name, 'مباشر') AS agent_name, COALESCE(creator.full_name, 'النظام') AS created_by_name
            FROM bookings b INNER JOIN trips t ON t.id=b.trip_id INNER JOIN companies co ON co.id=b.company_id INNER JOIN currencies cu ON cu.id=b.currency_id
            LEFT JOIN customers c ON c.id=b.customer_id LEFT JOIN users u ON u.id=c.user_id LEFT JOIN agents ag ON ag.id=b.agent_id LEFT JOIN users au ON au.id=ag.user_id LEFT JOIN users creator ON creator.id=b.created_by_user_id WHERE {$bookingWhere} ORDER BY b.created_at DESC LIMIT :latest_limit\");"""
if old not in text:
    raise SystemExit('latest query target not found')
text = text.replace(old, new, 1)

# Add a grouped currency result immediately before canViewFinancials.
old = """        $canViewFinancials = $this->canViewInternalFinancials($actor);
        $financial = $canViewFinancials ? ['current_sales' => (float) ($metrics['current_sales'] ?? 0), 'previous_sales' => (float) ($metrics['previous_sales'] ?? 0)] : ['current_sales' => null, 'previous_sales' => null];"""
new = """        $canViewFinancials = $this->canViewInternalFinancials($actor);
        $salesByCurrency = [];
        if ($canViewFinancials) {
            $currencySalesParams = $bookingParams + ['currency_sales_start' => $start, 'currency_sales_end' => $end, 'currency_sales_previous_start' => $previousStart, 'currency_sales_previous_end' => $previousEnd];
            $currencySales = $pdo->prepare(\"SELECT cu.code AS currency_code, cu.symbol_ar AS currency_symbol,
                COALESCE(SUM(CASE WHEN b.status='confirmed' AND b.created_at >= :currency_sales_start AND b.created_at < :currency_sales_end THEN b.total_amount ELSE 0 END),0) AS current_sales,
                COALESCE(SUM(CASE WHEN b.status='confirmed' AND b.created_at >= :currency_sales_previous_start AND b.created_at < :currency_sales_previous_end THEN b.total_amount ELSE 0 END),0) AS previous_sales
                FROM bookings b INNER JOIN currencies cu ON cu.id=b.currency_id INNER JOIN trips t ON t.id=b.trip_id WHERE {$bookingWhere} GROUP BY cu.id, cu.code, cu.symbol_ar ORDER BY cu.code\");
            $currencySales->execute($currencySalesParams); $salesByCurrency = $currencySales->fetchAll();
        }
        $financial = $canViewFinancials ? ['current_sales' => (float) ($metrics['current_sales'] ?? 0), 'previous_sales' => (float) ($metrics['previous_sales'] ?? 0)] : ['current_sales' => null, 'previous_sales' => null];"""
if old not in text:
    raise SystemExit('financial target not found')
text = text.replace(old, new, 1)

old = """'bookings' => (int) ($metrics['current_bookings'] ?? 0), 'previous_bookings' => (int) ($metrics['previous_bookings'] ?? 0), 'all_bookings' => (int) ($extraMetrics['all_bookings'] ?? 0), 'today_bookings' => (int) ($extraMetrics['today_bookings'] ?? 0), 'confirmed' => (int) ($metrics['current_confirmed'] ?? 0), 'previous_confirmed' => (int) ($metrics['previous_confirmed'] ?? 0), 'cancelled' => (int) ($metrics['current_cancelled'] ?? 0), 'pending' => (int) ($metrics['current_pending'] ?? 0),"""
new = """'bookings' => (int) ($metrics['current_bookings'] ?? 0), 'previous_bookings' => (int) ($metrics['previous_bookings'] ?? 0), 'all_bookings' => (int) ($extraMetrics['all_bookings'] ?? 0), 'today_bookings' => (int) ($extraMetrics['today_bookings'] ?? 0), 'confirmed' => (int) ($metrics['current_confirmed'] ?? 0), 'previous_confirmed' => (int) ($metrics['previous_confirmed'] ?? 0), 'cancelled' => (int) ($metrics['current_cancelled'] ?? 0), 'pending' => (int) ($metrics['current_pending'] ?? 0), 'sales_count' => (int) ($metrics['current_sales_count'] ?? 0), 'customers' => (int) ($metrics['current_customers'] ?? 0), 'refunded' => (float) ($metrics['current_refunded'] ?? 0),"""
if old not in text:
    raise SystemExit('metrics return target not found')
text = text.replace(old, new, 1)

old = """'series' => ['bookings_sales' => $series->fetchAll(), 'trips' => $tripSeries->fetchAll()], 'latest_bookings' => $latest->fetchAll(), 'upcoming_trips' => $upcoming->fetchAll(), 'top_companies' => $topCompanies->fetchAll(), 'top_agents' => $topAgents->fetchAll(), 'alerts' => $alertItems, 'filters' =>"""
new = """'series' => ['bookings_sales' => $series->fetchAll(), 'trips' => $tripSeries->fetchAll()], 'sales_by_currency' => $salesByCurrency, 'service_breakdown' => [['label' => 'حجوزات الحافلات', 'total' => (int) ($metrics['current_bookings'] ?? 0)]], 'latest_bookings' => $latest->fetchAll(), 'upcoming_trips' => $upcoming->fetchAll(), 'top_companies' => $topCompanies->fetchAll(), 'top_agents' => $topAgents->fetchAll(), 'alerts' => $alertItems, 'filters' =>"""
if old not in text:
    raise SystemExit('return series target not found')
text = text.replace(old, new, 1)

service.write_text(text)
PY
