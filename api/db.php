<?php
require_once 'config.php';

class Database {
    private $connection;
    private $host = DB_HOST;
    private $db_name = DB_NAME;
    private $username = DB_USER;
    private $password = DB_PASS;
    
    public function getConnection() {
        $this->connection = null;
        
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch(PDOException $exception) {
            http_response_code(500);
            echo json_encode([
                'error' => true,
                'message' => 'Database connection failed: ' . $exception->getMessage()
            ]);
            exit();
        }
        
        return $this->connection;
    }
    
    public function close() {
        $this->connection = null;
    }
}

// Helper function to get database connection
function getDbConnection() {
    $database = new Database();
    return $database->getConnection();
}

// Helper function for JSON responses
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

// Helper function for error responses
function errorResponse($message, $statusCode = 400) {
    jsonResponse([
        'error' => true,
        'message' => $message
    ], $statusCode);
}

// Helper function for success responses
function successResponse($data = [], $message = 'Success') {
    jsonResponse([
        'error' => false,
        'message' => $message,
        'data' => $data
    ]);
}
?>