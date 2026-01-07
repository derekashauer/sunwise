<?php
/**
 * Task Controller
 */

class TaskController
{
    /**
     * Get SQL condition for plants user can access (owned + shared via household)
     */
    private function getAccessiblePlantCondition(int $userId): string
    {
        $stmt = db()->prepare('SELECT household_id FROM household_members WHERE user_id = ?');
        $stmt->execute([$userId]);
        $households = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($households)) {
            return "p.user_id = {$userId}";
        }

        $householdList = implode(',', array_map('intval', $households));
        return "(p.user_id = {$userId} OR p.id IN (SELECT plant_id FROM household_plants WHERE household_id IN ({$householdList})))";
    }

    /**
     * Check if user can access a specific plant (owns it or has household access)
     */
    private function canAccessPlant(int $plantId, int $userId): bool
    {
        // Check ownership
        $stmt = db()->prepare('SELECT user_id FROM plants WHERE id = ?');
        $stmt->execute([$plantId]);
        $plant = $stmt->fetch();

        if (!$plant) return false;
        if ($plant['user_id'] == $userId) return true;

        // Check household access
        $stmt = db()->prepare('
            SELECT 1 FROM household_plants hp
            JOIN household_members hm ON hp.household_id = hm.household_id
            WHERE hp.plant_id = ? AND hm.user_id = ?
        ');
        $stmt->execute([$plantId, $userId]);
        return (bool)$stmt->fetch();
    }

    /**
     * Get today's tasks
     */
    public function today(array $params, array $body, ?int $userId): array
    {
        $today = date('Y-m-d');
        $accessCondition = $this->getAccessiblePlantCondition($userId);

        $stmt = db()->query("
            SELECT t.*,
                   p.name as plant_name,
                   p.location as plant_location,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as plant_thumbnail,
                   CASE WHEN p.user_id = {$userId} THEN 1 ELSE 0 END as is_owned,
                   CASE WHEN t.completed_by_user_id IS NOT NULL THEN
                       (SELECT COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, '@') - 1))
                        FROM users u WHERE u.id = t.completed_by_user_id)
                   ELSE NULL END as completed_by_name
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE {$accessCondition}
              AND t.due_date <= '{$today}'
              AND t.skipped_at IS NULL
              AND p.archived_at IS NULL
            ORDER BY
                CASE WHEN t.completed_at IS NULL THEN 0 ELSE 1 END,
                CASE t.priority
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'normal' THEN 3
                    WHEN 'low' THEN 4
                    ELSE 5
                END,
                t.due_date ASC
        ");

        return ['tasks' => $stmt->fetchAll()];
    }

    /**
     * Get upcoming tasks (next 7 days)
     */
    public function upcoming(array $params, array $body, ?int $userId): array
    {
        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime('+7 days'));
        $accessCondition = $this->getAccessiblePlantCondition($userId);

        $stmt = db()->query("
            SELECT t.*,
                   p.name as plant_name,
                   p.location as plant_location,
                   p.species as species,
                   p.pot_size as pot_size,
                   p.soil_type as soil_type,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as plant_thumbnail,
                   CASE WHEN p.user_id = {$userId} THEN 1 ELSE 0 END as is_owned
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE {$accessCondition}
              AND t.due_date BETWEEN '{$today}' AND '{$endDate}'
              AND t.completed_at IS NULL
              AND t.skipped_at IS NULL
            ORDER BY t.due_date ASC, t.priority ASC
        ");

        return ['tasks' => $stmt->fetchAll()];
    }

    /**
     * Get tasks for specific plant
     */
    public function forPlant(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify plant access (owned or shared via household)
        if (!$this->canAccessPlant($plantId, $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        $stmt = db()->prepare('
            SELECT t.*,
                   CASE WHEN t.completed_by_user_id IS NOT NULL THEN
                       (SELECT COALESCE(u.display_name, SUBSTR(u.email, 1, INSTR(u.email, \'@\') - 1))
                        FROM users u WHERE u.id = t.completed_by_user_id)
                   ELSE NULL END as completed_by_name
            FROM tasks t
            WHERE t.plant_id = ?
              AND t.skipped_at IS NULL
            ORDER BY
                CASE WHEN t.completed_at IS NULL THEN 0 ELSE 1 END,
                t.due_date ASC
            LIMIT 20
        ');
        $stmt->execute([$plantId]);

        return ['tasks' => $stmt->fetchAll()];
    }

    /**
     * Complete a task
     */
    public function complete(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];
        $notes = $body['notes'] ?? null;

        // Get task details
        $stmt = db()->prepare('
            SELECT t.*, p.user_id as owner_id
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Verify access (owned or shared via household)
        if (!$this->canAccessPlant($task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Mark as completed with attribution
        $stmt = db()->prepare('UPDATE tasks SET completed_at = datetime("now"), notes = ?, completed_by_user_id = ? WHERE id = ?');
        $stmt->execute([$notes, $userId, $taskId]);

        // Log to care log with performer attribution
        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes, performed_by_user_id)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['plant_id'],
            $taskId,
            $task['task_type'],
            $notes,
            $userId
        ]);

        // Generate next occurrence if recurring
        if ($task['recurrence']) {
            $this->generateNextOccurrence($task);
        }

        // Get updated task
        $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);

        return ['task' => $stmt->fetch()];
    }

    /**
     * Complete multiple tasks at once (bulk action)
     */
    public function bulkComplete(array $params, array $body, ?int $userId): array
    {
        $taskIds = $body['task_ids'] ?? [];
        $notes = $body['notes'] ?? null;

        if (empty($taskIds) || !is_array($taskIds)) {
            return ['status' => 400, 'data' => ['error' => 'No task IDs provided']];
        }

        // Limit to 50 tasks per request
        $taskIds = array_slice($taskIds, 0, 50);

        $completed = [];
        $failed = [];

        foreach ($taskIds as $taskId) {
            // Get task details
            $stmt = db()->prepare('
                SELECT t.*, p.user_id as owner_id
                FROM tasks t
                JOIN plants p ON t.plant_id = p.id
                WHERE t.id = ?
            ');
            $stmt->execute([$taskId]);
            $task = $stmt->fetch();

            if (!$task || !$this->canAccessPlant($task['plant_id'], $userId)) {
                $failed[] = $taskId;
                continue;
            }

            if ($task['completed_at']) {
                // Already completed, skip
                $completed[] = $taskId;
                continue;
            }

            // Mark as completed with attribution
            $stmt = db()->prepare('UPDATE tasks SET completed_at = datetime("now"), notes = ?, completed_by_user_id = ? WHERE id = ?');
            $stmt->execute([$notes, $userId, $taskId]);

            // Log to care log with performer attribution
            $stmt = db()->prepare('
                INSERT INTO care_log (plant_id, task_id, action, notes, performed_by_user_id)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $task['plant_id'],
                $taskId,
                $task['task_type'],
                $notes,
                $userId
            ]);

            // Generate next occurrence if recurring
            if ($task['recurrence']) {
                $this->generateNextOccurrence($task);
            }

            $completed[] = $taskId;
        }

        return [
            'completed' => $completed,
            'failed' => $failed,
            'count' => count($completed)
        ];
    }

    /**
     * Skip a task
     */
    public function skip(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];
        $reason = $body['reason'] ?? null;

        // Get task details
        $stmt = db()->prepare('
            SELECT t.*, p.user_id as owner_id
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Verify access (owned or shared via household)
        if (!$this->canAccessPlant($task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Mark as skipped
        $stmt = db()->prepare('UPDATE tasks SET skipped_at = datetime("now"), skip_reason = ? WHERE id = ?');
        $stmt->execute([$reason, $taskId]);

        // Log to care log with performer attribution
        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes, outcome, performed_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['plant_id'],
            $taskId,
            'skipped_' . $task['task_type'],
            $reason,
            'neutral',
            $userId
        ]);

        // Generate next occurrence if recurring
        if ($task['recurrence']) {
            $this->generateNextOccurrence($task);
        }

        return ['task' => $task];
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
        if (!$this->canAccessPlant($task['plant_id'], $userId)) {
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

        // Try to get weather data (if location available)
        $weather = $this->getWeatherContext();

        // Generate AI recommendations
        $recommendations = $this->generateAIRecommendations($task, $careHistory, $healthHistory, $weather);

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
        $month = (int)date('n');
        $season = match(true) {
            $month >= 3 && $month <= 5 => 'spring',
            $month >= 6 && $month <= 8 => 'summer',
            $month >= 9 && $month <= 11 => 'fall',
            default => 'winter'
        };

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
    private function generateAIRecommendations(array $task, array $careHistory, array $healthHistory, ?array $weather): array
    {
        // Build context for AI
        $context = $this->buildTaskContext($task, $careHistory, $healthHistory, $weather);

        // Try to get AI service
        try {
            $aiService = $this->getAIService();
            if ($aiService) {
                return $this->callAIForRecommendations($aiService, $task, $context);
            }
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
     * Get AI service instance
     */
    private function getAIService(): ?object
    {
        // Check if user has API keys configured
        $stmt = db()->prepare('SELECT setting_value FROM user_settings WHERE setting_key IN (?, ?) LIMIT 1');
        $stmt->execute(['claude_api_key', 'openai_api_key']);
        $setting = $stmt->fetch();

        if (!$setting) {
            return null;
        }

        // Decrypt and create service
        $encryptedKey = $setting['setting_value'];
        $apiKey = EncryptionService::decrypt($encryptedKey);

        // Determine which service based on key format
        if (strpos($apiKey, 'sk-ant-') === 0) {
            return new ClaudeService($apiKey);
        } else {
            return new OpenAIService($apiKey);
        }
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
                $strengths = ['small' => '1/4', 'medium' => '1/2', 'large' => '1/2-full', 'xlarge' => 'full'];
                $recommendations['summary'] = "Apply balanced fertilizer at {$strengths[$potSize]} strength to support growth.";
                $recommendations['amount'] = "{$strengths[$potSize]} strength dilution";
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
        if (!$this->canAccessPlant($task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Get current recurrence
        $recurrence = json_decode($task['recurrence'], true);
        $currentInterval = $recurrence['interval'] ?? 7;

        // Get skip history for this plant and task type
        $stmt = db()->prepare('
            SELECT COUNT(*) as skip_count,
                   GROUP_CONCAT(notes) as reasons
            FROM care_log
            WHERE plant_id = ?
              AND action LIKE ?
              AND performed_at > datetime("now", "-30 days")
        ');
        $stmt->execute([$task['plant_id'], 'skipped_%']);
        $skipHistory = $stmt->fetch();

        // Try AI service for smart suggestion
        try {
            $aiService = $this->getAIService();
            if ($aiService) {
                $suggestion = $this->getAIScheduleSuggestion($aiService, $task, $reason, $currentInterval, $skipHistory);
                if ($suggestion) {
                    return $suggestion;
                }
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
            $suggestion = "When a plant is stressed, maintaining the current schedule is often best. Monitor closely.";
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
     * Apply schedule adjustment and skip task
     */
    public function applyAdjustment(array $params, array $body, ?int $userId): array
    {
        $taskId = $params['id'];
        $reason = $body['reason'] ?? '';
        $adjustment = $body['adjustment'] ?? null;

        // Get task details
        $stmt = db()->prepare('
            SELECT t.*, p.user_id as owner_id, p.id as plant_id
            FROM tasks t
            JOIN plants p ON t.plant_id = p.id
            WHERE t.id = ?
        ');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Verify access (owned or shared via household)
        if (!$this->canAccessPlant($task['plant_id'], $userId)) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        // Update the recurrence interval if adjustment provided
        if ($adjustment && isset($adjustment['value'])) {
            $recurrence = json_decode($task['recurrence'], true) ?: ['type' => 'days', 'interval' => 7];
            $recurrence['interval'] = (int)$adjustment['value'];

            // Update future tasks of same type
            $stmt = db()->prepare('
                UPDATE tasks
                SET recurrence = ?
                WHERE plant_id = ?
                  AND task_type = ?
                  AND completed_at IS NULL
                  AND skipped_at IS NULL
            ');
            $stmt->execute([
                json_encode($recurrence),
                $task['plant_id'],
                $task['task_type']
            ]);

            // Update care plan if exists - use dynamic field based on task type
            $allowedTaskTypes = ['water', 'fertilize', 'mist', 'rotate', 'check'];
            if (in_array($task['task_type'], $allowedTaskTypes)) {
                $carePlanField = $task['task_type'] . '_frequency_days';
                try {
                    $stmt = db()->prepare("
                        UPDATE care_plans
                        SET $carePlanField = ?, updated_at = datetime('now')
                        WHERE plant_id = ? AND is_active = 1
                    ");
                    $stmt->execute([(int)$adjustment['value'], $task['plant_id']]);
                } catch (\Exception $e) {
                    // Field might not exist, ignore
                }
            }
        }

        // Skip the current task
        $stmt = db()->prepare('UPDATE tasks SET skipped_at = datetime("now"), skip_reason = ? WHERE id = ?');
        $stmt->execute([$reason . ' (schedule adjusted)', $taskId]);

        // Log to care log
        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes, outcome)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['plant_id'],
            $taskId,
            'schedule_adjusted_' . $task['task_type'],
            $reason . ' - Adjusted to every ' . ($adjustment['value'] ?? '?') . ' days',
            'positive'
        ]);

        // Generate next occurrence with new interval
        if ($task['recurrence']) {
            $newRecurrence = json_decode($task['recurrence'], true) ?: [];
            $newRecurrence['interval'] = (int)($adjustment['value'] ?? $newRecurrence['interval'] ?? 7);
            $task['recurrence'] = json_encode($newRecurrence);
            $this->generateNextOccurrence($task);
        }

        return [
            'success' => true,
            'message' => 'Schedule updated and task skipped',
            'new_interval' => $adjustment['value'] ?? null
        ];
    }

    /**
     * Generate next occurrence of recurring task
     */
    private function generateNextOccurrence(array $task): void
    {
        $recurrence = json_decode($task['recurrence'], true);
        if (!$recurrence) return;

        $interval = $recurrence['interval'] ?? 7;
        $type = $recurrence['type'] ?? 'days';

        $nextDate = date('Y-m-d', strtotime($task['due_date'] . " +$interval $type"));

        // Check if a similar pending task already exists to prevent duplicates
        $stmt = db()->prepare('
            SELECT id FROM tasks
            WHERE plant_id = ?
              AND task_type = ?
              AND due_date = ?
              AND completed_at IS NULL
              AND skipped_at IS NULL
            LIMIT 1
        ');
        $stmt->execute([$task['plant_id'], $task['task_type'], $nextDate]);
        if ($stmt->fetch()) {
            // Task already exists for this date, skip creation
            return;
        }

        $stmt = db()->prepare('
            INSERT INTO tasks (care_plan_id, plant_id, task_type, due_date, recurrence, instructions, priority)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['care_plan_id'],
            $task['plant_id'],
            $task['task_type'],
            $nextDate,
            $task['recurrence'],
            $task['instructions'],
            $task['priority']
        ]);
    }
}
