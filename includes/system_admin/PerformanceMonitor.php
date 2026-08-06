<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/includes/functions.php';

class AlGhazali_PerformanceMonitor
{
    private static $requestId = null;
    private static $startTime = null;
    private static $dbStartTime = null;
    private static $dbQueryCount = 0;
    private static $slowQueries = [];
    private static $errorCount = 0;
    private static $warningCount = 0;
    private static $apiCount = 0;
    private static $booted = false;

    public static function boot()
    {
        if (self::$booted) { return; }
        self::$booted = true;
        self::$requestId = self::genRequestId();
        self::$startTime = microtime(true);
        self::$dbStartTime = microtime(true);
        register_shutdown_function([self::class, 'onShutdownSave']);
    }

    public static function genRequestId()
    {
        if (function_exists('random_bytes')) {
            try { return bin2hex(random_bytes(16)); } catch (\Throwable $e) { /* fallthrough */ }
        }
        return md5(uniqid((string)mt_rand(), true));
    }

    public static function getRequestId()
    {
        if (self::$requestId === null) { self::$requestId = self::genRequestId(); }
        return self::$requestId;
    }

    public static function setDbStartTime($t = null)
    {
        self::$dbStartTime = $t ?: microtime(true);
    }

    public static function incrementDbQueryCount($n = 1)
    {
        self::$dbQueryCount += (int)$n;
    }

    public static function recordSlowQuery($sql, $durationMs, $params = [])
    {
        if ($durationMs < 500) { return; }
        self::$slowQueries[] = [
            'sql' => mb_substr(preg_replace('/\s+/', ' ', trim($sql)), 0, 500),
            'ms' => round($durationMs, 2),
            'params_count' => count($params),
        ];
    }

    public static function incrementError() { self::$errorCount += 1; }
    public static function incrementWarning() { self::$warningCount += 1; }
    public static function incrementApi() { self::$apiCount += 1; }

    public static function elapsedMs($fromTime)
    {
        return (microtime(true) - (float)$fromTime) * 1000.0;
    }

