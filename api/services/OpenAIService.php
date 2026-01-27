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

        // Add user notes if present
        if (!empty($plant['notes'])) {
            $plantInfo .= "\nUser notes: " . $plant['notes'];
        }

        // Propagation info
        if (!empty($plant['is_propagation'])) {
            $plantInfo .= "\nPROPAGATION: This is a cutting/propagation, NOT a mature plant";
            if (!empty($plant['propagation_date'])) {
                $plantInfo .= "\nPropagation started: " . $plant['propagation_date'];
            }
            if ($plant['soil_type'] === 'water') {
                $plantInfo .= "\nGrowing medium: Water (water propagation)";
            } elseif ($plant['soil_type'] === 'rooting') {
                $plantInfo .= "\nGrowing medium: Rooting medium";
            }
        }

        // Grow light info
        if (!empty($plant['has_grow_light'])) {
            $hours = $plant['grow_light_hours'] ?? 'unspecified';
            $plantInfo .= "\nGrow light: Yes, {$hours} hours/day";
        }

        // Species-specific care info (if available from AI identification)
        $speciesCareInfo = "";
        if (!empty($plant['known_care_needs'])) {
            $care = $plant['known_care_needs'];
            $speciesCareInfo = "\n\n=== SPECIES-SPECIFIC CARE GUIDELINES ===";
            if (!empty($care['water']['frequency'])) {
                $speciesCareInfo .= "\nWatering: " . $care['water']['frequency'];
            }
            if (!empty($care['light']['ideal'])) {
                $speciesCareInfo .= "\nLight needs: " . $care['light']['ideal'];
            }
            if (!empty($care['humidity']['ideal'])) {
                $speciesCareInfo .= "\nHumidity: " . $care['humidity']['ideal'];
            }
            if (!empty($care['fertilizer']['frequency'])) {
                $speciesCareInfo .= "\nFertilizing: " . $care['fertilizer']['frequency'];
            }
        }

        // Recent health analysis from photos
        $healthContext = "";
        if (!empty($plant['last_photo_analysis'])) {
            $analysis = $plant['last_photo_analysis'];
            $healthContext = "\n\n=== RECENT HEALTH ANALYSIS ===";
            $healthContext .= "\nPhoto date: " . ($plant['last_photo_date'] ?? 'Unknown');
            if (!empty($analysis['health_status'])) {
                $healthContext .= "\nHealth status: " . $analysis['health_status'];
            }
            if (!empty($analysis['issues']) && is_array($analysis['issues'])) {
                $healthContext .= "\nIdentified issues: " . implode(', ', $analysis['issues']);
            }
            if (!empty($analysis['recommendations']) && is_array($analysis['recommendations'])) {
                $healthContext .= "\nRecommendations: " . implode('; ', array_slice($analysis['recommendations'], 0, 3));
            }
        }

        // Care history statistics
        $statsInfo = "";
        if (!empty($plant['care_history_stats'])) {
            $statsInfo = "\n\n=== CARE COMPLETION HISTORY ===";
            foreach ($plant['care_history_stats'] as $stat) {
                $statsInfo .= "\n- {$stat['task_type']}: completed {$stat['count']} times";
                if (!empty($stat['last_completed'])) {
                    $statsInfo .= " (last: " . date('M j', strtotime($stat['last_completed'])) . ")";
                }
            }
        }

        $careHistory = "";
        $checkReadings = "";
        if (!empty($careLog)) {
            $careHistory = "\n\n=== RECENT CARE ACTIONS ===";
            $checks = [];
            foreach (array_slice($careLog, 0, 15) as $log) {
                $careHistory .= "\n- {$log['action']} on " . date('M j', strtotime($log['performed_at']));
                if (!empty($log['notes'])) {
                    $careHistory .= " ({$log['notes']})";
                }
                if (!empty($log['outcome'])) {
                    $careHistory .= " [outcome: {$log['outcome']}]";
                }

                // Collect check readings for separate analysis
                if ($log['action'] === 'check' && !empty($log['check_data'])) {
                    $checkData = json_decode($log['check_data'], true);
                    if ($checkData) {
                        $checks[] = ['date' => $log['performed_at'], 'data' => $checkData];
                    }
                }
            }

            // Format check readings for AI analysis
            if (!empty($checks)) {
                $checkReadings = "\n\n=== PLANT CHECK READINGS ===";
                $checkReadings .= "\n(Use these to assess plant health trends. Light readings taken in evening/night should be disregarded for peak light assessment.)";
                foreach (array_slice($checks, 0, 10) as $check) {
                    $data = $check['data'];
                    $time = date('M j \a\t g:ia', strtotime($data['recorded_at'] ?? $check['date']));
                    $checkReadings .= "\n\n$time:";

                    if (isset($data['moisture_level'])) {
                        $moistureLabel = $data['moisture_level'] <= 3 ? 'dry' : ($data['moisture_level'] <= 7 ? 'moist' : 'wet');
                        $checkReadings .= "\n  - Moisture: {$data['moisture_level']}/10 ($moistureLabel)";
                    }
                    if (!empty($data['light_reading'])) {
                        $checkReadings .= "\n  - Light: {$data['light_reading']} fc";
                    }
                    if (isset($data['general_health'])) {
                        $checkReadings .= "\n  - Health rating: {$data['general_health']}/5";
                    }

                    $observations = [];
                    if (!empty($data['new_growth'])) $observations[] = 'new growth';
                    if (!empty($data['yellowing_leaves'])) $observations[] = 'yellowing leaves';
                    if (!empty($data['brown_tips'])) $observations[] = 'brown tips';
                    if (!empty($data['pests_observed'])) {
                        $pestInfo = 'pests observed';
                        if (!empty($data['pest_notes'])) {
                            $pestInfo .= " ({$data['pest_notes']})";
                        }
                        $observations[] = $pestInfo;
                    }
                    if (!empty($data['dusty_dirty'])) $observations[] = 'needs cleaning';

                    if (!empty($observations)) {
                        $checkReadings .= "\n  - Observations: " . implode(', ', $observations);
                    }
                    if (!empty($data['notes'])) {
                        $checkReadings .= "\n  - Notes: {$data['notes']}";
                    }
                }
            }
        }

        $today = date('Y-m-d');

        $prompt = <<<PROMPT
