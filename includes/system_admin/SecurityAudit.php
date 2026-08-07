<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/includes/functions.php';

class AlGhazali_SecurityAudit
{
    public static function detectSqlInjectionPatterns($input, $sourceContext = null)
    {
        if (!is_string($input) || trim($input) === '') {
            return [];
        }
        $patterns = [
            '/(\bunion\b.*\bselect\b)/is' => 'UNION SELECT pattern',
            '/(\bor\b\s+\d+\s*=\s*\d+)/is' => 'OR 1=1 tautology',
            "/('.*\s*\bor\b\s*'.*')/is" => "OR string tautology",
            '/(\bunion\b\s+all\b)/is' => 'UNION ALL',
            '/(--\s|#\s)/' => 'SQL comment terminator',
            '/(\binsert\b.*\binto\b)/is' => 'INSERT INTO',
            '/(\bdrop\b.*\btable\b)/is' => 'DROP TABLE',
            '/(\bdelete\b\s+\bfrom\b)/is' => 'DELETE FROM',
            '/(\bupdate\b.*\bset\b)/is' => 'UPDATE SET',
            '/(\bsleep\s*\()/is' => 'SLEEP() timing attack',
            '/(\bbenchmark\s*\()/is' => 'BENCHMARK() timing attack',
            '/(0x[0-9a-f]{2,})/is' => 'Hex-encoded payload',
        ];
        $hits = [];
        foreach ($patterns as $re => $label) {
            if (preg_match($re, $input)) {
                $hits[] = ['pattern' => $label, 'regex' => $re, 'context' => $sourceContext];
            }
        }
        return $hits;
    }

    public static function detectXssPatterns($input)
    {
        if (!is_string($input) || trim($input) === '') {
            return [];
        }
        $patterns = [
            '/<script\b[^>]*>.*<\/script>/is' => 'script tag pair',
            '/<\s*script/is' => 'script open tag',
            '/javascript\s*:/is' => 'javascript: scheme',
            '/on\w+\s*=\s*"[^"]*"/i' => 'event handler with double quotes',
            "/on\w+\s*=\s*'[^']*'/i" => "event handler with single quotes",
            '/on\w+\s*=\s*\w+/i' => 'event handler unquoted',
            '/<\s*iframe/is' => 'iframe tag',
            '/<\s*img[^>]*onerror/is' => 'img onerror',
            '/(eval\s*\()/is' => 'eval()',
            '/(document\.cookie)/i' => 'document.cookie access',
        ];
        $hits = [];
        foreach ($patterns as $re => $label) {
            if (preg_match($re, $input)) {
                $hits[] = ['pattern' => $label, 'regex' => $re];
            }
        }
        return $hits;
    }

    public static function auditRequestGlobals()
    {
        $findings = [];
        $scanSources = [
            'GET' => $_GET ?? [],
            'POST' => $_POST ?? [],
            'COOKIE' => $_COOKIE ?? [],
            'REQUEST' => $_REQUEST ?? [],
        ];
        foreach ($scanSources as $srcName => $arr) {
            foreach ((array)$arr as $key => $value) {
                if (is_string($value)) {
                    $sqlHits = self::detectSqlInjectionPatterns($value, "$srcName:$key");
                    foreach ($sqlHits as $h) {
                        $findings[] = [
                            'type' => 'sql_injection',
                            'severity' => 'high',
                            'title' => "SQLi pattern detected in $srcName parameter '$key'",
                            'source' => $srcName,
                            'param' => $key,
                            'detail' => $h,
                        ];
                    }
                    $xssHits = self::detectXssPatterns($value);
                    foreach ($xssHits as $h) {
                        $findings[] = [
                            'type' => 'xss',
                            'severity' => 'medium',
                            'title' => "XSS pattern detected in $srcName parameter '$key'",
                            'source' => $srcName,
                            'param' => $key,
                            'detail' => $h,
                        ];
                    }
                }
            }
        }
        return $findings;
    }

