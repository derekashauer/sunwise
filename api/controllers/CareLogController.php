<?php
/**
 * Care Log Controller
 * Handles care log entries and custom action types
 */

class CareLogController
{
    /**
     * Check if user can access a specific plant (owns it or has household access)
     */
    private function canAccessPlant(int $plantId, int $userId): bool
    {
        return CareScheduler::canAccessPlant($plantId, $userId);
    }

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
     * Create care log entry.
     *
     * performed_at may be a local date ("2026-11-15") or local date-time
     * ("2026-11-15T18:30"); it is stored in UTC. If the action matches a
     * pending task due within the next two days, that task is completed too,
     * so logging "Watered" from the plant page clears the water task.
     */
    public function store(array $params, array $body, ?int $userId): array
    {
        $plantId = (int) $params['id'];
        $action = trim($body['action'] ?? '');
        $notes = trim($body['notes'] ?? '');

        if (!$action) {
            return ['status' => 400, 'data' => ['error' => 'Action type is required']];
        }
        if (mb_strlen($action) > 50) {
            return ['status' => 400, 'data' => ['error' => 'Action name too long (max 50 chars)']];
        }

        if (!CareScheduler::canAccessPlant($plantId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $performedAt = Clock::utcNow();
        if (!empty($body['performed_at'])) {
            $performedAt = Clock::localToUtc((string) $body['performed_at']);
            if (!$performedAt) {
                return ['status' => 400, 'data' => ['error' => 'Pick a date that is today or earlier']];
            }
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
                $photoId = (int) db()->lastInsertId();

                // Health analysis runs after the response is sent
                $analysisPhotoId = $photoId;
                Deferred::add(function () use ($plantId, $analysisPhotoId, $filename, $userId) {
                    $this->triggerHealthAnalysis($plantId, $analysisPhotoId, $filename, $userId);
                });
            }
        }

        // Find a matching pending task to close
        $matchingTask = null;
        $taskType = CareScheduler::ACTION_TO_TASK[$action] ?? null;
        if ($taskType) {
            $stmt = db()->prepare('
                SELECT id FROM tasks
                WHERE plant_id = ? AND task_type = ? AND completed_at IS NULL AND skipped_at IS NULL
                  AND due_date <= ?
                ORDER BY due_date ASC LIMIT 1
            ');
            $stmt->execute([$plantId, $taskType, Clock::daysFromToday(2)]);
            $matchId = $stmt->fetchColumn();
            if ($matchId) {
                $matchingTask = CareScheduler::loadTask((int) $matchId);
            }
        }

        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes, photo_id, performed_by_user_id, performed_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $plantId,
            $matchingTask['id'] ?? null,
            $action,
            $notes ?: null,
            $photoId,
            $userId,
            $performedAt,
        ]);
        $logId = db()->lastInsertId();

        $completedTaskId = null;
        if ($matchingTask) {
            // The log entry above is the record; don't write a second one
            (new CareScheduler())->complete($matchingTask, $userId, $notes ?: null, null, false);
            $completedTaskId = (int) $matchingTask['id'];
        } elseif (in_array($action, ['repot', 'pot_up'], true)) {
            (new CareScheduler())->normalizePendingFertilize($plantId);
        }

        // Fetch the created entry with photo info and performer name
        $stmt = db()->prepare('
            SELECT cl.*, p.filename as photo_filename, p.thumbnail as photo_thumbnail,
                   CASE WHEN cl.performed_by_user_id IS NOT NULL THEN
                       (SELECT COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, \'@\') - 1))
                        FROM users u WHERE u.id = cl.performed_by_user_id)
                   ELSE NULL END as performed_by_name
            FROM care_log cl
            LEFT JOIN photos p ON cl.photo_id = p.id
            WHERE cl.id = ?
        ');
        $stmt->execute([$logId]);

        return [
            'care_log_entry' => $stmt->fetch(),
            'completed_task_id' => $completedTaskId,
        ];
    }

    /**
     * Get care log for a plant (with optional filter)
     */
    public function index(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];
        $filter = $_GET['filter'] ?? null;
        $limit = min((int)($_GET['limit'] ?? 50), 100);

        // Verify plant access (owned or shared via household)
        if (!$this->canAccessPlant($plantId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $sql = '
            SELECT cl.*, p.filename as photo_filename, p.thumbnail as photo_thumbnail,
                   CASE WHEN cl.performed_by_user_id IS NOT NULL THEN
                       (SELECT COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, \'@\') - 1))
                        FROM users u WHERE u.id = cl.performed_by_user_id)
                   ELSE NULL END as performed_by_name
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
     * Health analysis for an uploaded photo (runs after the response is sent)
     */
    private function triggerHealthAnalysis(int $plantId, int $photoId, string $filename, ?int $userId = null): void
    {
        try {
            // Get plant data
            $stmt = db()->prepare('SELECT * FROM plants WHERE id = ?');
            $stmt->execute([$plantId]);
            $plant = $stmt->fetch();

            if (!$plant) return;

            // Use the owner's chosen AI provider and key
            $claudeService = AIServiceFactory::getForUser($userId ?? (int) $plant['user_id']);
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
