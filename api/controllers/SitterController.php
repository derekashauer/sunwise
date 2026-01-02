<?php
/**
 * Sitter Mode Controller
 */

class SitterController
{
    /**
     * Create sitter session
     */
    public function create(array $params, array $body, ?int $userId): array
    {
        $plantIds = $body['plant_ids'] ?? [];
        $startDate = $body['start_date'] ?? null;
        $endDate = $body['end_date'] ?? null;
        $sitterName = $body['sitter_name'] ?? null;
        $instructions = $body['instructions'] ?? null;

        if (empty($plantIds)) {
            return ['status' => 400, 'data' => ['error' => 'Please select at least one plant']];
        }

        if (!$startDate || !$endDate) {
            return ['status' => 400, 'data' => ['error' => 'Start and end dates are required']];
        }

        // Verify all plants belong to user
        $placeholders = implode(',', array_fill(0, count($plantIds), '?'));
        $stmt = db()->prepare("SELECT id FROM plants WHERE id IN ($placeholders) AND user_id = ?");
        $stmt->execute([...$plantIds, $userId]);
        $validPlants = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($validPlants) !== count($plantIds)) {
            return ['status' => 400, 'data' => ['error' => 'Some plants not found']];
        }

        // Generate secure token
        $token = bin2hex(random_bytes(32));

        // Create session
        $stmt = db()->prepare('
            INSERT INTO sitter_sessions (user_id, token, start_date, end_date, sitter_name, instructions)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $token, $startDate, $endDate, $sitterName, $instructions]);
        $sessionId = db()->lastInsertId();

        // Add plants to session
        $stmt = db()->prepare('INSERT INTO sitter_plants (session_id, plant_id) VALUES (?, ?)');
        foreach ($plantIds as $plantId) {
            $stmt->execute([$sessionId, $plantId]);
        }

        $url = APP_URL . '/sitter/' . $token;

        return [
            'session_id' => $sessionId,
            'token' => $token,
            'url' => $url
        ];
    }

    /**
     * Get sitter session (guest access)
     */
    public function show(array $params, array $body, ?int $userId): array
    {
        $token = $params['token'];

        // Find session
        $stmt = db()->prepare('
            SELECT * FROM sitter_sessions
            WHERE token = ?
              AND end_date >= date("now", "-1 day")
        ');
        $stmt->execute([$token]);
        $session = $stmt->fetch();

        if (!$session) {
            return ['status' => 404, 'data' => ['error' => 'Session not found or expired']];
        }

        // Update accessed_at
        $stmt = db()->prepare('UPDATE sitter_sessions SET accessed_at = datetime("now") WHERE id = ?');
        $stmt->execute([$session['id']]);

        // Get plants in session
        $stmt = db()->prepare('
            SELECT p.id, p.name, p.species, p.location,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as thumbnail
            FROM sitter_plants sp
            JOIN plants p ON sp.plant_id = p.id
            WHERE sp.session_id = ?
        ');
        $stmt->execute([$session['id']]);
        $plants = $stmt->fetchAll();

        // Get tasks for session date range
        $stmt = db()->prepare('
            SELECT t.id, t.task_type, t.due_date, t.instructions, t.priority, t.completed_at,
                   p.name as plant_name, p.location as plant_location,
                   (SELECT filename FROM photos WHERE plant_id = p.id ORDER BY uploaded_at DESC LIMIT 1) as plant_thumbnail
            FROM tasks t
            JOIN sitter_plants sp ON t.plant_id = sp.plant_id
            JOIN plants p ON t.plant_id = p.id
            WHERE sp.session_id = ?
              AND t.due_date BETWEEN ? AND ?
              AND t.skipped_at IS NULL
            ORDER BY t.due_date ASC, t.priority ASC
        ');
        $stmt->execute([$session['id'], $session['start_date'], $session['end_date']]);
        $tasks = $stmt->fetchAll();

        return [
            'session' => [
                'id' => $session['id'],
                'start_date' => $session['start_date'],
                'end_date' => $session['end_date'],
                'sitter_name' => $session['sitter_name'],
                'instructions' => $session['instructions'],
                'plants' => $plants
            ],
            'tasks' => $tasks
        ];
    }

    /**
     * Complete task (guest access)
     */
    public function completeTask(array $params, array $body, ?int $userId): array
    {
        $token = $params['token'];
        $taskId = $params['id'];

        // Verify session and task
        $stmt = db()->prepare('
            SELECT s.id as session_id, t.*
            FROM sitter_sessions s
            JOIN sitter_plants sp ON s.id = sp.session_id
            JOIN tasks t ON sp.plant_id = t.plant_id
            WHERE s.token = ?
              AND t.id = ?
              AND s.end_date >= date("now", "-1 day")
              AND t.due_date BETWEEN s.start_date AND s.end_date
        ');
        $stmt->execute([$token, $taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            return ['status' => 404, 'data' => ['error' => 'Task not found']];
        }

        if ($task['completed_at']) {
            return ['status' => 400, 'data' => ['error' => 'Task already completed']];
        }

        // Mark as completed
        $stmt = db()->prepare('UPDATE tasks SET completed_at = datetime("now"), notes = ? WHERE id = ?');
        $stmt->execute(['Completed by sitter', $taskId]);

        // Log to care log
        $stmt = db()->prepare('
            INSERT INTO care_log (plant_id, task_id, action, notes)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $task['plant_id'],
            $taskId,
            $task['task_type'],
            'Completed by sitter'
        ]);

        // Get updated task
        $stmt = db()->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);

        return ['task' => $stmt->fetch()];
    }
}
