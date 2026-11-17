<?php
/**
 * Task Controller
 *
 * HTTP layer for care tasks. Scheduling rules live in CareScheduler so every
 * path (owner, bulk, sitter, care log, email) follows the same rules.
 */

class TaskController
{
    private CareScheduler $scheduler;

    public function __construct()
    {
        $this->scheduler = new CareScheduler();
    }

    /**
     * Load a task the user may act on, or null.
     */
    private function findAccessibleTask($taskId, int $userId): ?array
    {
        $task = CareScheduler::loadTask((int) $taskId);
        if (!$task || !CareScheduler::canAccessPlant((int) $task['plant_id'], $userId)) {
            return null;
        }
        return $task;
    }

    private function notFound(): array
    {
        return ['status' => 404, 'data' => ['error' => 'Task not found']];
    }

    /**
     * Get today's tasks (due today or late), plus tasks finished today.
     */
    public function today(array $params, array $body, ?int $userId): array
    {
        $this->scheduler->reconcile($userId);

        return [
            'tasks' => $this->scheduler->tasksForToday($userId, true),
            'today' => Clock::today(),
        ];
    }

    /**
     * Get upcoming tasks (next 7 days)
     */
    public function upcoming(array $params, array $body, ?int $userId): array
    {
        $today = Clock::today();
        $endDate = Clock::addDays($today, 7);
        $access = CareScheduler::accessiblePlantCondition($userId);
        $disabled = CareScheduler::disabledTypes($userId);

        $params = [(int) $userId, $today, $endDate];
        $typeClause = '';
        if (!empty($disabled)) {
            $typeClause = ' AND t.task_type NOT IN (' . implode(',', array_fill(0, count($disabled), '?')) . ')';
            $params = array_merge($params, $disabled);
        }

        $stmt = db()->prepare("
            SELECT t.*,
                   p.name AS plant_name,
                   COALESCE(l.name, p.location) AS plant_location,
                   p.species AS species,
                   p.pot_size AS pot_size,
                   p.soil_type AS soil_type,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) AS plant_thumbnail,
                   CASE WHEN p.user_id = ? THEN 1 ELSE 0 END AS is_owned,
                   (SELECT MAX(cl.performed_at) FROM care_log cl
                    WHERE cl.plant_id = t.plant_id AND cl.action = t.task_type) AS last_done_at
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            LEFT JOIN locations l ON p.location_id = l.id
            WHERE {$access}
              AND t.due_date BETWEEN ? AND ?
              AND t.completed_at IS NULL
              AND t.skipped_at IS NULL
              AND p.archived_at IS NULL
              {$typeClause}
            ORDER BY t.due_date ASC,
                CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 WHEN 'low' THEN 4 ELSE 5 END
        ");
        $stmt->execute($params);

        $tasks = array_map(fn($t) => $this->scheduler->decorate($t, $today), $stmt->fetchAll());
        return ['tasks' => $tasks];
    }

    /**
     * Get tasks for specific plant
     */
    public function forPlant(array $params, array $body, ?int $userId): array
    {
        $plantId = (int) $params['id'];

        if (!CareScheduler::canAccessPlant($plantId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $disabled = CareScheduler::disabledTypes($userId);
        $values = [$plantId];
        $typeClause = '';
        if (!empty($disabled)) {
            $typeClause = ' AND t.task_type NOT IN (' . implode(',', array_fill(0, count($disabled), '?')) . ')';
            $values = array_merge($values, $disabled);
        }

        $stmt = db()->prepare("
            SELECT t.*,
                   CASE WHEN t.completed_by_user_id IS NOT NULL THEN
                       (SELECT COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, '@') - 1))
                        FROM users u WHERE u.id = t.completed_by_user_id)
                   ELSE NULL END AS completed_by_name,
                   (SELECT MAX(cl.performed_at) FROM care_log cl
                    WHERE cl.plant_id = t.plant_id AND cl.action = t.task_type) AS last_done_at
            FROM tasks t
            WHERE t.plant_id = ?
              AND t.skipped_at IS NULL
              {$typeClause}
            ORDER BY
                CASE WHEN t.completed_at IS NULL THEN 0 ELSE 1 END,
                CASE WHEN t.completed_at IS NULL THEN t.due_date END ASC,
                t.completed_at DESC
            LIMIT 20
        ");
        $stmt->execute($values);

        $today = Clock::today();
        $tasks = array_map(fn($t) => $this->scheduler->decorate($t, $today), $stmt->fetchAll());
        return ['tasks' => $tasks];
    }

    /**
     * Complete a task. Calling it twice is harmless.
     */
    public function complete(array $params, array $body, ?int $userId): array
    {
        $task = $this->findAccessibleTask($params['id'], $userId);
        if (!$task) {
            return $this->notFound();
        }

        $notes = isset($body['notes']) && trim((string) $body['notes']) !== '' ? trim((string) $body['notes']) : null;
        $checkData = isset($body['check_data']) && is_array($body['check_data']) ? $body['check_data'] : null;
        $alreadyDone = !empty($task['completed_at']);

        $updatedTask = $this->scheduler->complete($task, $userId, $notes, $checkData);

        $response = ['task' => $this->scheduler->decorate($updatedTask)];

        // Analyze check data if this is a fresh check completion
        if (!$alreadyDone && $task['task_type'] === 'check' && $checkData) {
            $analysis = $this->analyzeCheckDataAndUpdateCarePlan($checkData, (int) $task['plant_id'], $userId);
            if (!empty($analysis['insights'])) {
                $response['insights'] = $analysis['insights'];
            }
            if ($analysis['care_plan_updated']) {
                $response['care_plan_updated'] = true;
            }
        }

        return $response;
    }

