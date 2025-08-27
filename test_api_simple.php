<?php
// Simple API Test for Deployment
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "=== SIMPLE API TEST ===\n\n";

try {
    echo "1. Testing config include...\n";
    require_once 'api/config.php';
    echo "✅ Config loaded\n\n";
    
    echo "2. Testing database connection...\n";
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    echo "✅ Database connected\n\n";
    
    echo "3. Testing sections query...\n";
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM sections");
    $result = $stmt->fetch();
    echo "✅ Found {$result['count']} sections\n\n";
    
    echo "4. Testing JSON response...\n";
    header('Content-Type: application/json');
    $response = [
        'error' => false,
        'message' => 'API test successful',
        'data' => ['sections' => $result['count']]
    ];
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ], JSON_PRETTY_PRINT);
}
?>