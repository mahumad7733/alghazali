<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/includes/functions.php';

class AlGhazali_ReportsEngine
{
    public static $AVAILABLE_REPORTS = [
        'errors' => ['label' => 'تقرير الأخطاء', 'class' => 'self::buildErrorsReport'],
        'security' => ['label' => 'التقرير الأمني', 'class' => 'self::buildSecurityReport'],
        'performance' => ['label' => 'تقرير الأداء', 'class' => 'self::buildPerformanceReport'],
        'users' => ['label' => 'تقرير المستخدمين', 'class' => 'self::buildUsersReport'],
        'financial' => ['label' => 'التقرير المالي', 'class' => 'self::buildFinancialReport'],
        'health' => ['label' => 'تقرير النظام الصحي', 'class' => 'self::buildHealthReport'],
    ];

    public static function buildReport($key, $filters = [])
    {
        ensure_system_admin_tables();
        if (!isset(self::$AVAILABLE_REPORTS[$key])) {
            return ['success' => false, 'error' => 'نوع التقرير غير صالح'];
        }
        $report = call_user_func(self::$AVAILABLE_REPORTS[$key]['class'], $filters);
        return [
            'success' => true,
            'key' => $key,
            'label' => self::$AVAILABLE_REPORTS[$key]['label'],
            'generated_at' => date('Y-m-d H:i:s'),
            'filters' => $filters,
            'title' => self::$AVAILABLE_REPORTS[$key]['label'] . ' - ' . date('Y-m-d H:i'),
            'sections' => $report,
        ];
    }

    public static function exportCsv($reportData, $filenameWithoutExt)
    {
        if (empty($reportData['sections'])) { return ''; }
        $out = fopen('php://temp', 'w+b');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel Arabic
        foreach ($reportData['sections'] as $section) {
            fwrite($out, "## " . ($section['title'] ?? '') . "\n");
            if (!empty($section['summary'])) {
                foreach ($section['summary'] as $k => $v) {
                    fputcsv($out, [$k, $v]);
                }
                fwrite($out, "\n");
            }
            if (!empty($section['rows']) && is_array($section['rows'])) {
                if (isset($section['rows'][0]) && is_array($section['rows'][0])) {
                    fputcsv($out, array_keys($section['rows'][0]));
                    foreach ($section['rows'] as $row) {
                        $cleaned = [];
                        foreach ($row as $k => $v) {
                            if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
                            elseif ($v === null) { $v = ''; }
                            $cleaned[$k] = $v;
                        }
                        fputcsv($out, $cleaned);
                    }
                } else {
                    foreach ($section['rows'] as $row) {
                        if (is_array($row)) { $row = json_encode($row, JSON_UNESCAPED_UNICODE); }
                        fputcsv($out, [$row]);
                    }
                }
            }
            fwrite($out, "\n\n");
        }
        rewind($out);
        $content = stream_get_contents($out);
        fclose($out);
        return $content;
    }

    public static function exportHtmlExcel($reportData)
    {
        $html = "<!DOCTYPE html>\n<html dir='rtl' lang='ar'><head><meta charset='UTF-8'><title>" . htmlspecialchars($reportData['title'] ?? 'Report') . "</title>";
        $html .= "<style>body{font-family:'Segoe UI',Tahoma,Arial,sans-serif;}table{border-collapse:collapse;width:100%;margin-bottom:20px;}th,td{border:1px solid #999;padding:6px 10px;}th{background:#eee;}h2{margin-top:30px;}.summary{background:#f9f9f9;border-left:5px solid #4285F4;padding:10px;margin-bottom:20px;}</style>";
        $html .= "</head><body>";
        $html .= "<h1 style='text-align:center'>" . htmlspecialchars($reportData['title'] ?? 'تقرير') . "</h1>";
        $html .= "<div style='text-align:center;color:#555'>تاريخ التصدير: " . date('Y-m-d H:i:s') . "</div>";
        foreach ($reportData['sections'] as $section) {
            $html .= "<h2>" . htmlspecialchars($section['title'] ?? '') . "</h2>";
            if (!empty($section['summary'])) {
                $html .= "<div class='summary'><ul>";
                foreach ($section['summary'] as $k => $v) {
                    if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
                    $html .= "<li><strong>" . htmlspecialchars((string)$k) . "</strong>: " . htmlspecialchars((string)$v) . "</li>";
                }
                $html .= "</ul></div>";
            }
            if (!empty($section['rows']) && is_array($section['rows'])) {
                if (isset($section['rows'][0]) && is_array($section['rows'][0])) {
                    $html .= "<table><thead><tr>";
                    foreach (array_keys($section['rows'][0]) as $col) {
                        $html .= "<th>" . htmlspecialchars((string)$col) . "</th>";
                    }
                    $html .= "</tr></thead><tbody>";
                    foreach ($section['rows'] as $row) {
                        $html .= "<tr>";
                        foreach ($row as $v) {
                            if (is_array($v)) { $v = json_encode($v, JSON_UNESCAPED_UNICODE); }
                            $html .= "<td>" . htmlspecialchars((string)($v ?? '')) . "</td>";
                        }
                        $html .= "</tr>";
                    }
                    $html .= "</tbody></table>";
                }
            }
        }
        $html .= "</body></html>";
        return $html;
    }

