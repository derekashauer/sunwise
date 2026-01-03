<?php
/**
 * Location Controller
 */

class LocationController
{
    /**
     * List all locations for user
     */
    public function index(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            SELECT l.*,
                   (SELECT COUNT(*) FROM plants WHERE location_id = l.id) as plant_count
            FROM locations l
            WHERE l.user_id = ?
            ORDER BY l.name ASC
        ');
        $stmt->execute([$userId]);

        return ['locations' => $stmt->fetchAll()];
    }

    /**
     * Create new location
     */
    public function store(array $params, array $body, ?int $userId): array
    {
        $name = trim($body['name'] ?? '');

        if (!$name) {
            return ['status' => 400, 'data' => ['error' => 'Location name is required']];
        }

        // Check for duplicate
        $stmt = db()->prepare('SELECT id FROM locations WHERE user_id = ? AND LOWER(name) = LOWER(?)');
        $stmt->execute([$userId, $name]);
        if ($stmt->fetch()) {
            return ['status' => 400, 'data' => ['error' => 'Location already exists']];
        }

        $stmt = db()->prepare('INSERT INTO locations (user_id, name) VALUES (?, ?)');
        $stmt->execute([$userId, $name]);

        $locationId = db()->lastInsertId();

        $stmt = db()->prepare('SELECT * FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);

        return ['location' => $stmt->fetch()];
    }

    /**
     * Update location
     */
    public function update(array $params, array $body, ?int $userId): array
    {
        $locationId = $params['id'];
        $name = trim($body['name'] ?? '');

        if (!$name) {
            return ['status' => 400, 'data' => ['error' => 'Location name is required']];
        }

        // Verify ownership
        $stmt = db()->prepare('SELECT id FROM locations WHERE id = ? AND user_id = ?');
        $stmt->execute([$locationId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Location not found']];
        }

        $stmt = db()->prepare('UPDATE locations SET name = ? WHERE id = ?');
        $stmt->execute([$name, $locationId]);

        $stmt = db()->prepare('SELECT * FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);

        return ['location' => $stmt->fetch()];
    }

    /**
     * Delete location
     */
    public function destroy(array $params, array $body, ?int $userId): array
    {
        $locationId = $params['id'];

        // Verify ownership
        $stmt = db()->prepare('SELECT id FROM locations WHERE id = ? AND user_id = ?');
        $stmt->execute([$locationId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Location not found']];
        }

        // Clear location from plants (set to null)
        $stmt = db()->prepare('UPDATE plants SET location_id = NULL WHERE location_id = ?');
        $stmt->execute([$locationId]);

        // Delete location
        $stmt = db()->prepare('DELETE FROM locations WHERE id = ?');
        $stmt->execute([$locationId]);

        return ['message' => 'Location deleted successfully'];
    }

    /**
     * Get plants by location
     */
    public function plants(array $params, array $body, ?int $userId): array
    {
        $locationId = $params['id'];

        // Verify ownership
        $stmt = db()->prepare('SELECT id, name FROM locations WHERE id = ? AND user_id = ?');
        $stmt->execute([$locationId, $userId]);
        $location = $stmt->fetch();

        if (!$location) {
            return ['status' => 404, 'data' => ['error' => 'Location not found']];
        }

        $stmt = db()->prepare('
            SELECT p.*,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as thumbnail
            FROM plants p
            WHERE p.location_id = ? AND p.user_id = ?
            ORDER BY p.name ASC
        ');
        $stmt->execute([$locationId, $userId]);

        return [
            'location' => $location,
            'plants' => $stmt->fetchAll()
        ];
    }
}
