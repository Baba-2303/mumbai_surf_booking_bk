<?php
/**
 * Database Connection and Query Handler
 */

class Database {
    private static $instance = null;
    private $pdo;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];
            
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            
        } catch (PDOException $e) {
            if (ENVIRONMENT === 'development') {
                die("Database connection failed: " . $e->getMessage());
            } else {
                die("Database connection failed. Please try again later.");
            }
        }
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    /**
     * Get PDO instance
     */
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Execute a prepared statement
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            $this->logError($e, $sql, $params);
            throw $e;
        }
    }
    
    /**
     * Fetch all results
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Fetch single row
     */
    public function fetch($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }
    
    /**
     * Insert record and return ID
     */
    public function insert($sql, $params = []) {
        $this->query($sql, $params);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Update/Delete and return affected rows
     */
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction() {
        return $this->pdo->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    public function commit() {
        return $this->pdo->commit();
    }
    
    /**
     * Rollback transaction
     */
    public function rollback() {
        return $this->pdo->rollback();
    }
    
    /**
     * Check if record exists
     */
    public function exists($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Count records
     */
    public function count($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Log database errors
     */
    private function logError($exception, $sql, $params) {
        $errorMessage = date('Y-m-d H:i:s') . " - DATABASE ERROR\n";
        $errorMessage .= "SQL: " . $sql . "\n";
        $errorMessage .= "Params: " . json_encode($params) . "\n";
        $errorMessage .= "Error: " . $exception->getMessage() . "\n";
        $errorMessage .= "File: " . $exception->getFile() . " Line: " . $exception->getLine() . "\n";
        $errorMessage .= "--------------------------------\n";
        
        // Log to file (create logs directory if it doesn't exist)
        $logDir = dirname(__DIR__) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        error_log($errorMessage, 3, $logDir . '/database_errors.log');
    }
    
    /**
     * Escape string for LIKE queries
     */
    public function escapeLike($string) {
        return str_replace(['%', '_'], ['\\%', '\\_'], $string);
    }
    
    /**
     * Get table info (useful for debugging)
     */
    public function getTableInfo($tableName) {
        return $this->fetchAll("DESCRIBE `$tableName`");
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Helper function to get database instance
 */
function db() {
    return Database::getInstance();
}
?>