    public static function currentPagePath()
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if ($script) { return $script; }
        return $_SERVER['PHP_SELF'] ?? null;
    }

    public static function getCpuUsage()
    {
        if (function_exists('sys_getloadavg')) {
            $load = @sys_getloadavg();
            if (is_array($load) && isset($load[0])) {
                $cores = self::detectCpuCores();
                if ($cores > 0) {
                    $percent = ($load[0] / $cores) * 100;
                    return min(100, round($percent, 2));
                }
                return round($load[0] * 10, 2);
            }
        }
        return null;
    }

    public static function detectCpuCores()
    {
        static $cores = null;
        if ($cores !== null) { return $cores; }
        $cores = 1;
        if (DIRECTORY_SEPARATOR === '\\') {
            $cmd = "wmic cpu get NumberOfLogicalProcessors 2>NUL";
            $out = @shell_exec($cmd);
            if ($out && preg_match_all('/\d+/', $out, $m)) {
                $found = array_filter(array_map('intval', $m[0]), function ($v) { return $v > 0; });
                if (!empty($found)) { $cores = (int)max($found); }
            }
        } else {
            if (is_file('/proc/cpuinfo')) {
                $cpuinfo = @file_get_contents('/proc/cpuinfo');
                if ($cpuinfo && preg_match_all('/^processor\s*:\s*\d+/m', $cpuinfo, $m)) {
                    $cores = max(1, count($m[0]));
                }
            }
        }
        return (int)$cores;
    }

    public static function onShutdownSave()
    {
        ensure_system_admin_tables();
        global $pdo;
        if (!$pdo) { return; }
        if (self::$startTime === null) { self::$startTime = microtime(true); }
        $totalMs = self::elapsedMs(self::$startTime);
        $dbMs = self::$dbStartTime ? self::elapsedMs(self::$dbStartTime) : null;
        $mem = function_exists('memory_get_peak_usage') ? memory_get_peak_usage(true) : null;
        $cpu = self::getCpuUsage();
        if ($totalMs < 15 && empty(self::$slowQueries)) { return; }
        try {
            $stmt = $pdo->prepare("INSERT INTO system_performance_logs
                (request_id, script_path, request_method, url, user_id, ip_address, total_execution_ms, db_execution_ms, db_query_count, memory_peak_bytes, cpu_usage_percent, slow_queries_json, page_render_ms, api_calls_count, errors_count, warnings_count)
                VALUES (:rid, :sp, :rm, :url, :uid, :ip, :tms, :dms, :dcnt, :mem, :cpu, :sq, :prm, :api, :ec, :wc)");
            $stmt->execute([
                ':rid' => self::getRequestId(),
                ':sp' => self::currentPagePath(),
                ':rm' => $_SERVER['REQUEST_METHOD'] ?? null,
                ':url' => isset($_SERVER['REQUEST_URI']) ? mb_substr($_SERVER['REQUEST_URI'], 0, 1000) : null,
                ':uid' => $_SESSION['admin_id'] ?? ($_SESSION['user_id'] ?? null),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':tms' => round($totalMs),
                ':dms' => $dbMs !== null ? round($dbMs) : null,
                ':dcnt' => self::$dbQueryCount,
                ':mem' => $mem,
                ':cpu' => $cpu,
                ':sq' => !empty(self::$slowQueries) ? json_encode(self::$slowQueries, JSON_UNESCAPED_UNICODE) : null,
                ':prm' => null,
                ':api' => self::$apiCount,
                ':ec' => self::$errorCount,
                ':wc' => self::$warningCount,
            ]);
        } catch (\Throwable $e) { /* ignore shutdown error */ }
    }

    public static function slowestPages($intervalHours = 24, $limit = 20)
    {
        global $pdo;
        if (!$pdo) { return []; }
        $sql = "SELECT script_path,
                    COUNT(*) as requests,
                    AVG(total_execution_ms) as avg_ms,
                    MAX(total_execution_ms) as max_ms,
                    SUM(total_execution_ms) as sum_ms
                FROM system_performance_logs
                WHERE timestamp >= DATE_SUB(NOW(), INTERVAL " . (int)$intervalHours . " HOUR)
                GROUP BY script_path
                ORDER BY avg_ms DESC
                LIMIT " . (int)$limit;
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function slowestQueries($intervalHours = 24, $limit = 20)
    {
        global $pdo;
        if (!$pdo) { return []; }
        $rows = $pdo->query("SELECT id, slow_queries_json, request_id, script_path, timestamp
            FROM system_performance_logs
            WHERE timestamp >= DATE_SUB(NOW(), INTERVAL " . (int)$intervalHours . " HOUR)
              AND slow_queries_json IS NOT NULL
              AND CHAR_LENGTH(slow_queries_json) > 4
            ORDER BY total_execution_ms DESC
            LIMIT " . ((int)$limit * 3))->fetchAll(PDO::FETCH_ASSOC);
        $flat = [];
        foreach ($rows as $r) {
            $sq = json_decode($r['slow_queries_json'], true);
            if (!is_array($sq)) { continue; }
            foreach ($sq as $q) {
                $flat[] = array_merge($q, [
                    'request_id' => $r['request_id'],
                    'script_path' => $r['script_path'],
                    'timestamp' => $r['timestamp'],
                ]);
            }
        }
        usort($flat, function ($a, $b) { return $b['ms'] <=> $a['ms']; });
        return array_slice($flat, 0, $limit);
    }

    public static function memoryStats($intervalHours = 24)
    {
        global $pdo;
        if (!$pdo) { return []; }
        $sql = "SELECT
                    AVG(memory_peak_bytes) as avg_bytes,
                    MAX(memory_peak_bytes) as max_bytes,
                    script_path
                FROM system_performance_logs
                WHERE timestamp >= DATE_SUB(NOW(), INTERVAL " . (int)$intervalHours . " HOUR)
                GROUP BY script_path
                ORDER BY max_bytes DESC
                LIMIT 20";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }
}
