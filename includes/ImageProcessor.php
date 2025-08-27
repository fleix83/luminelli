<?php
// Try different paths to find config.php
if (file_exists(__DIR__ . '/../api/config.php')) {
    require_once __DIR__ . '/../api/config.php';
} elseif (file_exists('api/config.php')) {
    require_once 'api/config.php';
} elseif (file_exists('../api/config.php')) {
    require_once '../api/config.php';
}

class ImageProcessor {
    private $supportedFormats;
    private $thumbnailWidth;
    private $thumbnailHeight;
    private $quality;
    
    public function __construct($thumbnailWidth = 400, $thumbnailHeight = 300, $quality = 85) {
        $this->thumbnailWidth = $thumbnailWidth;
        $this->thumbnailHeight = $thumbnailHeight;
        $this->quality = $quality;
        
        // Check what image formats are supported
        $this->supportedFormats = [];
        if (function_exists('imagecreatefromjpeg')) $this->supportedFormats[] = 'jpg';
        if (function_exists('imagecreatefromjpeg')) $this->supportedFormats[] = 'jpeg';
        if (function_exists('imagecreatefrompng')) $this->supportedFormats[] = 'png';
        if (function_exists('imagecreatefromgif')) $this->supportedFormats[] = 'gif';
        if (function_exists('imagecreatefromwebp')) $this->supportedFormats[] = 'webp';
    }
    
    // Generate thumbnail from image file
    public function generateThumbnail($sourcePath, $destinationPath, $extension) {
        if (!in_array(strtolower($extension), $this->supportedFormats)) {
            throw new Exception("Unsupported image format: {$extension}");
        }
        
        // Get original image dimensions
        list($originalWidth, $originalHeight) = getimagesize($sourcePath);
        
        if (!$originalWidth || !$originalHeight) {
            throw new Exception("Invalid image file");
        }
        
        // Calculate thumbnail dimensions (maintain aspect ratio)
        $dimensions = $this->calculateThumbnailDimensions(
            $originalWidth, 
            $originalHeight, 
            $this->thumbnailWidth, 
            $this->thumbnailHeight
        );
        
        // Create image resource from source
        $sourceImage = $this->createImageResource($sourcePath, $extension);
        
        if (!$sourceImage) {
            throw new Exception("Failed to create image resource");
        }
        
        // Create thumbnail canvas
        $thumbnail = imagecreatetruecolor($dimensions['width'], $dimensions['height']);
        
        // Handle transparency for PNG and GIF
        if (in_array(strtolower($extension), ['png', 'gif'])) {
            $this->preserveTransparency($thumbnail, $sourceImage, $extension);
        }
        
        // Resize image
        $result = imagecopyresampled(
            $thumbnail,
            $sourceImage,
            0, 0, 0, 0,
            $dimensions['width'],
            $dimensions['height'],
            $originalWidth,
            $originalHeight
        );
        
        if (!$result) {
            imagedestroy($sourceImage);
            imagedestroy($thumbnail);
            throw new Exception("Failed to resize image");
        }
        
        // Save thumbnail
        $saved = $this->saveImage($thumbnail, $destinationPath, $extension);
        
        // Clean up
        imagedestroy($sourceImage);
        imagedestroy($thumbnail);
        
        if (!$saved) {
            throw new Exception("Failed to save thumbnail");
        }
        
        return [
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'path' => $destinationPath
        ];
    }
    
    // Generate video thumbnail (placeholder)
    public function generateVideoThumbnail($videoPath, $destinationPath) {
        // For now, create a simple placeholder
        // In production, you might want to use FFmpeg to extract a frame
        return $this->generatePlaceholderThumbnail($destinationPath, 'VIDEO');
    }
    
