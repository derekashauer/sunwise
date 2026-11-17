<?php
/**
 * Care Plan Prompt
 *
 * Builds the care plan prompt and its JSON schema. Both AI providers use
 * this, so a fix to the prompt only has to be made once.
 */

class CarePlanPrompt
{
    /**
     * Build the prompt text for a plant.
     */
    public static function build(array $plant, array $careLog, string $season): string
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
            // Always specify growing medium for propagations
            if ($plant['soil_type'] === 'water') {
                $plantInfo .= "\nGrowing medium: Water (water propagation)";
            } else {
                // Rooting medium, soil, or any other type - all treated as soil-based propagation
                $mediumLabel = $plant['soil_type'] === 'rooting' ? 'Rooting medium' : 'Soil (' . $plant['soil_type'] . ')';
                $plantInfo .= "\nGrowing medium: " . $mediumLabel . " (soil propagation - NOT water)";
            }
        }

        // Grow light info
        if (!empty($plant['has_grow_light'])) {
            $hours = $plant['grow_light_hours'] ?? 'unspecified';
            $plantInfo .= "\nGrow light: Yes, {$hours} hours/day";
        }

        // Rotation setting
        if (isset($plant['can_rotate']) && !$plant['can_rotate']) {
            $plantInfo .= "\nCAN ROTATE: NO - Do NOT include rotate tasks for this plant";
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
                $careHistory .= "\n- {$log['action']} on " . date('M j', strtotime($log['performed_at'] . ' UTC'));
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
                    $time = date('M j \a\t g:ia', (isset($data['recorded_at']) ? strtotime($data['recorded_at']) : strtotime($check['date'] . ' UTC')));
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


        // Owner's home and care preferences
        $preferences = "";
        if (!empty($plant['climate_notes'])) {
            $preferences .= "\nHome climate: " . $plant['climate_notes'];
        }
        if (($plant['fertilizer_method'] ?? 'separate') === 'mixed') {
            $preferences .= "\nFertilizer: liquid fertilizer mixed into the watering can (fertilizing also waters the plant)";
        } else {
            $preferences .= "\nFertilizer: slow-release granules (Osmocote Plus 15-9-12) sprinkled on the soil. One feeding lasts about 4 months. Fertilizing does NOT water the plant.";
        }
        if (!empty($plant['winter_rest'])) {
            $preferences .= "\nFertilizer rest: no fertilizing from September through February";
        }
        if (!empty($plant['last_repot_date'])) {
            $preferences .= "\nLast repotted: " . $plant['last_repot_date'] . " (fresh mix often has fertilizer; wait about 3 months before feeding)";
        }
        if (!empty($plant['schedule_overrides'])) {
            $parts = [];
            foreach ($plant['schedule_overrides'] as $type => $days) {
                $parts[] = "$type every $days days";
            }
            $preferences .= "\nOwner-set intervals (use exactly): " . implode(', ', $parts);
        }
        if ($preferences !== "") {
            $preferences = "\n\n=== OWNER'S HOME AND PREFERENCES ===" . $preferences;
        }

        $today = Clock::today();

        $prompt = <<<PROMPT
Generate a personalized care plan for this plant based on all available data.

=== PLANT DETAILS ===
$plantInfo
$speciesCareInfo
$healthContext
$statsInfo
$careHistory
$checkReadings
$preferences

Today's date: $today

Create a personalized care schedule considering:
1. USER NOTES ARE THE HIGHEST PRIORITY - if the user specifies a watering frequency or care preference, follow it exactly
2. The specific species needs (use the species-specific guidelines if provided, but user notes override these)
3. Current season ($season) and how it affects watering/fertilizing
4. The plant's health status and any recent issues identified
5. Previous care history, outcomes, and completion patterns
5b. The owner's home climate and fertilizer setup. Dry indoor air (low humidity, forced-air heat) can dry soil in small or terracotta pots faster even in winter, so trust moisture readings over the calendar
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

Include 3-5 different task types, with at most one task per type. Always include a "check" task (every 7 or more days) and, unless it is a water propagation, a "water" task.
Use "recurrence": {"type": "days", ...} with a whole number of days.

FERTILIZER RULES:
- With slow-release granules: one "fertilize" task with an interval of about 120 days. The instructions should say to sprinkle the label amount for the pot size on the soil and scratch it in lightly. Use half the amount for succulents and cacti.
- With liquid fertilizer: one "fertilize" task every 14-30 days in spring and summer.
- During the fertilizer rest months, set the fertilize due_date to the next March 1.
- Never fertilize propagations, sick plants with root problems, or plants repotted in the last 3 months.

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

        return $prompt;
    }

    /**
     * JSON schema for structured outputs, so the reply always parses.
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reasoning' => ['type' => 'string'],
                'next_photo_check' => ['type' => 'string', 'format' => 'date'],
                'photo_check_reason' => ['type' => 'string'],
                'tasks' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => [
                                'type' => 'string',
                                'enum' => ['water', 'fertilize', 'trim', 'repot', 'rotate', 'mist', 'check', 'change_water', 'check_roots', 'pot_up'],
                            ],
                            'due_date' => ['type' => 'string', 'format' => 'date'],
                            'recurrence' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => ['type' => 'string', 'enum' => ['days']],
                                    'interval' => ['type' => 'integer'],
                                ],
                                'required' => ['type', 'interval'],
                                'additionalProperties' => false,
                            ],
                            'instructions' => ['type' => 'string'],
                            'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'urgent']],
                        ],
                        'required' => ['type', 'due_date', 'recurrence', 'instructions', 'priority'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['reasoning', 'next_photo_check', 'photo_check_reason', 'tasks'],
            'additionalProperties' => false,
        ];
    }
}
