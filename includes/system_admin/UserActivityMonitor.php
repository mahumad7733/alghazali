<?php
defined('BASE_PATH') || define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/includes/functions.php';

class AlGhazali_UserActivityMonitor
{
    public static function parseUserAgent($ua)
    {
        $info = [
            'browser' => 'Unknown',
            'browser_version' => null,
            'os' => 'Unknown',
            'device_type' => 'desktop',
        ];
        $ua = (string)$ua;
        if (stripos($ua, 'Chrome') !== false && stripos($ua, 'Edg') === false) {
            $info['browser'] = 'Chrome';
            if (preg_match('/Chrome\/([\d.]+)/i', $ua, $m)) { $info['browser_version'] = $m[1]; }
        } elseif (stripos($ua, 'Firefox') !== false) {
            $info['browser'] = 'Firefox';
            if (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) { $info['browser_version'] = $m[1]; }
        } elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) {
            $info['browser'] = 'Safari';
        } elseif (stripos($ua, 'Edg') !== false) {
            $info['browser'] = 'Edge';
        } elseif (stripos($ua, 'MSIE') !== false || stripos($ua, 'Trident') !== false) {
            $info['browser'] = 'Internet Explorer';
        }
        if (preg_match('/Windows NT 10/i', $ua)) { $info['os'] = 'Windows 10/11'; }
        elseif (preg_match('/Windows NT 6\.3/i', $ua)) { $info['os'] = 'Windows 8.1'; }
        elseif (preg_match('/Windows/i', $ua)) { $info['os'] = 'Windows'; }
        elseif (preg_match('/Mac OS X/i', $ua)) { $info['os'] = 'macOS'; }
        elseif (preg_match('/Android/i', $ua)) { $info['os'] = 'Android'; $info['device_type'] = 'mobile'; }
        elseif (preg_match('/iPhone|iPad|iOS/i', $ua)) { $info['os'] = 'iOS'; $info['device_type'] = 'mobile'; }
        elseif (preg_match('/Linux/i', $ua)) { $info['os'] = 'Linux'; }
        if (preg_match('/Mobile|iPhone|Android|IEMobile|BlackBerry/i', $ua)) { $info['device_type'] = 'mobile'; }
        elseif (preg_match('/iPad|Tablet|PlayBook/i', $ua)) { $info['device_type'] = 'tablet'; }
        return $info;
    }

    public static function listActivityLogs($filters = [], $limit = 100, $offset = 0)
    {
        global $pdo;
        if (!$pdo) { return []; }
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['user_id'])) { $where[] = "ual.user_id = ?"; $params[] = $filters['user_id']; }
        if (!empty($filters['username'])) { $where[] = "(ual.username LIKE ? OR ual.full_name LIKE ?)"; $s = '%' . $filters['username'] . '%'; array_push($params, $s, $s); }
        if (!empty($filters['activity_type'])) { $where[] = "ual.activity_type = ?"; $params[] = $filters['activity_type']; }
        if (!empty($filters['from_date'])) { $where[] = "ual.created_at >= ?"; $params[] = $filters['from_date']; }
        if (!empty($filters['to_date'])) { $where[] = "ual.created_at <= ?"; $params[] = $filters['to_date']; }
        if (!empty($filters['ip_address'])) { $where[] = "ual.ip_address LIKE ?"; $params[] = '%' . $filters['ip_address'] . '%'; }
        $sql = "SELECT ual.*, NULL AS email, NULL AS phone_number, r.name AS role_name
                FROM user_activity_logs ual
                LEFT JOIN users u ON u.id = ual.user_id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY ual.created_at DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        $q = $pdo->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function lastActionsBeforeError($errorRecord, $limit = 10)
    {
        global $pdo;
        if (!$pdo) { return []; }
        $userId = $errorRecord['user_id'] ?? null;
        $errAt = $errorRecord['created_at'] ?? null;
        $sessionId = null;
        if ($userId && !empty($errorRecord['context_json'])) {
            $ctx = json_decode($errorRecord['context_json'], true);
            if (is_array($ctx) && !empty($ctx['session_id'])) { $sessionId = $ctx['session_id']; }
        }
        if (!$userId) { return []; }
        $q = $pdo->prepare("SELECT * FROM user_activity_logs
            WHERE user_id = ? AND created_at <= ?
            ORDER BY created_at DESC
            LIMIT " . (int)$limit);
        $q->execute([$userId, $errAt]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows && $sessionId) {
            $q2 = $pdo->prepare("SELECT al.*, u.username
                FROM audit_logs al LEFT JOIN users u ON u.id = al.user_id
                WHERE al.user_id = ? AND al.created_at <= ?
                ORDER BY al.created_at DESC LIMIT " . (int)$limit);
            $q2->execute([$userId, $errAt]);
            $rows = array_merge($rows, $q2->fetchAll(PDO::FETCH_ASSOC));
        }
        return $rows;
    }

    public static function activeUsers($minutes = 5)
    {
        global $pdo;
        if (!$pdo) { return []; }
        $sql = "SELECT DISTINCT us.user_id, u.username, u.full_name, r.name AS role_name,
                    us.ip_address, us.browser, us.operating_system, us.device_type, us.last_activity
                FROM user_sessions us
                LEFT JOIN users u ON u.id = us.user_id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE us.last_activity >= DATE_SUB(NOW(), INTERVAL " . (int)$minutes . " MINUTE)
                  AND us.status = 'active'
                ORDER BY us.last_activity DESC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function countByType($intervalHours = 24)
    {
        global $pdo;
        if (!$pdo) { return []; }
        $sql = "SELECT activity_type, COUNT(*) as cnt
                FROM user_activity_logs
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL " . (int)$intervalHours . " HOUR)
                GROUP BY activity_type
                ORDER BY cnt DESC";
        return $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    }
}
