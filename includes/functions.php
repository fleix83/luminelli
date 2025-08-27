<?php
// Try different paths to find config.php
if (file_exists(__DIR__ . '/../api/config.php')) {
    require_once __DIR__ . '/../api/config.php';
} elseif (file_exists('api/config.php')) {
    require_once 'api/config.php';
} elseif (file_exists('../api/config.php')) {
    require_once '../api/config.php';
} else {
    throw new Exception('Could not find config.php');
}

// Sanitize input to prevent XSS
function sanitizeInput($input) {
    if (is_array($input)) {
        return array_map('sanitizeInput', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Validate image file
function validateImageFile($file) {
    $allowedTypes = ALLOWED_IMAGE_TYPES;
    $maxSize = UPLOAD_MAX_SIZE;
    
    // Check if file was uploaded
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'File upload error'];
    }
    
    // Check file size
    if ($file['size'] > $maxSize) {
        return ['valid' => false, 'error' => 'File too large. Max size: ' . formatBytes($maxSize)];
    }
    
    // Get file extension
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension']);
    
    // Check file type
    if (!in_array($extension, $allowedTypes)) {
        return ['valid' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedTypes)];
    }
    
    // Verify actual file type (MIME)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $validMimeTypes = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp']
    ];
    
    if (!isset($validMimeTypes[$extension]) || !in_array($mimeType, $validMimeTypes[$extension])) {
        return ['valid' => false, 'error' => 'Invalid file format'];
    }
    
    return ['valid' => true, 'extension' => $extension, 'mime' => $mimeType];
}

// Validate video file
function validateVideoFile($file) {
    $allowedTypes = ALLOWED_VIDEO_TYPES;
    $maxSize = UPLOAD_MAX_SIZE;
    
    // Check if file was uploaded
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'File upload error'];
    }
    
    // Check file size
    if ($file['size'] > $maxSize) {
        return ['valid' => false, 'error' => 'File too large. Max size: ' . formatBytes($maxSize)];
    }
    
    // Get file extension
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension']);
    
    // Check file type
    if (!in_array($extension, $allowedTypes)) {
        return ['valid' => false, 'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedTypes)];
    }
    
    // Verify actual file type (MIME)
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $validMimeTypes = [
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'ogg' => ['video/ogg']
    ];
    
    if (!isset($validMimeTypes[$extension]) || !in_array($mimeType, $validMimeTypes[$extension])) {
        return ['valid' => false, 'error' => 'Invalid video format'];
    }
    
    return ['valid' => true, 'extension' => $extension, 'mime' => $mimeType];
}

// Extract YouTube video ID from URL
function getYouTubeID($url) {
    $patterns = [
        '/youtu\.be\/([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/',
        '/youtube\.com\/v\/([a-zA-Z0-9_-]+)/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    
    return false;
}

// Generate unique filename
function generateUniqueFilename($originalName, $prefix = '') {
    $fileInfo = pathinfo($originalName);
    $extension = isset($fileInfo['extension']) ? '.' . $fileInfo['extension'] : '';
    $baseName = $prefix . date('Y-m-d_H-i-s') . '_' . uniqid();
    return $baseName . $extension;
}

// Format bytes for human reading
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

// Create directory if it doesn't exist
function ensureDirectory($path) {
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true)) {
            return false;
        }
    }
    return true;
}

// Delete file safely
function deleteFile($filepath) {
    if (file_exists($filepath)) {
        return unlink($filepath);
    }
    return true; // File doesn't exist, consider it "deleted"
}

// Generate YouTube thumbnail URL
function getYouTubeThumbnail($videoId, $quality = 'maxresdefault') {
    // Available qualities: default, hqdefault, mqdefault, sddefault, maxresdefault
    return "https://img.youtube.com/vi/{$videoId}/{$quality}.jpg";
}

// Validate YouTube URL and get thumbnail
function validateYouTubeURL($url) {
    $videoId = getYouTubeID($url);
    
    if (!$videoId) {
        return ['valid' => false, 'error' => 'Invalid YouTube URL'];
    }
    
    // Check if video exists by trying to get thumbnail
    $thumbnailUrl = getYouTubeThumbnail($videoId);
    $headers = @get_headers($thumbnailUrl);
    
    if (!$headers || strpos($headers[0], '200') === false) {
        return ['valid' => false, 'error' => 'YouTube video not found or private'];
    }
    
    return [
        'valid' => true,
        'video_id' => $videoId,
        'thumbnail_url' => $thumbnailUrl,
        'embed_url' => "https://www.youtube.com/embed/{$videoId}"
    ];
}

// Log errors to file
function logError($message, $file = 'error.log') {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$message}" . PHP_EOL;
    error_log($logMessage, 3, $file);
}

// Generate CSRF token
function generateCSRFToken() {
    if (!isset($_SESSION)) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

// Verify CSRF token
function verifyCSRFToken($token) {
    if (!isset($_SESSION)) {
        session_start();
    }
    
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Rate limiting (simple)
function isRateLimited($key, $limit = 60, $window = 3600) {
    if (!isset($_SESSION)) {
        session_start();
    }
    
    $now = time();
    $rateLimitKey = "rate_limit_{$key}";
    
    if (!isset($_SESSION[$rateLimitKey])) {
        $_SESSION[$rateLimitKey] = ['count' => 1, 'window_start' => $now];
        return false;
    }
    
    $data = $_SESSION[$rateLimitKey];
    
    // Reset window if expired
    if ($now - $data['window_start'] > $window) {
        $_SESSION[$rateLimitKey] = ['count' => 1, 'window_start' => $now];
        return false;
    }
    
    // Check if limit exceeded
    if ($data['count'] >= $limit) {
        return true;
    }
    
    // Increment counter
    $_SESSION[$rateLimitKey]['count']++;
    return false;
}
?>