    public static function saveFinding($type, $severity, $title, $extra = [])
    {
        ensure_system_admin_tables();
        global $pdo;
        if (!$pdo) {
            return null;
        }
        try {
            $payload = !empty($extra['payload_sample']) ? $extra['payload_sample'] : null;
            if ($payload && strlen($payload) > 120 && in_array($type, ['sql_injection', 'xss', 'password_issue'], true)) {
                $payload = substr($payload, 0, 80) . '[REDACTED_TRUNCATED]';
            }
            $stmt = $pdo->prepare("INSERT INTO security_vulnerabilities
                (vulnerability_type, severity, confidence, title, description, affected_url, affected_file, ip_address, user_id, payload_sample, evidence_json, status, cvss_score, tags)
                VALUES (:vt, :sv, :cnf, :t, :d, :url, :af, :ip, :uid, :ps, :ej, :st, :cs, :tg)");
            $stmt->execute([
                ':vt' => $type,
                ':sv' => in_array($severity, ['low', 'medium', 'high', 'critical']) ? $severity : 'medium',
                ':cnf' => (int)($extra['confidence'] ?? 60),
                ':t' => mb_substr($title, 0, 255),
                ':d' => !empty($extra['description']) ? $extra['description'] : null,
                ':url' => isset($_SERVER['REQUEST_URI']) ? mb_substr($_SERVER['REQUEST_URI'], 0, 500) : null,
                ':af' => !empty($extra['affected_file']) ? $extra['affected_file'] : null,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':uid' => $_SESSION['admin_id'] ?? ($_SESSION['user_id'] ?? null),
                ':ps' => $payload,
                ':ej' => !empty($extra['evidence']) ? json_encode($extra['evidence'], JSON_UNESCAPED_UNICODE) : null,
                ':st' => 'open',
                ':cs' => !empty($extra['cvss']) ? $extra['cvss'] : null,
                ':tg' => !empty($extra['tags']) ? implode(',', (array)$extra['tags']) : null,
            ]);
            return $pdo->lastInsertId();
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function runPassiveAudit()
    {
        $findings = self::auditRequestGlobals();
        foreach ($findings as $f) {
            $eventType = null;
            if ($f['type'] === 'sql_injection') {
                $eventType = 'sql_injection_attempt';
            } elseif ($f['type'] === 'xss') {
                $eventType = 'xss_attempt';
            }
            if ($eventType) {
                record_security_event($eventType, $f['severity'], [
                    'finding_title' => $f['title'],
                    'param' => $f['param'] ?? null,
                    'source' => $f['source'] ?? null,
                    'detail' => $f['detail'] ?? null,
                ]);
            }
            self::saveFinding($f['type'], $f['severity'], $f['title'], [
                'evidence' => $f['detail'] ?? null,
                'confidence' => 65,
            ]);
        }
        return count($findings);
    }

    public static function listFindings($filters = [], $limit = 100, $offset = 0)
    {
        ensure_system_admin_tables();
        global $pdo;
        if (!$pdo) {
            return [];
        }
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['type'])) {
            $where[] = "vulnerability_type = ?";
            $params[] = $filters['type'];
        }
        if (!empty($filters['severity'])) {
            $where[] = "severity = ?";
            $params[] = $filters['severity'];
        }
        if (!empty($filters['status'])) {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(title LIKE ? OR description LIKE ?)";
            $s = '%' . $filters['search'] . '%';
            array_push($params, $s, $s);
        }
        $sql = "SELECT * FROM security_vulnerabilities WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function updateFindingStatus($id, $newStatus, $userId = null, $resolution = null)
    {
        ensure_system_admin_tables();
        global $pdo;
        if (!$pdo) {
            return false;
        }
        try {
            $updates = ["status = ?"];
            $params = [$newStatus];
            if (in_array($newStatus, ['resolved'], true)) {
                $updates[] = "resolved_at = NOW()";
                if ($userId) {
                    $updates[] = "assigned_to = ?";
                    $params[] = $userId;
                }
            }
            if ($resolution !== null) {
                $updates[] = "resolution_notes = ?";
                $params[] = $resolution;
            }
            $params[] = $id;
            $sql = "UPDATE security_vulnerabilities SET " . implode(', ', $updates) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($params);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
