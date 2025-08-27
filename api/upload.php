<?php
require_once 'db.php';
require_once '../includes/functions.php';
require_once '../includes/ImageProcessor.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

try {
    // Rate limiting
    if (isRateLimited('upload', 20, 3600)) { // 20 uploads per hour
        errorResponse('Rate limit exceeded. Please try again later.', 429);
    }
    
    // Determine upload type
    $uploadType = $_POST['type'] ?? 'image';
    
    switch ($uploadType) {
        case 'image':
            handleImageUpload();
            break;
        case 'video':
            handleVideoUpload();
            break;
        case 'youtube':
            handleYouTubeUpload();
            break;
        default:
            errorResponse('Invalid upload type', 400);
    }
    
} catch (Exception $e) {
    logError('Upload error: ' . $e->getMessage());
    errorResponse('Upload failed: ' . $e->getMessage(), 500);
}

// Handle image upload
function handleImageUpload() {
    if (!isset($_FILES['file'])) {
        errorResponse('No file provided', 400);
    }
    
    $file = $_FILES['file'];
    
    // Validate image file
    $validation = validateImageFile($file);
    if (!$validation['valid']) {
        errorResponse($validation['error'], 400);
    }
    
    // Ensure upload directories exist
    if (!ensureDirectory(IMAGE_PATH) || !ensureDirectory(THUMBNAIL_PATH)) {
        errorResponse('Failed to create upload directories', 500);
    }
    
    // Generate unique filename
    $filename = generateUniqueFilename($file['name'], 'img_');
    $imagePath = IMAGE_PATH . $filename;
    $thumbnailFilename = 'thumb_' . $filename;
    $thumbnailPath = THUMBNAIL_PATH . $thumbnailFilename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $imagePath)) {
        errorResponse('Failed to save uploaded file', 500);
    }
    
    try {
        // Generate thumbnail
        $processor = new ImageProcessor();
        $thumbnailInfo = $processor->generateThumbnail(
            $imagePath, 
            $thumbnailPath, 
            $validation['extension']
        );
        
        // Return URLs relative to web root
        $imageUrl = 'uploads/images/' . $filename;
        $thumbnailUrl = 'uploads/thumbnails/' . $thumbnailFilename;
        
        successResponse([
            'media_type' => 'image',
            'media_url' => $imageUrl,
            'thumbnail_url' => $thumbnailUrl,
            'thumbnail_width' => $thumbnailInfo['width'],
            'thumbnail_height' => $thumbnailInfo['height'],
            'filename' => $filename,
            'original_name' => $file['name'],
            'size' => $file['size'],
            'mime_type' => $validation['mime']
        ], 'Image uploaded successfully');
        
    } catch (Exception $e) {
        // Clean up uploaded file if thumbnail generation fails
        deleteFile($imagePath);
        throw $e;
    }
}

// Handle video upload
function handleVideoUpload() {
    if (!isset($_FILES['file'])) {
        errorResponse('No file provided', 400);
    }
    
    $file = $_FILES['file'];
    
    // Validate video file
    $validation = validateVideoFile($file);
    if (!$validation['valid']) {
        errorResponse($validation['error'], 400);
    }
    
    // Ensure upload directories exist
    if (!ensureDirectory(VIDEO_PATH) || !ensureDirectory(THUMBNAIL_PATH)) {
        errorResponse('Failed to create upload directories', 500);
    }
    
    // Generate unique filename
    $filename = generateUniqueFilename($file['name'], 'vid_');
    $videoPath = VIDEO_PATH . $filename;
    $thumbnailFilename = 'thumb_' . pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
    $thumbnailPath = THUMBNAIL_PATH . $thumbnailFilename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $videoPath)) {
        errorResponse('Failed to save uploaded file', 500);
    }
    
    try {
        // Generate video thumbnail (placeholder for now)
        $processor = new ImageProcessor();
        $thumbnailInfo = $processor->generateVideoThumbnail($videoPath, $thumbnailPath);
        
        // Return URLs relative to web root
        $videoUrl = 'uploads/videos/' . $filename;
        $thumbnailUrl = 'uploads/thumbnails/' . $thumbnailFilename;
        
        successResponse([
            'media_type' => 'video',
            'media_url' => $videoUrl,
            'thumbnail_url' => $thumbnailUrl,
            'thumbnail_width' => $thumbnailInfo['width'],
            'thumbnail_height' => $thumbnailInfo['height'],
            'filename' => $filename,
            'original_name' => $file['name'],
            'size' => $file['size'],
            'mime_type' => $validation['mime']
        ], 'Video uploaded successfully');
        
    } catch (Exception $e) {
        // Clean up uploaded file if thumbnail generation fails
        deleteFile($videoPath);
        throw $e;
    }
}

