<?php
// LUMINELLI Deployment Diagnostic Script
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>LUMINELLI Deployment Diagnostics</h1>\n";
echo "<pre>\n";

// 1. PHP Version Check
echo "=== PHP ENVIRONMENT ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "\n";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "\n";
echo "Script Path: " . __FILE__ . "\n";
echo "\n";

// 2. Required Extensions
echo "=== PHP EXTENSIONS ===\n";
$required_extensions = ['pdo', 'pdo_mysql', 'gd', 'json', 'mbstring'];
foreach ($required_extensions as $ext) {
    $status = extension_loaded($ext) ? "✅ LOADED" : "❌ MISSING";
    echo "$ext: $status\n";
}
echo "\n";

// 3. File Permissions
echo "=== FILE PERMISSIONS ===\n";
$paths_to_check = [
    '.',
    'api',
    'api/config.php',
    'includes',
    'uploads',
    'uploads/images',
    'uploads/videos',
    'uploads/thumbnails'
];

foreach ($paths_to_check as $path) {
    if (file_exists($path)) {
        $perms = fileperms($path);
        $perms_octal = substr(sprintf('%o', $perms), -4);
        $type = is_dir($path) ? 'DIR ' : 'FILE';
        $readable = is_readable($path) ? 'R' : '-';
        $writable = is_writable($path) ? 'W' : '-';
        echo "$type $path: $perms_octal ($readable$writable)\n";
    } else {
        echo "MISSING: $path\n";
    }
}
echo "\n";

// 4. Config File Test
echo "=== CONFIG FILE TEST ===\n";
try {
    if (file_exists('api/config.php')) {
        echo "Config file exists: ✅\n";
        
        // Try to include it
        ob_start();
        include 'api/config.php';
        $output = ob_get_clean();
        
        if (defined('DB_HOST')) {
            echo "Config loaded successfully: ✅\n";
            echo "DB_HOST: " . DB_HOST . "\n";
            echo "DB_NAME: " . DB_NAME . "\n";
            echo "DB_USER: " . DB_USER . "\n";
            echo "DB_PASS: " . (DB_PASS ? '[SET]' : '[EMPTY]') . "\n";
        } else {
            echo "Config constants not defined: ❌\n";
        }
    } else {
        echo "Config file missing: ❌\n";
    }
} catch (Exception $e) {
    echo "Config error: " . $e->getMessage() . "\n";
}
echo "\n";

// 5. Database Connection Test
echo "=== DATABASE CONNECTION TEST ===\n";
if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        
        echo "Database connection: ✅\n";
        
        // Test tables
        $tables = ['sections', 'tags', 'section_tags'];
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                $result = $stmt->fetch();
                echo "Table '$table': ✅ ({$result['count']} rows)\n";
            } catch (Exception $e) {
                echo "Table '$table': ❌ " . $e->getMessage() . "\n";
            }
        }
        
    } catch (Exception $e) {
        echo "Database connection failed: ❌\n";
        echo "Error: " . $e->getMessage() . "\n";
    }
} else {
    echo "Database constants not defined, skipping test\n";
}
echo "\n";

// 6. API Endpoint Test
echo "=== API ENDPOINT TEST ===\n";
try {
    if (file_exists('api/sections.php')) {
        echo "sections.php exists: ✅\n";
        
        // Test if file is readable
        $content = file_get_contents('api/sections.php', false, null, 0, 100);
        if ($content && strpos($content, '<?php') === 0) {
            echo "sections.php format: ✅\n";
        } else {
            echo "sections.php format: ❌ (not valid PHP)\n";
        }
    } else {
        echo "sections.php missing: ❌\n";
    }
} catch (Exception $e) {
    echo "API test error: " . $e->getMessage() . "\n";
}
echo "\n";

// 7. Include Path Test
echo "=== INCLUDE PATH TEST ===\n";
try {
    if (file_exists('includes/functions.php')) {
        echo "functions.php exists: ✅\n";
        include_once 'includes/functions.php';
        echo "functions.php included: ✅\n";
    }
    
    if (file_exists('includes/ImageProcessor.php')) {
        echo "ImageProcessor.php exists: ✅\n";
        include_once 'includes/ImageProcessor.php';
        
        if (class_exists('ImageProcessor')) {
            echo "ImageProcessor class available: ✅\n";
            $processor = new ImageProcessor();
            echo "ImageProcessor instantiated: ✅\n";
        }
    }
} catch (Exception $e) {
    echo "Include test error: " . $e->getMessage() . "\n";
}
echo "\n";

// 8. Error Log Check
echo "=== ERROR LOG CHECK ===\n";
$error_log = ini_get('error_log');
echo "Error log location: " . ($error_log ?: 'default') . "\n";

if ($error_log && file_exists($error_log)) {
    $recent_errors = tail($error_log, 10);
    if ($recent_errors) {
        echo "Recent errors:\n" . $recent_errors . "\n";
    }
} else {
    echo "No accessible error log found\n";
}

echo "</pre>\n";

// Helper function to read last n lines of a file
function tail($filename, $lines = 10) {
    if (!file_exists($filename) || !is_readable($filename)) {
        return false;
    }
    
    $file = new SplFileObject($filename, 'r');
    $file->seek(PHP_INT_MAX);
    $last_line = $file->key();
    $start = max(0, $last_line - $lines);
    
    $result = '';
    for ($i = $start; $i <= $last_line; $i++) {
        $file->seek($i);
        $result .= $file->current();
    }
    
    return $result;
}
?>