    // Generate placeholder thumbnail
    public function generatePlaceholderThumbnail($destinationPath, $text = 'IMAGE') {
        $width = $this->thumbnailWidth;
        $height = $this->thumbnailHeight;
        
        // Create canvas
        $image = imagecreatetruecolor($width, $height);
        
        // Colors
        $backgroundColor = imagecolorallocate($image, 45, 45, 45);  // Dark gray
        $textColor = imagecolorallocate($image, 255, 255, 255);    // White
        $borderColor = imagecolorallocate($image, 100, 100, 100);  // Gray border
        
        // Fill background
        imagefill($image, 0, 0, $backgroundColor);
        
        // Draw border
        imagerectangle($image, 0, 0, $width - 1, $height - 1, $borderColor);
        
        // Add text
        $fontSize = min($width, $height) / 15;
        $textBox = imagettfbbox($fontSize, 0, $this->getDefaultFont(), $text);
        $textWidth = $textBox[4] - $textBox[0];
        $textHeight = $textBox[1] - $textBox[5];
        
        $x = ($width - $textWidth) / 2;
        $y = ($height + $textHeight) / 2;
        
        if (function_exists('imagettftext') && $this->getDefaultFont()) {
            imagettftext($image, $fontSize, 0, $x, $y, $textColor, $this->getDefaultFont(), $text);
        } else {
            // Fallback to built-in font
            $x = ($width - strlen($text) * 10) / 2;
            $y = ($height - 15) / 2;
            imagestring($image, 5, $x, $y, $text, $textColor);
        }
        
        // Save as JPEG
        $result = imagejpeg($image, $destinationPath, $this->quality);
        imagedestroy($image);
        
        if (!$result) {
            throw new Exception("Failed to create placeholder thumbnail");
        }
        
        return [
            'width' => $width,
            'height' => $height,
            'path' => $destinationPath
        ];
    }
    
    // Create image resource based on file type
    private function createImageResource($path, $extension) {
        switch (strtolower($extension)) {
            case 'jpg':
            case 'jpeg':
                return imagecreatefromjpeg($path);
            case 'png':
                return imagecreatefrompng($path);
            case 'gif':
                return imagecreatefromgif($path);
            case 'webp':
                return imagecreatefromwebp($path);
            default:
                return false;
        }
    }
    
    // Save image resource to file
    private function saveImage($imageResource, $path, $extension) {
        switch (strtolower($extension)) {
            case 'jpg':
            case 'jpeg':
                return imagejpeg($imageResource, $path, $this->quality);
            case 'png':
                return imagepng($imageResource, $path);
            case 'gif':
                return imagegif($imageResource, $path);
            case 'webp':
                return imagewebp($imageResource, $path, $this->quality);
            default:
                return false;
        }
    }
    
    // Preserve transparency for PNG and GIF
    private function preserveTransparency($thumbnail, $sourceImage, $extension) {
        if (strtolower($extension) === 'png') {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            $transparent = imagecolorallocatealpha($thumbnail, 255, 255, 255, 127);
            imagefill($thumbnail, 0, 0, $transparent);
        } elseif (strtolower($extension) === 'gif') {
            $transparentIndex = imagecolortransparent($sourceImage);
            if ($transparentIndex >= 0) {
                $transparentColor = imagecolorsforindex($sourceImage, $transparentIndex);
                $transparentNew = imagecolorallocate(
                    $thumbnail,
                    $transparentColor['red'],
                    $transparentColor['green'],
                    $transparentColor['blue']
                );
                imagefill($thumbnail, 0, 0, $transparentNew);
                imagecolortransparent($thumbnail, $transparentNew);
            }
        }
    }
    
    // Calculate thumbnail dimensions maintaining aspect ratio
    private function calculateThumbnailDimensions($originalWidth, $originalHeight, $maxWidth, $maxHeight) {
        $ratio = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
        
        return [
            'width' => round($originalWidth * $ratio),
            'height' => round($originalHeight * $ratio)
        ];
    }
    
    // Get default font path (fallback)
    private function getDefaultFont() {
        // Try to find a system font
        $possibleFonts = [
            '/System/Library/Fonts/Arial.ttf',  // macOS
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',  // Linux
            'C:\\Windows\\Fonts\\arial.ttf'  // Windows
        ];
        
        foreach ($possibleFonts as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }
        
        return false;
    }
    
    // Get supported formats
    public function getSupportedFormats() {
        return $this->supportedFormats;
    }
    
    // Check if GD library is available
    public static function isGDAvailable() {
        return extension_loaded('gd');
    }
    
    // Get GD info
    public static function getGDInfo() {
        if (!self::isGDAvailable()) {
            return false;
        }
        
        return gd_info();
    }
}
?>