<?php
/**
 * Care Plan Controller
 */

class CarePlanController
{
    /**
     * Get active care plan for plant
     */
    public function show(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify plant ownership
        $stmt = db()->prepare('SELECT * FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Get active care plan
        $stmt = db()->prepare('
            SELECT * FROM care_plans
            WHERE plant_id = ? AND is_active = 1
            ORDER BY generated_at DESC
            LIMIT 1
        ');
        $stmt->execute([$plantId]);
        $carePlan = $stmt->fetch();

        if (!$carePlan) {
            // Generate one if none exists
            $carePlan = $this->generateCarePlan($plantId);
        }

        // Get upcoming tasks for this plan
        $stmt = db()->prepare('
            SELECT * FROM tasks
            WHERE care_plan_id = ? AND completed_at IS NULL AND skipped_at IS NULL
            ORDER BY due_date ASC
            LIMIT 10
        ');
        $stmt->execute([$carePlan['id']]);
        $tasks = $stmt->fetchAll();

        return [
            'care_plan' => $carePlan,
            'tasks' => $tasks
        ];
    }

    /**
     * Regenerate care plan
     */
    public function regenerate(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify plant ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $carePlan = $this->generateCarePlan($plantId, $userId);

        return ['care_plan' => $carePlan, 'message' => 'Care plan regenerated'];
    }

    /**
     * Get care log history
     */
    public function careLog(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify plant ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $stmt = db()->prepare('
            SELECT cl.*, p.filename as photo_filename
            FROM care_log cl
            LEFT JOIN photos p ON cl.photo_id = p.id
            WHERE cl.plant_id = ?
            ORDER BY cl.performed_at DESC
            LIMIT 50
        ');
        $stmt->execute([$plantId]);

        return ['care_log' => $stmt->fetchAll()];
    }

    /**
     * Generate care plan using AI
     */
    public function generateCarePlan(int $plantId, ?int $userId = null): ?array
    {
        // Get comprehensive plant details including location
        $stmt = db()->prepare('
            SELECT p.*, l.name as location_name, l.light_level as location_light
            FROM plants p
            LEFT JOIN locations l ON p.location_id = l.id
            WHERE p.id = ?
        ');
        $stmt->execute([$plantId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return null;
        }

        // Add location info to plant data for AI
        if ($plant['location_name']) {
            $plant['location'] = $plant['location_name'];
            if ($plant['location_light']) {
                $plant['location'] .= ' (' . $plant['location_light'] . ' light)';
            }
        }

        // Get species care info if available and add to plant context
        if (!empty($plant['species_care_info'])) {
            $careInfo = json_decode($plant['species_care_info'], true);
            if ($careInfo) {
                $plant['known_care_needs'] = $careInfo;
            }
        }

        // Get recent care log with more detail
        $stmt = db()->prepare('
            SELECT action, performed_at, notes, outcome
            FROM care_log
            WHERE plant_id = ?
            ORDER BY performed_at DESC
            LIMIT 30
        ');
        $stmt->execute([$plantId]);
        $careLog = $stmt->fetchAll();

        // Get most recent photo and its AI analysis for health context
        $stmt = db()->prepare('
            SELECT filename, ai_analysis, uploaded_at
            FROM photos
            WHERE plant_id = ?
            ORDER BY uploaded_at DESC
            LIMIT 1
        ');
        $stmt->execute([$plantId]);
        $photo = $stmt->fetch();

        // Add photo analysis to plant context if available
        if ($photo && !empty($photo['ai_analysis'])) {
            $photoAnalysis = json_decode($photo['ai_analysis'], true);
            if ($photoAnalysis) {
                $plant['last_photo_analysis'] = $photoAnalysis;
                $plant['last_photo_date'] = $photo['uploaded_at'];
            }
        }

        // Get any completed/skipped task info for context (what worked, what was skipped)
        $stmt = db()->prepare('
            SELECT task_type, COUNT(*) as count, MAX(completed_at) as last_completed
            FROM tasks
            WHERE plant_id = ? AND completed_at IS NOT NULL
            GROUP BY task_type
        ');
        $stmt->execute([$plantId]);
        $completedTaskStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($completedTaskStats)) {
            $plant['care_history_stats'] = $completedTaskStats;
        }

        // Deactivate existing care plans and delete pending tasks
        $stmt = db()->prepare('UPDATE care_plans SET is_active = 0 WHERE plant_id = ?');
        $stmt->execute([$plantId]);

        // Delete pending tasks from old care plans (keep completed/skipped for history)
        $stmt = db()->prepare('
            DELETE FROM tasks
            WHERE plant_id = ?
              AND completed_at IS NULL
              AND skipped_at IS NULL
        ');
        $stmt->execute([$plantId]);

        // Determine season
        $month = (int) date('n');
        if ($month >= 3 && $month <= 5) {
            $season = 'spring';
        } elseif ($month >= 6 && $month <= 8) {
            $season = 'summer';
        } elseif ($month >= 9 && $month <= 11) {
            $season = 'fall';
        } else {
            $season = 'winter';
        }

        // Generate care plan with AI - use AIServiceFactory to get user's preferred provider
        try {
            $effectiveUserId = $userId ?? $plant['user_id'];
            $aiService = AIServiceFactory::getForUser($effectiveUserId);
            $aiPlan = $aiService->generateCarePlan($plant, $careLog, $season);
            ClaudeService::logUsage($effectiveUserId, 'care_plan', true, null, $aiService->getModel());
        } catch (Exception $e) {
            error_log('AI care plan generation failed: ' . $e->getMessage());
            ClaudeService::logUsage($userId ?? $plant['user_id'], 'care_plan', false, $e->getMessage());
            $aiPlan = $this->getDefaultCarePlan($plant, $season);
        }

        // Create care plan record
        $stmt = db()->prepare('
            INSERT INTO care_plans (plant_id, season, ai_reasoning, next_photo_check, photo_check_reason, valid_until)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $plantId,
            $season,
            $aiPlan['reasoning'] ?? null,
            $aiPlan['next_photo_check'] ?? date('Y-m-d', strtotime('+14 days')),
            $aiPlan['photo_check_reason'] ?? 'Regular health check',
            date('Y-m-d', strtotime('+3 months'))
        ]);
        $carePlanId = db()->lastInsertId();

        // Get user's enabled task types
        $enabledTaskTypes = $this->getEnabledTaskTypes($plant['user_id']);

        // Create tasks from AI plan (filter by enabled types)
        if (!empty($aiPlan['tasks'])) {
            foreach ($aiPlan['tasks'] as $task) {
                // Skip if task type is disabled
                if (!in_array($task['type'], $enabledTaskTypes)) {
                    continue;
                }

                // Skip rotate tasks if plant's can_rotate is false
                if ($task['type'] === 'rotate' && isset($plant['can_rotate']) && !$plant['can_rotate']) {
                    continue;
                }

                // Propagation task validation
                if (!empty($plant['is_propagation'])) {
                    // Water propagations: no water, fertilize, or rotate tasks
                    if ($plant['soil_type'] === 'water' && in_array($task['type'], ['water', 'fertilize', 'rotate'])) {
                        continue;
                    }
                    // Rooting medium propagations: no change_water tasks
                    if ($plant['soil_type'] !== 'water' && $task['type'] === 'change_water') {
                        continue;
                    }
                }

                $stmt = db()->prepare('
                    INSERT INTO tasks (care_plan_id, plant_id, task_type, due_date, recurrence, instructions, priority)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $carePlanId,
                    $plantId,
                    $task['type'],
                    $task['due_date'],
                    isset($task['recurrence']) ? json_encode($task['recurrence']) : null,
                    $task['instructions'] ?? null,
                    $task['priority'] ?? 'normal'
                ]);
            }
        }

        // Get created care plan
        $stmt = db()->prepare('SELECT * FROM care_plans WHERE id = ?');
        $stmt->execute([$carePlanId]);

        return $stmt->fetch();
    }

    /**
     * Get list of enabled task types for a user
     */
    private function getEnabledTaskTypes(int $userId): array
    {
        // All default task types
        $allTypes = ['water', 'fertilize', 'mist', 'rotate', 'trim', 'repot', 'check', 'change_water', 'check_roots', 'pot_up'];

        // Get user's disabled types
        $stmt = db()->prepare('SELECT task_type FROM task_type_settings WHERE user_id = ? AND enabled = 0');
        $stmt->execute([$userId]);
        $disabledTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Return types that are not disabled
        return array_diff($allTypes, $disabledTypes);
    }

    /**
     * Get default care plan if AI fails
     */
    private function getDefaultCarePlan(array $plant, string $season): array
    {
        $today = date('Y-m-d');

        // Adjust watering based on season
        $waterInterval = $season === 'summer' ? 5 : ($season === 'winter' ? 10 : 7);

        return [
            'reasoning' => 'Default care plan based on general houseplant guidelines',
            'next_photo_check' => date('Y-m-d', strtotime('+14 days')),
            'photo_check_reason' => 'Regular health check',
            'tasks' => [
                [
                    'type' => 'water',
                    'due_date' => $today,
                    'recurrence' => ['type' => 'days', 'interval' => $waterInterval],
                    'instructions' => 'Water thoroughly until water drains from bottom',
                    'priority' => 'normal'
                ],
                [
                    'type' => 'check',
                    'due_date' => date('Y-m-d', strtotime('+3 days')),
                    'recurrence' => ['type' => 'days', 'interval' => 7],
                    'instructions' => 'Check soil moisture and leaf condition',
                    'priority' => 'low'
                ],
                [
                    'type' => 'fertilize',
                    'due_date' => date('Y-m-d', strtotime('+14 days')),
                    'recurrence' => ['type' => 'days', 'interval' => $season === 'winter' ? 60 : 30],
                    'instructions' => 'Apply balanced liquid fertilizer at half strength',
                    'priority' => 'low'
                ]
            ]
        ];
    }
}