Generate a personalized care plan for this plant based on all available data.

=== PLANT DETAILS ===
$plantInfo
$speciesCareInfo
$healthContext
$statsInfo
$careHistory
$checkReadings

Today's date: $today

Create a personalized care schedule considering:
1. USER NOTES ARE THE HIGHEST PRIORITY - if the user specifies a watering frequency or care preference, follow it exactly
2. The specific species needs (use the species-specific guidelines if provided, but user notes override these)
3. Current season ($season) and how it affects watering/fertilizing
4. The plant's health status and any recent issues identified
5. Previous care history, outcomes, and completion patterns
6. The plant's environment (location, light, grow lights)
7. PLANT CHECK READINGS (if available) - use moisture levels to optimize watering intervals, light readings to assess if plant is getting adequate light, and observations to identify health trends

CRITICAL - Calculating due_date:
- Look at the CARE COMPLETION HISTORY to find when each task type was last completed
- Calculate the next due_date as: last_completed_date + interval_days
- If this calculated date is in the past (overdue), set due_date to TODAY
- If no completion history exists, set due_date to today
- Example: If user says "water every 3 days" and last watering was 6 days ago, the task is overdue - set due_date to today

Respond ONLY with valid JSON:
{
    "reasoning": "Brief explanation of the care plan rationale, referencing specific factors you considered",
    "next_photo_check": "YYYY-MM-DD when to request health photo",
    "photo_check_reason": "Why you're requesting a photo then",
    "tasks": [
        {
            "type": "water|fertilize|trim|repot|rotate|mist|check|change_water|check_roots|pot_up",
            "due_date": "YYYY-MM-DD",
            "recurrence": {"type": "days", "interval": 7},
            "instructions": "Specific instructions for this task",
            "priority": "low|normal|high|urgent"
        }
    ]
}

Include 3-5 different task types. Set reasonable intervals based on plant type and season.

IMPORTANT for propagations:
- Check the "Growing medium" field to determine the correct task type:
  - If Growing medium is "Water" → use "change_water" task (NOT "water") every 3-7 days
  - If Growing medium is "Rooting medium" or any soil type → use "water" task (NOT "change_water")
- NEVER use "change_water" for soil/rooting medium propagations
- NEVER use "water" for water propagations
- Add "check_roots" tasks to monitor root development
- Add "pot_up" task when roots should be ready (usually 4-8 weeks)
- Skip fertilizing until roots are established
- Misting is more important for cuttings

IMPORTANT for grow lights:
- Consider grow light hours when scheduling. More light = more water needed
- Grow lights can compensate for low natural light conditions