    private static function defaultDateFilter($filters)
    {
        $from = $filters['from_date'] ?? date('Y-m-d 00:00:00', strtotime('-30 days'));
        $to = $filters['to_date'] ?? date('Y-m-d 23:59:59');
        return [$from, $to];
    }

    private static function buildErrorsReport($filters = [])
    {
        global $pdo;
        $sections = [];
        if (!$pdo) { return $sections; }
        list($from, $to) = self::defaultDateFilter($filters);
        $q = $pdo->prepare("SELECT COUNT(*) FROM system_error_audit WHERE created_at BETWEEN ? AND ?");
        $q->execute([$from, $to]);
        $total = (int)$q->fetchColumn();
        $q = $pdo->prepare("SELECT COUNT(*) FROM system_error_audit WHERE created_at BETWEEN ? AND ? AND (level IN ('CRITICAL','EMERGENCY') OR priority = 'critical')");
        $q->execute([$from, $to]);
        $critical = (int)$q->fetchColumn();
        $q = $pdo->prepare("SELECT status, COUNT(*) as c FROM system_error_audit WHERE created_at BETWEEN ? AND ? GROUP BY status");
        $q->execute([$from, $to]);
        $byStatus = $q->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $sections[] = [
            'title' => 'ملخص التقرير',
            'summary' => [
                'إجمالي الأخطاء' => $total,
                'الأخطاء الحرجة' => $critical,
                'الفترة من' => $from,
                'الفترة إلى' => $to,
                'حسب الحالة' => json_encode($byStatus, JSON_UNESCAPED_UNICODE),
            ],
            'rows' => [],
        ];
        $q = $pdo->prepare("SELECT id, created_at, level, priority, status, file, line, message, occurrences, url, user_id FROM system_error_audit WHERE created_at BETWEEN ? AND ? ORDER BY created_at DESC LIMIT 500");
        $q->execute([$from, $to]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $sections[] = [
            'title' => 'تفاصيل الأخطاء',
            'summary' => ['عدد السجلات' => count($rows)],
            'rows' => $rows,
        ];
        return $sections;
    }

    private static function buildSecurityReport($filters = [])
    {
        global $pdo;
        $sections = [];
        if (!$pdo) { return $sections; }
        list($from, $to) = self::defaultDateFilter($filters);
        $qV = $pdo->prepare("SELECT vulnerability_type, severity, COUNT(*) as cnt FROM security_vulnerabilities WHERE created_at BETWEEN ? AND ? GROUP BY vulnerability_type, severity ORDER BY cnt DESC");
        $qV->execute([$from, $to]);
        $vulnStats = $qV->fetchAll(PDO::FETCH_ASSOC);
        $qE = $pdo->prepare("SELECT event_type, severity, COUNT(*) as cnt FROM security_events WHERE occurred_at BETWEEN ? AND ? GROUP BY event_type, severity ORDER BY cnt DESC");
        $qE->execute([$from, $to]);
        $eventStats = $qE->fetchAll(PDO::FETCH_ASSOC);
        $sections[] = [
            'title' => 'ملخص الثغرات',
            'summary' => ['الفترة' => "$from إلى $to", "عدد أنواع الثغرات" => count($vulnStats), "عدد أنواع الأحداث" => count($eventStats)],
            'rows' => $vulnStats,
        ];
        $sections[] = [
            'title' => 'الأحداث الأمنية حسب النوع',
            'summary' => [],
            'rows' => $eventStats,
        ];
        $q = $pdo->prepare("SELECT * FROM security_vulnerabilities WHERE created_at BETWEEN ? AND ? ORDER BY severity DESC, created_at DESC LIMIT 300");
        $q->execute([$from, $to]);
        $sections[] = [
            'title' => 'تفاصيل الثغرات المكتشفة',
            'summary' => ['عدد السجلات' => $q->rowCount()],
            'rows' => $q->fetchAll(PDO::FETCH_ASSOC),
        ];
        return $sections;
    }

    private static function buildPerformanceReport($filters = [])
    {
        global $pdo;
        $sections = [];
        if (!$pdo) { return $sections; }
        list($from, $to) = self::defaultDateFilter($filters);
        $q = $pdo->prepare("SELECT COUNT(*) as requests, AVG(total_execution_ms) as avg_ms, MAX(total_execution_ms) as max_ms, AVG(memory_peak_bytes) as avg_mem_bytes FROM system_performance_logs WHERE timestamp BETWEEN ? AND ?");
        $q->execute([$from, $to]);
        $overview = $q->fetch(PDO::FETCH_ASSOC);
        $sections[] = [
            'title' => 'نظرة عامة على الأداء',
            'summary' => [
                'الفترة' => "$from إلى $to",
                'إجمالي الطلبات' => $overview['requests'] ?? 0,
                'متوسط وقت التنفيذ (ms)' => round($overview['avg_ms'] ?? 0, 2),
                'أقصى وقت تنفيذ (ms)' => $overview['max_ms'] ?? 0,
                'متوسط الذاكرة الذروة (MB)' => round(($overview['avg_mem_bytes'] ?? 0) / (1024*1024), 2),
            ],
            'rows' => [],
        ];
        require_once __DIR__ . '/PerformanceMonitor.php';
        $sections[] = [
            'title' => 'أبطأ الصفحات',
            'summary' => [],
            'rows' => AlGhazali_PerformanceMonitor::slowestPages(720, 20),
        ];
        $sections[] = [
            'title' => 'أبطأ الاستعلامات',
            'summary' => [],
            'rows' => AlGhazali_PerformanceMonitor::slowestQueries(720, 50),
        ];
        return $sections;
    }

    private static function buildUsersReport($filters = [])
    {
        global $pdo;
        $sections = [];
        if (!$pdo) { return $sections; }
        $users = $pdo->query("SELECT u.id, u.username, u.full_name, NULL AS email, u.status,
            u.last_seen AS last_login_at, r.name AS role_name, b.branch_name
            FROM users u
            LEFT JOIN roles r ON r.id = u.role_id
            LEFT JOIN branches b ON b.id = u.branch_id
            ORDER BY u.id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $sections[] = [
            'title' => 'قائمة المستخدمين والصلاحيات',
            'summary' => ['إجمالي المستخدمين' => count($users)],
            'rows' => $users,
        ];
        list($from, $to) = self::defaultDateFilter($filters);
        $activity = $pdo->prepare("SELECT user_id, username, full_name, activity_type, COUNT(*) as c
            FROM user_activity_logs WHERE created_at BETWEEN ? AND ?
            GROUP BY user_id, username, full_name, activity_type
            ORDER BY c DESC LIMIT 500");
        $activity->execute([$from, $to]);
        $sections[] = [
            'title' => "أكثر المستخدمين نشاطاً خلال الفترة ($from إلى $to)",
            'summary' => [],
            'rows' => $activity->fetchAll(PDO::FETCH_ASSOC),
        ];
        return $sections;
    }

    private static function buildFinancialReport($filters = [])
    {
        global $pdo;
        $sections = [];
        if (!$pdo) { return $sections; }
        list($from, $to) = self::defaultDateFilter($filters);
        $invTotals = $pdo->prepare("SELECT status, invoice_type, COUNT(*) as cnt, COALESCE(SUM(total_amount),0) as total
            FROM invoices WHERE created_at BETWEEN ? AND ? GROUP BY status, invoice_type");
        $invTotals->execute([$from, $to]);
        $sections[] = [
            'title' => "ملخص الفواتير خلال الفترة ($from إلى $to)",
            'summary' => [],
            'rows' => $invTotals->fetchAll(PDO::FETCH_ASSOC),
        ];
        $ft = $pdo->prepare("SELECT transaction_type, status, COALESCE(SUM(amount),0) as total, COUNT(*) as cnt
            FROM financial_transactions WHERE transaction_date BETWEEN ? AND ? GROUP BY transaction_type, status");
        $ft->execute([$from, $to]);
        $sections[] = [
            'title' => 'ملخص العمليات المالية (القبض / الصرف / ...)',
            'summary' => [],
            'rows' => $ft->fetchAll(PDO::FETCH_ASSOC),
        ];
        $fta = $pdo->prepare("SELECT * FROM financial_transaction_audit WHERE occurred_at BETWEEN ? AND ? ORDER BY occurred_at DESC LIMIT 300");
        $fta->execute([$from, $to]);
        $sections[] = [
            'title' => 'سجل تعديلات العمليات المالية الحساسة (قبل/بعد)',
            'summary' => ['عدد التعديلات المسجلة' => $fta->rowCount()],
            'rows' => $fta->fetchAll(PDO::FETCH_ASSOC),
        ];
        return $sections;
    }

    private static function buildHealthReport($filters = [])
    {
        require_once __DIR__ . '/HealthCheck.php';
        $sections = [];
        $result = AlGhazali_HealthCheck::runAll(false);
        $sections[] = [
            'title' => 'الحالة الصحية الحالية للنظام',
            'summary' => [
                'الوقت' => date('Y-m-d H:i:s'),
                'الحالة العامة' => $result['overall'],
            ],
            'rows' => $result['components'],
        ];
        $sections[] = [
            'title' => 'سجل الفحوصات الصحية السابقة',
            'summary' => [],
            'rows' => AlGhazali_HealthCheck::recentHistory(100),
        ];
        return $sections;
    }
}
