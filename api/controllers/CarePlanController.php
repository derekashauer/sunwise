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

        // Owners and household members can view the plan
        if (!CareScheduler::canAccessPlant((int) $plantId, $userId)) {
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
            $carePlan = $this->generateCarePlan((int) $plantId, $userId);
        }
        if (!$carePlan) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Get upcoming tasks for this plan
        $stmt = db()->prepare('
            SELECT * FROM tasks
            WHERE plant_id = ? AND completed_at IS NULL AND skipped_at IS NULL
            ORDER BY due_date ASC
            LIMIT 10
        ');
        $stmt->execute([$plantId]);
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

        if (!CareScheduler::canAccessPlant((int) $plantId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $carePlan = $this->generateCarePlan((int) $plantId, $userId);

        return ['care_plan' => $carePlan, 'message' => 'Care plan regenerated'];
    }

    /**
     * Get care log history
     */
    public function careLog(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        if (!CareScheduler::canAccessPlant((int) $plantId, $userId)) {
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
     * Build a care plan with AI and swap it in for the plant's current plan.
     *
     * The AI call happens before anything changes. If it fails or returns
     * something unusable, a sensible default plan is used instead, so a plant
     * is never left without tasks.
     */
    public function generateCarePlan(int $plantId, ?int $userId = null): ?array
    {
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

        $scheduler = new CareScheduler();
        $today = Clock::today();
        $season = Clock::season($today);

        if ($plant['location_name']) {
            $plant['location'] = $plant['location_name'];
            if ($plant['location_light']) {
                $plant['location'] .= ' (' . $plant['location_light'] . ' light)';
            }
        }

        if (!empty($plant['species_care_info'])) {
            $careInfo = json_decode($plant['species_care_info'], true);
            if ($careInfo) {
                $plant['known_care_needs'] = $careInfo;
            }
        }

        // Recent care, including the structured readings from checks
        $stmt = db()->prepare('
            SELECT action, performed_at, notes, outcome, check_data
            FROM care_log
            WHERE plant_id = ?
            ORDER BY performed_at DESC
            LIMIT 30
        ');
        $stmt->execute([$plantId]);
        $careLog = $stmt->fetchAll();

        $stmt = db()->prepare('
            SELECT filename, ai_analysis, uploaded_at
            FROM photos
            WHERE plant_id = ?
            ORDER BY uploaded_at DESC
            LIMIT 1
        ');
        $stmt->execute([$plantId]);
        $photo = $stmt->fetch();
        if ($photo && !empty($photo['ai_analysis'])) {
            $photoAnalysis = json_decode($photo['ai_analysis'], true);
            if ($photoAnalysis) {
                $plant['last_photo_analysis'] = $photoAnalysis;
                $plant['last_photo_date'] = $photo['uploaded_at'];
            }
        }

        // When each kind of care was last actually done. The care log covers
        // tasks, manual logs and sitter visits, so it's the most complete record.
        $stmt = db()->prepare("
            SELECT action, COUNT(*) AS count, MAX(performed_at) AS last_done
            FROM care_log
            WHERE plant_id = ? AND action NOT LIKE 'skipped_%' AND action NOT LIKE 'schedule_adjusted_%'
            GROUP BY action
        ");
        $stmt->execute([$plantId]);
        $lastDone = [];
        $stats = [];
        foreach ($stmt->fetchAll() as $row) {
            $taskType = CareScheduler::ACTION_TO_TASK[$row['action']] ?? ($row['action'] === 'check' ? 'check' : null);
            if (!$taskType) {
                continue;
            }
            $localDate = Clock::utcToLocalDate($row['last_done']);
            if (!isset($lastDone[$taskType]) || $localDate > $lastDone[$taskType]) {
                $lastDone[$taskType] = $localDate;
            }
            $stats[] = ['task_type' => $taskType, 'count' => (int) $row['count'], 'last_completed' => $localDate];
        }
        if (!empty($stats)) {
            $plant['care_history_stats'] = $stats;
        }

        // The owner's preferences and hand-set intervals
        $settings = $scheduler->ownerSettings($plantId);
        $overrides = CareScheduler::overrides($plantId);
        $stmt = db()->prepare('SELECT climate_notes FROM users WHERE id = ?');
        $stmt->execute([$plant['user_id']]);
        $plant['climate_notes'] = $stmt->fetchColumn() ?: null;
        $plant['fertilizer_method'] = $settings['fertilizer_method'];
        $plant['winter_rest'] = $settings['winter_rest'];
        $plant['schedule_overrides'] = $overrides;
        $plant['last_repot_date'] = $lastDone['repot'] ?? ($lastDone['pot_up'] ?? null);

        $effectiveUserId = $userId ?? (int) $plant['user_id'];
        $aiPlan = null;
        try {
            $aiService = AIServiceFactory::getForUser($effectiveUserId);
            $aiPlan = $aiService->generateCarePlan($plant, $careLog, $season);
            if (!is_array($aiPlan) || empty($aiPlan['tasks']) || !is_array($aiPlan['tasks'])) {
                throw new Exception('AI returned no usable care plan');
            }
            ClaudeService::logUsage($effectiveUserId, 'care_plan', true, null, $aiService->getModel());
        } catch (Exception $e) {
            error_log('AI care plan generation failed: ' . $e->getMessage());
            ClaudeService::logUsage($effectiveUserId, 'care_plan', false, $e->getMessage());
            $aiPlan = $this->getDefaultCarePlan($plant, $season);
        }

        $tasks = $this->prepareTasks($aiPlan['tasks'], $plant, $lastDone, $overrides, $settings, $today, $scheduler);

        // Atomic swap: deactivate old plans + delete pending tasks + create new plan + tasks.
        $db = db();
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE care_plans SET is_active = 0 WHERE plant_id = ?')->execute([$plantId]);
            $db->prepare('
                DELETE FROM tasks
                WHERE plant_id = ? AND completed_at IS NULL AND skipped_at IS NULL
            ')->execute([$plantId]);

            $nextPhotoCheck = $aiPlan['next_photo_check'] ?? null;
            if (!is_string($nextPhotoCheck) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $nextPhotoCheck)) {
                $nextPhotoCheck = Clock::addDays($today, 14);
            }

            $db->prepare('
                INSERT INTO care_plans (plant_id, season, ai_reasoning, next_photo_check, photo_check_reason, valid_until)
                VALUES (?, ?, ?, ?, ?, ?)
            ')->execute([
                $plantId,
                $season,
                $aiPlan['reasoning'] ?? null,
                $nextPhotoCheck,
                $aiPlan['photo_check_reason'] ?? 'Regular health check',
                Clock::addDays($today, 90),
            ]);
            $carePlanId = (int) $db->lastInsertId();

            $insert = $db->prepare('
                INSERT INTO tasks (care_plan_id, plant_id, task_type, due_date, recurrence, instructions, priority)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            foreach ($tasks as $task) {
                $insert->execute([
                    $carePlanId,
                    $plantId,
                    $task['type'],
                    $task['due_date'],
                    json_encode(['type' => 'days', 'interval' => $task['interval']]),
                    $task['instructions'],
                    $task['priority'],
                ]);
            }

            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            error_log('Care plan regeneration failed (rolled back): ' . $e->getMessage());
            throw $e;
        }

        $stmt = db()->prepare('SELECT * FROM care_plans WHERE id = ?');
        $stmt->execute([$carePlanId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Turn the AI's task list into safe rows: known types only, one per type,
     * the owner's intervals, fertilizer rules, and due dates that respect
     * when the care was last done. Adds water and check tasks if the AI left
     * them out, so the plan is never "half baked".
     */
    private function prepareTasks(array $aiTasks, array $plant, array $lastDone, array $overrides, array $settings, string $today, CareScheduler $scheduler): array
    {
        $enabled = $this->getEnabledTaskTypes((int) $plant['user_id']);
        $isWaterProp = !empty($plant['is_propagation']) && $plant['soil_type'] === 'water';
        $isSoilProp = !empty($plant['is_propagation']) && $plant['soil_type'] !== 'water';

        // Make sure the basics exist even if the AI skipped them
        $types = array_map(fn($t) => $t['type'] ?? null, $aiTasks);
        $defaults = $this->getDefaultCarePlan($plant, Clock::season($today))['tasks'];
        foreach ($defaults as $default) {
            if (in_array($default['type'], ['water', 'check', 'change_water'], true) && !in_array($default['type'], $types, true)) {
                $aiTasks[] = $default;
            }
        }

        $out = [];
        foreach ($aiTasks as $task) {
            $type = $task['type'] ?? null;
            if (!is_string($type) || !in_array($type, CareScheduler::TASK_TYPES, true) || isset($out[$type])) {
                continue;
            }
            if (!in_array($type, $enabled, true)) {
                continue;
            }
            if ($type === 'rotate' && isset($plant['can_rotate']) && !$plant['can_rotate']) {
                continue;
            }
            if ($isWaterProp && in_array($type, ['water', 'fertilize', 'rotate'], true)) {
                continue;
            }
            if (!$isWaterProp && $type === 'change_water') {
                continue;
            }
            if ($isSoilProp && $type === 'fertilize') {
                continue;
            }

            // Interval: owner's override, then the AI's, kept to sane bounds
            $interval = (int) ($task['recurrence']['interval'] ?? 7);
            if (($task['recurrence']['type'] ?? 'days') === 'weeks') {
                $interval *= 7;
            } elseif (($task['recurrence']['type'] ?? 'days') === 'months') {
                $interval *= 30;
            }
            $interval = max(1, min(365, $interval));
            if ($type === 'check') {
                $interval = max(7, $interval);
            }
            if ($type === 'fertilize' && $settings['fertilizer_method'] === 'separate' && $interval < CareScheduler::SEPARATE_FERTILIZE_MIN_INTERVAL) {
                $interval = CareScheduler::SEPARATE_FERTILIZE_INTERVAL;
            }
            if (isset($overrides[$type])) {
                $interval = $overrides[$type];
            }

            // Due date: what the AI said, but never in the past and never
            // sooner than one interval after the care was last done
            $due = $task['due_date'] ?? $today;
            if (!is_string($due) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
                $due = $today;
            }
            if (isset($lastDone[$type])) {
                $earliest = Clock::addDays($lastDone[$type], $interval);
                if ($due < $earliest) {
                    $due = $earliest;
                }
            }
            if ($due < $today) {
                $due = $today;
            }
            $due = $scheduler->normalizeDueDate(['task_type' => $type, 'plant_id' => $plant['id']], $due);

            $priority = in_array($task['priority'] ?? 'normal', ['low', 'normal', 'high', 'urgent'], true) ? $task['priority'] : 'normal';

            $out[$type] = [
                'type' => $type,
                'due_date' => $due,
                'interval' => $interval,
                'instructions' => isset($task['instructions']) ? (string) $task['instructions'] : null,
                'priority' => $priority,
            ];
        }

        return array_values($out);
    }

    /**
     * Get list of enabled task types for a user
     */
    private function getEnabledTaskTypes(int $userId): array
    {
        return array_values(array_diff(CareScheduler::TASK_TYPES, CareScheduler::disabledTypes($userId)));
    }

    /**
     * Default care plan when AI is unavailable or returns nothing usable.
     */
    private function getDefaultCarePlan(array $plant, string $season): array
    {
        $today = Clock::today();
        $waterInterval = $season === 'summer' ? 5 : ($season === 'winter' ? 10 : 7);
        $separate = ($plant['fertilizer_method'] ?? 'separate') === 'separate';
        $isWaterProp = !empty($plant['is_propagation']) && ($plant['soil_type'] ?? '') === 'water';

        $tasks = [];
        if ($isWaterProp) {
            $tasks[] = [
                'type' => 'change_water',
                'due_date' => Clock::addDays($today, 3),
                'recurrence' => ['type' => 'days', 'interval' => 5],
                'instructions' => 'Swap in fresh room-temperature water',
                'priority' => 'normal',
            ];
        } else {
            $tasks[] = [
                'type' => 'water',
                'due_date' => $today,
                'recurrence' => ['type' => 'days', 'interval' => $waterInterval],
                'instructions' => 'Water thoroughly until water drains from the bottom, once the top inch of soil is dry',
                'priority' => 'normal',
            ];
        }

        $tasks[] = [
            'type' => 'check',
            'due_date' => Clock::addDays($today, 3),
            'recurrence' => ['type' => 'days', 'interval' => 7],
            'instructions' => 'Check soil moisture and leaf condition',
            'priority' => 'low',
        ];

        if (!$isWaterProp) {
            $tasks[] = $separate
                ? [
                    'type' => 'fertilize',
                    'due_date' => Clock::addDays($today, 14),
                    'recurrence' => ['type' => 'days', 'interval' => CareScheduler::SEPARATE_FERTILIZE_INTERVAL],
                    'instructions' => 'Top-dress with slow-release granules (Osmocote Plus) at the label rate for this pot size and scratch in lightly',
                    'priority' => 'low',
                ]
                : [
                    'type' => 'fertilize',
                    'due_date' => Clock::addDays($today, 14),
                    'recurrence' => ['type' => 'days', 'interval' => $season === 'winter' ? 60 : 30],
                    'instructions' => 'Apply balanced liquid fertilizer at half strength',
                    'priority' => 'low',
                ];
        }

        return [
            'reasoning' => 'Default care plan based on general houseplant guidelines',
            'next_photo_check' => Clock::addDays($today, 14),
            'photo_check_reason' => 'Regular health check',
            'tasks' => $tasks,
        ];
    }
}
