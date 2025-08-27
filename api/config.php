<?php
// LUMINELLI Database Configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'luminelli_db');
define('DB_USER', 'luminelli');
define('DB_PASS', 'luminelli2025');

// Admin credentials (password_hash for security)
define('ADMIN_USER', 'admin');
define('ADMIN_PASS', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'); // "password"

// Upload settings
define('UPLOAD_MAX_SIZE', 50 * 1024 * 1024); // 50MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('ALLOWED_VIDEO_TYPES', ['mp4', 'webm', 'ogg']);

// Paths
define('UPLOAD_PATH', '../uploads/');
define('IMAGE_PATH', UPLOAD_PATH . 'images/');
define('VIDEO_PATH', UPLOAD_PATH . 'videos/');
define('THUMBNAIL_PATH', UPLOAD_PATH . 'thumbnails/');

// Error reporting for development
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
?>
