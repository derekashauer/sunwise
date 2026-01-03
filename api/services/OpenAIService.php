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

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
        $this->model = defined('OPENAI_MODEL') ? OPENAI_MODEL : 'gpt-5.2';
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
            ], 10);
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
2. Current health assessment (thriving, healthy, struggling, critical, or unknown)
3. Any visible issues (pests, disease, nutrient deficiency, overwatering, underwatering, etc.)
4. Estimated age/maturity (young, juvenile, mature, or unknown)

Respond ONLY with valid JSON in this exact format:
{
    "species": "Common Name (Scientific Name)",
    "confidence": 0.85,
    "health_status": "healthy",
    "issues": ["description of any issues"],
    "maturity": "mature",
    "notes": "Any additional observations"
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
        $plantInfo = "Species: " . ($plant['species'] ?? 'Unknown houseplant');
        $plantInfo .= "\nPot size: " . ($plant['pot_size'] ?? 'medium');
        $plantInfo .= "\nSoil type: " . ($plant['soil_type'] ?? 'standard');
        $plantInfo .= "\nLight condition: " . ($plant['light_condition'] ?? 'medium');
        $plantInfo .= "\nLocation: " . ($plant['location'] ?? 'Not specified');
        $plantInfo .= "\nCurrent health: " . ($plant['health_status'] ?? 'unknown');
        $plantInfo .= "\nSeason: $season";

        $careHistory = "";
        if (!empty($careLog)) {
            $careHistory = "\n\nRecent care history:\n";
            foreach (array_slice($careLog, 0, 10) as $log) {
                $careHistory .= "- {$log['action']} on {$log['performed_at']}";
                if ($log['outcome']) {
                    $careHistory .= " (outcome: {$log['outcome']})";
                }
                $careHistory .= "\n";
            }
        }

        $today = date('Y-m-d');

        $prompt = <<<PROMPT
Generate a care plan for this plant.

$plantInfo
$careHistory

Today's date: $today

Create a personalized care schedule considering:
1. The specific species needs
2. Current season ($season) and how it affects watering/fertilizing
3. The plant's health status
4. Previous care history and outcomes

Respond ONLY with valid JSON:
{
    "reasoning": "Brief explanation of the care plan rationale",
    "next_photo_check": "YYYY-MM-DD when to request health photo",
    "photo_check_reason": "Why you're requesting a photo then",
    "tasks": [
        {
            "type": "water|fertilize|trim|repot|rotate|mist|check",
            "due_date": "YYYY-MM-DD",
            "recurrence": {"type": "days", "interval": 7},
            "instructions": "Specific instructions for this task",
            "priority": "low|normal|high|urgent"
        }
    ]
}

Include 3-5 different task types. Set reasonable intervals based on plant type and season.
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        $response = $this->sendRequest($messages);
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

        $response = $this->sendRequest($apiMessages, 2048);
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
        $prompt = "You are a knowledgeable and friendly plant care expert. You're helping the user care for their plant.\n\n";

        $prompt .= "Current plant information:\n";
        $prompt .= "- Name: " . ($plant['name'] ?? 'Unknown') . "\n";
        $prompt .= "- Species: " . ($plant['species'] ?? 'Unknown') . "\n";

        if (!empty($plant['species_confidence'])) {
            $prompt .= "- Species identification confidence: " . round($plant['species_confidence'] * 100) . "%\n";
        }

        $prompt .= "- Location: " . ($plant['location_name'] ?? $plant['location'] ?? 'Not specified') . "\n";
        $prompt .= "- Pot size: " . ($plant['pot_size'] ?? 'Not specified') . "\n";
        $prompt .= "- Soil type: " . ($plant['soil_type'] ?? 'Not specified') . "\n";
        $prompt .= "- Light condition: " . ($plant['light_condition'] ?? 'Not specified') . "\n";
        $prompt .= "- Health status: " . ($plant['health_status'] ?? 'Unknown') . "\n";

        if (!empty($plant['notes'])) {
            $prompt .= "- User notes: " . $plant['notes'] . "\n";
        }

        // Add care log if available
        if (!empty($context['care_log'])) {
            $prompt .= "\nRecent care history:\n";
            foreach (array_slice($context['care_log'], 0, 5) as $log) {
                $prompt .= "- {$log['action']} on {$log['performed_at']}\n";
            }
        }

        // Add upcoming tasks if available
        if (!empty($context['tasks'])) {
            $prompt .= "\nUpcoming care tasks:\n";
            foreach (array_slice($context['tasks'], 0, 5) as $task) {
                $prompt .= "- {$task['task_type']} due on {$task['due_date']}\n";
            }
        }

        $prompt .= "\nAlways consider the specific needs of this species when giving advice.";
        $prompt .= " If the user mentions issues like yellowing leaves, drooping, or pests, provide specific diagnosis and treatment recommendations.";
        $prompt .= " If you think the species identification might be wrong based on the conversation, suggest correcting it.";

        return $prompt;
    }

    private function sendRequest(array $messages, int $maxTokens = 1024): ?string
    {
        if (!$this->apiKey) {
            throw new Exception('OpenAI API key not configured');
        }

        $data = [
            'model' => $this->model,
            'max_tokens' => $maxTokens,
            'messages' => $messages
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('API request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            $errorData = json_decode($response, true);
            throw new Exception('API error: ' . ($errorData['error']['message'] ?? 'Unknown error'));
        }

        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? null;
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
