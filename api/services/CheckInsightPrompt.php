<?php
/**
 * Check Insight Prompt
 *
 * Builds the prompt and JSON schema for analyzing a plant check. Shared by
 * both AI providers. Besides the written insight, the reply says plainly
 * whether the plant should be watered today and whether its watering
 * interval should change, so the app can act on it instead of only
 * showing text.
 */

class CheckInsightPrompt
{
    public static function build(array $checkData, array $recentChecks, array $plantInfo, array $currentTasks): string
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
                $date = isset($check['recorded_at'])
                    ? date('M j', strtotime($check['recorded_at']))
                    : date('M j', strtotime(($check['performed_at'] ?? 'now') . ' UTC'));
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
                $tasksInfo .= "- {$task['task_type']}: every {$interval} days, next due {$task['due_date']}\n";
            }
        }

        $location = $plantInfo['location'] ?? 'Not specified';
        $lightCondition = $plantInfo['light_condition'] ?? 'Not specified';

        $prompt = <<<PROMPT
Analyze this plant check data and give a brief, actionable insight.

Plant: {$species}
Location: {$location}
Light condition: {$lightCondition}

{$currentCheck}
{$recentHistory}
{$tasksInfo}

Based on this data, give ONE focused insight. Consider:
- Trends in moisture or health over time
- Whether the current care schedule seems right
- Any concerning patterns or positive signs
- Specific actions the owner should take

Fields:
- type: "success" for positive signs (new growth, improving health), "warning" for concerning patterns that need attention, "info" for neutral notes or minor suggestions.
- message: 1-2 sentences of specific, actionable advice.
- water_now: true only if the plant should be watered today (for example the soil is dry for this species). The app will put watering on today's list. Never true when the soil is moist or wet.
- water_interval_days: a new number of days between waterings if the current watering interval should change, otherwise null.
- suggestion.action: the main follow-up (adjust_watering, adjust_light, treat_pests, clean_plant, monitor or none), and suggestion.details with specifics or null.
PROMPT;

        return $prompt;
    }

    /**
     * JSON schema for structured outputs.
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['info', 'warning', 'success']],
                'message' => ['type' => 'string'],
                'water_now' => ['type' => 'boolean'],
                'water_interval_days' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]],
                'suggestion' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'enum' => ['adjust_watering', 'adjust_light', 'treat_pests', 'clean_plant', 'monitor', 'none'],
                        ],
                        'details' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                    ],
                    'required' => ['action', 'details'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['type', 'message', 'water_now', 'water_interval_days', 'suggestion'],
            'additionalProperties' => false,
        ];
    }
}
