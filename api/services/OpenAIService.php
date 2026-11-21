<?php
/**
 * OpenAI API Service
 * Implements AIServiceInterface for GPT models
 */

class OpenAIService implements AIServiceInterface
{
    private string $apiKey;
    private string $model;
    private string $apiUrl = 'https://api.openai.com/v1/chat/completions';

    public function __construct(string $apiKey, ?string $model = null)
    {
        $this->apiKey = $apiKey;
        $this->model = $model ?? (defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-4o');
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
        return 'openai';
    }

    public function validateApiKey(): bool
    {
        try {
            // Make a minimal API call to validate the key
            $response = $this->sendRequest([
                ['role' => 'user', 'content' => 'Say "ok"']
            ], 2000);
            return !empty($response);
        } catch (Exception $e) {
            return false;
        }
    }

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

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$imageData}"
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt
                    ]
                ]
            ]
        ];

        $response = $this->sendRequest($messages);
        return $this->parseJsonResponse($response);
    }

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

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$imageData}"
                        ]
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt
                    ]
                ]
            ]
        ];

        $response = $this->sendRequest($messages);
        return $this->parseJsonResponse($response);
    }

    public function generateCarePlan(array $plant, array $careLog, string $season): ?array
    {
        $prompt = CarePlanPrompt::build($plant, $careLog, $season);
        $response = $this->sendRequest([
            ['role' => 'user', 'content' => $prompt]
        ], 16000, CarePlanPrompt::schema());

        return $this->parseJsonResponse($response);
    }

    public function chat(array $plant, array $messages, array $context = []): array
    {
        // Build system prompt with plant context
        $systemPrompt = $this->buildChatSystemPrompt($plant, $context);

        // Convert messages to OpenAI format
        $apiMessages = [
            ['role' => 'system', 'content' => $systemPrompt]
        ];

        foreach ($messages as $msg) {
            $apiMessages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }

        // Add instruction for suggested actions
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

        // Add the instruction to the last user message
        $lastIndex = count($apiMessages) - 1;
        if ($apiMessages[$lastIndex]['role'] === 'user') {
            $apiMessages[$lastIndex]['content'] .= $actionInstruction;
        }

        $response = $this->sendRequest($apiMessages, 8000);
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

    private function sendRequest(array $messages, int $maxTokens = 8000, ?array $schema = null): ?string
    {
        if (!$this->apiKey) {
            throw new Exception('OpenAI API key not configured');
        }

        $data = [
            'model' => $this->model,
            'max_completion_tokens' => $maxTokens,
            'messages' => $messages
        ];
        if ($schema) {
            // Structured outputs: the reply always matches the schema
            $data['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'structured_reply', 'schema' => $schema, 'strict' => true],
            ];
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init($this->apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey
                ],
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_TIMEOUT => 180
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
        $choice = $result['choices'][0] ?? [];
        if (!empty($choice['message']['refusal'])) {
            throw new Exception('The AI declined to answer this request.');
        }
        if (($choice['finish_reason'] ?? null) === 'length' && $schema) {
            throw new Exception('The AI reply was cut off before it finished.');
        }
        return $choice['message']['content'] ?? null;
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

        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        $response = $this->sendRequest($messages, 8000);
        return $this->parseJsonResponse($response);
    }

    /**
     * Analyze check data for insights
     */
    public function analyzeCheckData(array $checkData, array $recentChecks, array $plantInfo, array $currentTasks): ?array
    {
        $response = $this->sendRequest([
            ['role' => 'user', 'content' => CheckInsightPrompt::build($checkData, $recentChecks, $plantInfo, $currentTasks)]
        ], 4000, CheckInsightPrompt::schema());

        return $this->parseJsonResponse($response);
    }

    private function parseJsonResponse(?string $response): ?array
    {
        if (!$response) {
            return null;
        }

        // Try to extract JSON from response (AI might include extra text)
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

        error_log('Failed to parse OpenAI response: ' . $response);
        return null;
    }
}
