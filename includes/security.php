<?php
require_once __DIR__ . '/functions.php';

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
    $token = request_header_value('X-CSRF-Token');
    if ($token) {
        return $token;
    }

    if (isset($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }

    if (isset($_GET['csrf_token'])) {
        return $_GET['csrf_token'];
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
