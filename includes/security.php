<?php
require_once __DIR__ . '/functions.php';

/**
 * Require an active authenticated user for sensitive financial endpoints.
 * This is intentionally server-side and does not trust role/session labels
 * without reloading the user record from the database.
 */
function require_active_financial_user(PDO $pdo, ?string $permission = null, ?int $requestedBranchId = null, ?int $recordBranchId = null): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $userId = (int)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        security_json_error('Authentication required', 401);
    }

    $stmt = $pdo->prepare(
        'SELECT u.*, r.name AS role_name
           FROM users u
           LEFT JOIN roles r ON r.id = u.role_id
          WHERE u.id = ?
          LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user || strtolower((string)($user['status'] ?? '')) !== 'active') {
        security_json_error('Active authenticated user required', 401);
    }

    $_SESSION['admin_id'] = $userId;
    $_SESSION['user_id'] = $userId;
    $_SESSION['role_id'] = (int)($user['role_id'] ?? 0);
    $_SESSION['role_name'] = (string)($user['role_name'] ?? '');
    $_SESSION['branch_id'] = $user['branch_id'] !== null ? (int)$user['branch_id'] : null;
    $_SESSION['branch_scope'] = (string)($user['branch_scope'] ?? 'single_branch');

    $role = strtolower((string)($user['role_name'] ?? ''));
    $isGlobalRole = in_array($role, ['admin', 'developer'], true)
        || strtolower((string)($user['user_type'] ?? '')) === 'developer';

    if ($permission !== null && !$isGlobalRole && !has_permission($permission)) {
        security_json_error('Permission denied', 403);
    }

    foreach ([$requestedBranchId, $recordBranchId] as $branchId) {
        if ($branchId === null || $branchId <= 0 || $isGlobalRole) {
            continue;
        }
        $allowed = false;
        if ((string)($user['branch_scope'] ?? '') === 'all_branches') {
            $allowed = true;
        } elseif ((int)($user['branch_id'] ?? 0) === $branchId) {
            $allowed = true;
        } else {
            $branchStmt = $pdo->prepare('SELECT 1 FROM user_branches WHERE user_id = ? AND branch_id = ? LIMIT 1');
            $branchStmt->execute([$userId, $branchId]);
            $allowed = (bool)$branchStmt->fetchColumn();
        }
        if (!$allowed) {
            security_json_error('Branch access denied', 403);
        }
    }

    return $user;
}

function require_open_financial_period(PDO $pdo, ?string $operationDate): void
{
    $date = substr((string)($operationDate ?: date('Y-m-d')), 0, 10);
    $stmt = $pdo->prepare(
        'SELECT period_name, is_closed
           FROM fiscal_periods
          WHERE ? BETWEEN start_date AND end_date
          ORDER BY id DESC
          LIMIT 1'
    );
    $stmt->execute([$date]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$period || (int)$period['is_closed'] === 1) {
        security_json_error('Fiscal period is closed or unavailable', 403);
    }
}

function security_json_error($message, $status_code = 400)
{
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $message]);
    exit();
}

function request_header_value($name)
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $header => $value) {
            if (strcasecmp($header, $name) === 0) {
                return $value;
            }
        }
    }

    return null;
}

function csrf_token_from_request()
{
    // Read CSRF token from header or POST/JSON body only. Do NOT accept token from GET to avoid token leakage via URLs.
    $token = request_header_value('X-CSRF-Token');
    if ($token) {
        return $token;
    }

    if (isset($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }

    $raw = file_get_contents('php://input');
    if ($raw) {
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['csrf_token'])) {
            return $json['csrf_token'];
        }
    }

    return '';
}

function require_csrf($force = false)
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $unsafe_methods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    if (!$force && !in_array($method, $unsafe_methods, true)) {
        return true;
    }

    if (!verify_csrf_token(csrf_token_from_request())) {
        security_json_error('CSRF token invalid', 403);
    }

    return true;
}

function require_csrf_for_actions(array $actions)
{
    $action = $_REQUEST['action'] ?? '';
    if (in_array($action, $actions, true)) {
        require_csrf(true);
    } else {
        require_csrf(false);
    }
}

function rate_limit($action, $max_requests = 30, $window = 60)
{
    $identity = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'guest');
    $key = hash('sha256', $action . '|' . $identity . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'erp_rl_' . $key . '.json';
    $now = time();

    $handle = fopen($file, 'c+');
    if (!$handle) {
        return true;
    }

    try {
        flock($handle, LOCK_EX);
        $contents = stream_get_contents($handle);
        $data = $contents ? json_decode($contents, true) : null;
        if (!is_array($data) || !isset($data['c'], $data['t'])) {
            $data = ['c' => 0, 't' => $now];
        }

        if (($now - (int)$data['t']) >= $window) {
            $data = ['c' => 1, 't' => $now];
        } else {
            $data['c'] = (int)$data['c'] + 1;
        }

        if ($data['c'] > $max_requests) {
            security_json_error('Too many requests', 429);
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($data));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    return true;
}

function json_exception_response($e, $message = 'حدث خطأ داخلي في النظام', $status_code = 500)
{
    error_log('File: ' . ($_SERVER['SCRIPT_FILENAME'] ?? __FILE__) . ' Error: ' . $e->getMessage());
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'success' => false, 'message' => $message]);
    exit();
}

/**
 * Clear user permissions cache when permissions are updated
 * @param int $user_id The ID of the user whose permissions to clear
 */
function clearUserPermissionsCache($user_id)
{
    // Clear session-based permissions cache
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $user_id) {
            unset($_SESSION['perms']);
        }
        if (isset($_SESSION['admin_id']) && $_SESSION['admin_id'] == $user_id) {
            unset($_SESSION['perms']);
        }
    }
    
    // Note: If using Redis, Memcached, or database cache, clear it here
    // Example for Redis:
    // $redis = new Redis();
    // $redis->connect('127.0.0.1', 6379);
    // $redis->del("user:perms:{$user_id}");
}
