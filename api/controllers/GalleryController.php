<?php
/**
 * Gallery Controller
 * Handles public plant gallery viewing (no auth required)
 */

class GalleryController
{
    /**
     * Get public gallery by token
     * GET /gallery/{token}
     */
    public function show(array $params, array $body, ?int $userId): array
    {
        $token = $params['token'] ?? '';

        if (empty($token)) {
            return ['status' => 400, 'data' => ['error' => 'Gallery token required']];
        }

        // Find user with this token and gallery enabled
        $stmt = db()->prepare('
            SELECT id, public_gallery_name, public_gallery_enabled
            FROM users
            WHERE public_gallery_token = ? AND public_gallery_enabled = 1
        ');
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['status' => 404, 'data' => ['error' => 'Gallery not found or not public']];
        }

        // Get user's plants with photos
        $stmt = db()->prepare('
            SELECT p.id, p.name, p.species, p.health_status, p.light_condition,
                   l.name as location_name, l.window_orientation,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as thumbnail
            FROM plants p
            LEFT JOIN locations l ON p.location_id = l.id
            WHERE p.user_id = ?
            ORDER BY p.name ASC
        ');
        $stmt->execute([$user['id']]);
        $plants = $stmt->fetchAll();

        return [
            'gallery_name' => $user['public_gallery_name'] ?: 'My Plant Collection',
            'plant_count' => count($plants),
            'plants' => $plants
        ];
    }
}
