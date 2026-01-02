<?php
/**
 * Claude AI Service
 */

class ClaudeService
{
    private string $apiKey;
    private string $model;
    private string $apiUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = CLAUDE_API_KEY;
        $this->model = CLAUDE_MODEL;
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

        $response = $this->sendRequest([
            [
                'type' => 'text',
                'text' => $prompt
            ]
        ]);

        return $this->parseJsonResponse($response);
    }

    /**
     * Send request to Claude API
     */
    private function sendRequest(array $content): ?string
    {
        if (!$this->apiKey) {
            throw new Exception('Claude API key not configured');
        }

        $data = [
            'model' => $this->model,
            'max_tokens' => 1024,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $content
                ]
            ]
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01'
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
        return $result['content'][0]['text'] ?? null;
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
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
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
