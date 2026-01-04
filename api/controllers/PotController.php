<?php
/**
 * Pot Controller
 * Manages available pots inventory
 */

class PotController
{
    /**
     * List all pots for user
     * GET /pots
     */
    public function index(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            SELECT p.*, pl.name as plant_name
            FROM pots p
            LEFT JOIN plants pl ON p.plant_id = pl.id
            WHERE p.user_id = ?
            ORDER BY p.available DESC, p.created_at DESC
        ');
        $stmt->execute([$userId]);
        $pots = $stmt->fetchAll();

        return ['pots' => $pots];
    }

    /**
     * Get available pots only
     * GET /pots/available
     */
    public function available(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            SELECT * FROM pots
            WHERE user_id = ? AND available = 1
            ORDER BY size ASC, created_at DESC
        ');
        $stmt->execute([$userId]);
        $pots = $stmt->fetchAll();

        return ['pots' => $pots];
    }

    /**
     * Get single pot
     * GET /pots/{id}
     */
    public function show(array $params, array $body, ?int $userId): array
    {
        $potId = $params['id'];

        $stmt = db()->prepare('
            SELECT p.*, pl.name as plant_name
            FROM pots p
            LEFT JOIN plants pl ON p.plant_id = pl.id
            WHERE p.id = ? AND p.user_id = ?
        ');
        $stmt->execute([$potId, $userId]);
        $pot = $stmt->fetch();

        if (!$pot) {
            return ['status' => 404, 'data' => ['error' => 'Pot not found']];
        }

        return ['pot' => $pot];
    }

    /**
     * Create new pot
     * POST /pots
     */
    public function store(array $params, array $body, ?int $userId): array
    {
        $size = $body['size'] ?? '';

        if (!$size) {
            return ['status' => 400, 'data' => ['error' => 'Pot size is required']];
        }

        // Handle image upload
        $imageFilename = null;
        $thumbnailFilename = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $imageService = new ImageService();
            $imageFilename = $imageService->upload($_FILES['image']);

            if (!$imageFilename) {
                return ['status' => 400, 'data' => ['error' => 'Failed to upload image']];
            }

            // Create thumbnail
            $thumbnailFilename = $imageService->createThumbnail($imageFilename, 200);
        }

        $stmt = db()->prepare('
            INSERT INTO pots (user_id, name, size, diameter_inches, has_drainage, material, color, image, image_thumbnail, notes, available)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ');
        $stmt->execute([
            $userId,
            $body['name'] ?? null,
            $size,
            $body['diameter_inches'] ?? null,
            isset($body['has_drainage']) ? ($body['has_drainage'] ? 1 : 0) : 1,
            $body['material'] ?? null,
            $body['color'] ?? null,
            $imageFilename,
            $thumbnailFilename,
            $body['notes'] ?? null
        ]);

        $potId = db()->lastInsertId();

        $stmt = db()->prepare('SELECT * FROM pots WHERE id = ?');
        $stmt->execute([$potId]);

        return ['pot' => $stmt->fetch()];
    }

    /**
     * Update pot
     * PUT /pots/{id}
     */
    public function update(array $params, array $body, ?int $userId): array
    {
        $potId = $params['id'];

        // Verify ownership
        $stmt = db()->prepare('SELECT id FROM pots WHERE id = ? AND user_id = ?');
        $stmt->execute([$potId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Pot not found']];
        }

        $allowedFields = ['name', 'size', 'diameter_inches', 'has_drainage', 'material', 'color', 'notes', 'available', 'plant_id'];
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

        $values[] = $potId;
        $sql = 'UPDATE pots SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $stmt = db()->prepare($sql);
        $stmt->execute($values);

        $stmt = db()->prepare('SELECT * FROM pots WHERE id = ?');
        $stmt->execute([$potId]);

        return ['pot' => $stmt->fetch()];
    }

    /**
     * Upload photo for pot
     * POST /pots/{id}/photo
     */
    public function uploadPhoto(array $params, array $body, ?int $userId): array
    {
        $potId = $params['id'];

        // Verify ownership
        $stmt = db()->prepare('SELECT id, image FROM pots WHERE id = ? AND user_id = ?');
        $stmt->execute([$potId, $userId]);
        $pot = $stmt->fetch();

        if (!$pot) {
            return ['status' => 404, 'data' => ['error' => 'Pot not found']];
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            return ['status' => 400, 'data' => ['error' => 'No image uploaded']];
        }

        $imageService = new ImageService();

        // Delete old image if exists
        if ($pot['image']) {
            $imageService->delete($pot['image']);
        }

        $imageFilename = $imageService->upload($_FILES['image']);
        if (!$imageFilename) {
            return ['status' => 400, 'data' => ['error' => 'Failed to upload image']];
        }

        $thumbnailFilename = $imageService->createThumbnail($imageFilename, 200);

        $stmt = db()->prepare('UPDATE pots SET image = ?, image_thumbnail = ? WHERE id = ?');
        $stmt->execute([$imageFilename, $thumbnailFilename, $potId]);

        return ['image' => $imageFilename, 'thumbnail' => $thumbnailFilename];
    }

    /**
     * Delete pot
     * DELETE /pots/{id}
     */
    public function destroy(array $params, array $body, ?int $userId): array
    {
        $potId = $params['id'];

        // Verify ownership and get image
        $stmt = db()->prepare('SELECT id, image, image_thumbnail FROM pots WHERE id = ? AND user_id = ?');
        $stmt->execute([$potId, $userId]);
        $pot = $stmt->fetch();

        if (!$pot) {
            return ['status' => 404, 'data' => ['error' => 'Pot not found']];
        }

        // Delete images
        if ($pot['image']) {
            $imageService = new ImageService();
            $imageService->delete($pot['image']);
            if ($pot['image_thumbnail']) {
                $imageService->delete($pot['image_thumbnail']);
            }
        }

        $stmt = db()->prepare('DELETE FROM pots WHERE id = ?');
        $stmt->execute([$potId]);

        return ['message' => 'Pot deleted successfully'];
    }

    /**
     * Assign pot to plant
     * POST /pots/{id}/assign
     */
    public function assign(array $params, array $body, ?int $userId): array
    {
        $potId = $params['id'];
        $plantId = $body['plant_id'] ?? null;

        // Verify pot ownership
        $stmt = db()->prepare('SELECT id FROM pots WHERE id = ? AND user_id = ?');
        $stmt->execute([$potId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Pot not found']];
        }

        // Verify plant ownership if assigning
        if ($plantId) {
            $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
            $stmt->execute([$plantId, $userId]);
            if (!$stmt->fetch()) {
                return ['status' => 404, 'data' => ['error' => 'Plant not found']];
            }
        }

        // Update pot
        $stmt = db()->prepare('UPDATE pots SET plant_id = ?, available = ? WHERE id = ?');
        $stmt->execute([$plantId, $plantId ? 0 : 1, $potId]);

        $stmt = db()->prepare('
            SELECT p.*, pl.name as plant_name
            FROM pots p
            LEFT JOIN plants pl ON p.plant_id = pl.id
            WHERE p.id = ?
        ');
        $stmt->execute([$potId]);

        return ['pot' => $stmt->fetch()];
    }
}
