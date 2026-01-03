<?php
/**
 * Chat Controller
 * Handles AI chat conversations about plants
 */

class ChatController
{
    /**
     * Send a chat message about a plant
     * POST /plants/{id}/chat
     */
    public function chat(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];
        $message = $body['message'] ?? '';
        $provider = $body['provider'] ?? null;  // Optional: 'claude' or 'openai'

        if (empty($message)) {
            return ['status' => 400, 'data' => ['error' => 'Message is required']];
        }

        // Verify plant ownership
        $stmt = db()->prepare('
            SELECT p.*, l.name as location_name
            FROM plants p
            LEFT JOIN locations l ON p.location_id = l.id
            WHERE p.id = ? AND p.user_id = ?
        ');
        $stmt->execute([$plantId, $userId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Get conversation history for this plant (last 10 messages)
        $stmt = db()->prepare('
            SELECT role, content, provider
            FROM chat_messages
            WHERE plant_id = ? AND user_id = ?
            ORDER BY created_at DESC
            LIMIT 10
        ');
        $stmt->execute([$plantId, $userId]);
        $history = array_reverse($stmt->fetchAll());

        // Add the new user message to history
        $history[] = ['role' => 'user', 'content' => $message];

        // Get additional context
        $context = $this->getPlantContext($plantId);

        try {
            // Get AI service for user
            $aiService = AIServiceFactory::getForUser($userId, $provider);
            $usedProvider = $aiService->getProviderName();

            // Send chat request
            $response = $aiService->chat($plant, $history, $context);

            // Save user message to database
            $stmt = db()->prepare('
                INSERT INTO chat_messages (plant_id, user_id, role, content, provider)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$plantId, $userId, 'user', $message, $usedProvider]);

            // Save AI response to database
            $stmt = db()->prepare('
                INSERT INTO chat_messages (plant_id, user_id, role, content, provider, suggested_actions)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $plantId,
                $userId,
                'assistant',
                $response['content'],
                $usedProvider,
                !empty($response['suggested_actions']) ? json_encode($response['suggested_actions']) : null
            ]);

            return [
                'response' => $response['content'],
                'suggested_actions' => $response['suggested_actions'] ?? [],
                'provider' => $usedProvider
            ];
        } catch (Exception $e) {
            return ['status' => 500, 'data' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * Get chat history for a plant
     * GET /plants/{id}/chat
     */
    public function history(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];
        $limit = $body['limit'] ?? 50;

        // Verify plant ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Get chat history
        $stmt = db()->prepare('
            SELECT id, role, content, provider, suggested_actions, created_at
            FROM chat_messages
            WHERE plant_id = ? AND user_id = ?
            ORDER BY created_at ASC
            LIMIT ?
        ');
        $stmt->execute([$plantId, $userId, $limit]);
        $messages = $stmt->fetchAll();

        // Parse suggested_actions JSON
        foreach ($messages as &$msg) {
            if ($msg['suggested_actions']) {
                $msg['suggested_actions'] = json_decode($msg['suggested_actions'], true);
            }
        }

        return ['messages' => $messages];
    }

    /**
     * Apply a suggested action from AI
     * POST /plants/{id}/chat/apply-action
     */
    public function applyAction(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];
        $action = $body['action'] ?? null;

        if (!$action || !isset($action['type'])) {
            return ['status' => 400, 'data' => ['error' => 'Invalid action']];
        }

        // Verify plant ownership
        $stmt = db()->prepare('SELECT * FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        $plant = $stmt->fetch();

        if (!$plant) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        try {
            switch ($action['type']) {
                case 'update_species':
                    $stmt = db()->prepare('UPDATE plants SET species = ?, species_confidence = 1.0 WHERE id = ?');
                    $stmt->execute([$action['new'], $plantId]);
                    return ['success' => true, 'updated_field' => 'species', 'new_value' => $action['new']];

                case 'update_notes':
                    // Append to existing notes
                    $newNotes = $plant['notes'] ? $plant['notes'] . "\n\n" . $action['new'] : $action['new'];
                    $stmt = db()->prepare('UPDATE plants SET notes = ? WHERE id = ?');
                    $stmt->execute([$newNotes, $plantId]);
                    return ['success' => true, 'updated_field' => 'notes', 'new_value' => $newNotes];

                case 'update_health':
                    $stmt = db()->prepare('UPDATE plants SET health_status = ?, last_health_check = datetime("now") WHERE id = ?');
                    $stmt->execute([$action['new'], $plantId]);
                    return ['success' => true, 'updated_field' => 'health_status', 'new_value' => $action['new']];

                case 'update_care_schedule':
                    // This would require more complex logic to update tasks
                    // For now, regenerate the care plan
                    $carePlanController = new CarePlanController();
                    $carePlanController->generateCarePlan($plantId);
                    return ['success' => true, 'updated_field' => 'care_schedule', 'message' => 'Care plan regenerated'];

                default:
                    return ['status' => 400, 'data' => ['error' => 'Unknown action type: ' . $action['type']]];
            }
        } catch (Exception $e) {
            return ['status' => 500, 'data' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * Clear chat history for a plant
     * DELETE /plants/{id}/chat
     */
    public function clearHistory(array $params, array $body, ?int $userId): array
    {
        $plantId = $params['id'];

        // Verify plant ownership
        $stmt = db()->prepare('SELECT id FROM plants WHERE id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);
        if (!$stmt->fetch()) {
            return ['status' => 404, 'data' => ['error' => 'Plant not found']];
        }

        // Delete chat history
        $stmt = db()->prepare('DELETE FROM chat_messages WHERE plant_id = ? AND user_id = ?');
        $stmt->execute([$plantId, $userId]);

        return ['success' => true, 'message' => 'Chat history cleared'];
    }

    /**
     * Get additional context for AI chat
     */
    private function getPlantContext(int $plantId): array
    {
        $context = [];

        // Get recent care log
        $stmt = db()->prepare('
            SELECT action, performed_at, notes, outcome
            FROM care_log
            WHERE plant_id = ?
            ORDER BY performed_at DESC
            LIMIT 10
        ');
        $stmt->execute([$plantId]);
        $context['care_log'] = $stmt->fetchAll();

        // Get upcoming tasks
        $stmt = db()->prepare('
            SELECT task_type, due_date, priority, instructions
            FROM tasks
            WHERE plant_id = ? AND completed_at IS NULL
            ORDER BY due_date ASC
            LIMIT 10
        ');
        $stmt->execute([$plantId]);
        $context['tasks'] = $stmt->fetchAll();

        return $context;
    }
}
