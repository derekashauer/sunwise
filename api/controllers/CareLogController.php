<?php
/**
 * Care Log Controller
 * Handles care log entries and custom action types
 */

class CareLogController
{
    /**
     * Preset action types available to all users
     */
    private array $presetActions = [
        ['value' => 'water', 'label' => 'Watered', 'icon' => '💧'],
        ['value' => 'fertilize', 'label' => 'Fertilized', 'icon' => '🧪'],
        ['value' => 'mist', 'label' => 'Misted', 'icon' => '💨'],
        ['value' => 'rotate', 'label' => 'Rotated', 'icon' => '🔄'],
        ['value' => 'prune', 'label' => 'Pruned', 'icon' => '✂️'],
        ['value' => 'repot', 'label' => 'Repotted', 'icon' => '🪴'],
        ['value' => 'moved', 'label' => 'Moved Location', 'icon' => '📍'],
        ['value' => 'photo', 'label' => 'Health Check Photo', 'icon' => '📸'],
        ['value' => 'note', 'label' => 'General Note', 'icon' => '📝'],
        ['value' => 'change_water', 'label' => 'Changed Water', 'icon' => '🔄💧'],
        ['value' => 'check_roots', 'label' => 'Checked Roots', 'icon' => '🌱'],
        ['value' => 'pot_up', 'label' => 'Potted Up', 'icon' => '⬆️🪴']
    ];

    /**
     * Get all action types (preset + custom)
     */
    public function getActionTypes(array $params, array $body, ?int $userId): array
    {
        $stmt = db()->prepare('
            SELECT id, name as value, name as label, icon
            FROM custom_action_types
            WHERE user_id = ?
            ORDER BY name
        ');
        $stmt->execute([$userId]);
        $customActions = $stmt->fetchAll();

        // Mark custom actions
        foreach ($customActions as &$action) {
            $action['custom'] = true;
        }

        return [
            'preset' => $this->presetActions,
            'custom' => $customActions
        ];
    }

    /**
     * Create custom action type
     */
    public function createActionType(array $params, array $body, ?int $userId): array
    {
        $name = trim($body['name'] ?? '');
        $icon = trim($body['icon'] ?? '⭐');

        if (!$name) {
            return ['status' => 400, 'data' => ['error' => 'Action name is required']];
        }

        if (strlen($name) > 50) {
            return ['status' => 400, 'data' => ['error' => 'Action name too long (max 50 chars)']];
        }

        // Check if it conflicts with preset
        foreach ($this->presetActions as $preset) {
            if (strtolower($preset['value']) === strtolower($name)) {
                return ['status' => 400, 'data' => ['error' => 'This action type already exists']];
            }
        }

        try {
            $stmt = db()->prepare('
                INSERT INTO custom_action_types (user_id, name, icon)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$userId, $name, $icon]);

            return [
                'action_type' => [
                    'id' => db()->lastInsertId(),
                    'value' => $name,
                    'label' => $name,
                    'icon' => $icon,
                    'custom' => true
                ]
            ];
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'UNIQUE') !== false) {
                return ['status' => 400, 'data' => ['error' => 'You already have this action type']];
            }
            throw $e;
        }
    }

    /**
     * Delete custom action type
     */
    public function deleteActionType(array $params, array $body, ?int $userId): array
    {
        $id = $params['id'];

        $stmt = db()->prepare('DELETE FROM custom_action_types WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);

        if ($stmt->rowCount() === 0) {
            return ['status' => 404, 'data' => ['error' => 'Action type not found']];
        }

        return ['message' => 'Action type deleted'];
    }

    /**
     * Create care log entry
     */
    public function store(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];
        $action = trim($body['action'] ?? '');
        $notes = trim($body['notes'] ?? '');
        $performedAt = $body['performed_at'] ?? null;

        if (!$action) {
            return ['status' => 400, 'data' => ['error' => 'Action type is required']];
        }

        // Verify plant ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Handle photo upload if present
        $photoId = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $imageService = new ImageService();
            $filename = $imageService->upload($_FILES['image']);

            if ($filename) {
                $thumbnail = $imageService->createThumbnail($filename);

                $stmt = db()->prepare('INSERT INTO photos (plant_id, filename, thumbnail) VALUES (?, ?, ?)');
                $stmt->execute([$plantId, $filename, $thumbnail]);
                $photoId = db()->lastInsertId();

                // Trigger AI health analysis in background
                $this->triggerHealthAnalysis($plantId, $photoId, $filename);
            }
        }

        // Insert care log entry
        $sql = 'INSERT INTO care_log (plant_id, action, notes, photo_id';
        $values = [$plantId, $action, $notes ?: null, $photoId];

        if ($performedAt) {
            $sql .= ', performed_at) VALUES (?, ?, ?, ?, ?)';
            $values[] = $performedAt;
        } else {
            $sql .= ') VALUES (?, ?, ?, ?)';
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($values);

        $logId = db()->lastInsertId();

        // Fetch the created entry with photo info
        $stmt = db()->prepare('
            SELECT cl.*, p.filename as photo_filename, p.thumbnail as photo_thumbnail
            FROM care_log cl
            LEFT JOIN photos p ON cl.photo_id = p.id
            WHERE cl.id = ?
        ');
        $stmt->execute([$logId]);

        return ['care_log_entry' => $stmt->fetch()];
    }

    /**
     * Get care log for a plant (with optional filter)
     */
    public function index(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];
        $filter = $_GET['filter'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 100);

        // Verify plant ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $sql = '
            SELECT cl.*, p.filename as photo_filename, p.thumbnail as photo_thumbnail
            FROM care_log cl
            LEFT JOIN photos p ON cl.photo_id = p.id
            WHERE cl.plant_id = ?
        ';
        $values = [$plantId];

        if ($filter) {
            $sql .= ' AND cl.action = ?';
            $values[] = $filter;
        }

        $sql .= ' ORDER BY cl.performed_at DESC LIMIT ?';
        $values[] = $limit;

        $stmt = db()->prepare($sql);
        $stmt->execute($values);

        return ['care_log' => $stmt->fetchAll()];
    }

    /**
     * Trigger async health analysis for uploaded photo
     */
    private function triggerHealthAnalysis(int $plantId, int $photoId, string $filename): void
    {
        try {
            // Get plant data
            $stmt = db()->prepare('SELECT * FROM plants WHERE id = ?');
            $stmt->execute([$plantId]);
            $plant = $stmt->fetch();

            if (!$plant) return;

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
                $stmt->execute([$result['health_status'] ?? 'unknown', $plantId]);

                // Store analysis in photo
                $stmt = db()->prepare('UPDATE photos SET ai_analysis = ?, health_assessment = ? WHERE id = ?');
                $stmt->execute([
                    json_encode($result),
                    $result['health_status'] ?? null,
                    $photoId
                ]);
            }
        } catch (Exception $e) {
            error_log('Health analysis failed: ' . $e->getMessage());
        }
    }
}