// Handle YouTube URL
function handleYouTubeUpload() {
    $url = $_POST['youtube_url'] ?? '';
    
    if (empty($url)) {
        errorResponse('YouTube URL required', 400);
    }
    
    // Validate YouTube URL
    $validation = validateYouTubeURL($url);
    if (!$validation['valid']) {
        errorResponse($validation['error'], 400);
    }
    
    // Ensure thumbnail directory exists
    if (!ensureDirectory(THUMBNAIL_PATH)) {
        errorResponse('Failed to create thumbnail directory', 500);
    }
    
    // Download and save YouTube thumbnail
    $thumbnailFilename = 'yt_thumb_' . $validation['video_id'] . '_' . date('Y-m-d_H-i-s') . '.jpg';
    $localThumbnailPath = THUMBNAIL_PATH . $thumbnailFilename;
    
    try {
        // Download thumbnail
        $thumbnailData = file_get_contents($validation['thumbnail_url']);
        if ($thumbnailData === false) {
            throw new Exception('Failed to download YouTube thumbnail');
        }
        
        if (file_put_contents($localThumbnailPath, $thumbnailData) === false) {
            throw new Exception('Failed to save YouTube thumbnail');
        }
        
        // Get thumbnail dimensions
        list($width, $height) = getimagesize($localThumbnailPath);
        
        $thumbnailUrl = 'uploads/thumbnails/' . $thumbnailFilename;
        
        successResponse([
            'media_type' => 'youtube',
            'media_url' => $validation['embed_url'],
            'thumbnail_url' => $thumbnailUrl,
            'thumbnail_width' => $width ?: 1280,
            'thumbnail_height' => $height ?: 720,
            'video_id' => $validation['video_id'],
            'original_url' => $url
        ], 'YouTube video processed successfully');
        
    } catch (Exception $e) {
        // Clean up thumbnail file if it was created
        deleteFile($localThumbnailPath);
        throw $e;
    }
}

// Handle file deletion (for cleanup)
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    handleFileDelete();
}

function handleFileDelete() {
    $mediaUrl = $_POST['media_url'] ?? '';
    $thumbnailUrl = $_POST['thumbnail_url'] ?? '';
    
    if (empty($mediaUrl) && empty($thumbnailUrl)) {
        errorResponse('No files specified for deletion', 400);
    }
    
    $deleted = [];
    $errors = [];
    
    // Delete main media file
    if (!empty($mediaUrl) && strpos($mediaUrl, 'uploads/') === 0) {
        $filePath = '../' . $mediaUrl;
        if (deleteFile($filePath)) {
            $deleted[] = $mediaUrl;
        } else {
            $errors[] = "Failed to delete: {$mediaUrl}";
        }
    }
    
    // Delete thumbnail
    if (!empty($thumbnailUrl) && strpos($thumbnailUrl, 'uploads/') === 0) {
        $filePath = '../' . $thumbnailUrl;
        if (deleteFile($filePath)) {
            $deleted[] = $thumbnailUrl;
        } else {
            $errors[] = "Failed to delete: {$thumbnailUrl}";
        }
    }
    
    if (!empty($errors)) {
        errorResponse('Some files could not be deleted: ' . implode(', ', $errors), 500);
    }
    
    successResponse([
        'deleted_files' => $deleted
    ], 'Files deleted successfully');
}
?>