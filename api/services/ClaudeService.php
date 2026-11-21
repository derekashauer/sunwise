<?php
/**
 * Claude AI Service
 * Implements AIServiceInterface for Claude models
 */

class ClaudeService implements AIServiceInterface
{
    /** Models that accept server-side refusal fallback ("fallbacks": "default"). */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    private string $apiKey;
    private string $model;
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct(?string $apiKey = null, ?string $model = null)
    {
        $this->apiKey = $apiKey ?? CLAUDE_API_KEY;
        $this->model = $model ?? (defined('CLAUDE_MODEL') ? CLAUDE_MODEL : 'claude-opus-5-5');
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getProviderName(): string
    {
        return 'claude';
    }

    public function validateApiKey(): bool
    {
        try {
            // Make a minimal API call to validate the key
            $response = $this->sendRequest([
                ['type' => 'text', 'text' => 'Say "ok"']
            ], 2000);
            return !empty($response);
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Identify plant from image
     */
    public function identifyPlant(string $imagePath): ?array
    {
        if (!file_exists($imagePath)) {
            throw new Exception('Image file not found');
        }

        $imageData = base64_encode(file_get_contents($imagePath));
        $mimeType = mime_content_type($imagePath);

        $prompt = <<<PROMPT
Analyze this plant photo and identify:
1. Species/common name (with confidence level 0-1)
2. If uncertain, provide up to 3 possible species candidates with confidence levels
3. Current health assessment (thriving, healthy, struggling, critical, or unknown)
4. Any visible issues (pests, disease, nutrient deficiency, overwatering, underwatering, etc.)
5. Estimated age/maturity (young, juvenile, mature, or unknown)

Respond ONLY with valid JSON in this exact format:
{
    "species": "Most Likely Common Name (Scientific Name)",
    "confidence": 0.85,
    "candidates": [
        {"species": "Most Likely (Scientific)", "confidence": 0.85},
        {"species": "Second Option (Scientific)", "confidence": 0.65},
        {"species": "Third Option (Scientific)", "confidence": 0.45}
    ],
    "health_status": "healthy",
    "issues": ["description of any issues"],
    "maturity": "mature",
    "notes": "Any additional observations"
}

ALWAYS include the "candidates" array with at least 1 entry (the best match). Include up to 3 candidates if the plant could reasonably be one of several similar species.
PROMPT;

        $response = $this->sendRequest([
            [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimeType,
                    'data' => $imageData
                ]
            ],
            [
                'type' => 'text',
                'text' => $prompt
            ]
        ]);

        return $this->parseJsonResponse($response);
    }

    /**
     * Analyze plant health
     */
    public function analyzeHealth(string $imagePath, array $plant): ?array
    {
        if (!file_exists($imagePath)) {
            throw new Exception('Image file not found');
        }

        $imageData = base64_encode(file_get_contents($imagePath));
        $mimeType = mime_content_type($imagePath);

        $plantInfo = "Species: " . ($plant['species'] ?? 'Unknown');
        $plantInfo .= "\nPot size: " . ($plant['pot_size'] ?? 'Unknown');
        $plantInfo .= "\nSoil type: " . ($plant['soil_type'] ?? 'Unknown');
        $plantInfo .= "\nLight condition: " . ($plant['light_condition'] ?? 'Unknown');
        $plantInfo .= "\nPrevious health status: " . ($plant['health_status'] ?? 'Unknown');

        $prompt = <<<PROMPT
Analyze this plant photo for health status.

Plant Information:
$plantInfo

Assess:
1. Current health status (thriving, healthy, struggling, critical)
2. Any visible issues or problems
3. Specific recommendations for improvement
4. Whether the current care conditions (pot, soil, light) are appropriate

Respond ONLY with valid JSON:
{
    "health_status": "healthy",
    "issues": ["list of any issues found"],
    "recommendations": ["list of recommendations"],
    "conditions_appropriate": true,
    "condition_notes": "Notes about pot/soil/light appropriateness",
    "urgency": "none|low|medium|high"
}
PROMPT;

        $response = $this->sendRequest([
            [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimeType,
                    'data' => $imageData
                ]
            ],
            [
                'type' => 'text',
                'text' => $prompt
            ]
        ]);

        return $this->parseJsonResponse($response);
    }

    /**
     * Generate care plan
     */
    public function generateCarePlan(array $plant, array $careLog, string $season): ?array
    {
        $prompt = CarePlanPrompt::build($plant, $careLog, $season);
        $response = $this->sendRequest([
            ['type' => 'text', 'text' => $prompt]
        ], 16000, CarePlanPrompt::schema());

        return $this->parseJsonResponse($response);
    }

    /**
     * Chat with AI about a specific plant
     */
    public function chat(array $plant, array $messages, array $context = []): array
    {
        // Build system prompt with plant context
        $systemPrompt = $this->buildChatSystemPrompt($plant, $context);

        // Convert messages to Claude format
        $claudeMessages = [];
        foreach ($messages as $msg) {
            $claudeMessages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        // Add instruction for suggested actions to last message
        $actionInstruction = <<<INST

If you have recommendations that could update the plant's information or care schedule, include them as suggested_actions in your response.

Always respond with valid JSON in this format:
{
    "content": "Your conversational response to the user",
    "suggested_actions": [
        {
            "type": "update_species|update_care_schedule|update_notes|update_health",
            "field": "the field to update (if applicable)",
            "current": "current value",
            "new": "proposed new value",
            "reason": "why you're suggesting this change"
        }
    ]
}

Only include suggested_actions if you have specific changes to recommend. The array can be empty if no changes are needed.
INST;

        // Modify the last message to include the instruction
        if (!empty($claudeMessages)) {
            $lastIndex = count($claudeMessages) - 1;
            if ($claudeMessages[$lastIndex]['role'] === 'user') {
                $claudeMessages[$lastIndex]['content'] .= $actionInstruction;
            }
        }

        // Prepare content with system prompt prepended to first user message
        $content = [];
        $systemAdded = false;

        foreach ($claudeMessages as $msg) {
            if ($msg['role'] === 'user' && !$systemAdded) {
                $content[] = [
                    'role' => 'user',
                    'content' => $systemPrompt . "\n\n" . $msg['content']
                ];
                $systemAdded = true;
            } else {
                $content[] = $msg;
            }
        }

        // Send to Claude API
        $response = $this->sendChatRequest($content, 8000);
        $parsed = $this->parseJsonResponse($response);

        if ($parsed && isset($parsed['content'])) {
            return [
                'content' => $parsed['content'],
                'suggested_actions' => $parsed['suggested_actions'] ?? []
            ];
        }

        // Fallback if response isn't in expected format
        return [
            'content' => $response ?? 'I apologize, but I encountered an error processing your request.',
            'suggested_actions' => []
        ];
    }

    private function buildChatSystemPrompt(array $plant, array $context): string
    {
        $species = $plant['species'] ?? 'Unknown species';
        $plantName = $plant['name'] ?? 'this plant';

        $prompt = "You are a knowledgeable and friendly plant care expert. You are chatting with a user about their specific plant.\n\n";

        $prompt .= "=== IMPORTANT: PLANT-SPECIFIC CONTEXT ===\n";
        $prompt .= "You MUST tailor ALL advice specifically for this plant. Never give generic houseplant advice.\n\n";

        $prompt .= "## THIS PLANT'S DETAILS:\n";
        $prompt .= "**Plant Name**: {$plantName}\n";
        $prompt .= "**Species**: {$species}\n";

        if (!empty($plant['species_confidence'])) {
            $confidence = round($plant['species_confidence'] * 100);
            $prompt .= "**Species ID Confidence**: {$confidence}%\n";
        }

        $prompt .= "**Location**: " . ($plant['location_name'] ?? $plant['location'] ?? 'Not specified') . "\n";
        $prompt .= "**Pot Size**: " . ($plant['pot_size'] ?? 'Not specified') . "\n";
        $prompt .= "**Soil Type**: " . ($plant['soil_type'] ?? 'Not specified') . "\n";
        $prompt .= "**Light Condition**: " . ($plant['light_condition'] ?? 'Not specified') . "\n";
        $prompt .= "**Current Health**: " . ($plant['health_status'] ?? 'Unknown') . "\n";
        if (!empty($plant['climate_notes'])) {
            $prompt .= "**Home Climate**: " . $plant['climate_notes'] . "\n";
        }
        if (!empty($plant['fertilizer_method'])) {
            $prompt .= "**Fertilizer Used**: " . ($plant['fertilizer_method'] === 'mixed'
                ? 'liquid fertilizer mixed into water'
                : 'slow-release granules (Osmocote Plus 15-9-12), fed about every 4 months, paused September to February') . "\n";
        }

        // Propagation info
        if (!empty($plant['is_propagation'])) {
            $prompt .= "\n⚠️ **PROPAGATION STATUS**: This is a CUTTING/PROPAGATION, NOT a mature plant!\n";
            if (!empty($plant['propagation_date'])) {
                $prompt .= "**Propagation Started**: " . $plant['propagation_date'] . "\n";
            }
            // Always specify medium for propagations
            if ($plant['soil_type'] === 'water') {
                $prompt .= "**Medium**: Water propagation\n";
            } else {
                $mediumLabel = $plant['soil_type'] === 'rooting' ? 'Rooting medium' : 'Soil';
                $prompt .= "**Medium**: " . $mediumLabel . " (soil propagation)\n";
            }
        }

        // Grow light info
        if (!empty($plant['has_grow_light'])) {
            $hours = $plant['grow_light_hours'] ?? 'unspecified';
            $prompt .= "**Grow Light**: Yes, {$hours} hours/day\n";
        }

        if (!empty($plant['notes'])) {
            $prompt .= "**Owner's Notes**: " . $plant['notes'] . "\n";
        }

        // Add care log if available
        if (!empty($context['care_log'])) {
            $prompt .= "\n## RECENT CARE HISTORY:\n";
            foreach (array_slice($context['care_log'], 0, 5) as $log) {
                $date = date('M j', strtotime($log['performed_at']));
                $prompt .= "- **{$date}**: {$log['action']}";
                if (!empty($log['notes'])) {
                    $prompt .= " - {$log['notes']}";
                }
                $prompt .= "\n";
            }
        }

        // Add upcoming tasks if available
        if (!empty($context['tasks'])) {
            $prompt .= "\n## SCHEDULED CARE TASKS:\n";
            foreach (array_slice($context['tasks'], 0, 5) as $task) {
                $date = date('M j', strtotime($task['due_date']));
                $prompt .= "- **{$date}**: {$task['task_type']}";
                if (!empty($task['instructions'])) {
                    $prompt .= " - {$task['instructions']}";
                }
                $prompt .= "\n";
            }
        }

        $prompt .= "\n=== RESPONSE GUIDELINES ===\n";
        $prompt .= "1. **ALWAYS reference {$species} specifically** - mention its specific needs, tolerances, and characteristics.\n";
        $prompt .= "2. Consider this plant's current conditions (pot size: {$plant['pot_size']}, soil: {$plant['soil_type']}, light: {$plant['light_condition']}).\n";
        $prompt .= "3. If discussing watering, fertilizing, or care schedules, tailor advice for {$species}.\n";
        $prompt .= "4. If the user describes symptoms, diagnose based on {$species}-specific issues.\n";
        $prompt .= "5. Reference the care history above when relevant.\n";
        $prompt .= "6. If you believe the species identification is wrong, suggest correcting it.\n";

        return $prompt;
    }

    /**
     * Send a multi-turn chat request to Claude.
     */
    private function sendChatRequest(array $messages, int $maxTokens = 8000): ?string
    {
        return $this->callApi([
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ]);
    }

    /**
     * Send a single-turn request to Claude. With a schema, the reply is
     * constrained to valid JSON matching it (structured outputs).
     */
    private function sendRequest(array $content, int $maxTokens = 8000, ?array $schema = null): ?string
    {
        $data = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
        ];
        if ($schema) {
            $data['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $schema]];
        }
        return $this->callApi($data);
    }

    /**
     * POST to the Messages API and return the reply's text.
     *
     * Newer models think before answering, and the thinking counts toward
     * max_tokens, which is why the limits here are much higher than before.
     * Text is read from every text block, because a thinking block can come
     * first. Opus 5.5 and Sonnet 5.5 requests opt into server-side fallback,
     * so a rare safety decline is retried on another model automatically.
     * Rate limits and overloads are retried twice with a short wait.
     */
    private function callApi(array $data): ?string
    {
        if (!$this->apiKey) {
            throw new Exception('Claude API key not configured');
        }

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
        ];
        if (in_array($this->model, self::FALLBACK_MODELS, true)) {
            $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
            $data['fallbacks'] = 'default';
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_TIMEOUT => 180,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            $retryable = $error || $httpCode === 429 || $httpCode >= 500;
            if ($retryable && $attempt < 3) {
                sleep(2 * $attempt);
                continue;
            }
            break;
        }

        if ($error) {
            throw new Exception('API request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            throw new Exception('API error: ' . ($errorData['error']['message'] ?? 'Unknown error'));
        }

        $result = json_decode($response, true);
        $stopReason = $result['stop_reason'] ?? null;
        if ($stopReason === 'refusal') {
            throw new Exception('The AI declined to answer this request.');
        }

        $text = '';
        foreach ($result['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        if ($stopReason === 'max_tokens' && isset($data['output_config'])) {
            throw new Exception('The AI reply was cut off before it finished.');
        }

        return $text !== '' ? $text : null;
    }

    /**
     * Generate species care info sheet
     */
    public function generateSpeciesCareInfo(string $species): ?array
    {
        $prompt = <<<PROMPT
Generate a comprehensive care guide for: {$species}

Provide general species information that would help someone care for this plant. This should be reference information, not personalized to a specific plant.

Respond ONLY with valid JSON in this exact format:
{
    "common_name": "Common name of the plant",
    "scientific_name": "Scientific/botanical name",
    "family": "Plant family (e.g., Araceae)",
    "origin": "Native region/habitat",
    "light": {
        "ideal": "Description of ideal light conditions",
        "foot_candles": {
            "low": 50,
            "ideal_min": 200,
            "ideal_max": 500,
            "max": 1000
        },
        "tolerance": "What light levels it can tolerate",
        "signs_of_too_much": "Signs of too much light",
        "signs_of_too_little": "Signs of insufficient light"
    },
    "water": {
        "frequency": "General watering frequency",
        "method": "Best watering method",
        "signs_of_overwatering": "Signs of overwatering",
        "signs_of_underwatering": "Signs of underwatering"
    },
    "humidity": {
        "ideal": "Ideal humidity percentage or description",
        "tips": "How to increase humidity if needed"
    },
    "temperature": {
        "ideal_range": "Ideal temperature range",
        "minimum": "Minimum safe temperature",
        "maximum": "Maximum safe temperature"
    },
    "soil": {
        "type": "Best soil type/mix",
        "drainage": "Drainage requirements"
    },
    "fertilizer": {
        "type": "Best fertilizer type",
        "frequency": "How often to fertilize",
        "season": "Best season to fertilize"
    },
    "toxicity": {
        "toxic_to_pets": true,
        "toxic_to_humans": false,
        "details": "Specific toxicity information"
    },
    "common_issues": [
        {
            "issue": "Name of common issue",
            "cause": "What causes it",
            "solution": "How to fix it"
        }
    ],
    "propagation": {
        "methods": ["List of propagation methods"],
        "difficulty": "easy|moderate|difficult",
        "tips": "Propagation tips"
    },
    "growth": {
        "rate": "slow|moderate|fast",
        "mature_size": "Expected mature size",
        "lifespan": "Expected lifespan"
    },
    "care_tips": ["List of general care tips"],
    "fun_facts": ["Optional interesting facts about the plant"]
}
PROMPT;

        $response = $this->sendRequest([
            ['type' => 'text', 'text' => $prompt]
        ], 8000);

        return $this->parseJsonResponse($response);
    }

    /**
     * Analyze check data for insights
     */
    public function analyzeCheckData(array $checkData, array $recentChecks, array $plantInfo, array $currentTasks): ?array
    {
        $response = $this->sendRequest([
            ['type' => 'text', 'text' => CheckInsightPrompt::build($checkData, $recentChecks, $plantInfo, $currentTasks)]
        ], 4000, CheckInsightPrompt::schema());

        return $this->parseJsonResponse($response);
    }

    /**
     * Log AI usage to database
     */
    public static function logUsage(?int $userId, string $action, bool $success = true, ?string $errorMessage = null, ?string $model = null): void
    {
        if (!$userId) return;

        try {
            $stmt = db()->prepare('
                INSERT INTO ai_usage_log (user_id, action, model, success, error_message)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $userId,
                $action,
                $model,
                $success ? 1 : 0,
                $errorMessage
            ]);
        } catch (Exception $e) {
            error_log('Failed to log AI usage: ' . $e->getMessage());
        }
    }

    /**
     * Parse JSON from Claude response
     */
    private function parseJsonResponse(?string $response): ?array
    {
        if (!$response) {
            return null;
        }

        // Try to extract JSON from response (Claude might include extra text)
        if (preg_match('/\{[\s\S]*\}/s', $response, $matches)) {
            $json = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $json;
            }
        }

        // Try direct parse
        $json = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        error_log('Failed to parse Claude response: ' . $response);
        return null;
    }
}
