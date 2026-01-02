<?php
/**
 * Image Upload and Processing Service
 */

class ImageService
{
    private string $uploadPath;
    private int $maxSize;
    private array $allowedTypes;
    private int $maxWidth = 1200;
    private int $thumbnailSize = 300;

    public function __construct()
    {
        $this->uploadPath = UPLOAD_PATH . '/plants';
        $this->maxSize = MAX_UPLOAD_SIZE;
        $this->allowedTypes = ALLOWED_IMAGE_TYPES;

        // Ensure upload directory exists
        if (!is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0755, true);
        }
    }

    /**
     * Upload and process image
     */
    public function upload(array $file): ?string
    {
        // Validate file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        if ($file['size'] > $this->maxSize) {
            return null;
        }

        $mimeType = mime_content_type($file['tmp_name']);
        if (!in_array($mimeType, $this->allowedTypes)) {
            return null;
        }

        // Generate unique filename
        $extension = $this->getExtension($mimeType);
        $filename = uniqid('plant_') . '_' . time() . '.' . $extension;
        $filepath = $this->uploadPath . '/' . $filename;

        // Process and save image
        if (!$this->processImage($file['tmp_name'], $filepath, $mimeType)) {
            return null;
        }

        return $filename;
    }

    /**
     * Create thumbnail
     */
    public function createThumbnail(string $filename): ?string
    {
        $filepath = $this->uploadPath . '/' . $filename;
        if (!file_exists($filepath)) {
            return null;
        }

        $thumbnailFilename = 'thumb_' . $filename;
        $thumbnailPath = $this->uploadPath . '/' . $thumbnailFilename;

        $mimeType = mime_content_type($filepath);
        if (!$this->resizeImage($filepath, $thumbnailPath, $mimeType, $this->thumbnailSize)) {
            return null;
        }

        return $thumbnailFilename;
    }

    /**
     * Delete image and its thumbnail
     */
    public function delete(string $filename): bool
    {
        $filepath = $this->uploadPath . '/' . $filename;
        $thumbnailPath = $this->uploadPath . '/thumb_' . $filename;

        $deleted = false;
        if (file_exists($filepath)) {
            $deleted = unlink($filepath);
        }
        if (file_exists($thumbnailPath)) {
            unlink($thumbnailPath);
        }

        return $deleted;
    }

    /**
     * Process and optimize image
     */
    private function processImage(string $source, string $destination, string $mimeType): bool
    {
        return $this->resizeImage($source, $destination, $mimeType, $this->maxWidth);
    }

    /**
     * Resize image maintaining aspect ratio
     */
    private function resizeImage(string $source, string $destination, string $mimeType, int $maxDimension): bool
    {
        // Create image from source
        $image = $this->createImageFromFile($source, $mimeType);
        if (!$image) {
            // If GD fails, just copy the file
            return copy($source, $destination);
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Calculate new dimensions
        if ($width > $height) {
            if ($width > $maxDimension) {
                $newWidth = $maxDimension;
                $newHeight = (int) ($height * ($maxDimension / $width));
            } else {
                $newWidth = $width;
                $newHeight = $height;
            }
        } else {
            if ($height > $maxDimension) {
                $newHeight = $maxDimension;
                $newWidth = (int) ($width * ($maxDimension / $height));
            } else {
                $newWidth = $width;
                $newHeight = $height;
            }
        }

        // Create resized image
        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG
        if ($mimeType === 'image/png') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Save as JPEG for efficiency (or PNG if original was PNG)
        if ($mimeType === 'image/png') {
            $result = imagepng($resized, $destination, 8);
        } else {
            $result = imagejpeg($resized, $destination, 85);
        }

        imagedestroy($image);
        imagedestroy($resized);

        return $result;
    }

    /**
     * Create GD image resource from file
     */
    private function createImageFromFile(string $filepath, string $mimeType)
    {
        switch ($mimeType) {
            case 'image/jpeg':
                return @imagecreatefromjpeg($filepath);
            case 'image/png':
                return @imagecreatefrompng($filepath);
            case 'image/webp':
                return @imagecreatefromwebp($filepath);
            default:
                return false;
        }
    }

    /**
     * Get file extension from mime type
     */
    private function getExtension(string $mimeType): string
    {
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'jpg' // Convert HEIC to JPG
        ];

        return $extensions[$mimeType] ?? 'jpg';
    }
}