    /**
     * Undo a completion.
     */
    public function uncomplete(array $params, array $body, ?int $userId): array
    {
        $task = $this->findAccessibleTask($params['id'], $userId);
        if (!$task) {
            return $this->notFound();
        }

        $task = $this->scheduler->uncomplete($task);
        return ['task' => $this->scheduler->decorate($task)];
    }

    /**
     * Complete several tasks at once. Check tasks are left out so their
     * moisture and health readings are always recorded through the check form.
     */
    public function bulkComplete(array $params, array $body, ?int $userId): array
    {
        $taskIds = $body['task_ids'] ?? [];
        $notes = isset($body['notes']) && trim((string) $body['notes']) !== '' ? trim((string) $body['notes']) : null;

        if (empty($taskIds) || !is_array($taskIds)) {
            return ['status' => 400, 'data' => ['error' => 'No task IDs provided']];
        }

        $completed = [];
        $failed = [];
        $skippedCheck = [];

        foreach (array_slice($taskIds, 0, 50) as $taskId) {
            $task = $this->findAccessibleTask($taskId, $userId);
            if (!$task) {
                $failed[] = $taskId;
                continue;
            }
            if ($task['task_type'] === 'check' && empty($task['completed_at'])) {
                $skippedCheck[] = (int) $task['id'];
                continue;
            }
            $this->scheduler->complete($task, $userId, $notes);
            $completed[] = (int) $task['id'];
        }

        return [
            'completed' => $completed,
            'failed' => $failed,
            'skipped_check' => $skippedCheck,
            'count' => count($completed),
        ];
    }

    /**
     * Undo several completions (the "Undo" after Complete all).
     */
    public function bulkUncomplete(array $params, array $body, ?int $userId): array
    {
        $taskIds = $body['task_ids'] ?? [];
        if (empty($taskIds) || !is_array($taskIds)) {
            return ['status' => 400, 'data' => ['error' => 'No task IDs provided']];
        }

        $uncompleted = [];
        $failed = [];
        foreach (array_slice($taskIds, 0, 50) as $taskId) {
            $task = $this->findAccessibleTask($taskId, $userId);
            if (!$task) {
                $failed[] = $taskId;
                continue;
            }
            $this->scheduler->uncomplete($task);
            $uncompleted[] = (int) $task['id'];
        }

        return ['uncompleted' => $uncompleted, 'failed' => $failed, 'count' => count($uncompleted)];
    }

    /**
     * Skip several tasks at once. They come back after the same short delay
     * as a single skip.
     */
    public function bulkSkip(array $params, array $body, ?int $userId): array
    {
        $taskIds = $body['task_ids'] ?? [];
        $reason = $body['reason'] ?? null;

        if (empty($taskIds) || !is_array($taskIds)) {
            return ['status' => 400, 'data' => ['error' => 'No task IDs provided']];
        }

        $skipped = [];
        $failed = [];

        foreach (array_slice($taskIds, 0, 50) as $taskId) {
            $task = $this->findAccessibleTask($taskId, $userId);
            if (!$task) {
                $failed[] = $taskId;
                continue;
            }
            $this->scheduler->skip($task, $userId, $reason, 'skip');
            $skipped[] = (int) $task['id'];
        }

        return ['skipped' => $skipped, 'failed' => $failed, 'count' => count($skipped)];
    }

    /**
     * Skip a task. It comes back after a short, type-based delay.
     */
    public function skip(array $params, array $body, ?int $userId): array
    {
        $task = $this->findAccessibleTask($params['id'], $userId);
        if (!$task) {
            return $this->notFound();
        }

        $this->scheduler->skip($task, $userId, $body['reason'] ?? null, 'skip');

        return ['task' => CareScheduler::loadTask((int) $task['id'])];
    }

    /**
     * Move a task to another day: { days: 1-30 } or { date: "YYYY-MM-DD" }.
     */
    public function snooze(array $params, array $body, ?int $userId): array
    {
        $task = $this->findAccessibleTask($params['id'], $userId);
        if (!$task) {
            return $this->notFound();
        }
        if (!empty($task['completed_at']) || !empty($task['skipped_at'])) {
            return ['status' => 400, 'data' => ['error' => 'Only pending tasks can be snoozed']];
        }

        $today = Clock::today();
        if (!empty($body['date'])) {
            $date = (string) $body['date'];
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
                return ['status' => 400, 'data' => ['error' => 'Pick a valid date']];
            }
            if ($date <= $today) {
                return ['status' => 400, 'data' => ['error' => 'Pick a day after today']];
            }
            if (Clock::daysBetween($today, $date) > 60) {
                return ['status' => 400, 'data' => ['error' => 'Snooze up to 60 days at a time']];
            }
        } else {
            $days = (int) ($body['days'] ?? 1);
            if ($days < 1 || $days > 30) {
                return ['status' => 400, 'data' => ['error' => 'Snooze between 1 and 30 days']];
            }
            $date = Clock::addDays($today, $days);
        }

