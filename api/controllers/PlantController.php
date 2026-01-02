<?php
/**
 * Plant Controller
 */

class PlantController
{
    /**
     * List all plants for user
     */
    public function index(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            SELECT p.*,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as thumbnail
            FROM plants p
            WHERE p.user_id = ?
            ORDER BY p.created_at DESC
        ');
        $stmt->execute([$userId]);
        $plants = $stmt->fetchAll();

        return ['plants' => $plants];
    }

    /**
     * Create new plant
     */
    public function store(array $params, array $body, ?int $userId): array
    {
        $name = trim($body['name'] ?? '');

        if (!$name) {
            return ['status' => 400, 'data' => ['error' => 'Plant name is required']];
        }

        // Handle image upload
        $imageFilename = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $imageService = new ImageService();
            $imageFilename = $imageService->upload($_FILES['image']);

            if (!$imageFilename) {
                return ['status' => 400, 'data' => ['error' => 'Failed to upload image']];
            }
        }

        // Insert plant
        $stmt = db()->prepare('
            INSERT INTO plants (user_id, name, species, pot_size, soil_type, light_condition, location, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $userId,
            $name,
            $body['species'] ?? null,
            $body['pot_size'] ?? 'medium',
            $body['soil_type'] ?? 'standard',
            $body['light_condition'] ?? 'medium',
            $body['location'] ?? null,
            $body['notes'] ?? null
        ]);

        $plantId = db()->lastInsertId();

        // Save photo if uploaded
        if ($imageFilename) {
            $stmt = db()->prepare('INSERT INTO photos (plant_id, filename) VALUES (?, ?)');
            $stmt->execute([$plantId, $imageFilename]);
            $photoId = db()->lastInsertId();

            // Trigger AI identification in background
            $this->triggerAIIdentification($plantId, $photoId, $imageFilename);
        }

        // Get the created plant
        $stmt = db()->prepare('SELECT * FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);
        $plant = $stmt->fetch();
        $plant['thumbnail'] = $imageFilename;

        return ['plant' => $plant];
    }

    /**
     * Get single plant
     */
    public function show(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        $stmt = db()->prepare('
            SELECT p.*,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as thumbnail
            FROM plants p
            WHERE p.id = ? AND p.user_id = ?
        ');
        $stmt->execute([$plantId, $userId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        return ['plant' => $plant];
    }

    /**
     * Update plant
     */
    public function update(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Build update query dynamically
        $allowedFields = ['name', 'species', 'pot_size', 'soil_type', 'light_condition', 'location', 'notes'];
        $updates = [];
        $values = [];

        foreach ($allowedFields as $field) {
            if (isset($body[$field])) {
                $updates[] = "$field = ?";
                $values[] = $body[$field];
            }
        }

        if (empty($updates)) {
            return ['status' => 400, 'data' => ['error' => 'No fields to update']];
        }

        $values[] = $plantId;
        $sql = 'UPDATE plants SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute($values);

        // Return updated plant
        $stmt = db()->prepare('SELECT * FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);

        return ['plant' => $stmt->fetch()];
    }

    /**
     * Delete plant
     */
    public function destroy(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Delete photos from filesystem
        $stmt = db()->prepare('SELECT filename, thumbnail FROM photos WHERE plant_id = ?');
        $stmt->execute([$plantId]);
        $photos = $stmt->fetchAll();

        $imageService = new ImageService();
        foreach ($photos as $photo) {
            $imageService->delete($photo['filename']);
            if ($photo['thumbnail']) {
                $imageService->delete($photo['thumbnail']);
            }
        }

        // Delete plant (cascade will handle related records)
        $stmt = db()->prepare('DELETE FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);

        return ['message' => 'Plant deleted successfully'];
    }

    /**
     * Trigger AI identification (async in future, sync for now)
     */
    private function triggerAIIdentification(int $plantId, int $photoId, string $filename): void
    {
        try {
            $claudeService = new ClaudeService();
            $imagePath = UPLOAD_PATH . '/plants/' . $filename;

            $result = $claudeService->identifyPlant($imagePath);

            if ($result) {
                // Update plant with identified species
                $stmt = db()->prepare('
                    UPDATE plants
                    SET species = ?, species_confidence = ?, health_status = ?, last_health_check = datetime("now")
                    WHERE id = ?
                ');
                $stmt->execute([
                    $result['species'] ?? null,
                    $result['confidence'] ?? null,
                    $result['health_status'] ?? 'unknown',
                    $plantId
                ]);

                // Store AI analysis in photo
                $stmt = db()->prepare('UPDATE photos SET ai_analysis = ? WHERE id = ?');
                $stmt->execute([json_encode($result), $photoId]);

                // Generate initial care plan
                $carePlanController = new CarePlanController();
                $carePlanController->generateCarePlan($plantId);
            }
        } catch (Exception $e) {
            error_log('AI identification failed: ' . $e->getMessage());
        }
    }
}
