<?php
require_once 'database.php';
require_once 'config.php';

class ImageManager
{
    private $pdo;
    private $db; // DECLARE the property first

    public function __construct()
    {
        $this->db = new Database(); // Now this is not dynamic creation
        $this->pdo = $this->db->getConnection();
    }

    private function generateFilePath($originalName, $mimeType)
    {
        // Get file extension
        $extension = ALLOWED_IMAGE_TYPES[$mimeType] ?? pathinfo($originalName, PATHINFO_EXTENSION);

        // Generate unique filename - FIXED: Better sanitization
        $uniqueId = uniqid();
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $filename = $safeName . '_' . $uniqueId . '.' . $extension;

        // Create year/month directory structure
        $year = date('Y');
        $month = date('m');
        $directory = IMAGE_UPLOAD_PATH . $year . '/' . $month . '/';

        // Create directory if it doesn't exist
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $filePath = $year . '/' . $month . '/' . $filename;

        // DEBUG: Log the generated filename
        error_log("Generated filename: " . $filename . " from original: " . $originalName);

        return [
            'filename' => $filename,
            'file_path' => $filePath,
            'full_path' => $directory . $filename
        ];
    }

    private function validateFile($file)
    {
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessage = $this->getUploadError($file['error']);
            throw new Exception("Upload error: " . $errorMessage);
        }

        // Check file size
        if ($file['size'] > MAX_FILE_SIZE) {
            throw new Exception("File too large. Maximum size: " . (MAX_FILE_SIZE / 1024 / 1024) . "MB");
        }

        // Verify MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($mimeType, ALLOWED_IMAGE_TYPES)) {
            throw new Exception("Invalid file type. Allowed: JPG, PNG, GIF, WebP");
        }

        // Verify it's actually an image
        if (!getimagesize($file['tmp_name'])) {
            throw new Exception("File is not a valid image");
        }

        return $mimeType;
    }

    public function uploadImage($uploadedFile, $userId = null)
    {
        try {
            // Validate the file
            $mimeType = $this->validateFile($uploadedFile);

            // Generate file path
            $pathInfo = $this->generateFilePath($uploadedFile['name'], $mimeType);

            // Move uploaded file to permanent location
            if (!move_uploaded_file($uploadedFile['tmp_name'], $pathInfo['full_path'])) {
                throw new Exception("Failed to move uploaded file");
            }

            // Set proper permissions (especially important on Linux)
            chmod($pathInfo['full_path'], 0644);

            // Store metadata in database
            $imageId = $this->storeImageMetadata(
                $pathInfo['filename'],
                $pathInfo['file_path'],
                $uploadedFile['name'],
                $mimeType,
                $uploadedFile['size'],
                $userId
            );

            return [
                'success' => true,
                'image_id' => $imageId,
                'file_path' => $pathInfo['file_path'],
                'url' => UPLOAD_URL . $pathInfo['file_path']
            ];

        } catch (Exception $e) {
            // Clean up if something went wrong
            if (isset($pathInfo) && file_exists($pathInfo['full_path'])) {
                unlink($pathInfo['full_path']);
            }

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function storeImageMetadata($filename, $filePath, $originalName, $mimeType, $fileSize, $userId)
    {
        $sql = "INSERT INTO images (filename, file_path, original_name, mime_type, file_size, created_by) 
                VALUES (?, ?, ?, ?, ?, ?)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$filename, $filePath, $originalName, $mimeType, $fileSize, $userId]);

        return $this->pdo->lastInsertId();
    }

    public function getImage($imageId)
    {
        $sql = "SELECT * FROM images WHERE id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$imageId]);

        $image = $stmt->fetch();

        if ($image) {
            $image['url'] = UPLOAD_URL . $image['file_path'];
            $image['full_path'] = IMAGE_UPLOAD_PATH . $image['file_path'];
        }

        return $image;
    }

    public function getAllImages()
    {
        $sql = "SELECT * FROM images ORDER BY created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();

        $images = $stmt->fetchAll();

        foreach ($images as &$image) {
            $image['url'] = UPLOAD_URL . $image['file_path'];
        }

        return $images;
    }

    public function deleteImage($imageId)
    {
        // Get image data first
        $image = $this->getImage($imageId);

        if (!$image) {
            throw new Exception("Image not found");
        }

        // Start transaction
        $this->pdo->beginTransaction();

        try {
            // Delete file from file system
            if (file_exists($image['full_path'])) {
                unlink($image['full_path']);
            }

            // Delete from database
            $sql = "DELETE FROM images WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$imageId]);

            $this->pdo->commit();

            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Utility: Get upload error message
     */
    private function getUploadError($errorCode)
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive in form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
        ];

        return $errors[$errorCode] ?? 'Unknown upload error';
    }
}
?>