        $task = $this->scheduler->snooze($task, $date);
        return ['task' => $this->scheduler->decorate($task)];
    }

    /**
     * Thin wrapper kept for callers outside this class.
     */
    public function runCompletionSideEffects(array $task): void
    {
        $this->scheduler->runCompletionSideEffects($task);
    }

    /**
     * How long to hold off watering after a check finds wet soil.
     * 7/10 waits about a third of the interval, 8/10 about half, 9-10 most of it.
     */
    public static function wetSoilDelay(int $moisture, int $interval): int
    {
        if ($moisture >= 9) {
            return max(3, (int) round($interval * 0.7));
        }
        if ($moisture >= 8) {
            return max(2, (int) round($interval * 0.5));
        }
        return max(2, (int) round($interval * 0.35));
    }

    /**
     * Adjust the water task from a check's moisture reading, and rebuild the
     * care plan only when health is poor or pests showed up.
     */
    private function analyzeCheckDataAndUpdateCarePlan(array $checkData, int $plantId, int $userId): array
    {
        $insights = $this->analyzeCheckData($checkData, $plantId, $userId);
        $carePlanUpdated = false;

        $moisture = isset($checkData['moisture_level']) ? (int) $checkData['moisture_level'] : null;
        $health = isset($checkData['general_health']) ? (int) $checkData['general_health'] : 3;
        $today = Clock::today();

        // Poor health or pests: ask for a fresh care plan first, so the
        // moisture adjustments below apply to the new water task
        if ($health <= 2 || !empty($checkData['pests_observed'])) {
            try {
                (new CarePlanController())->generateCarePlan($plantId, $userId);
                $carePlanUpdated = true;

                if (!$this->hasInsightType($insights, 'warning') && !$this->hasInsightType($insights, 'urgent')) {
                    $insights[] = [
                        'type' => 'info',
                        'message' => 'Care plan has been automatically updated based on your check data.',
                        'suggestion' => ['action' => 'none', 'details' => null],
                    ];
                }
            } catch (Exception $e) {
                error_log('Auto care plan update failed: ' . $e->getMessage());
            }
        }

        $stmt = db()->prepare('
            SELECT id FROM tasks
            WHERE plant_id = ? AND task_type = "water" AND completed_at IS NULL AND skipped_at IS NULL
            ORDER BY due_date ASC LIMIT 1
        ');
        $stmt->execute([$plantId]);
        $waterId = $stmt->fetchColumn();
        $water = $waterId ? CareScheduler::loadTask((int) $waterId) : null;

        if ($water && $moisture !== null) {
            $daysUntil = Clock::daysBetween($today, $water['due_date']);
            $interval = $this->scheduler->intervalFor($water) ?: 7;

            if ($moisture <= 2 && $daysUntil >= 2) {
                // Bone dry and watering is days away: water today instead
                $this->scheduler->snooze($water, $today);
                $insights[] = [
                    'type' => 'warning',
                    'message' => "Soil is very dry ({$moisture}/10), so watering moved to today instead of in {$daysUntil} days.",
                ];
            } elseif ($moisture >= 7) {
                $delay = self::wetSoilDelay($moisture, $interval);
                if ($daysUntil <= 0) {
                    $this->scheduler->skip($water, $userId, "Auto-skipped: check reported wet soil ({$moisture}/10)", 'skip', $delay);
                    $insights[] = [
                        'type' => 'info',
                        'message' => "Soil is still wet ({$moisture}/10), so today's watering was skipped. Next watering in {$delay} days.",
                    ];
                } elseif ($daysUntil < $delay) {
                    $this->scheduler->snooze($water, Clock::addDays($today, $delay));
                    $insights[] = [
                        'type' => 'info',
                        'message' => "Soil is still wet ({$moisture}/10), so watering moved out to {$delay} days from now.",
                    ];
                }
            }
        }

        return ['insights' => $insights, 'care_plan_updated' => $carePlanUpdated];
    }


    /**
     * Analyze check data for concerning patterns
     */
    private function analyzeCheckData(array $checkData, int $plantId, int $userId): array
    {
        $insights = [];
        $triggerAI = false;
        $concernCount = 0;

        // Local pattern detection - immediate alerts

        // Pests detected - urgent
        if (!empty($checkData['pests_observed'])) {
            $pestMsg = 'Pests detected!';
            if (!empty($checkData['pest_notes'])) {
                $pestMsg .= ' (' . $checkData['pest_notes'] . ')';
            }
            $pestMsg .= ' Consider treatment options.';
            $insights[] = ['type' => 'urgent', 'message' => $pestMsg];
            $triggerAI = true;
            $concernCount++;
        }

        // Low health rating - warning
        $health = (int) ($checkData['general_health'] ?? 5);
        if ($health <= 2) {
            $insights[] = ['type' => 'warning', 'message' => 'Plant health is poor. Consider reviewing care routine and environment.'];
            $triggerAI = true;
            $concernCount++;
        } elseif ($health === 3) {
            $concernCount++;
        }

        // Moisture extremes
        $moisture = (int) ($checkData['moisture_level'] ?? 5);
        if ($moisture <= 2) {
            $insights[] = ['type' => 'info', 'message' => 'Soil is very dry. May need watering soon.'];
            $concernCount++;
        } elseif ($moisture >= 9) {
            $insights[] = ['type' => 'warning', 'message' => 'Soil is very wet. Watch for signs of overwatering.'];
            $concernCount++;
        }

        // Stress indicators
        if (!empty($checkData['yellowing_leaves'])) {
            $insights[] = ['type' => 'info', 'message' => 'Yellowing leaves may indicate overwatering, nutrient deficiency, or natural aging.'];
            $concernCount++;
        }

        if (!empty($checkData['brown_tips'])) {
            $insights[] = ['type' => 'info', 'message' => 'Brown leaf tips often indicate low humidity, underwatering, or salt buildup.'];
            $concernCount++;
        }

        // Multiple concerns warrant AI analysis
        if ($concernCount >= 2) {
            $triggerAI = true;
        }

        // Trigger AI analysis for deeper insights if needed
        if ($triggerAI) {
            try {
                $aiInsight = $this->getAICheckInsight($checkData, $plantId, $userId);
                if ($aiInsight) {
                    $insights[] = $aiInsight;
                }
            } catch (Exception $e) {
                error_log('AI check analysis failed: ' . $e->getMessage());
                // Don't fail the whole response if AI fails
            }
        }

        // Positive feedback if everything looks good
        if (empty($insights) && $health >= 4 && !empty($checkData['new_growth'])) {
            $insights[] = ['type' => 'success', 'message' => 'Plant is thriving! New growth observed and health looks great.'];
        }

        return $insights;
    }

    /**
     * Check if insights array contains a specific type
     */
    private function hasInsightType(array $insights, string $type): bool
    {
        foreach ($insights as $insight) {
            if (($insight['type'] ?? '') === $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get AI-powered insight for check data
     */
    private function getAICheckInsight(array $checkData, int $plantId, int $userId): ?array
    {
        // Get plant info
        $stmt = db()->prepare('SELECT name, species, notes FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return null;
        }

        // Get recent check history
        $stmt = db()->prepare('
            SELECT check_data, performed_at
            FROM care_log
            WHERE plant_id = ? AND action = "check" AND check_data IS NOT NULL
            ORDER BY performed_at DESC
            LIMIT 5
        ');
        $stmt->execute([$plantId]);
        $rawChecks = $stmt->fetchAll();

        // Decode JSON check_data for each record
        $recentChecks = [];
        foreach ($rawChecks as $check) {
            $decoded = json_decode($check['check_data'], true);
            if ($decoded) {
                $decoded['performed_at'] = $check['performed_at'];
                $recentChecks[] = $decoded;
            }
        }

        // Get current care tasks
        $stmt = db()->prepare('
            SELECT task_type, due_date, recurrence
            FROM tasks
            WHERE plant_id = ? AND completed_at IS NULL AND skipped_at IS NULL
            ORDER BY due_date
            LIMIT 10
        ');
        $stmt->execute([$plantId]);
        $rawTasks = $stmt->fetchAll();

        // Extract recurrence_interval from JSON recurrence field
        $currentTasks = [];
        foreach ($rawTasks as $task) {
            $recurrence = json_decode($task['recurrence'] ?? '', true);
            $currentTasks[] = [
                'task_type' => $task['task_type'],
                'due_date' => $task['due_date'],
                'recurrence_interval' => $recurrence['interval'] ?? null
            ];
        }

        // Use user's configured AI service
        $aiService = AIServiceFactory::getForUser($userId);
        $result = $aiService->analyzeCheckData($checkData, $recentChecks, $plant, $currentTasks);

        if ($result) {
            ClaudeService::logUsage($userId, 'check_analysis', true, null, $aiService->getModel());
            return $result;
        }

        return null;
    }

    /**
     * Get AI-generated recommendations for a specific task
     */
    public function recommendations(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];

        // Get task with full plant details
        $stmt = db()->prepare('
            SELECT t.*,
                   p.id as plant_id,
                   p.name as plant_name,
                   p.species,
                   p.pot_size,
                   p.soil_type,
                   p.light_condition,
                   p.health_status,
                   p.notes as plant_notes,
                   p.user_id,
                   l.name as location_name,
                   cp.season,
                   cp.ai_reasoning
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            LEFT JOIN locations l ON p.location_id = l.id
            LEFT JOIN care_plans cp ON cp.plant_id = p.id AND cp.is_active = 1
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Verify access (owned or shared via household)
        if (!CareScheduler::canAccessPlant((int) $task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Get recent care history for this plant
        $stmt = db()->prepare('
            SELECT action, performed_at, notes, outcome
            FROM care_log
            WHERE plant_id = ?
            ORDER BY performed_at DESC
            LIMIT 10
        ');
        $stmt->execute([$task['plant_id']]);
        $careHistory = $stmt->fetchAll();

        // Get recent AI analysis from photos (contains health assessment)
        $stmt = db()->prepare('
            SELECT ai_analysis, uploaded_at
            FROM photos
            WHERE plant_id = ? AND ai_analysis IS NOT NULL
            ORDER BY uploaded_at DESC
            LIMIT 3
        ');
        $stmt->execute([$task['plant_id']]);
        $healthHistory = $stmt->fetchAll();

        // Parse health status from AI analysis
        $healthHistory = array_map(function($photo) {
            $analysis = json_decode($photo['ai_analysis'], true);
            return [
                'health_assessment' => $analysis['health_status'] ?? null,
                'uploaded_at' => $photo['uploaded_at']
            ];
        }, $healthHistory);
        $healthHistory = array_filter($healthHistory, fn($h) => $h['health_assessment']);

        // Owner's care preferences shape the advice (fertilizer type, climate)
        $settings = $this->scheduler->ownerSettings((int) $task['plant_id']);
        $task['fertilizer_method'] = $settings['fertilizer_method'];
        $stmt = db()->prepare('SELECT climate_notes FROM users WHERE id = ?');
        $stmt->execute([$task['user_id']]);
        $task['climate_notes'] = $stmt->fetchColumn() ?: null;

        // Seasonal context
        $weather = $this->getWeatherContext();

        // Generate AI recommendations
        $recommendations = $this->generateAIRecommendations($task, $careHistory, $healthHistory, $weather, $userId);

        return [
            'recommendations' => $recommendations,
            'task' => $task,
            'weather' => $weather
        ];
    }

    /**
     * Get current weather context
     */
    private function getWeatherContext(): ?array
    {
        // For now, return seasonal context based on date
        // Could integrate with weather API later
        $season = Clock::season();

        $seasonalContext = [
            'spring' => [
                'season' => 'spring',
                'growth_phase' => 'active growth beginning',
                'watering_adjustment' => 'gradually increase watering',
                'fertilizing' => 'begin regular fertilizing',
                'notes' => 'Plants are waking up from dormancy. Watch for new growth.'
            ],
            'summer' => [
                'season' => 'summer',
                'growth_phase' => 'peak growth',
                'watering_adjustment' => 'water more frequently, check soil daily in heat',
                'fertilizing' => 'continue regular fertilizing',
                'notes' => 'High heat may stress plants. Watch for wilting.'
            ],
            'fall' => [
                'season' => 'fall',
                'growth_phase' => 'slowing growth',
                'watering_adjustment' => 'gradually reduce watering',
                'fertilizing' => 'reduce or stop fertilizing',
                'notes' => 'Plants preparing for dormancy. Less water needed.'
            ],
            'winter' => [
                'season' => 'winter',
                'growth_phase' => 'dormancy or slow growth',
                'watering_adjustment' => 'water sparingly, let soil dry more',
                'fertilizing' => 'stop fertilizing for most plants',
                'notes' => 'Low light and dry indoor air. Watch humidity.'
            ]
        ];

        return $seasonalContext[$season];
    }

    /**
     * Generate AI recommendations for the task
     */
    private function generateAIRecommendations(array $task, array $careHistory, array $healthHistory, ?array $weather, int $userId): array
    {
        // Build context for AI
        $context = $this->buildTaskContext($task, $careHistory, $healthHistory, $weather);

        // Use this user's own AI provider and key
        try {
            $aiService = AIServiceFactory::getForUser($userId);
            return $this->callAIForRecommendations($aiService, $task, $context);
        } catch (\Exception $e) {
            error_log("AI recommendation error: " . $e->getMessage());
        }

        // Fallback to smart defaults if no AI available
        return $this->generateFallbackRecommendations($task, $weather);
    }

    /**
     * Build context string for AI
     */
    private function buildTaskContext(array $task, array $careHistory, array $healthHistory, ?array $weather): string
    {
        $context = "## Plant Information\n";
        $context .= "- Name: {$task['plant_name']}\n";
        $context .= "- Species: " . ($task['species'] ?: 'Unknown') . "\n";
        $context .= "- Current Health: " . ($task['health_status'] ?: 'Unknown') . "\n";
        $context .= "- Pot Size: " . ($task['pot_size'] ?: 'Medium') . "\n";
        $context .= "- Soil Type: " . ($task['soil_type'] ?: 'Standard potting mix') . "\n";
        $context .= "- Light Condition: " . ($task['light_condition'] ?: 'Unknown') . "\n";
        $context .= "- Location: " . ($task['location_name'] ?: 'Unknown') . "\n";

        if ($task['plant_notes']) {
            $context .= "- Owner's Notes: {$task['plant_notes']}\n";
        }
        if (!empty($task['climate_notes'])) {
            $context .= "- Home climate: {$task['climate_notes']}\n";
        }
        $context .= "- Fertilizer used: " . (($task['fertilizer_method'] ?? 'separate') === 'mixed'
            ? 'liquid fertilizer mixed into the watering can'
            : 'slow-release granules (Osmocote Plus 15-9-12) top-dressed on the soil') . "\n";

        // Care plan details from AI reasoning
        if (!empty($task['ai_reasoning'])) {
            $context .= "\n## Current Care Plan\n";
            $context .= "- Season: " . ($task['season'] ?: 'Not specified') . "\n";
            $context .= "- AI Care Notes: {$task['ai_reasoning']}\n";
        }

        // Recent care history
        if (!empty($careHistory)) {
            $context .= "\n## Recent Care History\n";
            foreach (array_slice($careHistory, 0, 5) as $log) {
                $date = date('M j', strtotime($log['performed_at']));
                $context .= "- $date: {$log['action']}";
                if ($log['notes']) $context .= " - {$log['notes']}";
                if ($log['outcome']) $context .= " (outcome: {$log['outcome']})";
                $context .= "\n";
            }
        }

        // Health history from photos
        if (!empty($healthHistory)) {
            $context .= "\n## Recent Health Assessments\n";
            foreach ($healthHistory as $assessment) {
                $date = date('M j', strtotime($assessment['uploaded_at']));
                $context .= "- $date: {$assessment['health_assessment']}\n";
            }
        }

        // Weather/seasonal context
        if ($weather) {
            $context .= "\n## Current Conditions\n";
            $context .= "- Season: {$weather['season']}\n";
            $context .= "- Growth Phase: {$weather['growth_phase']}\n";
            $context .= "- Seasonal Note: {$weather['notes']}\n";
        }

        return $context;
    }

    /**
     * Call AI service for recommendations
     */
    private function callAIForRecommendations($aiService, array $task, string $context): array
    {
        $taskType = $task['task_type'];
        $plantName = $task['plant_name'];

        $prompt = "You are a plant care expert. Based on the following information about \"$plantName\", provide specific, actionable recommendations for the upcoming $taskType task.

$context

## Task Details
- Task Type: $taskType
- Due Date: {$task['due_date']}
- Priority: " . ($task['priority'] ?: 'normal') . "
" . ($task['instructions'] ? "- Current Instructions: {$task['instructions']}\n" : "") . "

Please provide personalized recommendations in the following JSON format:
{
  \"summary\": \"A brief 1-2 sentence summary of what to do\",
  \"steps\": [\"Step 1...\", \"Step 2...\", \"Step 3...\"],
  \"amount\": \"Specific amounts if applicable (e.g., water amount, fertilizer dilution)\",
  \"timing\": \"Best time of day or conditions for this task\",
  \"warnings\": [\"Any cautions or things to watch for\"],
  \"tips\": [\"Optional helpful tips specific to this plant\"]
}

Be specific to THIS plant's species ({$task['species']}), current health ({$task['health_status']}), and conditions. Do not give generic advice.";

        // Build full plant data for the chat method
        $plant = [
            'name' => $task['plant_name'],
            'species' => $task['species'],
            'pot_size' => $task['pot_size'],
            'soil_type' => $task['soil_type'],
            'light_condition' => $task['light_condition'],
            'health_status' => $task['health_status'],
            'notes' => $task['plant_notes'],
            'location_name' => $task['location_name']
        ];

        try {
            $response = $aiService->chat(
                $plant,
                [['role' => 'user', 'content' => $prompt]],
                [] // Additional context - already included in prompt
            );

            // Parse JSON from response
            $content = $response['content'] ?? '';

            // Extract JSON from response (it might be wrapped in markdown code blocks)
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
                $content = $matches[1];
            }

            $recommendations = json_decode(trim($content), true);

            if ($recommendations && isset($recommendations['summary'])) {
                $recommendations['source'] = 'ai';
                return $recommendations;
            }
        } catch (\Exception $e) {
            error_log("AI recommendation call failed: " . $e->getMessage());
        }

        // Return fallback if AI fails
        return $this->generateFallbackRecommendations($task, null);
    }

    /**
     * Generate fallback recommendations when AI is unavailable
     */
    private function generateFallbackRecommendations(array $task, ?array $weather): array
    {
        $taskType = $task['task_type'];
        $species = strtolower($task['species'] ?? '');
        $potSize = $task['pot_size'] ?? 'medium';
        $healthStatus = $task['health_status'] ?? 'healthy';

        $recommendations = [
            'source' => 'fallback',
            'summary' => '',
            'steps' => [],
            'amount' => '',
            'timing' => 'Morning is generally best',
            'warnings' => [],
            'tips' => []
        ];

        // Adjust for health status
        if ($healthStatus === 'struggling' || $healthStatus === 'critical') {
            $recommendations['warnings'][] = "This plant is currently $healthStatus - proceed carefully and monitor closely after care.";
        }

        // Seasonal adjustments
        if ($weather) {
            $recommendations['tips'][] = "Seasonal note ({$weather['season']}): {$weather['watering_adjustment']}";
        }

        switch ($taskType) {
            case 'water':
                $amounts = ['small' => '100-200ml', 'medium' => '300-500ml', 'large' => '500-750ml', 'xlarge' => '1-1.5L'];
                $recommendations['summary'] = "Water {$task['plant_name']} thoroughly until water drains from the bottom.";
                $recommendations['amount'] = $amounts[$potSize] ?? $amounts['medium'];
                $recommendations['steps'] = [
                    "Check soil moisture 1-2 inches deep with your finger or moisture meter",
                    "If dry, water slowly around the base of the plant",
                    "Continue until water drains from drainage holes",
                    "Empty saucer after 30 minutes to prevent root rot"
                ];
                if (str_contains($species, 'succulent') || str_contains($species, 'cactus')) {
                    $recommendations['warnings'][] = "Succulents prefer to dry out completely between waterings";
                    $recommendations['amount'] = "Soak thoroughly, then wait until bone dry";
                }
                break;

            case 'fertilize':
                if (($task['fertilizer_method'] ?? 'separate') === 'separate') {
                    $doses = ['small' => 'about 1/2 teaspoon', 'medium' => 'about 1 teaspoon', 'large' => 'about 2 teaspoons', 'xlarge' => 'about 1 tablespoon'];
                    $dose = $doses[$potSize] ?? $doses['medium'];
                    $recommendations['summary'] = "Top-dress {$task['plant_name']} with slow-release granules. They feed for about 4 months.";
                    $recommendations['amount'] = "$dose of Osmocote Plus (check the label rate for your pot size)";
                    $recommendations['steps'] = [
                        "Sprinkle the granules evenly on the soil surface, away from the stems",
                        "Scratch them lightly into the top inch of soil",
                        "Water as usual on the plant's normal schedule (no extra watering needed today)",
                    ];
                    $recommendations['timing'] = 'Early spring and mid-summer. Skip September through February.';
                    if (str_contains($species, 'succulent') || str_contains($species, 'cactus')) {
                        $recommendations['warnings'][] = "Use half the label rate for succulents and cacti";
                    }
                    $recommendations['warnings'][] = "Skip it if this plant was repotted in the last 3 months into mix that already has fertilizer";
                    break;
                }
                $strengths = ['small' => '1/4', 'medium' => '1/2', 'large' => '1/2-full', 'xlarge' => 'full'];
                $strength = $strengths[$potSize] ?? $strengths['medium'];
                $recommendations['summary'] = "Apply balanced liquid fertilizer at {$strength} strength to support growth.";
                $recommendations['amount'] = "{$strength} strength dilution";
                $recommendations['steps'] = [
                    "Ensure soil is moist before fertilizing (water lightly first if dry)",
                    "Mix fertilizer at recommended dilution",
                    "Apply evenly around the soil surface",
                    "Water again lightly to help distribute nutrients"
                ];
                if ($weather && $weather['season'] === 'winter') {
                    $recommendations['warnings'][] = "Most plants don't need fertilizer in winter - consider skipping";
                }
                break;

            case 'trim':
                $recommendations['summary'] = "Remove dead or yellowing leaves and shape {$task['plant_name']} as needed.";
                $recommendations['steps'] = [
                    "Use clean, sharp scissors or pruning shears",
                    "Remove any yellow, brown, or dead leaves at their base",
                    "Trim leggy growth to encourage bushier shape",
                    "Cut just above a leaf node for best regrowth"
                ];
                $recommendations['tips'][] = "Healthy cuttings can often be propagated in water!";
                break;

            case 'repot':
                $newSizes = ['small' => 'medium', 'medium' => 'large', 'large' => 'xlarge'];
                $recommendations['summary'] = "Move {$task['plant_name']} to a slightly larger pot with fresh soil.";
                $recommendations['amount'] = "New pot should be 1-2 inches larger in diameter";
                $recommendations['steps'] = [
                    "Water the plant 1-2 days before repotting",
                    "Prepare new pot with drainage and fresh potting mix",
                    "Gently remove plant and loosen root ball",
                    "Place in new pot at same depth, fill with soil",
                    "Water thoroughly and keep in indirect light for a week"
                ];
                break;

            case 'mist':
                $recommendations['summary'] = "Mist {$task['plant_name']} to increase humidity around the leaves.";
                $recommendations['steps'] = [
                    "Use room temperature water in a spray bottle",
                    "Mist around and above the plant, not directly on leaves",
                    "Focus on the air around the plant"
                ];
                $recommendations['timing'] = "Morning is best - leaves need time to dry before evening";
                if (str_contains($species, 'succulent') || str_contains($species, 'cactus')) {
                    $recommendations['warnings'][] = "Skip misting! Succulents and cacti prefer dry conditions.";
                }
                break;

            case 'rotate':
                $recommendations['summary'] = "Turn {$task['plant_name']} 1/4 turn for even light exposure.";
                $recommendations['steps'] = [
                    "Rotate the pot 90 degrees (1/4 turn)",
                    "Always rotate in the same direction",
                    "Mark the pot if needed to track rotation"
                ];
                $recommendations['tips'][] = "This prevents lopsided growth toward the light source";
                break;

            case 'check':
                $recommendations['summary'] = "Inspect {$task['plant_name']} for overall health and any issues.";
                $recommendations['steps'] = [
                    "Check leaves (top and bottom) for pests or spots",
                    "Feel soil moisture level",
                    "Look for new growth or changes",
                    "Check for yellowing, browning, or drooping"
                ];
                $recommendations['tips'][] = "Take a photo to track changes over time";
                break;

            default:
                $recommendations['summary'] = "Complete the {$taskType} task for {$task['plant_name']}.";
                $recommendations['steps'] = ["Follow standard care practices for this task type"];
        }

        return $recommendations;
    }

    /**
     * Get AI-suggested schedule adjustment based on skip reason
     */
    public function adjustSchedule(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];
        $reason = $body['reason'] ?? '';

        // Get task with full details
        $stmt = db()->prepare('
            SELECT t.*,
                   p.id as plant_id,
                   p.name as plant_name,
                   p.species,
                   p.pot_size,
                   p.soil_type,
                   p.light_condition,
                   p.health_status,
                   p.user_id,
                   l.name as location_name
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            LEFT JOIN locations l ON p.location_id = l.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Verify access (owned or shared via household)
        if (!CareScheduler::canAccessPlant((int) $task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Current interval (the owner's override wins over the plan)
        $currentInterval = $this->scheduler->intervalFor($task) ?? 7;

        // Get skip history for this plant and task type
        $stmt = db()->prepare('
            SELECT COUNT(*) as skip_count,
                   GROUP_CONCAT(notes) as reasons
            FROM care_log
            WHERE plant_id = ?
              AND action = ?
              AND performed_at > datetime("now", "-30 days")
        ');
        $stmt->execute([$task['plant_id'], 'skipped_' . $task['task_type']]);
        $skipHistory = $stmt->fetch();

        // Try AI service for smart suggestion
        try {
            $aiService = AIServiceFactory::getForUser($userId);
            $suggestion = $this->getAIScheduleSuggestion($aiService, $task, $reason, $currentInterval, $skipHistory);
            if ($suggestion) {
                return $suggestion;
            }
        } catch (\Exception $e) {
            error_log("AI schedule adjustment error: " . $e->getMessage());
        }

        // Fallback logic
        return $this->getFallbackScheduleSuggestion($task, $reason, $currentInterval);
    }

    /**
     * Get AI-powered schedule suggestion
     */
    private function getAIScheduleSuggestion($aiService, array $task, string $reason, int $currentInterval, array $skipHistory): ?array
    {
        $prompt = "You are a plant care expert. A user is skipping a {$task['task_type']} task for their {$task['species']} plant.

Current schedule: Every {$currentInterval} days
Skip reason: {$reason}
Plant details:
- Species: {$task['species']}
- Pot size: {$task['pot_size']}
- Soil type: {$task['soil_type']}
- Light: {$task['light_condition']}
- Health: {$task['health_status']}
- Location: {$task['location_name']}

Recent skip history (last 30 days): {$skipHistory['skip_count']} skips
Previous skip reasons: " . ($skipHistory['reasons'] ?: 'None') . "

Based on this information, suggest whether the schedule should be adjusted. Respond in JSON format:
{
  \"should_adjust\": true/false,
  \"new_interval\": <number of days, or null if no change>,
  \"suggestion\": \"<brief explanation for the user, 1-2 sentences>\",
  \"reasoning\": \"<internal reasoning>\"
}

Consider:
- If soil is still moist, the plant may need less frequent watering
- Seasonal changes affect watering needs
- The skip history pattern (frequent skips suggest schedule is too aggressive)
- Species-specific needs";

        $plant = [
            'name' => $task['plant_name'],
            'species' => $task['species'],
            'pot_size' => $task['pot_size'],
            'soil_type' => $task['soil_type'],
            'light_condition' => $task['light_condition'],
            'health_status' => $task['health_status']
        ];

        try {
            $response = $aiService->chat(
                $plant,
                [['role' => 'user', 'content' => $prompt]],
                []
            );

            $content = $response['content'] ?? '';

            // Extract JSON
            if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
                $content = $matches[1];
            }

            $result = json_decode(trim($content), true);

            if ($result && isset($result['suggestion'])) {
                if (isset($result['new_interval'])) {
                    $result['new_interval'] = max(1, min(365, (int) $result['new_interval']));
                }
                return [
                    'suggestion' => $result['suggestion'],
                    'new_interval' => $result['new_interval'],
                    'should_adjust' => $result['should_adjust'] ?? false,
                    'adjustment' => [
                        'type' => 'interval',
                        'value' => $result['new_interval'],
                        'task_type' => $task['task_type']
                    ],
                    'source' => 'ai'
                ];
            }
        } catch (\Exception $e) {
            error_log("AI schedule suggestion failed: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Fallback schedule suggestion when AI is unavailable
     */
    private function getFallbackScheduleSuggestion(array $task, string $reason, int $currentInterval): array
    {
        $newInterval = $currentInterval;
        $suggestion = '';

        // Simple heuristics based on reason
        if (strpos(strtolower($reason), 'moist') !== false || strpos(strtolower($reason), 'wet') !== false) {
            $newInterval = min($currentInterval + 2, $currentInterval * 1.5);
            $suggestion = "Since the soil is still moist, I suggest extending watering to every " . round($newInterval) . " days.";
        } elseif (strpos(strtolower($reason), 'recently') !== false || strpos(strtolower($reason), 'already') !== false) {
            $newInterval = $currentInterval + 1;
            $suggestion = "Extending the schedule slightly to every " . round($newInterval) . " days to avoid over-caring.";
        } elseif (strpos(strtolower($reason), 'stressed') !== false) {
            return [
                'suggestion' => "When a plant is stressed, keeping the current schedule is usually best. Watch it closely.",
                'new_interval' => $currentInterval,
                'should_adjust' => false,
                'adjustment' => null,
                'source' => 'fallback'
            ];
        } else {
            $suggestion = "Based on your feedback, consider adjusting to every " . ($currentInterval + 1) . " days.";
            $newInterval = $currentInterval + 1;
        }

        return [
            'suggestion' => $suggestion,
            'new_interval' => round($newInterval),
            'should_adjust' => true,
            'adjustment' => [
                'type' => 'interval',
                'value' => round($newInterval),
                'task_type' => $task['task_type']
            ],
            'source' => 'fallback'
        ];
    }

    /**
     * Save a new interval for this plant and task type, then skip the
     * current task. The interval is kept as an override, so care plan
     * rebuilds and season changes don't undo it.
     */
    public function applyAdjustment(array $params, array $body, ?int $userId): array
    {
        $task = $this->findAccessibleTask($params['id'], $userId);
        if (!$task) {
            return $this->notFound();
        }

        $reason = trim((string) ($body['reason'] ?? ''));
        $adjustment = $body['adjustment'] ?? null;
        $newInterval = null;

        if (is_array($adjustment) && isset($adjustment['value']) && (int) $adjustment['value'] > 0) {
            $newInterval = $this->scheduler->setOverride((int) $task['plant_id'], $task['task_type'], (int) $adjustment['value']);
        }

        $task = CareScheduler::loadTask((int) $task['id']);
        $this->scheduler->skip($task, $userId, trim($reason . ' (schedule adjusted)'), 'complete', null, null, false);

        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes, outcome, performed_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['plant_id'],
            $task['id'],
            'schedule_adjusted_' . $task['task_type'],
            trim($reason . ' - Adjusted to every ' . ($newInterval ?? '?') . ' days', ' -'),
            'positive',
            $userId,
        ]);

        return [
            'success' => true,
            'message' => 'Schedule updated and task skipped',
            'new_interval' => $newInterval,
        ];
    }
}
