<?php
require_once 'database.php';
require_once 'config.php';

class FoodImageManager
{
    private $pdo;
    private $db;

    public function __construct()
    {
        $this->db = new Database();
        $this->pdo = $this->db->getConnection();
    }

    private function generateFilePath($originalName, $mimeType) {
        $extension = ALLOWED_IMAGE_TYPES[$mimeType] ?? pathinfo($originalName, PATHINFO_EXTENSION);
        $uniqueId = uniqid();
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($originalName, PATHINFO_FILENAME));
        $filename = $safeName . '_' . $uniqueId . '.' . $extension;
        $year = date('Y');
        $month = date('m');
        $directory = IMAGE_UPLOAD_PATH . 'food/' . $year . '/' . $month . '/'; 

        if (!is_dir($directory)) { mkdir($directory, 0755, true); }

        return [
            'filename' => $filename,
            'file_path' => 'food/' . $year . '/' . $month . '/' . $filename,
            'full_path' => $directory . $filename
        ];
    }

    private function validateFile($file) {
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Upload error code: " . $file['error']);
        if ($file['size'] > MAX_FILE_SIZE) throw new Exception("File too large.");
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!array_key_exists($mimeType, ALLOWED_IMAGE_TYPES)) throw new Exception("Invalid file type.");
        return $mimeType;
    }

    public function uploadFoodImage($uploadedFile, $foodName, $foodCategory = 'main', $userId = null)
    {
        try {
            $mimeType = $this->validateFile($uploadedFile);
            $pathInfo = $this->generateFilePath($uploadedFile['name'], $mimeType);

            if (!move_uploaded_file($uploadedFile['tmp_name'], $pathInfo['full_path'])) {
                throw new Exception("Failed to move uploaded file");
            }
            chmod($pathInfo['full_path'], 0644);

            $imageId = $this->storeFoodMetadata(
                $pathInfo['filename'],
                $pathInfo['file_path'],
                $uploadedFile['name'],
                $mimeType,
                $uploadedFile['size'],
                $userId,
                $foodName,
                $foodCategory
            );

            return ['success' => true, 'image_id' => $imageId, 'url' => UPLOAD_URL . $pathInfo['file_path']];

        } catch (Exception $e) {
            if (isset($pathInfo) && file_exists($pathInfo['full_path'])) unlink($pathInfo['full_path']);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function storeFoodMetadata($filename, $filePath, $originalName, $mimeType, $fileSize, $userId, $foodName, $foodCategory)
    {
        $sql = "INSERT INTO food_images (filename, file_path, original_name, mime_type, file_size, created_by, food_name, food_category) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$filename, $filePath, $originalName, $mimeType, $fileSize, $userId, $foodName, $foodCategory]);

        return $this->pdo->lastInsertId();
    }

    public function getAllFoodImages()
    {
        $sql = "SELECT * FROM food_images ORDER BY created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $images = $stmt->fetchAll();

        foreach ($images as &$image) {
            $image['url'] = UPLOAD_URL . $image['file_path'];
        }
        return $images;
    }

    // --- NEW: DELETE FUNCTION ---
    public function deleteFoodImage($imageId)
    {
        // 1. Get image data first to find the file path
        $sql = "SELECT * FROM food_images WHERE image_id = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$imageId]);
        $image = $stmt->fetch();

        if (!$image) {
            throw new Exception("Food image not found");
        }

        // Construct full path for deletion
        $fullPath = IMAGE_UPLOAD_PATH . $image['file_path'];

        $this->pdo->beginTransaction();

        try {
            // 2. Delete file from folder
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }

            // 3. Delete record from database
            $sql = "DELETE FROM food_images WHERE image_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$imageId]);

            $this->pdo->commit();
            return true;

        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
?>