IMPORTANT for health issues:
- If there are identified health issues, prioritize tasks that address them
- Adjust watering frequency if overwatering/underwatering was detected
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        $response = $this->sendRequest($messages, 2048);
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

        // Propagation info
        if (!empty($plant['is_propagation'])) {
            $prompt .= "\n⚠️ **PROPAGATION STATUS**: This is a CUTTING/PROPAGATION, NOT a mature plant!\n";
            if (!empty($plant['propagation_date'])) {
                $prompt .= "**Propagation Started**: " . $plant['propagation_date'] . "\n";
            }
            if ($plant['soil_type'] === 'water') {
                $prompt .= "**Medium**: Water propagation\n";
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

        $response = $this->sendRequest($messages, 2048);
        return $this->parseJsonResponse($response);
    }

    /**
     * Analyze check data for insights
     */
    public function analyzeCheckData(array $checkData, array $recentChecks, array $plantInfo, array $currentTasks): ?array
    {
        $species = $plantInfo['species'] ?? 'Unknown plant';

        // Format current check
        $currentCheck = "Current Check:\n";
        if (isset($checkData['moisture_level'])) {
            $moistureLabel = $checkData['moisture_level'] <= 3 ? 'dry' : ($checkData['moisture_level'] <= 7 ? 'moist' : 'wet');
            $currentCheck .= "- Moisture: {$checkData['moisture_level']}/10 ($moistureLabel)\n";
        }
        if (!empty($checkData['light_reading'])) {
            $currentCheck .= "- Light: {$checkData['light_reading']} foot-candles\n";
        }
        if (isset($checkData['general_health'])) {
            $currentCheck .= "- Health rating: {$checkData['general_health']}/5\n";
        }

        $observations = [];
        if (!empty($checkData['new_growth'])) $observations[] = 'new growth observed';
        if (!empty($checkData['yellowing_leaves'])) $observations[] = 'yellowing leaves';
        if (!empty($checkData['brown_tips'])) $observations[] = 'brown tips';
        if (!empty($checkData['pests_observed'])) {
            $pestInfo = 'pests observed';
            if (!empty($checkData['pest_notes'])) {
                $pestInfo .= " ({$checkData['pest_notes']})";
            }
            $observations[] = $pestInfo;
        }
        if (!empty($checkData['dusty_dirty'])) $observations[] = 'plant is dusty/dirty';

        if (!empty($observations)) {
            $currentCheck .= "- Observations: " . implode(', ', $observations) . "\n";
        }
        if (!empty($checkData['notes'])) {
            $currentCheck .= "- Notes: {$checkData['notes']}\n";
        }

        // Format recent checks for trend analysis
        $recentHistory = "";
        if (!empty($recentChecks)) {
            $recentHistory = "\n\nRecent Check History (for trend analysis):\n";
            foreach (array_slice($recentChecks, 0, 5) as $check) {
                $date = date('M j', strtotime($check['recorded_at'] ?? $check['performed_at'] ?? 'now'));
                $recentHistory .= "\n$date:";
                if (isset($check['moisture_level'])) {
                    $recentHistory .= " moisture={$check['moisture_level']}/10";
                }
                if (isset($check['general_health'])) {
                    $recentHistory .= " health={$check['general_health']}/5";
                }
                if (!empty($check['light_reading'])) {
                    $recentHistory .= " light={$check['light_reading']}fc";
                }
            }
        }

        // Format current tasks
        $tasksInfo = "";
        if (!empty($currentTasks)) {
            $tasksInfo = "\n\nCurrent Care Schedule:\n";
            foreach (array_slice($currentTasks, 0, 5) as $task) {
                $interval = $task['recurrence_interval'] ?? '?';
                $tasksInfo .= "- {$task['task_type']}: every {$interval} days\n";
            }
        }

        $location = $plantInfo['location'] ?? 'Not specified';
        $lightCondition = $plantInfo['light_condition'] ?? 'Not specified';

        $prompt = <<<PROMPT
Analyze this plant check data and provide a brief, actionable insight.

Plant: {$species}
Location: {$location}
Light condition: {$lightCondition}

{$currentCheck}
{$recentHistory}
{$tasksInfo}

Based on this data, provide ONE focused insight. Consider:
- Trends in moisture or health over time
- Whether current care schedule seems appropriate
- Any concerning patterns or positive signs
- Specific actions the owner should take

Respond ONLY with valid JSON:
{
    "type": "info|warning|success",
    "message": "1-2 sentence insight with specific, actionable advice",
    "suggestion": {
        "action": "adjust_watering|adjust_light|treat_pests|clean_plant|monitor|none",
        "details": "Specific suggestion if applicable, otherwise null"
    }
}

Use "success" type for positive observations (new growth, improving health).
Use "warning" for concerning patterns that need attention.
Use "info" for neutral observations or minor suggestions.
PROMPT;

        $messages = [
            ['role' => 'user', 'content' => $prompt]
        ];

        $response = $this->sendRequest($messages, 512);
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
