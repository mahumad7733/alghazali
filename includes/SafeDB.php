<?php
/**
 * SafeDB Class - Secure Database Layer
 * Protects against SQL Injection with prepared statements
 * Supports: SELECT, INSERT, UPDATE, DELETE, JOIN, IN operator, error logging
 */
class SafeDB {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Get PDO instance for custom queries
     */
    public function getPDO() {
        return $this->pdo;
    }

    /**
     * Log database errors to system_error_audit
     */
    private function logError(Throwable $e, $sql, $params) {
        require_once __DIR__ . '/system_error_audit.php';
        
        $user_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
        $url = null;
        $method = null;
        if (PHP_SAPI !== 'cli') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $uri = $_SERVER['REQUEST_URI'] ?? '';
            $url = ($host !== '') ? ($scheme . '://' . $host . $uri) : $uri;
            $method = $_SERVER['REQUEST_METHOD'] ?? null;
        }

        log_system_error_audit($this->pdo, [
            'level' => 'error',
            'errno' => $e->getCode(),
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
            'url' => $url,
            'method' => $method,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'user_id' => $user_id ? (int)$user_id : null,
            'context_json' => json_encode(['sql' => $sql, 'params' => $params], JSON_UNESCAPED_UNICODE)
        ]);
    }

    /**
     * Execute a prepared statement
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters array
     * @return PDOStatement
     */
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (Throwable $e) {
            $this->logError($e, $sql, $params);
            throw $e;
        }
    }

    /**
     * Insert record into database
     * @param string $table Table name
     * @param array $data Key-value pairs (column => value)
     * @return int Last inserted ID
     */
    public function insert($table, $data) {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $sql = "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
        $this->execute($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    /**
     * Update records in database
     * @param string $table Table name
     * @param array $data Key-value pairs to update (column => value)
     * @param string $where WHERE clause with placeholders (e.g., "id = ?")
     * @param array $whereParams Parameters for WHERE clause
     * @return int Number of affected rows
     */
    public function update($table, $data, $where, $whereParams = []) {
        $sets = array_map(fn($f) => "`$f` = ?", array_keys($data));
        $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE $where";
        $params = array_merge(array_values($data), $whereParams);
        $stmt = $this->execute($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Delete records from database
     * @param string $table Table name
     * @param string $where WHERE clause with placeholders
     * @param array $params Parameters for WHERE clause
     * @return int Number of affected rows
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM `$table` WHERE $where";
        $stmt = $this->execute($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Select records from database
     * @param string $table Table name
     * @param array $columns Columns to select (default: all)
     * @param string $where WHERE clause (optional)
     * @param array $params Parameters for WHERE clause
     * @return array
     */
    public function select($table, $columns = ['*'], $where = '1=1', $params = []) {
        $cols = is_array($columns) ? '`' . implode('`, `', $columns) . '`' : $columns;
        $sql = "SELECT $cols FROM `$table` WHERE $where";
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Select single record from database
     * @param string $table Table name
     * @param array $columns Columns to select
     * @param string $where WHERE clause
     * @param array $params Parameters
     * @return array|false
     */
    public function selectOne($table, $columns = ['*'], $where = '1=1', $params = []) {
        $cols = is_array($columns) ? '`' . implode('`, `', $columns) . '`' : $columns;
        $sql = "SELECT $cols FROM `$table` WHERE $where LIMIT 1";
        $stmt = $this->execute($sql, $params);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Helper to build IN clause with placeholders
     * @param array $values Values for IN clause
     * @param array &$params Reference to params array to add values to
     * @return string Placeholder string (e.g., "?, ?, ?")
     */
    public function buildInClause($values, &$params) {
        if (!is_array($values) || empty($values)) {
            return 'NULL';
        }
        $placeholders = array_fill(0, count($values), '?');
        $params = array_merge($params, $values);
        return implode(', ', $placeholders);
    }
}
