<?php
/**
 * Photo Controller
 */

class PhotoController
{
    /**
     * List photos for a plant
     */
    public function index(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify plant ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $stmt = db()->prepare('
            SELECT id, filename, thumbnail, ai_analysis, uploaded_at
            FROM photos
            WHERE plant_id = ?
            ORDER BY uploaded_at DESC
        ');
        $stmt->execute([$plantId]);
        $photos = $stmt->fetchAll();

        // Parse AI analysis JSON
        foreach ($photos as &$photo) {
            if ($photo['ai_analysis']) {
                $photo['ai_analysis'] = json_decode($photo['ai_analysis'], true);
            }
        }

        return ['photos' => $photos];
    }

    /**
     * Upload new photo for plant
     */
    public function store(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify plant ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Handle image upload
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            return ['status' => 400, 'data' => ['error' => 'No image uploaded']];
        }

        $imageService = new ImageService();
        $filename = $imageService->upload($_FILES['image']);

        if (!$filename) {
            return ['status' => 400, 'data' => ['error' => 'Failed to upload image']];
        }

        // Create thumbnail
        $thumbnail = $imageService->createThumbnail($filename);

        // Save photo record
        $stmt = db()->prepare('INSERT INTO photos (plant_id, filename, thumbnail) VALUES (?, ?, ?)');
        $stmt->execute([$plantId, $filename, $thumbnail]);
        $photoId = db()->lastInsertId();

        // Trigger AI health analysis
        $this->analyzeHealth($plantId, $photoId, $filename);

        $stmt = db()->prepare('SELECT * FROM photos WHERE id = ?');
        $stmt->execute([$photoId]);
        $photo = $stmt->fetch();

        if ($photo['ai_analysis']) {
            $photo['ai_analysis'] = json_decode($photo['ai_analysis'], true);
        }

        return ['photo' => $photo];
    }

    /**
     * Analyze plant health from photo
     */
    private function analyzeHealth(int $plantId, int $photoId, string $filename): void
    {
        try {
            // Get plant info for context
            $stmt = db()->prepare('SELECT * FROM plants WHERE id = ?');
            $stmt->execute([$plantId]);
            $plant = $stmt->fetch();

            $claudeService = new ClaudeService();
            $imagePath = UPLOAD_PATH . '/plants/' . $filename;

            $result = $claudeService->analyzeHealth($imagePath, $plant);

            if ($result) {
                // Update plant health status
                $stmt = db()->prepare('
                    UPDATE plants
                    SET health_status = ?, last_health_check = datetime("now")
                    WHERE id = ?
                ');
                $stmt->execute([
                    $result['health_status'] ?? 'unknown',
                    $plantId
                ]);

                // Store analysis in photo
                $stmt = db()->prepare('UPDATE photos SET ai_analysis = ? WHERE id = ?');
                $stmt->execute([json_encode($result), $photoId]);

                // Update care plan if health changed significantly
                if (in_array($result['health_status'] ?? '', ['struggling', 'critical'])) {
                    $carePlanController = new CarePlanController();
                    $carePlanController->generateCarePlan($plantId);
                }
            }
        } catch (Exception $e) {
            error_log('Health analysis failed: ' . $e->getMessage());
        }
    }
}
